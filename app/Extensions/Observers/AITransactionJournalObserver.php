<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Observers;

use Exception;
use FireflyIII\Extensions\AI\Jobs\AICategoryJob;
use FireflyIII\Extensions\AI\Repositories\AISuggestionRepository;
use FireflyIII\Extensions\AI\Repositories\AISettingsRepository;
use FireflyIII\Extensions\AI\Services\AICategorizer;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\Log;

/**
 * Listens for TransactionJournal creation events and dispatches the async
 * AI categorisation job.
 *
 * The observer MUST NOT throw — any exception is swallowed here so that the
 * parent transaction is never blocked by an AI failure.
 */
class AITransactionJournalObserver
{
    public function created(TransactionJournal $journal): void
    {
        try {
            $settingsRepo  = new AISettingsRepository();
            $suggestionRepo = new AISuggestionRepository();
            $categorizer   = new AICategorizer($settingsRepo);

            // Set the user context so the repository can scope queries
            $settingsRepo->setUser($journal->user);

            AICategoryJob::dispatch($journal, $categorizer, $suggestionRepo);

            Log::debug(sprintf('[AI] AICategoryJob dispatched for journal #%d', $journal->id));
        } catch (Exception $e) {
            // Log the failure but never re-throw — transaction must succeed regardless.
            Log::error('[AI] Failed to dispatch AICategoryJob', [
                'journal_id' => $journal->id,
                'message'    => $e->getMessage(),
            ]);
        }
    }
}
