<?php

declare(strict_types=1);

namespace Tests\integration\Extensions\GoogleSheets\Jobs;

use FireflyIII\Extensions\GoogleSheets\Jobs\SyncToGoogleSheetsJob;
use FireflyIII\Extensions\GoogleSheets\Models\SheetConnection;
use FireflyIII\Extensions\GoogleSheets\Models\SheetSyncLog;
use FireflyIII\Extensions\GoogleSheets\Services\GoogleSheetsConnector;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\integration\CreatesApplication;
use Tests\integration\TestCase;

#[CoversClass(SyncToGoogleSheetsJob::class)]
final class SyncToGoogleSheetsJobTest extends TestCase
{
    use CreatesApplication;
    use RefreshDatabase;

    protected $seed = false;

    private User            $user;
    private SheetConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $group      = UserGroup::create(['title' => 'sheets-to-test@email.com']);
        $role       = UserRole::firstOrCreate(['title' => 'owner']);
        $this->user = User::create([
            'email'         => 'sheets-to-test@email.com',
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
            'direction'      => 'export',
            'last_synced_at' => null,
            'is_active'      => true,
        ]);
    }

    public function testJobCreatesASyncLogEntry(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);
        $connector->method('decryptToken')->willReturn(json_encode(['access_token' => 'test-token', 'refresh_token' => 'refresh-token']));
        $connector->method('appendSheetRows')->willReturn(null);
        $connector->method('buildRowFromTransaction')->willReturnCallback(
            static fn (array $txn, array $fieldMap): array => [$txn['date'] ?? '', $txn['payee'] ?? '', $txn['amount'] ?? '']
        );

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $job = new SyncToGoogleSheetsJob($this->connection->id);
        $job->handle($connector);

        $log = SheetSyncLog::where('sheet_connection_id', $this->connection->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('completed', $log->status);
    }

    public function testJobUpdatesLastSyncedAtOnSuccess(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);
        $connector->method('decryptToken')->willReturn(json_encode(['access_token' => 'test-token', 'refresh_token' => 'refresh-token']));

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $job = new SyncToGoogleSheetsJob($this->connection->id);
        $job->handle($connector);

        $this->connection->refresh();
        $this->assertNotNull($this->connection->last_synced_at);
    }

    public function testJobThrowsWhenConnectionNotFound(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SheetConnection not found');

        $job = new SyncToGoogleSheetsJob(99999);
        $job->handle($connector);
    }

    public function testJobThrowsWhenConnectionIsInactive(): void
    {
        $this->connection->update(['is_active' => false]);

        $connector = $this->createMock(GoogleSheetsConnector::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SheetConnection is not active');

        $job = new SyncToGoogleSheetsJob($this->connection->id);
        $job->handle($connector);
    }

    public function testJobLogsFailureOnConnectorError(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);
        // Throw from decryptToken to simulate a connector-level error before we even read the sheet
        $connector->method('decryptToken')->willThrowException(new \RuntimeException('API quota exceeded'));

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $job = new SyncToGoogleSheetsJob($this->connection->id);

        try {
            $job->handle($connector);
        } catch (\RuntimeException) {
            // Expected
        }

        $log = SheetSyncLog::where('sheet_connection_id', $this->connection->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->status);
    }

    public function testJobRecordsRecordsOutCount(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);
        $connector->method('decryptToken')->willReturn(json_encode(['access_token' => 'test-token', 'refresh_token' => 'refresh-token']));
        $connector->method('appendSheetRows')->willReturn(null);
        $connector->method('buildRowFromTransaction')->willReturnCallback(
            static fn (array $txn, array $fieldMap): array => [$txn['date'] ?? '', $txn['payee'] ?? '', $txn['amount'] ?? '']
        );

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $job = new SyncToGoogleSheetsJob($this->connection->id);
        $job->handle($connector);

        // No journals in test DB → records_out = 0
        $log = SheetSyncLog::where('sheet_connection_id', $this->connection->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(0, $log->records_out);
    }
}
