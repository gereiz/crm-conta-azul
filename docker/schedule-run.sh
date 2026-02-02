#!/bin/bash
set -euo pipefail

cd /var/www/html

LOG="storage/logs/laravel.log"
mkdir -p storage/logs
touch "$LOG"

echo "$(date '+%Y-%m-%d %H:%M:%S') [cron] schedule:run starting" >> "$LOG"
if ! php artisan schedule:run --no-interaction --verbose >> "$LOG" 2>&1; then
  echo "$(date '+%Y-%m-%d %H:%M:%S') [cron] schedule:run failed" >> "$LOG"
fi
echo "$(date '+%Y-%m-%d %H:%M:%S') [cron] schedule:run finished" >> "$LOG"
