<?php

declare(strict_types=1);

use FireflyIII\Extensions\AI\Http\Controllers\AIController;
use FireflyIII\Extensions\GoogleSheets\Http\Controllers\GoogleSheetsController;
use FireflyIII\Extensions\Import\Http\Controllers\StatementImportController;
use FireflyIII\Extensions\Investment\Http\Controllers\InvestmentController;
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
        Route::get('health', static fn () => response()->json(['data' => 'ok']));

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
            Route::post('profiles/{profile}/tags', [TaxController::class, 'linkTag'])
                ->name('extensions.tax.profiles.tags.link');
        });

        // ---- Investment module ------------------------------------------
        Route::prefix('investments')->group(static function (): void {
            Route::get('summary', [InvestmentController::class, 'summary'])
                ->name('extensions.investments.summary');
            Route::get('', [InvestmentController::class, 'index'])
                ->name('extensions.investments.index');
            Route::post('', [InvestmentController::class, 'store'])
                ->name('extensions.investments.store');
            Route::get('{id}', [InvestmentController::class, 'show'])
                ->name('extensions.investments.show')
                ->whereNumber('id');
            Route::put('{id}', [InvestmentController::class, 'update'])
                ->name('extensions.investments.update')
                ->whereNumber('id');
            Route::delete('{id}', [InvestmentController::class, 'destroy'])
                ->name('extensions.investments.destroy')
                ->whereNumber('id');
            Route::post('{id}/snapshots', [InvestmentController::class, 'storeSnapshot'])
                ->name('extensions.investments.snapshots.store')
                ->whereNumber('id');
        });

        // ---- AI module --------------------------------------------------
        Route::prefix('ai')->group(static function (): void {
            // Settings
            Route::get('settings', [AIController::class, 'getSettings'])
                ->name('extensions.ai.settings.get');
            Route::post('settings', [AIController::class, 'storeSettings'])
                ->name('extensions.ai.settings.store');

            // Spending insights
            Route::get('insights', [AIController::class, 'getInsights'])
                ->name('extensions.ai.insights');

            // Natural-language transaction parser (rate-limited: 10/min per user)
            Route::post('parse-nl', [AIController::class, 'parseNL'])
                ->name('extensions.ai.parse-nl')
                ->middleware('throttle:10,1');

            // Suggestions
            Route::get('suggestions', [AIController::class, 'getSuggestions'])
                ->name('extensions.ai.suggestions.index');
            Route::put('suggestions/{id}/accept', [AIController::class, 'acceptSuggestion'])
                ->name('extensions.ai.suggestions.accept')
                ->whereNumber('id');
            Route::put('suggestions/{id}/reject', [AIController::class, 'rejectSuggestion'])
                ->name('extensions.ai.suggestions.reject')
                ->whereNumber('id');
        });

        // ---- Google Sheets module --------------------------------------
        Route::prefix('sheets')->group(static function (): void {
            // OAuth2 flow
            Route::get('auth/url', [GoogleSheetsController::class, 'authUrl'])
                ->name('extensions.sheets.auth.url');
            Route::get('auth/callback', [GoogleSheetsController::class, 'authCallback'])
                ->name('extensions.sheets.auth.callback');

            // Connection management
            Route::get('connections', [GoogleSheetsController::class, 'listConnections'])
                ->name('extensions.sheets.connections.index');
            Route::post('connections', [GoogleSheetsController::class, 'storeConnection'])
                ->name('extensions.sheets.connections.store');
            Route::delete('connections/{id}', [GoogleSheetsController::class, 'destroyConnection'])
                ->name('extensions.sheets.connections.destroy')
                ->whereNumber('id');
        });

        // ---- PDF Import module -----------------------------------------
        Route::prefix('import/pdf')->group(static function (): void {
            Route::post('parse', [StatementImportController::class, 'parse'])
                ->name('extensions.import.pdf.parse');
            Route::get('preview/{previewId}', [StatementImportController::class, 'preview'])
                ->name('extensions.import.pdf.preview');
            Route::post('confirm', [StatementImportController::class, 'confirm'])
                ->name('extensions.import.pdf.confirm');
        });

        // Push notification routes will be registered here
        // Route::prefix('push')->group(...)
    });
