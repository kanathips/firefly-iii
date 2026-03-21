<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Import\Providers;

use FireflyIII\Extensions\Import\Http\Controllers\StatementImportController;
use FireflyIII\Extensions\Import\Services\OCRExtractor;
use FireflyIII\Extensions\Import\Services\PDFStatementParser;
use Illuminate\Support\ServiceProvider;

class ImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PDFStatementParser::class, PDFStatementParser::class);
        $this->app->bind(OCRExtractor::class, OCRExtractor::class);

        $this->app->when(StatementImportController::class)
            ->needs(PDFStatementParser::class)
            ->give(PDFStatementParser::class);

        $this->app->when(StatementImportController::class)
            ->needs(OCRExtractor::class)
            ->give(OCRExtractor::class);
    }

    public function boot(): void
    {
        // Routes are registered in ExtensionsServiceProvider via extensions-api.php
    }
}
