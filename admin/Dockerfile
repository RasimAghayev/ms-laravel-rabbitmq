FROM php:8.5-fpm-alpine

# nginx + supervisor + build deps for PHP extensions, then prune build deps
RUN apk add --no-cache nginx supervisor dos2unix curl linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql sockets pcntl bcmath \
    && apk del $PHPIZE_DEPS

# set composer related environment variables
ENV PATH="/composer/vendor/bin:$PATH" \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_VENDOR_DIR=/var/www/vendor \
    COMPOSER_HOME=/composer

# install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer --ansi --version --no-interaction

# install application dependencies
WORKDIR /var/www
COPY ./composer.json ./composer.lock* ./
RUN composer install --no-scripts --no-autoloader --ansi --no-interaction

# add custom php-fpm pool settings, these get written at entrypoint startup
ENV FPM_PM_MAX_CHILDREN=20 \
    FPM_PM_START_SERVERS=2 \
    FPM_PM_MIN_SPARE_SERVERS=1 \
    FPM_PM_MAX_SPARE_SERVERS=3

# set application environment variables
ENV APP_ENV=local \
    APP_DEBUG=false

# copy entrypoint files
COPY ./docker/docker-php-* /usr/local/bin/
RUN dos2unix /usr/local/bin/docker-php-entrypoint
RUN dos2unix /usr/local/bin/docker-php-entrypoint-dev

# copy nginx configuration
COPY ./docker/nginx.conf /etc/nginx/nginx.conf
COPY ./docker/default.conf /etc/nginx/conf.d/default.conf

# copy supervisor configuration
COPY ./docker/supervisord.conf /etc/supervisord.conf

# copy application code
WORKDIR /var/www
COPY . .
RUN composer dump-autoload -o \
    && chown -R :www-data /var/www \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

EXPOSE 80

# run supervisor
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]
