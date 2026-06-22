<?php

echo "PHP_SAPI: " . PHP_SAPI . PHP_EOL;
echo "CACHE_STORE env: " . (getenv('CACHE_STORE') ?: 'NOT SET') . PHP_EOL;
echo "DB_CONNECTION env: " . (getenv('DB_CONNECTION') ?: 'NOT SET') . PHP_EOL;
echo "DB_DATABASE env: " . (getenv('DB_DATABASE') ?: 'NOT SET') . PHP_EOL;

require '/var/www/app/vendor/autoload.php';
$app = require '/var/www/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Config cache.default: " . config('cache.default') . PHP_EOL;
echo "Config database.default: " . config('database.default') . PHP_EOL;
echo "Config database.sqlite.database: " . config('database.sqlite.database') . PHP_EOL;
echo "env(CACHE_STORE): " . (env('CACHE_STORE') ?? 'NULL') . PHP_EOL;
echo "env(DB_CONNECTION): " . (env('DB_CONNECTION') ?? 'NULL') . PHP_EOL;
echo "env(DB_DATABASE): " . (env('DB_DATABASE') ?? 'NULL') . PHP_EOL;
