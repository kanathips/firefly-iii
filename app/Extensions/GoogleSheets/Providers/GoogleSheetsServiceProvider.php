<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\GoogleSheets\Providers;

use FireflyIII\Extensions\GoogleSheets\Http\Controllers\GoogleSheetsController;
use FireflyIII\Extensions\GoogleSheets\Services\GoogleSheetsConnector;
use Illuminate\Support\ServiceProvider;

class GoogleSheetsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GoogleSheetsConnector::class, static function (): GoogleSheetsConnector {
            return new GoogleSheetsConnector(
                clientId:     (string) config('services.google.client_id', ''),
                clientSecret: (string) config('services.google.client_secret', ''),
                redirectUri:  (string) config('services.google.redirect_uri', ''),
            );
        });

        $this->app->when(GoogleSheetsController::class)
            ->needs(GoogleSheetsConnector::class)
            ->give(GoogleSheetsConnector::class);
    }

    public function boot(): void
    {
        // Routes registered in ExtensionsServiceProvider via extensions-api.php
    }
}
