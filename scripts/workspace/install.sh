#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
test -f .env || { echo 'Run node scripts/workspace/configure.mjs first.'; exit 1; }
composer install --no-interaction --prefer-dist
npm ci
if ! grep -Eq '^APP_KEY=.+$' .env; then
    php artisan key:generate --no-interaction
fi
php artisan migrate --no-interaction
php artisan db:seed --no-interaction
php artisan wayfinder:generate --with-form
npm run build
printf '\nDependencies, application key, PostgreSQL migrations and assets initialized.\n'
