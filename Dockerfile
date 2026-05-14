# ────────────────────────────────────────────────────────
# Dockerfile — Laravel E-Commerce API (PHP 8.2)
# ────────────────────────────────────────────────────────
FROM php:8.2-fpm-alpine

# ── System Dependencies ───────────────────────────────
RUN apk add --no-cache \
    bash \
    curl \
    supervisor \
    zip \
    libzip-dev \
    oniguruma-dev \
    # For waiting on Redis in entrypoint
    busybox-extras \
    sqlite-dev \
    && docker-php-ext-install \
        pdo \
        pdo_sqlite \
        mbstring \
        bcmath \
        zip \
        opcache \
    && rm -rf /var/cache/apk/*

# ── Redis Extension (from PECL) ────────────────────────
# $PHPIZE_DEPS provides phpize and necessary headers for pecl
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/pear /root/.cache

# ── Composer ───────────────────────────────────────────
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ── PHP-FPM Config ────────────────────────────────────
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
RUN echo "memory_limit=256M" >> "$PHP_INI_DIR/conf.d/custom.ini"

# ── Application Setup ──────────────────────────────────
WORKDIR /var/www/app

# Copy application code (including artisan)
COPY . .

# Install dependencies (no dev for production image)
# Note: composer.json/composer.lock are copied as part of the full COPY above.
# We could optimize layer caching but the full copy ensures artisan exists for post-install scripts.
RUN composer install --no-interaction --optimize-autoloader \
    && rm -rf /root/.composer/cache

# Laravel setup: create storage structure, link storage
RUN php artisan storage:link --force 2>/dev/null || true \
    && chmod -R 775 storage bootstrap/cache database \
    && chown -R www-data:www-data storage bootstrap/cache database

# ── Supervisor Config (queue workers) ──────────────────
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# ── Entrypoint ─────────────────────────────────────────
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/entrypoint.sh"]
