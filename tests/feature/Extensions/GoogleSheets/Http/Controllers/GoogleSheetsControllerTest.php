<?php

declare(strict_types=1);

namespace Tests\feature\Extensions\GoogleSheets\Http\Controllers;

use FireflyIII\Extensions\GoogleSheets\Http\Controllers\GoogleSheetsController;
use FireflyIII\Extensions\GoogleSheets\Models\SheetConnection;
use FireflyIII\Extensions\GoogleSheets\Services\GoogleSheetsConnector;
use FireflyIII\Models\UserGroup;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\feature\TestCase;
use Tests\integration\CreatesApplication;

/**
 * @internal
 */
#[CoversClass(GoogleSheetsController::class)]
final class GoogleSheetsControllerTest extends TestCase
{
    use CreatesApplication;
    use RefreshDatabase;

    protected $seed = false;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $group      = UserGroup::create(['title' => 'sheets-ctrl-test@example.com']);
        $this->user = User::create([
            'email'         => 'sheets-ctrl-test@example.com',
            'password'      => bcrypt('password'),
            'user_group_id' => $group->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /v1/ext/sheets/auth/url
    // -------------------------------------------------------------------------

    public function testAuthUrlReturnsUrlForAuthenticatedUser(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);
        $connector->method('getAuthorizationUrl')
            ->willReturn('https://accounts.google.com/o/oauth2/v2/auth?client_id=test&scope=spreadsheets+offline_access&redirect_uri=https%3A%2F%2Fexample.com&response_type=code&access_type=offline');

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/ext/sheets/auth/url')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['url']]);
    }

    public function testAuthUrlRequiresAuthentication(): void
    {
        $this->getJson('/api/v1/ext/sheets/auth/url')
            ->assertStatus(401);
    }

    public function testAuthUrlContainsSheetsScope(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);
        $connector->method('getAuthorizationUrl')
            ->willReturn('https://accounts.google.com/o/oauth2/v2/auth?client_id=test&scope=spreadsheets+offline_access&offline=true');

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/ext/sheets/auth/url');

        $response->assertStatus(200);
        $url = $response->json('data.url');
        $this->assertStringContainsString('spreadsheets', $url);
    }

    // -------------------------------------------------------------------------
    // GET /v1/ext/sheets/auth/callback
    // -------------------------------------------------------------------------

    public function testAuthCallbackRequiresCodeParameter(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/ext/sheets/auth/callback')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function testAuthCallbackReturns422OnOAuthError(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);
        $connector->method('exchangeCodeForTokens')
            ->willThrowException(new \RuntimeException('invalid_grant'));

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/ext/sheets/auth/callback?code=bad-code')
            ->assertStatus(422);
    }

    public function testAuthCallbackCreatesConnectionOnSuccess(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);
        $connector->method('exchangeCodeForTokens')
            ->willReturn(['access_token' => 'abc', 'refresh_token' => 'xyz']);
        $connector->method('encryptToken')
            ->willReturn('encrypted-token-value');

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/ext/sheets/auth/callback?code=valid-code');

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'direction', 'is_active']]);

        $this->assertDatabaseHas('extension_sheet_connections', [
            'user_id'   => $this->user->id,
            'is_active' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /v1/ext/sheets/connections
    // -------------------------------------------------------------------------

    public function testListConnectionsRequiresAuthentication(): void
    {
        $this->getJson('/api/v1/ext/sheets/connections')
            ->assertStatus(401);
    }

    public function testListConnectionsReturnsEmptyArrayWhenNoConnections(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/ext/sheets/connections')
            ->assertStatus(200)
            ->assertJson(['data' => []]);
    }

    public function testListConnectionsReturnsUserConnections(): void
    {
        SheetConnection::create([
            'user_id'      => $this->user->id,
            'google_token' => 'fake-token',
            'sheet_id'     => 'spreadsheet-123',
            'field_map'    => json_encode(['date' => 'A', 'payee' => 'B', 'amount' => 'C']),
            'direction'    => 'import',
            'is_active'    => true,
        ]);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/ext/sheets/connections')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure(['data' => [['id', 'sheet_id', 'direction', 'is_active']]]);
    }

    public function testListConnectionsDoesNotReturnOtherUsersConnections(): void
    {
        $other      = UserGroup::create(['title' => 'other-sheets@example.com']);
        $otherUser  = User::create([
            'email'         => 'other-sheets@example.com',
            'password'      => bcrypt('password'),
            'user_group_id' => $other->id,
        ]);

        SheetConnection::create([
            'user_id'      => $otherUser->id,
            'google_token' => 'fake-token',
            'sheet_id'     => 'other-spreadsheet',
            'field_map'    => json_encode(['date' => 'A', 'payee' => 'B', 'amount' => 'C']),
            'direction'    => 'import',
            'is_active'    => true,
        ]);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/ext/sheets/connections')
            ->assertStatus(200)
            ->assertJson(['data' => []]);
    }

    // -------------------------------------------------------------------------
    // POST /v1/ext/sheets/connections
    // -------------------------------------------------------------------------

    public function testStoreConnectionRequiresAuthentication(): void
    {
        $this->postJson('/api/v1/ext/sheets/connections', [])
            ->assertStatus(401);
    }

    public function testStoreConnectionValidatesRequiredFields(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/sheets/connections', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sheet_id', 'field_map', 'direction']);
    }

    public function testStoreConnectionRejectsInvalidFieldMap(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);
        $connector->method('validateFieldMap')->willReturn(false);

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/sheets/connections', [
                'sheet_id'  => 'abc123',
                'field_map' => ['only_date' => 'A'],
                'direction' => 'import',
            ])
            ->assertStatus(422);
    }

    public function testStoreConnectionReturns422WhenNoPendingConnection(): void
    {
        $connector = $this->createMock(GoogleSheetsConnector::class);
        $connector->method('validateFieldMap')->willReturn(true);

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/sheets/connections', [
                'sheet_id'  => 'abc123',
                'field_map' => ['date' => 'A', 'payee' => 'B', 'amount' => 'C'],
                'direction' => 'import',
            ])
            ->assertStatus(422);
    }

    public function testStoreConnectionUpdatesPendingConnection(): void
    {
        // Create a pending connection (sheet_id = '')
        SheetConnection::create([
            'user_id'      => $this->user->id,
            'google_token' => 'fake-token',
            'sheet_id'     => '',
            'field_map'    => json_encode(['date' => 'A', 'payee' => 'B', 'amount' => 'C']),
            'direction'    => 'import',
            'is_active'    => true,
        ]);

        $connector = $this->createMock(GoogleSheetsConnector::class);
        $connector->method('validateFieldMap')->willReturn(true);

        $this->app->instance(GoogleSheetsConnector::class, $connector);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/sheets/connections', [
                'sheet_id'  => 'new-sheet-id',
                'field_map' => ['date' => 'A', 'payee' => 'B', 'amount' => 'C'],
                'direction' => 'export',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'sheet_id', 'direction', 'is_active']]);

        $this->assertDatabaseHas('extension_sheet_connections', [
            'user_id'  => $this->user->id,
            'sheet_id' => 'new-sheet-id',
            'direction' => 'export',
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /v1/ext/sheets/connections/{id}
    // -------------------------------------------------------------------------

    public function testDestroyConnectionRequiresAuthentication(): void
    {
        $this->deleteJson('/api/v1/ext/sheets/connections/1')
            ->assertStatus(401);
    }

    public function testDestroyConnectionReturns404ForUnknownId(): void
    {
        $this->actingAs($this->user, 'api')
            ->deleteJson('/api/v1/ext/sheets/connections/99999')
            ->assertStatus(404);
    }

    public function testDestroyConnectionDeactivatesAndDeletesConnection(): void
    {
        $connection = SheetConnection::create([
            'user_id'      => $this->user->id,
            'google_token' => 'fake-token',
            'sheet_id'     => 'spreadsheet-123',
            'field_map'    => json_encode(['date' => 'A', 'payee' => 'B', 'amount' => 'C']),
            'direction'    => 'import',
            'is_active'    => true,
        ]);

        $this->actingAs($this->user, 'api')
            ->deleteJson('/api/v1/ext/sheets/connections/' . $connection->id)
            ->assertStatus(204);

        $this->assertSoftDeleted('extension_sheet_connections', ['id' => $connection->id]);
    }

    public function testDestroyConnectionDoesNotAllowDeletingOtherUsersConnections(): void
    {
        $other      = UserGroup::create(['title' => 'destroy-other@example.com']);
        $otherUser  = User::create([
            'email'         => 'destroy-other@example.com',
            'password'      => bcrypt('password'),
            'user_group_id' => $other->id,
        ]);

        $connection = SheetConnection::create([
            'user_id'      => $otherUser->id,
            'google_token' => 'fake-token',
            'sheet_id'     => 'spreadsheet-123',
            'field_map'    => json_encode(['date' => 'A', 'payee' => 'B', 'amount' => 'C']),
            'direction'    => 'import',
            'is_active'    => true,
        ]);

        $this->actingAs($this->user, 'api')
            ->deleteJson('/api/v1/ext/sheets/connections/' . $connection->id)
            ->assertStatus(404);
    }
}
