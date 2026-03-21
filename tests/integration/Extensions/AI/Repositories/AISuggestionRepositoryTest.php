<?php

declare(strict_types=1);

namespace Tests\integration\Extensions\AI\Repositories;

use FireflyIII\Extensions\AI\Models\AISuggestion;
use FireflyIII\Extensions\AI\Repositories\AISuggestionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\integration\TestCase;

/**
 * @internal
 */
#[CoversClass(AISuggestionRepository::class)]
#[Group('integration')]
#[Group('ai')]
final class AISuggestionRepositoryTest extends TestCase
{
    private AISuggestionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new AISuggestionRepository();
    }

    #[Test]
    public function testCreatesNewSuggestion(): void
    {
        $user = $this->createAuthenticatedUser();

        $suggestion = $this->repo->create([
            'user_id'                  => $user->id,
            'transaction_journal_id'   => null,
            'suggested_category'       => 'Food & Drink',
            'confidence'               => 0.88,
            'status'                   => 'pending',
        ]);

        $this->assertInstanceOf(AISuggestion::class, $suggestion);
        $this->assertSame('Food & Drink', $suggestion->suggested_category);
        $this->assertSame(0.88, (float) $suggestion->confidence);
        $this->assertSame('pending', $suggestion->status);
    }

    #[Test]
    public function testFindsPendingSuggestionsForUser(): void
    {
        $user = $this->createAuthenticatedUser();

        $this->repo->create([
            'user_id'                  => $user->id,
            'transaction_journal_id'   => null,
            'suggested_category'       => 'Transport',
            'confidence'               => 0.75,
            'status'                   => 'pending',
        ]);

        $this->repo->create([
            'user_id'                  => $user->id,
            'transaction_journal_id'   => null,
            'suggested_category'       => 'Food',
            'confidence'               => 0.90,
            'status'                   => 'accepted',
        ]);

        $pending = $this->repo->getPendingForUser($user->id);

        $this->assertCount(1, $pending);
        $this->assertSame('Transport', $pending->first()->suggested_category);
    }

    #[Test]
    public function testMarksSuggestionAsAccepted(): void
    {
        $user = $this->createAuthenticatedUser();

        $suggestion = $this->repo->create([
            'user_id'                  => $user->id,
            'transaction_journal_id'   => null,
            'suggested_category'       => 'Shopping',
            'confidence'               => 0.80,
            'status'                   => 'pending',
        ]);

        $updated = $this->repo->markAccepted($suggestion->id);

        $this->assertSame('accepted', $updated->status);
    }

    #[Test]
    public function testMarksSuggestionAsRejected(): void
    {
        $user = $this->createAuthenticatedUser();

        $suggestion = $this->repo->create([
            'user_id'                  => $user->id,
            'transaction_journal_id'   => null,
            'suggested_category'       => 'Entertainment',
            'confidence'               => 0.65,
            'status'                   => 'pending',
        ]);

        $updated = $this->repo->markRejected($suggestion->id);

        $this->assertSame('rejected', $updated->status);
    }

    #[Test]
    public function testReturnsPendingCountForUser(): void
    {
        $user = $this->createAuthenticatedUser();

        $this->repo->create([
            'user_id'                  => $user->id,
            'transaction_journal_id'   => null,
            'suggested_category'       => 'Transport',
            'confidence'               => 0.75,
            'status'                   => 'pending',
        ]);

        $this->repo->create([
            'user_id'                  => $user->id,
            'transaction_journal_id'   => null,
            'suggested_category'       => 'Food',
            'confidence'               => 0.80,
            'status'                   => 'pending',
        ]);

        $count = $this->repo->countPendingForUser($user->id);

        $this->assertSame(2, $count);
    }

    #[Test]
    public function testReturnsEmptyCollectionWhenNoPendingSuggestions(): void
    {
        $user    = $this->createAuthenticatedUser();
        $pending = $this->repo->getPendingForUser($user->id);

        $this->assertCount(0, $pending);
    }

    #[Test]
    public function testSoftDeletesSuggestion(): void
    {
        $user = $this->createAuthenticatedUser();

        $suggestion = $this->repo->create([
            'user_id'                  => $user->id,
            'transaction_journal_id'   => null,
            'suggested_category'       => 'Test',
            'confidence'               => 0.70,
            'status'                   => 'pending',
        ]);

        $this->repo->delete($suggestion->id);

        $this->assertNull(AISuggestion::find($suggestion->id));
        $this->assertNotNull(AISuggestion::withTrashed()->find($suggestion->id));
    }

    #[Test]
    public function testIsolatesSuggestionsBetweenUsers(): void
    {
        $userA = $this->createAuthenticatedUser();
        // Creating second user inline since createAuthenticatedUser() always uses same email
        $userB = \FireflyIII\User::create([
            'email'         => 'userb@test.com',
            'password'      => 'password',
            'user_group_id' => $userA->user_group_id,
        ]);

        $this->repo->create([
            'user_id'                  => $userA->id,
            'transaction_journal_id'   => null,
            'suggested_category'       => 'UserA Category',
            'confidence'               => 0.75,
            'status'                   => 'pending',
        ]);

        $pendingForB = $this->repo->getPendingForUser($userB->id);

        $this->assertCount(0, $pendingForB);
    }
}
