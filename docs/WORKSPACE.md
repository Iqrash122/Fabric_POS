# Fabric POS workspace

Install PHP/Composer and Node dependencies, copy `.env.example` to `.env`, configure PostgreSQL, generate the application key, migrate, and seed `FabricSeeder`.

Useful commands:

- `php artisan migrate`
- `php artisan db:seed --class=Database\\Seeders\\FabricSeeder`
- `php artisan fabric:owner owner@example.com`
- `npm run dev`
- `npm run build`
- `php artisan test --testsuite=Feature`

For automated browser testing use a disposable browser-test database and `scripts/qa/seed-browser.php`.
