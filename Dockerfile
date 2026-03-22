# ============================================================
# Builder stage – compile PHP extensions, build JS, install Composer deps
# Mirrors what release.yml does: npm run prod (v1) + npm run build (v2)
# ============================================================
FROM php:8.5-fpm-alpine AS builder

# Build-time dependencies (removed after this stage)
RUN apk add --no-cache \
    gcc g++ make autoconf \
    nodejs npm \
    libpng-dev libjpeg-turbo-dev freetype-dev \
    libzip-dev oniguruma-dev \
    postgresql-dev \
    icu-dev \
    curl git

# Compile PHP extensions (matches release.yml: mbstring, intl, zip, bcmath + db drivers)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install -j$(nproc) \
        gd \
        pdo_mysql \
        pdo_pgsql \
        bcmath \
        intl \
        zip \
        mbstring \
        pcntl

# Install Composer via official installer (avoids Docker Hub credential requirement)
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

WORKDIR /var/www/html

# ── Composer: copy manifests first so this layer is cached until deps change ─
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --optimize-autoloader --no-progress --no-interaction --no-scripts

# ── npm: copy manifests + patches before install so this layer is cached ─────
COPY package.json package-lock.json ./
COPY patches/ patches/
COPY resources/assets/v1/package.json resources/assets/v1/
COPY resources/assets/v2/package.json resources/assets/v2/
RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-fund --no-audit

# ── Copy remaining source, then build assets ─────────────────────────────────
COPY . .

# Build JS assets exactly as release.yml does:
#   npm run prod --workspace=v1   (webpack-mix for legacy v1)
#   npm run build --workspace=v2  (vite for v2)
RUN npm run prod --workspace=v1 && \
    npm run build --workspace=v2

# release.yml uses --no-scripts so package:discover never auto-runs.
# The committed bootstrap/cache files were generated on a dev machine with
# dev dependencies (Debugbar, IdeHelper, etc.) that are absent in --no-dev builds.
# Eager-loading those missing providers crashes the bootstrap before auth registers.
# Delete them here so the entrypoint regenerates them clean at container start.
RUN rm -f bootstrap/cache/services.php \
         bootstrap/cache/packages.php \
         bootstrap/cache/config.php \
         bootstrap/cache/routes*.php \
         bootstrap/cache/events.php

# ============================================================
# Production stage – runtime image with nginx + php-fpm
# ============================================================
FROM php:8.5-fpm-alpine

# Runtime libraries (no -dev packages needed – extensions copied from builder)
RUN apk add --no-cache \
    libpng libjpeg-turbo freetype \
    libzip oniguruma \
    postgresql-libs \
    icu-libs \
    nginx \
    supervisor \
    curl \
    tini

# Copy compiled PHP extensions and their ini files from builder
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/     /usr/local/etc/php/conf.d/

WORKDIR /var/www/html

# Copy fully-built application (vendor + compiled assets)
COPY --from=builder /var/www/html /var/www/html

# Docker support files
COPY docker/nginx.conf          /etc/nginx/nginx.conf
COPY docker/supervisord.conf    /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh       /entrypoint.sh

# Ensure storage and bootstrap directories exist with correct permissions
RUN mkdir -p \
        storage/logs \
        storage/upload \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        bootstrap/cache \
        /run/nginx \
        /var/log/supervisor && \
    chmod +x /entrypoint.sh && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 755 storage bootstrap

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=10s --retries=3 --start-period=60s \
    CMD curl -f http://localhost:8080/api/v1/about || exit 1

# tini handles signal forwarding; entrypoint runs migrations then starts supervisord
ENTRYPOINT ["/sbin/tini", "--", "/entrypoint.sh"]
