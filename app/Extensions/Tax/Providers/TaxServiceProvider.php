<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Tax\Providers;

use FireflyIII\Extensions\Tax\Repositories\TaxRepository;
use FireflyIII\Extensions\Tax\Repositories\TaxRepositoryInterface;
use FireflyIII\Extensions\Tax\Services\TaxCalculationService;
use FireflyIII\Extensions\Tax\Transformers\TaxSummaryTransformer;
use Illuminate\Support\ServiceProvider;

/**
 * Registers all Tax extension bindings into the service container.
 */
class TaxServiceProvider extends ServiceProvider
{
    public function boot(): void {}

    public function register(): void
    {
        // Bind the repository interface to the concrete implementation.
        // When a user is authenticated the repository is pre-configured with
        // the current user, mirroring the pattern used by core providers like
        // TagServiceProvider.
        $this->app->bind(TaxRepositoryInterface::class, static function (Application $app): TaxRepositoryInterface {
            /** @var TaxRepository $repository */
            return $app->make(TaxRepository::class);
        });

        // Bind the calculation service (constructor-injected with the interface).
        $this->app->bind(TaxCalculationService::class, static function (Application $app): TaxCalculationService {
            return new TaxCalculationService($app->make(TaxRepositoryInterface::class));
        });

        // Bind the transformer (stateless, no dependencies).
        $this->app->bind(TaxSummaryTransformer::class, TaxSummaryTransformer::class);
    }
}
