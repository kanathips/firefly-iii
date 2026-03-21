<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\GoogleSheets\Jobs;

use Carbon\Carbon;
use FireflyIII\Extensions\GoogleSheets\Models\SheetConnection;
use FireflyIII\Extensions\GoogleSheets\Models\SheetSyncLog;
use FireflyIII\Extensions\GoogleSheets\Services\GoogleSheetsConnector;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Writes new Firefly transactions created since last_synced_at to a Google Sheet.
 *
 * Flow:
 *   1. Load SheetConnection; throw if not found or inactive.
 *   2. Decrypt stored token; refresh access token.
 *   3. Query TransactionJournals newer than last_synced_at for this user.
 *   4. Build rows via GoogleSheetsConnector::buildRowFromTransaction.
 *   5. Append rows to the configured sheet.
 *   6. Write a SheetSyncLog and update last_synced_at.
 */
class SyncToGoogleSheetsJob implements ShouldQueue
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
            $tokenJson    = $connector->decryptToken($connection->google_token);
            $tokenData    = json_decode($tokenJson, true, 512, JSON_THROW_ON_ERROR);
            $refreshToken = $tokenData['refresh_token'] ?? '';
            $accessToken  = $tokenData['access_token'];

            if ('' !== $refreshToken) {
                $refreshed = $connector->refreshAccessToken($refreshToken);
                if (is_array($refreshed) && isset($refreshed['access_token'])) {
                    $accessToken = $refreshed['access_token'];
                }
            }

            $fieldMap = $connection->getDecodedFieldMap();
            $since    = $connection->last_synced_at;

            $journals = $this->queryJournals($connection->user_id, $since);
            $rows     = [];

            foreach ($journals as $journal) {
                $txn  = $this->journalToArray($journal);
                $row  = $connector->buildRowFromTransaction($txn, $fieldMap);
                $rows[] = $row;
            }

            $recordsOut = 0;
            if ([] !== $rows) {
                $range = 'Sheet1!A:' . $this->lastColumn($fieldMap);
                $connector->appendSheetRows($connection->sheet_id, $range, $rows, $accessToken);
                $recordsOut = count($rows);
            }

            $this->createLog($connection, 'completed', 0, $recordsOut, null, $startedAt);
            $connection->update(['last_synced_at' => Carbon::now()]);
        } catch (\Throwable $e) {
            $this->createLog($connection, 'failed', 0, 0, $e->getMessage(), $startedAt);

            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Query TransactionJournals for a user since an optional date.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, TransactionJournal>
     */
    private function queryJournals(int $userId, ?Carbon $since): \Illuminate\Database\Eloquent\Collection
    {
        $query = TransactionJournal::where('user_id', $userId);

        if (null !== $since) {
            $query->where('date', '>', $since->toDateString());
        }

        return $query->get();
    }

    /**
     * Convert a TransactionJournal to a flat array for row building.
     *
     * @return array<string, mixed>
     */
    private function journalToArray(TransactionJournal $journal): array
    {
        $transaction = $journal->transactions()->first();

        return [
            'date'   => $journal->date?->format('Y-m-d') ?? '',
            'payee'  => $journal->description ?? '',
            'amount' => $transaction?->amount ?? 0,
        ];
    }

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
