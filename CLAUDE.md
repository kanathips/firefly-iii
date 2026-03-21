# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Firefly III is a self-hosted personal finance manager built on **Laravel 12 / PHP 8.5+** using double-entry bookkeeping. It exposes both a web UI (Twig/Blade templates) and a RESTful JSON API (Laravel Passport OAuth 2.0).

## Commands

### PHP / Laravel

```bash
# Install dependencies
composer install

# Run all migrations
php artisan migrate

# Run tests
composer run-script unit-test          # Unit tests only (no coverage)
composer run-script integration-test   # Integration tests only (no coverage)
composer run-script coverage           # All suites with coverage report

# Run a specific test file or filter
./vendor/bin/phpunit -c phpunit.xml --testsuite=unit --filter=MyTestClass
./vendor/bin/phpunit -c phpunit.xml --testsuite=integration --filter=testMethodName

# Static analysis
./vendor/bin/phpstan analyse

# Automated refactoring
./vendor/bin/rector process

# Code formatting / linting (Mago)
mago format
mago lint
```

### Frontend

The project has two frontend versions under `resources/assets/v1` and `resources/assets/v2`, managed as pnpm workspaces.

```bash
pnpm install        # Install all workspace dependencies
pnpm -w build       # Build all workspaces
```

## Architecture

### Layer Overview

```
routes/          → api.php (REST API), web.php (UI), breadcrumbs.php
app/Http/        → Controllers + Middleware + Form Requests
app/Repositories/→ Data access layer (interface + implementation pattern)
app/Services/    → Business logic (Internal/, FireflyIIIOrg/, Webhook/, Password/)
app/Models/      → Eloquent models (~30 models, many with soft deletes + observers)
app/Transformers/→ Fractal transformers for API responses
app/Enums/       → PHP 8.1+ enums (AccountTypeEnum, TransactionTypeEnum, etc.)
app/Events/ + app/Handlers/ → Event-driven side effects (observers, listeners)
app/Jobs/        → Queued jobs
app/TransactionRules/ → Rule engine: triggers + actions for auto-categorisation
```

### Key Architectural Patterns

**Repository pattern**: Controllers and services depend on repository interfaces (bound in service providers), never directly on Eloquent models. When adding new data access logic, add a method to the interface and implement it in the concrete repository.

**Double-entry bookkeeping**: A `TransactionJournal` is the top-level record for any financial event. Each journal has two or more `Transaction` rows (debit + credit sides). `Account` records hold balances. Never manipulate `Transaction` rows without going through the journal layer.

**Transaction Rule Engine**: `app/TransactionRules/` contains `Triggers/` (conditions that match journals) and `Actions/` (mutations applied when triggers fire). New rules follow this two-class pattern.

**API Transformers**: All API output goes through Fractal transformer classes in `app/Transformers/`. Controllers should not build API response arrays manually.

**Enums for types**: Account types, transaction types, user roles, and similar discriminators are PHP enums in `app/Enums/`. Use these instead of raw strings.

### Testing

- Tests live in `tests/unit/`, `tests/integration/`, `tests/feature/`
- Test environment uses SQLite (configured in `.env.testing` and `phpunit.xml`)
- Factories are in `database/factories/`; use them for test data, not raw `DB::` inserts
- PHPUnit strict mode is enabled — avoid output in tests, avoid risky tests

### Configuration

- `config/firefly.php` — the main custom config file; feature flags, supported currencies, account type lists, and more
- `config/search.php` — search engine operator definitions
- Environment variables follow standard Laravel `.env` conventions; see `.env.example`

### Code Style

- Line width: 160 characters (enforced by Mago formatter)
- Tab width: 4 spaces
- PHP 8.5 syntax is expected (readonly properties, enums, named arguments, etc.)
- PHPStan level configured via `phpstan.neon` / `phpstan.neon.dist`
