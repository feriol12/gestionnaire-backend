# Image de base épinglée par digest (index multi-arch de php:8.3-apache,
# PHP 8.3.35 / Debian trixie) pour des builds reproductibles. SEC-AUTH-02A.
FROM php:8.3-apache@sha256:c2c73159da2f7a19167a2849bd9785ca9ad4f5eeff01f85295ef861a5e9bcf4d

RUN apt-get update && apt-get install -y \
        libpq-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        libcurl4-openssl-dev \
        unzip \
        git \
    && docker-php-ext-install pdo pdo_pgsql zip mbstring curl dom xml xmlwriter \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Ne pas annoncer la version de PHP (en-tête X-Powered-By). SEC-AUTH-02A.
RUN echo 'expose_php = Off' > /usr/local/etc/php/conf.d/zz-moneva-security.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
