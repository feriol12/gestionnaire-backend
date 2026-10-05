#!/bin/sh
set -e

# Render impose le port d'écoute via $PORT (Apache écoute sur 80 par défaut)
: "${PORT:=80}"
sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

# Bascule Neon : diagnostic des variables brutes, puis garde sur la configuration
# réellement mise en cache. Si la cible n'est pas Neon, arrêt AVANT migrate.
php docker/db-target-guard.php raw
php artisan config:cache
if ! php docker/db-target-guard.php cached; then
    echo "DB_GUARD STOP: database target is not Neon, migrate skipped"
    exit 1
fi
php artisan route:cache
php artisan migrate --force

exec apache2-foreground
