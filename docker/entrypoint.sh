#!/bin/sh
# entrypoint.sh – container startup sequence for Firefly III.
#
# Order mirrors the composer lifecycle scripts from composer.json:
#   post-autoload-dump  → package:discover
#   post-update-cmd     → upgrade-database, passport-keys, instructions
#   post-install-cmd    → instructions install, security-alerts
#
# "clear" variants are replaced with "cache" variants for production.
set -e

cd /var/www/html

# ── App key ───────────────────────────────────────────────────────────────────
# APP_KEY must be in base64:<value> format. Regenerate if missing or malformed.
case "$APP_KEY" in
    ""|"SomeRandomStringOf32CharsExactly")
        echo "Generating APP_KEY..."
        php artisan key:generate --force
        ;;
    base64:*)
        ;;
    *)
        echo "APP_KEY is set but not in base64: format — regenerating..."
        php artisan key:generate --force
        ;;
esac

# ── Bootstrap cache (post-autoload-dump equivalent) ───────────────────────────
# release.yml runs composer with --no-scripts, so Illuminate's postAutoloadDump
# (which calls package:discover) never fires during the Docker build.
# The committed bootstrap/cache files were generated on a dev machine and may
# list dev-only providers (Debugbar, IdeHelper…) absent in --no-dev builds.
# Wipe them so package:discover rebuilds a clean production manifest.
echo "Clearing stale bootstrap cache..."
rm -f bootstrap/cache/services.php \
      bootstrap/cache/packages.php \
      bootstrap/cache/config.php \
      bootstrap/cache/routes*.php \
      bootstrap/cache/events.php

echo "Discovering packages..."
php artisan package:discover --ansi

# ── Caches (replaces clear variants from post-update-cmd) ────────────────────
echo "Caching configuration..."
php artisan config:cache

echo "Caching routes..."
php artisan route:cache

echo "Caching views..."
php artisan view:cache

# ── Database (post-update-cmd: firefly-iii:upgrade-database) ─────────────────
# Runs migrations and all Firefly III data-upgrade steps in one command.
echo "Running database upgrade..."
php artisan firefly-iii:upgrade-database

# ── Passport OAuth keys (post-update-cmd: firefly-iii:laravel-passport-keys) ──
echo "Ensuring Passport OAuth keys exist..."
php artisan firefly-iii:laravel-passport-keys

# ── First-boot tasks (post-install-cmd) ──────────────────────────────────────
echo "Running install instructions..."
php artisan firefly-iii:instructions install

echo "Verifying security alerts..."
php artisan firefly-iii:verify-security-alerts

# ── Permissions ───────────────────────────────────────────────────────────────
# php-fpm runs as www-data; fix any root-owned files written above.
chown -R www-data:www-data storage bootstrap/cache

echo "Starting supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
