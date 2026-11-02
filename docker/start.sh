#!/usr/bin/env bash
set -euo pipefail

cd /var/www/app
php bin/validate-config.php
php bin/migrate.php
php bin/register-webhooks.php

exec apache2-foreground
