#!/bin/sh
set -e

# Render impose le port d'écoute via $PORT (Apache écoute sur 80 par défaut)
: "${PORT:=80}"
sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

php artisan config:cache
php artisan route:cache
php artisan migrate --force

exec apache2-foreground
