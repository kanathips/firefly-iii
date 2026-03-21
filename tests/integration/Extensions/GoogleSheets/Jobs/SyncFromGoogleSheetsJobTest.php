<?php

declare(strict_types=1);

namespace Tests\integration\Extensions\GoogleSheets\Jobs;

use FireflyIII\Extensions\GoogleSheets\Jobs\SyncFromGoogleSheetsJob;
use FireflyIII\Extensions\GoogleSheets\Models\SheetConnection;
use FireflyIII\Extensions\GoogleSheets\Models\SheetSyncLog;
use FireflyIII\Extensions\GoogleSheets\Services\GoogleSheetsConnector;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\integration\CreatesApplication;
use Tests\integration\TestCase;

#[CoversClass(SyncFromGoogleSheetsJob::class)]
final class SyncFromGoogleSheetsJobTest extends TestCase
{
    use CreatesApplication;
    use RefreshDatabase;

    protected $seed = false;

    private User            $user;
    private SheetConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $group      = UserGroup::create(['title' => 'sheets-test@email.com']);
        $role       = UserRole::firstOrCreate(['title' => 'owner']);
        $this->user = User::create([
            'email'         => 'sheets-test@email.com',
            'password'      => bcrypt('password'),
            'user_group_id' => $group->id,
        ]);
        GroupMembership::create([
            'user_id'       => $this->user->id,
            'user_group_id' => $group->id,
            'user_role_id'  => $role->id,
        ]);

        $this->connection = SheetConnection::create([
            'user_id'        => $this->user->id,
            'google_token'   => json_encode(['access_token' => 'test-token', 'refresh_token' => 'refresh-token']),
            'sheet_id'       => 'test-spreadsheet-id',
            'field_map'      => json_encode(['date' => 'A', 'payee' => 'B', 'amount' => 'C']),
            'direction'      => 'import',
            'last_synced_at' => null,
            'is_active'      => true,
        ]);
    }

    public function testJobCreatesASyncLogEntry(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);
        $connector->method('readSheetRows')->willReturn([
            ['2024-01-15', 'Coffee Shop', '-5.50'],
            ['2024-01-16', 'Grocery Store', '-45.00'],
        ]);
        $connector->method('parseRowToTransaction')->willReturnCallback(function (array $row, array $fieldMap): ?array {
            if (count($row) < 3) {
                return null;
            }

            return ['date' => $row[0], 'payee' => $row[1], 'amount' => (float) $row[2]];
        });
        $connector->method('refreshAccessToken')->willReturn(['access_token' => 'new-token']);
        $connector->method('decryptToken')->willReturn(json_encode(['access_token' => 'test-token', 'refresh_token' => 'refresh-token']));

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $job = new SyncFromGoogleSheetsJob($this->connection->id);
        $job->handle($connector);

        $log = SheetSyncLog::where('sheet_connection_id', $this->connection->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('completed', $log->status);
    }

    public function testJobRecordsRecordsInCount(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);
        $connector->method('readSheetRows')->willReturn([
            ['2024-01-15', 'Coffee Shop', '-5.50'],
            ['2024-01-16', 'Grocery Store', '-45.00'],
        ]);
        $connector->method('parseRowToTransaction')->willReturnCallback(function (array $row, array $fieldMap): ?array {
            if (count($row) < 3) {
                return null;
            }

            return ['date' => $row[0], 'payee' => $row[1], 'amount' => (float) $row[2]];
        });
        $connector->method('refreshAccessToken')->willReturn(['access_token' => 'new-token']);
        $connector->method('decryptToken')->willReturn(json_encode(['access_token' => 'test-token', 'refresh_token' => 'refresh-token']));

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $job = new SyncFromGoogleSheetsJob($this->connection->id);
        $job->handle($connector);

        $log = SheetSyncLog::where('sheet_connection_id', $this->connection->id)->first();
        $this->assertSame(2, $log->records_in);
    }

    public function testJobLogsFailureOnConnectorError(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);
        $connector->method('readSheetRows')->willThrowException(new \RuntimeException('API quota exceeded'));
        $connector->method('decryptToken')->willReturn(json_encode(['access_token' => 'test-token', 'refresh_token' => 'refresh-token']));

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $job = new SyncFromGoogleSheetsJob($this->connection->id);

        try {
            $job->handle($connector);
        } catch (\RuntimeException) {
            // Expected
        }

        $log = SheetSyncLog::where('sheet_connection_id', $this->connection->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->status);
    }

    public function testJobUpdatesLastSyncedAtOnSuccess(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);
        $connector->method('readSheetRows')->willReturn([]);
        $connector->method('decryptToken')->willReturn(json_encode(['access_token' => 'test-token', 'refresh_token' => 'refresh-token']));

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $job = new SyncFromGoogleSheetsJob($this->connection->id);
        $job->handle($connector);

        $this->connection->refresh();
        $this->assertNotNull($this->connection->last_synced_at);
    }

    public function testJobSkipsRowsWithInvalidData(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);
        $connector->method('readSheetRows')->willReturn([
            ['2024-01-15', 'Coffee Shop', '-5.50'],
            [],  // invalid row
            ['header row'],  // too short row
        ]);
        $connector->method('parseRowToTransaction')->willReturnCallback(function (array $row, array $fieldMap): ?array {
            if (count($row) < 3) {
                return null;
            }

            return ['date' => $row[0], 'payee' => $row[1], 'amount' => (float) $row[2]];
        });
        $connector->method('decryptToken')->willReturn(json_encode(['access_token' => 'test-token', 'refresh_token' => 'refresh-token']));

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $job = new SyncFromGoogleSheetsJob($this->connection->id);
        $job->handle($connector);

        $log = SheetSyncLog::where('sheet_connection_id', $this->connection->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(1, $log->records_in);
    }

    public function testJobThrowsWhenConnectionNotFound(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SheetConnection not found');

        $job = new SyncFromGoogleSheetsJob(99999);
        $job->handle($connector);
    }

    public function testJobThrowsWhenConnectionIsInactive(): void
    {
        $this->connection->update(['is_active' => false]);

        $connector = $this->createMock(GoogleSheetsConnector::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SheetConnection is not active');

        $job = new SyncFromGoogleSheetsJob($this->connection->id);
        $job->handle($connector);
    }
}
