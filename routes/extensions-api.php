<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// --- Unauthenticated routes (no auth:api middleware)
Route::middleware('api_basic')
    ->prefix('v1/ext')
    ->group(static function (): void {
        // OAuth callback must be reachable without a pre-existing token
        // Route::get('oauth/callback', [OAuthCallbackController::class, 'handle'])
        //     ->name('extensions.oauth.callback');
    });

// --- Authenticated routes (full api group including auth:api)
Route::middleware('api')
    ->prefix('v1/ext')
    ->group(static function (): void {
        Route::get('health', static fn () => response()->json(['data' => 'ok']));

        // Tax routes will be registered here
        // Route::prefix('tax')->group(...)

        // Investment routes will be registered here
        // Route::prefix('investments')->group(...)

        // AI routes will be registered here
        // Route::prefix('ai')->group(...)

        // Google Sheets routes will be registered here
        // Route::prefix('sheets')->group(...)

        // PDF import routes will be registered here
        // Route::prefix('import/pdf')->group(...)

        // Push notification routes will be registered here
        // Route::prefix('push')->group(...)
    });
