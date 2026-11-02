#!/usr/bin/env bash
set -euo pipefail

cd /var/www/app
mkdir -p storage/modules
chown -R www-data:www-data storage
chmod 750 storage storage/modules

# Migration is a Render pre-deploy task, not a per-instance startup task.
# A database DNS/configuration issue must not crash-loop every web container.
php bin/validate-config.php
php bin/register-webhooks.php

exec apache2-foreground
