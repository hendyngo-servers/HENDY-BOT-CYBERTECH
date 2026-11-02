#!/usr/bin/env bash
set -euo pipefail

cd /var/www/app

# Render supplies PORT (default 10000). Validate it before writing Apache config.
port="${PORT:-10000}"
if ! [[ "$port" =~ ^[0-9]{1,5}$ ]] || (( port < 1 || port > 65535 )); then
    echo "PORT phải là số nguyên từ 1 đến 65535." >&2
    exit 1
fi

sed -i -E "s/^Listen [0-9]+$/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s/:10000>/:${port}>/" /etc/apache2/sites-available/000-default.conf

mkdir -p storage/modules
chown -R www-data:www-data storage
chmod 750 storage storage/modules

# Validate required environment values before attempting external connections.
php bin/validate-config.php

# Wait for PostgreSQL before applying the idempotent schema. This path works on
# Render Free as well, where a paid-only Pre-Deploy Command is unavailable.
php bin/wait-for-database.php
if [[ "${RUN_MIGRATIONS_ON_START:-true}" == "true" ]]; then
    php bin/migrate.php
else
    echo "RUN_MIGRATIONS_ON_START=false; bỏ qua migration lúc khởi động."
fi

# Telegram failures must not prevent Apache from starting.
if ! php bin/register-webhooks.php; then
    echo "CẢNH BÁO: Không thể đăng ký webhook lúc khởi động; Apache vẫn sẽ được chạy." >&2
fi

exec apache2-foreground
