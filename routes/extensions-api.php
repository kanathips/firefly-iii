<?php

declare(strict_types=1);

use FireflyIII\Extensions\Tax\Http\Controllers\TaxController;
use Illuminate\Support\Facades\Route;

// --- Unauthenticated routes (no auth:api middleware)
Route::middleware('api_basic')
    ->prefix('api/v1/ext')
    ->group(static function (): void {
        // OAuth callback must be reachable without a pre-existing token
        // Route::get('oauth/callback', [OAuthCallbackController::class, 'handle'])
        //     ->name('extensions.oauth.callback');
    });

// --- Authenticated routes (full api group including auth:api)
Route::middleware('api')
    ->prefix('api/v1/ext')
    ->group(static function (): void {
        Route::get('health', static fn() => response()->json(['data' => 'ok']));

        // ---- Tax module -------------------------------------------------
        Route::prefix('tax')->group(static function (): void {
            // Profile CRUD
            Route::post('profiles', [TaxController::class, 'store'])
                ->name('extensions.tax.profiles.store');
            Route::get('profiles', [TaxController::class, 'index'])
                ->name('extensions.tax.profiles.index');

            // Per-profile operations
            Route::get('profiles/{profile}/summary', [TaxController::class, 'summary'])
                ->name('extensions.tax.profiles.summary');
            Route::get('profiles/{profile}/export', [TaxController::class, 'export'])
                ->name('extensions.tax.profiles.export');
            Route::get('profiles/{profile}/tags', [TaxController::class, 'getTags'])
                ->name('extensions.tax.profiles.tags.index');
            Route::post('profiles/{profile}/tags', [TaxController::class, 'linkTag'])
                ->name('extensions.tax.profiles.tags.link');
            Route::delete('profiles/{profile}/tags/{tag}', [TaxController::class, 'unlinkTag'])
                ->name('extensions.tax.profiles.tags.unlink');
            Route::delete('profiles/{profile}', [TaxController::class, 'destroy'])
                ->name('extensions.tax.profiles.destroy');
        });
    });
