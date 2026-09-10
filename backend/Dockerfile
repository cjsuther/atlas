FROM php:8.3-fpm-alpine

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

# ---------------------------------------------------------------------
# Configuración de PHP
#   Los errores no se muestran en la respuesta: un aviso del intérprete
#   filtra rutas, versiones y estructura interna. Se registran en el log.
#   Los límites de subida acompañan al client_max_body_size del gateway,
#   para que un archivo grande devuelva un error de la aplicación y no del
#   intérprete.
# ---------------------------------------------------------------------
RUN { \
        echo 'display_errors = Off'; \
        echo 'display_startup_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'error_log = /proc/self/fd/2'; \
        echo 'expose_php = Off'; \
        echo 'post_max_size = 25M'; \
        echo 'upload_max_filesize = 25M'; \
    } > /usr/local/etc/php/conf.d/atlas-seguridad.ini

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
