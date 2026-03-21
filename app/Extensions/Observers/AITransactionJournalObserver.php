<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Observers;

use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\Log;

class AITransactionJournalObserver
{
    public function created(TransactionJournal $journal): void
    {
        Log::debug(sprintf('[AI] TransactionJournal created: %d', $journal->id));
        // AI categorization job will be dispatched here
    }
}
