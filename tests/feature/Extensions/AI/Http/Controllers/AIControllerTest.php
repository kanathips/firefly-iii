<?php

declare(strict_types=1);

namespace Tests\feature\Extensions\AI\Http\Controllers;

use FireflyIII\Extensions\AI\Http\Controllers\AIController;
use FireflyIII\Extensions\AI\Models\AISuggestion;
use FireflyIII\Extensions\AI\Repositories\AISettingsRepository;
use FireflyIII\Extensions\AI\Services\AIInsightService;
use FireflyIII\Extensions\AI\Services\NLTransactionParser;
use FireflyIII\Models\UserGroup;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\feature\TestCase;

/**
 * @internal
 */
#[CoversClass(AIController::class)]
final class AIControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $group      = UserGroup::create(['title' => 'ai-test@example.com']);
        $this->user = User::create([
            'email'         => 'ai-test@example.com',
            'password'      => bcrypt('password'),
            'user_group_id' => $group->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /api/v1/ext/ai/settings
    // -----------------------------------------------------------------------

    public function testGetSettingsReturnsNullWhenNotConfigured(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/ext/ai/settings');

        $response->assertStatus(200);
        $response->assertJsonPath('data', null);
    }

    public function testGetSettingsReturnsSettingsWhenConfigured(): void
    {
        \FireflyIII\Extensions\AI\Models\AISettings::create([
            'user_id'    => $this->user->id,
            'provider'   => 'openai',
            'api_key'    => Crypt::encryptString('sk-test-key-123456'),
            'is_enabled' => true,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/ext/ai/settings');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'type',
                'attributes' => [
                    'provider',
                    'is_enabled',
                    'created_at',
                    'updated_at',
                ],
            ],
        ]);
        $response->assertJsonPath('data.attributes.provider', 'openai');
        $response->assertJsonPath('data.attributes.is_enabled', true);

        // API key MUST NOT appear in response
        $this->assertArrayNotHasKey('api_key', $response->json('data.attributes'));
    }

    public function testGetSettingsRequiresAuthentication(): void
    {
        $response = $this->getJson('/api/v1/ext/ai/settings');
        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // POST /api/v1/ext/ai/settings
    // -----------------------------------------------------------------------

    public function testStoreSettingsCreatesSettingsAndReturns201(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/ai/settings', [
                'provider'   => 'openai',
                'api_key'    => 'sk-valid-test-key-xyz',
                'is_enabled' => true,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.attributes.provider', 'openai');
        $response->assertJsonPath('data.attributes.is_enabled', true);
        $this->assertArrayNotHasKey('api_key', $response->json('data.attributes'));
    }

    public function testStoreSettingsValidatesProvider(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/ai/settings', [
                'provider'   => 'invalid-provider',
                'api_key'    => 'sk-key-12345678',
                'is_enabled' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['provider']);
    }

    public function testStoreSettingsValidatesApiKeyRequired(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/ai/settings', [
                'provider'   => 'openai',
                'is_enabled' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['api_key']);
    }

    public function testStoreSettingsValidatesApiKeyMinLength(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/ai/settings', [
                'provider'   => 'openai',
                'api_key'    => 'short',
                'is_enabled' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['api_key']);
    }

    public function testStoreSettingsEncryptsApiKey(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/ai/settings', [
                'provider'   => 'anthropic',
                'api_key'    => 'sk-ant-very-secret-key',
                'is_enabled' => true,
            ]);

        $record = \FireflyIII\Extensions\AI\Models\AISettings::where('user_id', $this->user->id)->first();

        $this->assertNotNull($record);
        // Stored value must NOT be the plaintext key
        $this->assertNotSame('sk-ant-very-secret-key', $record->api_key);
        // But decrypting it must return the original key
        $this->assertSame('sk-ant-very-secret-key', Crypt::decryptString($record->api_key));
    }

    public function testStoreSettingsRequiresAuthentication(): void
    {
        $response = $this->postJson('/api/v1/ext/ai/settings', [
            'provider'   => 'openai',
            'api_key'    => 'sk-test-key-1234567',
            'is_enabled' => true,
        ]);

        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // GET /api/v1/ext/ai/insights
    // -----------------------------------------------------------------------

    public function testGetInsightsReturnsExpectedStructure(): void
    {
        $mockService = $this->createMock(AIInsightService::class);
        $mockService->expects($this->once())
            ->method('buildInsights')
            ->willReturn([
                'period_start'      => '2026-02-19',
                'period_end'        => '2026-03-20',
                'total_spent'       => 450.00,
                'top_categories'    => [
                    ['category' => 'Food', 'amount' => 200.00, 'percentage' => 44.4],
                ],
                'daily_average'     => 15.00,
                'transaction_count' => 30,
            ]);

        $this->app->instance(AIInsightService::class, $mockService);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/ext/ai/insights');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'type',
                'attributes' => [
                    'period_start',
                    'period_end',
                    'total_spent',
                    'top_categories',
                    'daily_average',
                    'transaction_count',
                ],
            ],
        ]);
    }

    public function testGetInsightsRequiresAuthentication(): void
    {
        $response = $this->getJson('/api/v1/ext/ai/insights');
        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // POST /api/v1/ext/ai/parse-nl
    // -----------------------------------------------------------------------

    public function testParseNLReturns422ForEmptyInput(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/ai/parse-nl', ['input' => '']);

        $response->assertStatus(422);
    }

    public function testParseNLReturns422ForInputExceedingMaxLength(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/ai/parse-nl', [
                'input' => str_repeat('a', 501),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['input']);
    }

    public function testParseNLReturns422WhenNoAIConfigured(): void
    {
        // No AI settings for this user
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/ai/parse-nl', [
                'input' => 'Coffee $5.50 at Starbucks',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'AI not configured or disabled');
    }

    public function testParseNLReturnsParsedResultOnSuccess(): void
    {
        $mockParser = $this->createMock(NLTransactionParser::class);
        $mockParser->expects($this->once())
            ->method('parse')
            ->willReturn([
                'amount'   => 5.50,
                'date'     => '2026-03-21',
                'payee'    => 'Starbucks',
                'category' => 'Food & Drink',
                'notes'    => 'morning coffee',
            ]);

        $this->app->instance(NLTransactionParser::class, $mockParser);

        // Rebind AIController with the mock parser
        $this->app->bind(AIController::class, function ($app) use ($mockParser) {
            return new AIController(
                $app->make(\FireflyIII\Extensions\AI\Repositories\AISettingsRepository::class),
                $app->make(\FireflyIII\Extensions\AI\Repositories\AISuggestionRepository::class),
                $app->make(AIInsightService::class),
                $mockParser,
                $app->make(\FireflyIII\Extensions\AI\Transformers\AISuggestionTransformer::class),
                $app->make(\FireflyIII\Extensions\AI\Transformers\AIInsightTransformer::class),
            );
        });

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/ai/parse-nl', [
                'input' => 'Coffee $5.50 at Starbucks',
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['amount', 'date', 'payee', 'category', 'notes'],
        ]);
        $response->assertJsonPath('data.payee', 'Starbucks');
    }

    public function testParseNLRequiresAuthentication(): void
    {
        $response = $this->postJson('/api/v1/ext/ai/parse-nl', ['input' => 'Coffee $5']);
        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // GET /api/v1/ext/ai/suggestions
    // -----------------------------------------------------------------------

    public function testGetSuggestionsReturnsOnlyPendingForCurrentUser(): void
    {
        AISuggestion::create([
            'user_id'                => $this->user->id,
            'transaction_journal_id' => null,
            'suggested_category'     => 'Transport',
            'confidence'             => 0.80,
            'status'                 => 'pending',
        ]);

        AISuggestion::create([
            'user_id'                => $this->user->id,
            'transaction_journal_id' => null,
            'suggested_category'     => 'Food',
            'confidence'             => 0.90,
            'status'                 => 'accepted',   // Not pending — must be excluded
        ]);

        $otherGroup = UserGroup::create(['title' => 'other-ai@example.com']);
        $otherUser  = User::create([
            'email'         => 'other-ai@example.com',
            'password'      => bcrypt('x'),
            'user_group_id' => $otherGroup->id,
        ]);
        AISuggestion::create([
            'user_id'                => $otherUser->id,
            'transaction_journal_id' => null,
            'suggested_category'     => 'Other User',
            'confidence'             => 0.70,
            'status'                 => 'pending',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/ext/ai/suggestions');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.attributes.suggested_category', 'Transport');
    }

    public function testGetSuggestionsRequiresAuthentication(): void
    {
        $response = $this->getJson('/api/v1/ext/ai/suggestions');
        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // PUT /api/v1/ext/ai/suggestions/{id}/accept
    // -----------------------------------------------------------------------

    public function testAcceptSuggestionUpdatesStatusToAccepted(): void
    {
        $suggestion = AISuggestion::create([
            'user_id'                => $this->user->id,
            'transaction_journal_id' => null,
            'suggested_category'     => 'Shopping',
            'confidence'             => 0.85,
            'status'                 => 'pending',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->putJson("/api/v1/ext/ai/suggestions/{$suggestion->id}/accept");

        $response->assertStatus(200);
        $response->assertJsonPath('data.attributes.status', 'accepted');
    }

    // -----------------------------------------------------------------------
    // PUT /api/v1/ext/ai/suggestions/{id}/reject
    // -----------------------------------------------------------------------

    public function testRejectSuggestionUpdatesStatusToRejected(): void
    {
        $suggestion = AISuggestion::create([
            'user_id'                => $this->user->id,
            'transaction_journal_id' => null,
            'suggested_category'     => 'Entertainment',
            'confidence'             => 0.65,
            'status'                 => 'pending',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->putJson("/api/v1/ext/ai/suggestions/{$suggestion->id}/reject");

        $response->assertStatus(200);
        $response->assertJsonPath('data.attributes.status', 'rejected');
    }
}
