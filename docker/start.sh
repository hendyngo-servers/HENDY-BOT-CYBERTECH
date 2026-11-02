#!/usr/bin/env bash
set -euo pipefail

cd /var/www/app
mkdir -p storage/modules
chown -R www-data:www-data storage
chmod 750 storage storage/modules
php bin/validate-config.php
php bin/migrate.php
php bin/register-webhooks.php

exec apache2-foreground
