#!/bin/sh
# cron.sh – Firefly III cron runner for the alpine cron container.
#
# Requires env vars injected via docker-compose env_file:
#   TZ                 – timezone for localtime symlink (e.g. "Asia/Bangkok")
#   STATIC_CRON_TOKEN  – exactly 32 characters, matches APP_ENV in firefly.env
set -e

apk add --no-cache tzdata wget

# Set container timezone
ln -sf "/usr/share/zoneinfo/${TZ}" /etc/localtime || true

# Install crontab: trigger Firefly III cron endpoint daily at 03:00
echo "0 3 * * * wget -qO- \"http://app:8080/api/v1/cron/${STATIC_CRON_TOKEN}\"; echo" \
    | crontab -

exec crond -f -L /dev/stdout
