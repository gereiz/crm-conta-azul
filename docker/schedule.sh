#!/bin/sh
set -eu

LOG_FILE="storage/logs/laravel.log"
touch "$LOG_FILE"

while true; do
  echo "$(date '+%Y-%m-%d %H:%M:%S') [scheduler] starting schedule:work" >> "$LOG_FILE"
  /usr/bin/php artisan schedule:work --verbose --no-interaction >> "$LOG_FILE" 2>&1 || true
  EXITCODE=$?
  echo "$(date '+%Y-%m-%d %H:%M:%S') [scheduler] exited with code $EXITCODE" >> "$LOG_FILE"
  sleep 5
done
