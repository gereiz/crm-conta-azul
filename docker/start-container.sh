#!/bin/bash
set -euo pipefail

cd /var/www/html

BRANDING_STORAGE_PATH="${BRANDING_STORAGE_PATH:-/var/www/persistent/branding}"
BRANDING_LINK_PATH="storage/app/public/branding"
LOGS_STORAGE_PATH="${LOGS_STORAGE_PATH:-/var/www/persistent/logs}"
LOGS_LINK_PATH="storage/logs"
ENV_STORAGE_FILE="${ENV_STORAGE_FILE:-/var/www/persistent/env/.env}"

mkdir -p \
    "$BRANDING_STORAGE_PATH" \
    "$LOGS_STORAGE_PATH" \
    "$(dirname "$ENV_STORAGE_FILE")" \
    storage/app/public \
    storage \
    bootstrap/cache \
    database

# Na primeira inicializacao com volume vazio, preserva arquivos que vierem do container.
if [ -d "$BRANDING_LINK_PATH" ] && [ ! -L "$BRANDING_LINK_PATH" ]; then
    if [ -z "$(find "$BRANDING_STORAGE_PATH" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
        cp -a "$BRANDING_LINK_PATH"/. "$BRANDING_STORAGE_PATH"/ 2>/dev/null || true
    fi
    rm -rf "$BRANDING_LINK_PATH"
fi

ln -sfn "$BRANDING_STORAGE_PATH" "$BRANDING_LINK_PATH"

# Na primeira inicializacao com volume vazio, preserva logs existentes do container.
if [ -d "$LOGS_LINK_PATH" ] && [ ! -L "$LOGS_LINK_PATH" ]; then
    if [ -z "$(find "$LOGS_STORAGE_PATH" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
        cp -a "$LOGS_LINK_PATH"/. "$LOGS_STORAGE_PATH"/ 2>/dev/null || true
    fi
    rm -rf "$LOGS_LINK_PATH"
fi

ln -sfn "$LOGS_STORAGE_PATH" "$LOGS_LINK_PATH"

# Na primeira inicializacao com volume vazio, preserva o .env existente do container.
if [ -f .env ] && [ ! -L .env ]; then
    if [ ! -f "$ENV_STORAGE_FILE" ]; then
        cp .env "$ENV_STORAGE_FILE"
    fi
    rm -f .env
fi

# Se ainda NAO existe .env persistido, cria a partir do example.
if [ ! -f "$ENV_STORAGE_FILE" ]; then
    cp .env.example "$ENV_STORAGE_FILE"
    # Forca driver de sessao para file para evitar erro de banco na instalacao
    sed -i "s/SESSION_DRIVER=database/SESSION_DRIVER=file/g" "$ENV_STORAGE_FILE"
fi

ln -sfn "$ENV_STORAGE_FILE" .env

php artisan package:discover --ansi
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Limpa config cache antigo gerado com APP_KEY diferente.
php artisan config:clear

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database "$BRANDING_STORAGE_PATH" "$LOGS_STORAGE_PATH" "$(dirname "$ENV_STORAGE_FILE")"
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database "$BRANDING_STORAGE_PATH" "$LOGS_STORAGE_PATH" "$(dirname "$ENV_STORAGE_FILE")"

if [ -f .env ]; then
    chown www-data:www-data .env
    chmod 664 .env
fi

cron

exec apache2-foreground
