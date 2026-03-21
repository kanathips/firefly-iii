<?php

declare(strict_types=1);

use FireflyIII\Extensions\Tax\Http\Controllers\TaxWebController;
use Illuminate\Support\Facades\Route;

Route::middleware('user-full-auth')
    ->prefix('ext/tax')
    ->as('ext.tax.')
    ->group(static function (): void {
        Route::get('/', [TaxWebController::class, 'index'])->name('index');
        Route::get('/{id}', [TaxWebController::class, 'show'])->name('show');
    });
