# Fabric POS operations

## Production basics

- Use PostgreSQL, not an in-memory test database.
- Set `APP_ENV=production`, `APP_DEBUG=false`, and a unique `APP_KEY`.
- Run `php artisan migrate --force` and `php artisan db:seed --class=Database\\Seeders\\FabricSeeder --force` for a fresh installation.
- Create/recover the owner with `php artisan fabric:owner owner@example.com`.
- Run the Laravel scheduler so expired fabric reservations are released.
- Keep database and uploaded receipt/document backups outside the application server.

## Browser QA

Use an isolated database whose name contains `browser`, then run `scripts/qa/seed-browser.php` and the Fabric Playwright tests. Never run browser fixtures against real business data.
