<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Jobs;

use FireflyIII\Extensions\AI\Exceptions\AIQuotaExceededException;
use FireflyIII\Extensions\AI\Exceptions\AIServiceException;
use FireflyIII\Extensions\AI\Repositories\AISuggestionRepository;
use FireflyIII\Extensions\AI\Services\AICategorizer;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async job that calls the AI categoriser for a given journal and persists the
 * resulting suggestion.
 *
 * All exceptions from the AI provider are caught here so that a categorisation
 * failure never bubbles up and prevents a transaction from being recorded.
 *
 * A suggestion is only stored when:
 *  - The categoriser returns a non-null result
 *  - The confidence score is at or above the minimum threshold (0.50)
 */
class AICategoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const MINIMUM_CONFIDENCE = 0.50;

    public function __construct(
        private readonly TransactionJournal $journal,
        private readonly AICategorizer $categorizer,
        private readonly AISuggestionRepository $suggestionRepository
    ) {}

    public function handle(): void
    {
        try {
            // Ensure the settings repository is scoped to the journal's user
            // before categorisation so the correct API key is retrieved.
            $result = $this->categorizer->categorize($this->journal);
        } catch (AIQuotaExceededException $e) {
            Log::warning('[AI] Quota exceeded — skipping categorisation', [
                'journal_id' => $this->journal->id,
                'message'    => $e->getMessage(),
            ]);

            return;
        } catch (AIServiceException $e) {
            Log::error('[AI] Categorisation failed', [
                'journal_id' => $this->journal->id,
                'message'    => $e->getMessage(),
            ]);

            return;
        } catch (\Exception $e) {
            Log::error('[AI] Unexpected error during categorisation', [
                'journal_id' => $this->journal->id,
                'message'    => $e->getMessage(),
            ]);

            return;
        }

        if (null === $result) {
            return;
        }

        if ($result['confidence'] < self::MINIMUM_CONFIDENCE) {
            Log::debug(sprintf(
                '[AI] Confidence %.2f below threshold %.2f — suggestion discarded',
                $result['confidence'],
                self::MINIMUM_CONFIDENCE
            ));

            return;
        }

        $this->suggestionRepository->create([
            'user_id'                => $this->journal->user_id,
            'transaction_journal_id' => $this->journal->id,
            'suggested_category'     => $result['category'],
            'confidence'             => $result['confidence'],
            'status'                 => 'pending',
        ]);
    }
}
