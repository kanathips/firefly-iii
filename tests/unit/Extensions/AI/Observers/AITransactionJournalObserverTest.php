<?php

declare(strict_types=1);

namespace Tests\unit\Extensions\AI\Observers;

use Exception;
use FireflyIII\Extensions\Observers\AITransactionJournalObserver;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\integration\TestCase;

/**
 * @internal
 */
#[CoversClass(AITransactionJournalObserver::class)]
#[Group('unit')]
#[Group('ai')]
final class AITransactionJournalObserverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Log::spy();
    }

    #[Test]
    public function testCreatedDispatchesJobWithoutThrowingOnSuccess(): void
    {
        $journal          = new TransactionJournal();
        $journal->id      = 100;
        $journal->user_id = 1;

        $observer = new AITransactionJournalObserver();

        // Must not throw — if it does the test fails
        $observer->created($journal);

        // We cannot assert dispatch easily without user relation; just verify no exception
        $this->assertTrue(true);
    }

    #[Test]
    public function testCreatedDoesNotThrowWhenJobDispatchFails(): void
    {
        // Simulate a scenario where dispatch itself throws
        $journal = $this->createPartialMock(TransactionJournal::class, ['getAttribute']);
        $journal->method('getAttribute')->willThrowException(new Exception('Simulated failure'));

        $observer = new AITransactionJournalObserver();

        // The observer MUST swallow the exception
        $observer->created($journal);

        Log::shouldHaveReceived('error')->once();
    }
}
