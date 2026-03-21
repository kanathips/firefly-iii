<?php

declare(strict_types=1);

namespace Tests\feature\Extensions\Tax\Http\Controllers;

use FireflyIII\Extensions\Tax\Models\TaxProfile;
use FireflyIII\Extensions\Tax\Repositories\TaxRepositoryInterface;
use FireflyIII\Extensions\Tax\Services\TaxCalculationService;
use FireflyIII\Models\Tag;
use FireflyIII\Models\UserGroup;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\feature\TestCase;
use FireflyIII\Extensions\Tax\Http\Controllers\TaxController;

/**
 * @internal
 */
#[CoversClass(TaxController::class)]
final class TaxControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $group      = UserGroup::create(['title' => 'tax-test@example.com']);
        $this->user = User::create([
            'email'         => 'tax-test@example.com',
            'password'      => bcrypt('password'),
            'user_group_id' => $group->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /v1/ext/tax/profiles
    // -----------------------------------------------------------------------

    public function testStoreProfileCreatesProfileAndReturns201(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/tax/profiles', [
                'name'     => 'Freelance 2025',
                'tax_year' => 2025,
                'tax_rate' => 20.5,
                'notes'    => 'Self-employed income',
            ])
        ;

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'attributes' => [
                    'name',
                    'tax_year',
                    'tax_rate',
                    'notes',
                    'created_at',
                    'updated_at',
                ],
            ],
        ]);
        $response->assertJsonPath('data.attributes.name', 'Freelance 2025');
        $response->assertJsonPath('data.attributes.tax_year', 2025);
    }

    public function testStoreProfileValidatesRequiredFields(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/tax/profiles', [])
        ;

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'tax_year']);
    }

    public function testStoreProfileValidatesTaxYearRange(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/tax/profiles', [
                'name'     => 'Bad Year',
                'tax_year' => 1800,
            ])
        ;

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['tax_year']);
    }

    public function testStoreProfileValidatesTaxRateRange(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/ext/tax/profiles', [
                'name'     => 'Bad Rate',
                'tax_year' => 2025,
                'tax_rate' => 150.0,
            ])
        ;

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['tax_rate']);
    }

    public function testStoreProfileRequiresAuthentication(): void
    {
        $response = $this->postJson('/api/v1/ext/tax/profiles', [
            'name'     => 'No Auth',
            'tax_year' => 2025,
        ]);

        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // GET /v1/ext/tax/profiles
    // -----------------------------------------------------------------------

    public function testIndexProfilesListsOnlyUserProfiles(): void
    {
        TaxProfile::create(['user_id' => $this->user->id, 'name' => 'Profile A', 'tax_year' => 2025, 'tax_rate' => 10.0]);
        TaxProfile::create(['user_id' => $this->user->id, 'name' => 'Profile B', 'tax_year' => 2024, 'tax_rate' => 15.0]);

        $otherGroup = UserGroup::create(['title' => 'other@example.com']);
        $otherUser  = User::create(['email' => 'other@example.com', 'password' => bcrypt('x'), 'user_group_id' => $otherGroup->id]);
        TaxProfile::create(['user_id' => $otherUser->id, 'name' => 'Other Profile', 'tax_year' => 2025, 'tax_rate' => 5.0]);

        $response   = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/ext/tax/profiles')
        ;

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function testIndexProfilesRequiresAuthentication(): void
    {
        $response = $this->getJson('/api/v1/ext/tax/profiles');

        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // GET /v1/ext/tax/profiles/{profile}/summary
    // -----------------------------------------------------------------------

    public function testGetSummaryReturnsCorrectStructure(): void
    {
        $profile     = TaxProfile::create([
            'user_id'  => $this->user->id,
            'name'     => 'Summary Test',
            'tax_year' => 2025,
            'tax_rate' => 20.0,
        ]);

        $mockService = $this->createMock(TaxCalculationService::class);
        $mockService
            ->expects($this->once())
            ->method('buildSummary')
            ->willReturn([
                'profile_id'       => $profile->id,
                'start'            => '2025-01-01',
                'end'              => '2025-12-31',
                'total_deductible' => 500.00,
                'by_category'      => ['Medical' => 500.00],
                'by_period'        => ['2025-01' => ['total' => 500.00]],
            ])
        ;

        $this->app->instance(TaxCalculationService::class, $mockService);

        $response    = $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/ext/tax/profiles/{$profile->id}/summary?start=2025-01-01&end=2025-12-31")
        ;

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'profile_id',
                'start',
                'end',
                'total_deductible',
                'by_category',
                'by_period',
            ],
        ]);
    }

    public function testGetSummaryReturns404ForOtherUserProfile(): void
    {
        $otherGroup = UserGroup::create(['title' => 'other2@example.com']);
        $otherUser  = User::create(['email' => 'other2@example.com', 'password' => bcrypt('x'), 'user_group_id' => $otherGroup->id]);
        $profile    = TaxProfile::create([
            'user_id'  => $otherUser->id,
            'name'     => 'Not Mine',
            'tax_year' => 2025,
            'tax_rate' => 10.0,
        ]);

        $response   = $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/ext/tax/profiles/{$profile->id}/summary?start=2025-01-01&end=2025-12-31")
        ;

        $response->assertStatus(404);
    }

    public function testGetSummaryValidatesDateParams(): void
    {
        $profile  = TaxProfile::create([
            'user_id'  => $this->user->id,
            'name'     => 'Date Validation',
            'tax_year' => 2025,
            'tax_rate' => 20.0,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/ext/tax/profiles/{$profile->id}/summary")
        ;

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start', 'end']);
    }

    // -----------------------------------------------------------------------
    // GET /v1/ext/tax/profiles/{profile}/export
    // -----------------------------------------------------------------------

    public function testExportReturnsCsvResponse(): void
    {
        $profile  = TaxProfile::create([
            'user_id'  => $this->user->id,
            'name'     => 'Export Test',
            'tax_year' => 2025,
            'tax_rate' => 20.0,
        ]);

        $mockRepo = $this->createMock(TaxRepositoryInterface::class);
        $mockRepo->method('findProfile')->willReturn($profile);
        $mockRepo->method('getDeductibleJournals')->willReturn([
            ['amount' => '-100.00', 'category' => 'Medical', 'date' => '2025-03-01', 'description' => 'Doctor visit'],
        ]);
        $this->app->instance(TaxRepositoryInterface::class, $mockRepo);

        $response = $this->actingAs($this->user, 'api')
            ->get("/api/v1/ext/tax/profiles/{$profile->id}/export?start=2025-01-01&end=2025-12-31")
        ;

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type') ?? '');
    }

    public function testExportReturns404ForOtherUserProfile(): void
    {
        $otherGroup = UserGroup::create(['title' => 'other3@example.com']);
        $otherUser  = User::create(['email' => 'other3@example.com', 'password' => bcrypt('x'), 'user_group_id' => $otherGroup->id]);
        $profile    = TaxProfile::create([
            'user_id'  => $otherUser->id,
            'name'     => 'Not Mine Export',
            'tax_year' => 2025,
            'tax_rate' => 10.0,
        ]);

        $response   = $this->actingAs($this->user, 'api')
            ->get("/api/v1/ext/tax/profiles/{$profile->id}/export?start=2025-01-01&end=2025-12-31")
        ;

        $response->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // POST /v1/ext/tax/profiles/{profile}/tags
    // -----------------------------------------------------------------------

    public function testLinkTagToProfile(): void
    {
        $profile  = TaxProfile::create([
            'user_id'  => $this->user->id,
            'name'     => 'Tag Link Test',
            'tax_year' => 2025,
            'tax_rate' => 20.0,
        ]);
        $tag      = Tag::create([
            'user_id'       => $this->user->id,
            'user_group_id' => $this->user->user_group_id,
            'tag'           => 'medical-deductible',
            'tag_mode'      => 'nothing',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/v1/ext/tax/profiles/{$profile->id}/tags", [
                'tag_id' => $tag->id,
            ])
        ;

        $response->assertStatus(200);
        $response->assertJsonPath('data.tag_id', $tag->id);
        $response->assertJsonPath('data.tax_profile_id', $profile->id);
    }

    public function testLinkTagValidatesTagId(): void
    {
        $profile  = TaxProfile::create([
            'user_id'  => $this->user->id,
            'name'     => 'Validate Tag',
            'tax_year' => 2025,
            'tax_rate' => 20.0,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/v1/ext/tax/profiles/{$profile->id}/tags", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['tag_id']);
    }
}
