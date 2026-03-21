<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Investment\Providers;

use FireflyIII\Extensions\Investment\Http\Controllers\InvestmentController;
use FireflyIII\Extensions\Investment\Repositories\InvestmentRepository;
use FireflyIII\Extensions\Investment\Repositories\InvestmentRepositoryInterface;
use FireflyIII\Extensions\Investment\Services\InvestmentService;
use FireflyIII\Extensions\Investment\Transformers\InvestmentPositionTransformer;
use FireflyIII\Extensions\Investment\Transformers\PortfolioSummaryTransformer;
use Illuminate\Support\ServiceProvider;

class InvestmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InvestmentRepositoryInterface::class, InvestmentRepository::class);

        $this->app->bind(InvestmentService::class, static function ($app) {
            return new InvestmentService($app->make(InvestmentRepositoryInterface::class));
        });

        $this->app->bind(InvestmentController::class, static function ($app) {
            return new InvestmentController(
                $app->make(InvestmentRepositoryInterface::class),
                $app->make(InvestmentService::class),
                new InvestmentPositionTransformer(),
                new PortfolioSummaryTransformer(),
            );
        });
    }

    public function boot(): void
    {
        // No boot-time side effects needed
    }
}
