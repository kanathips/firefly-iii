<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Providers;

use FireflyIII\Extensions\Observers\AITransactionJournalObserver;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\ServiceProvider;

class AIServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        TransactionJournal::observe(AITransactionJournalObserver::class);
    }

    public function register(): void
    {
        // AI service bindings
    }
}
