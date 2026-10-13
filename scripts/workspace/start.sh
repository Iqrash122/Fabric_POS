#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
npx concurrently --kill-others --names web,vite,queue,scheduler \
  'php artisan serve --host=0.0.0.0 --port=8000' \
  'npm run dev -- --host=0.0.0.0 --port=5173' \
  'php artisan queue:work --tries=3 --timeout=300' \
  'php artisan schedule:work'
