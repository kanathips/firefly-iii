<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ExtensionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(base_path('database/migrations/extensions'));

        $this->loadRoutesFrom(base_path('routes/extensions-api.php'));
    }

    public function register(): void
    {
        // Bindings registered here
    }
}
