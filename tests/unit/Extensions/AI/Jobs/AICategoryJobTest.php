<?php

declare(strict_types=1);

namespace Tests\unit\Extensions\AI\Jobs;

use FireflyIII\Extensions\AI\Exceptions\AIQuotaExceededException;
use FireflyIII\Extensions\AI\Exceptions\AIServiceException;
use FireflyIII\Extensions\AI\Jobs\AICategoryJob;
use FireflyIII\Extensions\AI\Repositories\AISuggestionRepository;
use FireflyIII\Extensions\AI\Services\AICategorizer;
use FireflyIII\Models\TransactionJournal;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\integration\TestCase;

/**
 * @internal
 */
#[CoversClass(AICategoryJob::class)]
#[Group('unit')]
#[Group('ai')]
final class AICategoryJobTest extends TestCase
{
    private MockInterface $categorizer;
    private MockInterface $suggestionRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categorizer    = Mockery::mock(AICategorizer::class);
        $this->suggestionRepo = Mockery::mock(AISuggestionRepository::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function testJobStoresSuggestionOnSuccessfulCategorization(): void
    {
        $journal              = new TransactionJournal();
        $journal->id          = 42;
        $journal->user_id     = 1;
        $journal->description = 'Coffee at Starbucks';

        $this->categorizer
            ->shouldReceive('categorize')
            ->once()
            ->with($journal)
            ->andReturn(['category' => 'Food & Drink', 'confidence' => 0.92]);

        $this->suggestionRepo
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(static function (array $data): bool {
                return 'Food & Drink' === $data['suggested_category']
                    && 0.92 === $data['confidence']
                    && 42 === $data['transaction_journal_id'];
            }));

        $job = new AICategoryJob($journal, $this->categorizer, $this->suggestionRepo);
        $job->handle();
    }

    #[Test]
    public function testJobDoesNotStoreSuggestionWhenCategorizerReturnsNull(): void
    {
        $journal          = new TransactionJournal();
        $journal->id      = 43;
        $journal->user_id = 1;

        $this->categorizer
            ->shouldReceive('categorize')
            ->once()
            ->andReturn(null);

        $this->suggestionRepo
            ->shouldReceive('create')
            ->never();

        $job = new AICategoryJob($journal, $this->categorizer, $this->suggestionRepo);
        $job->handle();
    }

    #[Test]
    public function testJobHandlesQuotaExceededGracefully(): void
    {
        $journal          = new TransactionJournal();
        $journal->id      = 44;
        $journal->user_id = 1;

        $this->categorizer
            ->shouldReceive('categorize')
            ->once()
            ->andThrow(new AIQuotaExceededException('Quota exceeded'));

        $this->suggestionRepo
            ->shouldReceive('create')
            ->never();

        // Job should complete without throwing — quota exceeded is graceful degradation
        $job = new AICategoryJob($journal, $this->categorizer, $this->suggestionRepo);
        $job->handle();

        // Test passes if no exception bubbles up
        $this->assertTrue(true);
    }

    #[Test]
    public function testJobHandlesGenericAIServiceExceptionGracefully(): void
    {
        $journal          = new TransactionJournal();
        $journal->id      = 45;
        $journal->user_id = 1;

        $this->categorizer
            ->shouldReceive('categorize')
            ->once()
            ->andThrow(new AIServiceException('API error'));

        $this->suggestionRepo
            ->shouldReceive('create')
            ->never();

        $job = new AICategoryJob($journal, $this->categorizer, $this->suggestionRepo);
        $job->handle();

        $this->assertTrue(true);
    }

    #[Test]
    public function testJobDoesNotStoreSuggestionWithZeroConfidence(): void
    {
        $journal          = new TransactionJournal();
        $journal->id      = 46;
        $journal->user_id = 1;

        $this->categorizer
            ->shouldReceive('categorize')
            ->once()
            ->andReturn(['category' => 'Unknown', 'confidence' => 0.0]);

        $this->suggestionRepo
            ->shouldReceive('create')
            ->never();

        $job = new AICategoryJob($journal, $this->categorizer, $this->suggestionRepo);
        $job->handle();
    }

    #[Test]
    public function testJobRequiresMinimumConfidenceThreshold(): void
    {
        $journal          = new TransactionJournal();
        $journal->id      = 47;
        $journal->user_id = 1;

        // Below minimum threshold (0.50)
        $this->categorizer
            ->shouldReceive('categorize')
            ->once()
            ->andReturn(['category' => 'Food', 'confidence' => 0.30]);

        $this->suggestionRepo
            ->shouldReceive('create')
            ->never();

        $job = new AICategoryJob($journal, $this->categorizer, $this->suggestionRepo);
        $job->handle();
    }
}
