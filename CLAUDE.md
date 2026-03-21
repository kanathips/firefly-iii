# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

### PHP / Backend
```bash
composer install                                              # Install PHP dependencies
cp .env.example .env && php artisan key:generate             # First-time setup
php artisan serve                                             # Dev server
```

### Frontend
```bash
npm install                             # Install all frontend deps (v1 + v2 workspaces)

# V2 (Vite + Alpine.js + Bootstrap 5) — the active frontend
cd resources/assets/v2
npm run build                           # Production build
npm run dev                             # Dev server with HMR

# V1 (Legacy Laravel Mix + Vue 2)
cd resources/assets/v1
npm run production                      # Production build
npm run watch                           # Watch mode
```

### Tests
```bash
composer unit-test                                                         # Unit tests only
composer integration-test                                                  # Integration tests only
php vendor/bin/phpunit --testsuite feature --no-coverage                   # Feature tests
php vendor/bin/phpunit tests/path/to/SomeTest.php                          # Single test file
```
Tests use an in-memory SQLite database (configured in `phpunit.xml`).

### Static Analysis & Code Quality
```bash
bash .ci/all.sh               # Run all checks (PHP-CS-Fixer + PHPStan + PHPMD)
bash .ci/phpcs.sh             # Code style (PHP-CS-Fixer)
bash .ci/phpstan.sh           # Static analysis (PHPStan)
bash .ci/phpmd.sh             # Mess detector
bash .ci/rector.sh --dry-run  # Preview refactoring suggestions
```

## Architecture

Firefly III is a **Laravel 12** application using double-entry bookkeeping for personal finance tracking. It exposes a REST API and two parallel web frontends.

### Key Patterns

- **Repository Pattern** — Every major domain (Account, Budget, Bill, Transaction, etc.) has an interface + implementation under `app/Repositories/`. Interfaces are bound in dedicated service providers under `app/Providers/`.
- **Transformers** — API responses are formatted via League Fractal transformers in `app/Transformers/`.
- **Rule Engine** — `app/TransactionRules/` uses Symfony's ExpressionLanguage for flexible automated transaction matching.
- **Event-Driven** — Domain events in `app/Events/` with listeners in `app/Listeners/`; async work (webhooks, recurring transactions) runs via queued jobs in `app/Jobs/`.

### Directory Map

| Path | Purpose |
|------|---------|
| `app/Models/` | Eloquent models (Account, Transaction, Budget, Bill, Category, PiggyBank, Rule, Recurrence, Webhook, …) |
| `app/Repositories/` | Data access layer, one folder per domain |
| `app/Api/V1/` | REST API controllers, middleware, request validators |
| `app/Http/Controllers/` | Web (non-API) controllers |
| `app/Services/` | Business logic (Internal, Webhook, Password sub-folders) |
| `app/Transformers/` | Fractal transformers for API output |
| `app/Support/` | Shared helpers (Amount, Navigation, Preferences, Steam, …) |
| `app/Providers/` | 22+ service providers — each domain has its own |
| `resources/assets/v1/` | Legacy frontend: Vue 2 + jQuery + Bootstrap 3 (Laravel Mix) |
| `resources/assets/v2/` | Modern frontend: Alpine.js + Bootstrap 5 + AdminLTE 4 (Vite) |
| `resources/views/` | Blade/Twig templates for v1 web UI |
| `resources/views/v2/` | Templates for v2 web UI |
| `resources/locales/` | 40+ language packs |
| `config/firefly.php` | Primary application config |
| `config/bindables.php` | Interface-to-implementation bindings |
| `.ci/` | CI scripts and tool configs (phpstan.neon, rector.php, phpmd.xml) |

### Core Domain Concepts

- **Accounts** — Asset, liability, expense, revenue, and equity types (double-entry bookkeeping)
- **TransactionJournal / TransactionGroup** — Core financial entry; a group holds one or more split journals
- **Budgets / BudgetLimits** — Spending envelopes per period
- **PiggyBanks** — Savings goals linked to accounts
- **Rules / RuleGroups** — Automated transaction categorization/tagging using the rule engine
- **Recurrences** — Template-based recurring transaction creation (via `CreateRecurringTransactions` job)
- **Webhooks** — Outbound HTTP notifications on financial events

### Authentication

Uses **Laravel Passport** (OAuth2). Keys are generated via `php artisan firefly-iii:laravel-passport-keys`. API tokens and personal access clients are managed through Passport.
