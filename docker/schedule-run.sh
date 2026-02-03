#!/bin/bash
set -euo pipefail

cd /var/www/html

LOG="storage/logs/laravel.log"
mkdir -p storage/logs
touch "$LOG"
# Garantir propriedade e permissões para o processo web (www-data)
chown -R www-data:www-data storage/logs
chmod -R 775 storage/logs
chmod 664 "$LOG"

PHP_BIN="${PHP_BIN:-/usr/local/bin/php}"

echo "$(date '+%Y-%m-%d %H:%M:%S') [cron] schedule:run starting" >> "$LOG"
if ! "$PHP_BIN" artisan schedule:run --no-interaction --verbose >> "$LOG" 2>&1; then
  echo "$(date '+%Y-%m-%d %H:%M:%S') [cron] schedule:run failed" >> "$LOG"
fi
echo "$(date '+%Y-%m-%d %H:%M:%S') [cron] schedule:run finished" >> "$LOG"
