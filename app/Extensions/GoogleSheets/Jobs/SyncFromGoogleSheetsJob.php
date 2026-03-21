<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\GoogleSheets\Jobs;

use Carbon\Carbon;
use FireflyIII\Extensions\GoogleSheets\Models\SheetConnection;
use FireflyIII\Extensions\GoogleSheets\Models\SheetSyncLog;
use FireflyIII\Extensions\GoogleSheets\Services\GoogleSheetsConnector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Reads rows from a connected Google Sheet and records them as Firefly transactions.
 *
 * Flow:
 *   1. Load SheetConnection; throw if not found or inactive.
 *   2. Decrypt stored token; refresh access token.
 *   3. Read rows from the configured sheet/range.
 *   4. Parse each row via GoogleSheetsConnector::parseRowToTransaction.
 *   5. Skip rows that return null.
 *   6. Create a SheetSyncLog with status 'completed' or 'failed'.
 *   7. Update last_synced_at on success.
 */
class SyncFromGoogleSheetsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $connectionId) {}

    public function handle(GoogleSheetsConnector $connector): void
    {
        $connection = SheetConnection::find($this->connectionId);

        if (null === $connection) {
            $this->createLog(null, 'failed', 0, 0, 'SheetConnection not found');

            throw new RuntimeException('SheetConnection not found: ' . $this->connectionId);
        }

        if (!$connection->is_active) {
            $this->createLog($connection, 'failed', 0, 0, 'SheetConnection is not active');

            throw new RuntimeException('SheetConnection is not active: ' . $this->connectionId);
        }

        $startedAt = Carbon::now();

        try {
            // Decrypt stored token
            $tokenJson    = $connector->decryptToken($connection->google_token);
            $tokenData    = json_decode($tokenJson, true, 512, JSON_THROW_ON_ERROR);
            $refreshToken = $tokenData['refresh_token'] ?? '';
            $accessToken  = $tokenData['access_token'];

            // Refresh access token if we have a refresh token and the refresh succeeds
            if ('' !== $refreshToken) {
                $refreshed = $connector->refreshAccessToken($refreshToken);
                if (is_array($refreshed) && isset($refreshed['access_token'])) {
                    $accessToken = $refreshed['access_token'];
                }
            }

            $fieldMap = $connection->getDecodedFieldMap();
            $range    = 'Sheet1!A:' . $this->lastColumn($fieldMap);

            $rows        = $connector->readSheetRows($connection->sheet_id, $range, $accessToken);
            $recordsIn   = 0;

            foreach ($rows as $row) {
                $txn = $connector->parseRowToTransaction($row, $fieldMap);
                if (null === $txn) {
                    continue;
                }

                // TODO: create Firefly transaction via existing pipeline
                ++$recordsIn;
            }

            $this->createLog($connection, 'completed', $recordsIn, 0, null, $startedAt);
            $connection->update(['last_synced_at' => Carbon::now()]);
        } catch (\Throwable $e) {
            $this->createLog($connection, 'failed', 0, 0, $e->getMessage(), $startedAt);

            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function createLog(
        ?SheetConnection $connection,
        string $status,
        int $recordsIn,
        int $recordsOut,
        ?string $errorMessage,
        ?Carbon $startedAt = null,
    ): void {
        SheetSyncLog::create([
            'sheet_connection_id' => $connection?->id ?? $this->connectionId,
            'status'              => $status,
            'records_in'          => $recordsIn,
            'records_out'         => $recordsOut,
            'error_message'       => $errorMessage,
            'started_at'          => $startedAt,
            'finished_at'         => Carbon::now(),
        ]);
    }

    /**
     * Determine the last column letter used in a field map.
     *
     * @param  array<string, string>  $fieldMap
     */
    private function lastColumn(array $fieldMap): string
    {
        if ([] === $fieldMap) {
            return 'Z';
        }

        $columns = array_values($fieldMap);
        sort($columns);

        return end($columns) ?: 'Z';
    }
}
