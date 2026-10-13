#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
php --version
composer --version
node --version
npm --version
composer check-platform-reqs
php artisan wayfinder:generate --with-form
npm run types:check
npm run check
npm run build
php artisan test --configuration=phpunit.pgsql.xml
