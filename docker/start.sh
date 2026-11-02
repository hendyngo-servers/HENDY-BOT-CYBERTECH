#!/usr/bin/env bash
set -euo pipefail

cd /var/www/app
mkdir -p storage/modules
chown -R www-data:www-data storage
chmod 750 storage storage/modules

# Database schema is applied in Render preDeployCommand, not at every startup.
php bin/validate-config.php

# Telegram API/network failures must not take down the HTTP dashboard.
if ! php bin/register-webhooks.php; then
    echo "CẢNH BÁO: Không thể đăng ký webhook lúc khởi động; Apache vẫn sẽ được chạy." >&2
fi

exec apache2-foreground
