FROM php:8.2-fpm-alpine

# ---------------------------------------------------------------------
# Dependencias del sistema y extensiones PHP necesarias para Laravel + LDAP
# ---------------------------------------------------------------------
RUN apk add --no-cache \
        bash \
        git \
        curl \
        unzip \
        libzip-dev \
        oniguruma-dev \
        libxml2-dev \
        openldap-dev \
        icu-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        zip \
        bcmath \
        ldap \
        intl \
        gd \
        opcache \
    && rm -rf /var/cache/apk/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---------------------------------------------------------------------
# Composer install (capa cacheable)
# ---------------------------------------------------------------------
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist \
    && composer clear-cache

# ---------------------------------------------------------------------
# Código fuente
# ---------------------------------------------------------------------
COPY . /var/www/html

# Permisos de storage y bootstrap/cache
RUN mkdir -p storage/framework/cache/data \
            storage/framework/sessions \
            storage/framework/views \
            storage/logs \
            bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Optimizaciones (config cache la generamos en runtime, después de tener .env del compose)
RUN composer dump-autoload --optimize --no-dev

# Script de arranque
COPY docker-entrypoint.sh /usr/local/bin/atlas-entrypoint
RUN chmod +x /usr/local/bin/atlas-entrypoint

EXPOSE 9000

ENTRYPOINT ["atlas-entrypoint"]
CMD ["php-fpm", "-F"]
