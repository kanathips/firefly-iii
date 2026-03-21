<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Providers;

use FireflyIII\Extensions\AI\Http\Controllers\AIController;
use FireflyIII\Extensions\AI\Repositories\AISettingsRepository;
use FireflyIII\Extensions\AI\Repositories\AISuggestionRepository;
use FireflyIII\Extensions\AI\Services\AIInsightService;
use FireflyIII\Extensions\AI\Services\AICategorizer;
use FireflyIII\Extensions\AI\Services\NLTransactionParser;
use FireflyIII\Extensions\AI\Transformers\AIInsightTransformer;
use FireflyIII\Extensions\AI\Transformers\AISuggestionTransformer;
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
        // Repositories
        $this->app->bind(AISettingsRepository::class, AISettingsRepository::class);
        $this->app->bind(AISuggestionRepository::class, AISuggestionRepository::class);

        // Services
        $this->app->bind(AICategorizer::class, static function ($app): AICategorizer {
            return new AICategorizer($app->make(AISettingsRepository::class));
        });

        $this->app->bind(NLTransactionParser::class, static function ($app): NLTransactionParser {
            return new NLTransactionParser($app->make(AISettingsRepository::class));
        });

        $this->app->bind(AIInsightService::class, AIInsightService::class);

        // Transformers
        $this->app->bind(AISuggestionTransformer::class, AISuggestionTransformer::class);
        $this->app->bind(AIInsightTransformer::class, AIInsightTransformer::class);

        // Controller
        $this->app->bind(AIController::class, static function ($app): AIController {
            return new AIController(
                $app->make(AISettingsRepository::class),
                $app->make(AISuggestionRepository::class),
                $app->make(AIInsightService::class),
                $app->make(NLTransactionParser::class),
                $app->make(AISuggestionTransformer::class),
                $app->make(AIInsightTransformer::class),
            );
        });
    }
}
