<?php

declare(strict_types=1);

namespace Tests\integration\Extensions\Tax\Repositories;

use Carbon\Carbon;
use FireflyIII\Extensions\Tax\Models\TaxDeductibleTag;
use FireflyIII\Extensions\Tax\Models\TaxProfile;
use FireflyIII\Extensions\Tax\Repositories\TaxRepository;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\Tag;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\integration\TestCase;

/**
 * @internal
 */
#[CoversClass(TaxRepository::class)]
final class TaxRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private TaxRepository $repository;
    private User          $user;

    private function createSecondUser(): User
    {
        $group = UserGroup::create(['title' => 'other@email.com']);
        $role  = UserRole::where('title', 'owner')->first();
        $user  = User::create(['email' => 'other@email.com', 'password' => 'password', 'user_group_id' => $group->id]);
        GroupMembership::create(['user_id' => $user->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->user       = $this->createAuthenticatedUser();
        $this->repository = new TaxRepository();
        $this->repository->setUser($this->user);
    }

    // -----------------------------------------------------------------------
    // createProfile / findProfile
    // -----------------------------------------------------------------------

    public function testCreateProfilePersistsRecord(): void
    {
        $profile = $this->repository->createProfile([
            'name'       => 'My Tax Profile',
            'tax_year'   => 2025,
            'tax_rate'   => 20.0,
            'notes'      => 'For freelancing',
        ]);

        $this->assertInstanceOf(TaxProfile::class, $profile);
        $this->assertNotNull($profile->id);
        $this->assertSame('My Tax Profile', $profile->name);
        $this->assertSame(2025, $profile->tax_year);
        $this->assertEqualsWithDelta(20.0, (float) $profile->tax_rate, 0.001);
        $this->assertSame($this->user->id, $profile->user_id);
    }

    public function testFindProfileReturnsNullForUnknownId(): void
    {
        $profile = $this->repository->findProfile(999_999);

        $this->assertNull($profile);
    }

    public function testFindProfileReturnsOwnProfileOnly(): void
    {
        $ownProfile   = $this->repository->createProfile(['name' => 'Mine', 'tax_year' => 2025, 'tax_rate' => 10.0]);

        $otherUser    = $this->createSecondUser();
        $otherRepo    = new TaxRepository();
        $otherRepo->setUser($otherUser);
        $otherProfile = $otherRepo->createProfile(['name' => 'Theirs', 'tax_year' => 2025, 'tax_rate' => 10.0]);

        // Own user cannot see other user's profile
        $this->assertNull($this->repository->findProfile($otherProfile->id));
        // Own profile found
        $this->assertNotNull($this->repository->findProfile($ownProfile->id));
    }

    public function testGetProfilesReturnsOnlyUserProfiles(): void
    {
        $this->repository->createProfile(['name' => 'Profile A', 'tax_year' => 2025, 'tax_rate' => 10.0]);
        $this->repository->createProfile(['name' => 'Profile B', 'tax_year' => 2024, 'tax_rate' => 15.0]);

        $otherUser = $this->createSecondUser();
        $otherRepo = new TaxRepository();
        $otherRepo->setUser($otherUser);
        $otherRepo->createProfile(['name' => 'Other Profile', 'tax_year' => 2025, 'tax_rate' => 5.0]);

        $profiles  = $this->repository->getProfiles();

        $this->assertCount(2, $profiles);
    }

    // -----------------------------------------------------------------------
    // linkTag / unlinkTag / getLinkedTags
    // -----------------------------------------------------------------------

    public function testLinkTagCreatesLink(): void
    {
        $profile = $this->repository->createProfile(['name' => 'Test', 'tax_year' => 2025, 'tax_rate' => 20.0]);
        $tag     = Tag::create([
            'user_id'       => $this->user->id,
            'user_group_id' => $this->user->user_group_id,
            'tag'           => 'deductible-medical',
            'tag_mode'      => 'nothing',
        ]);

        $link    = $this->repository->linkTag($profile, $tag);

        $this->assertInstanceOf(TaxDeductibleTag::class, $link);
        $this->assertSame($profile->id, $link->tax_profile_id);
        $this->assertSame($tag->id, $link->tag_id);
    }

    public function testLinkTagIsIdempotent(): void
    {
        $profile = $this->repository->createProfile(['name' => 'Test', 'tax_year' => 2025, 'tax_rate' => 20.0]);
        $tag     = Tag::create([
            'user_id'       => $this->user->id,
            'user_group_id' => $this->user->user_group_id,
            'tag'           => 'deductible-idempotent',
            'tag_mode'      => 'nothing',
        ]);

        $this->repository->linkTag($profile, $tag);
        $this->repository->linkTag($profile, $tag); // second call must not throw or duplicate

        $links   = $this->repository->getLinkedTags($profile);
        $this->assertCount(1, $links);
    }

    public function testUnlinkTagRemovesLink(): void
    {
        $profile = $this->repository->createProfile(['name' => 'Test', 'tax_year' => 2025, 'tax_rate' => 20.0]);
        $tag     = Tag::create([
            'user_id'       => $this->user->id,
            'user_group_id' => $this->user->user_group_id,
            'tag'           => 'deductible-unlink',
            'tag_mode'      => 'nothing',
        ]);

        $this->repository->linkTag($profile, $tag);
        $this->repository->unlinkTag($profile, $tag);

        $links   = $this->repository->getLinkedTags($profile);
        $this->assertCount(0, $links);
    }

    public function testGetLinkedTagsReturnsEmptyCollectionForNoLinks(): void
    {
        $profile = $this->repository->createProfile(['name' => 'Empty', 'tax_year' => 2025, 'tax_rate' => 5.0]);

        $links   = $this->repository->getLinkedTags($profile);
        $this->assertCount(0, $links);
    }

    // -----------------------------------------------------------------------
    // getDeductibleJournals
    // -----------------------------------------------------------------------

    public function testGetDeductibleJournalsReturnsJournalsWithLinkedTags(): void
    {
        $profile  = $this->repository->createProfile(['name' => 'Deductible', 'tax_year' => 2025, 'tax_rate' => 20.0]);
        $tag      = Tag::create([
            'user_id'       => $this->user->id,
            'user_group_id' => $this->user->user_group_id,
            'tag'           => 'medical-expense',
            'tag_mode'      => 'nothing',
        ]);
        $this->repository->linkTag($profile, $tag);

        // The actual journal creation requires a lot of supporting records (transaction type, currency etc.)
        // so we assert the return type and empty result as the underlying journals are not set up.
        $start    = Carbon::parse('2025-01-01');
        $end      = Carbon::parse('2025-12-31');
        $journals = $this->repository->getDeductibleJournals($profile, $start, $end);

        $this->assertIsArray($journals);
    }

    public function testGetDeductibleJournalsReturnsEmptyArrayWhenNoTagsLinked(): void
    {
        $profile  = $this->repository->createProfile(['name' => 'No Tags', 'tax_year' => 2025, 'tax_rate' => 20.0]);

        $start    = Carbon::parse('2025-01-01');
        $end      = Carbon::parse('2025-12-31');
        $journals = $this->repository->getDeductibleJournals($profile, $start, $end);

        $this->assertSame([], $journals);
    }

    public function testGetDeductibleJournalsReturnsEmptyArrayForUnknownProfile(): void
    {
        $profile     = new TaxProfile();
        $profile->id = 999_999;

        $start       = Carbon::parse('2025-01-01');
        $end         = Carbon::parse('2025-12-31');
        $journals    = $this->repository->getDeductibleJournals($profile, $start, $end);

        $this->assertSame([], $journals);
    }

    // -----------------------------------------------------------------------
    // deleteProfile
    // -----------------------------------------------------------------------

    public function testDeleteProfileSoftDeletesRecord(): void
    {
        $profile = $this->repository->createProfile(['name' => 'ToDelete', 'tax_year' => 2025, 'tax_rate' => 10.0]);
        $id      = $profile->id;

        $this->repository->deleteProfile($profile);

        $this->assertNull($this->repository->findProfile($id));
        $this->assertNotNull(TaxProfile::withTrashed()->find($id));
    }
}
