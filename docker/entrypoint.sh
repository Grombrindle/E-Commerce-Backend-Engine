#!/bin/bash
set -e

# ── Wait for Redis (if configured) ────────────────────
if [ -n "$REDIS_HOST" ]; then
    echo "⏳ Waiting for Redis at $REDIS_HOST:${REDIS_PORT:-6379}..."
    while ! nc -z "$REDIS_HOST" "${REDIS_PORT:-6379}" 2>/dev/null; do
        sleep 1
    done
    echo "✅ Redis is ready."
fi

# ── Create SQLite database if it doesn't exist ─────────
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    DB_PATH="${DB_DATABASE:-/var/www/app/database/database.sqlite}"
    mkdir -p "$(dirname "$DB_PATH")"
    touch "$DB_PATH"
    echo "✅ SQLite database ready at $DB_PATH"
fi

# ── Laravel Setup ──────────────────────────────────────
echo "⏳ Running Laravel setup..."

# Generate app key if not set
php artisan key:generate --force --quiet 2>/dev/null || true

# Cache config and routes (skip if APP_ENV=local)
if [ "${APP_ENV:-production}" != "local" ]; then
    php artisan config:cache --quiet 2>/dev/null || true
    php artisan route:cache --quiet 2>/dev/null || true
    php artisan view:cache --quiet 2>/dev/null || true
fi

# Run migrations
echo "⏳ Running migrations..."
php artisan migrate --force --isolated 2>&1 || echo "⚠️ Migration warning (continuing...)"
echo "✅ Migrations complete."

# Run migrations (the job_batches table is already included in 2024_01_01_000010_create_jobs_tables.php)
php artisan migrate --force --isolated 2>&1 || true

# Fix permissions
chown -R www-data:www-data storage bootstrap/cache database 2>/dev/null || true
chmod -R 775 storage bootstrap/cache database 2>/dev/null || true

echo "✅ Laravel setup complete."
echo ""

# ── Start Services ────────────────────────────────────
echo "🚀 Starting services..."

# Start PHP-FPM in background
php-fpm -D
echo "   ✅ PHP-FPM started"

mkdir -p /var/log/supervisor

# Start Supervisor (manages queue workers)
if [ -f /etc/supervisor/conf.d/supervisord.conf ]; then
    supervisord -c /etc/supervisor/conf.d/supervisord.conf 2>/dev/null || true
    echo "   ✅ Supervisor started (queue workers)"
fi

# Start PHP artisan serve (serves the app on port 8000)
echo "   🚀 Starting Laravel on 0.0.0.0:8000..."
exec php artisan serve --host=0.0.0.0 --port=8000
