FROM php:8.5-fpm-alpine AS app

# Useful PHP extension installer image, copy binary into your container
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install php extensions
# exit on errors, exit on unset variables, print every command as it is executed
RUN set -eux; \
    install-php-extensions pdo pdo_pgsql pgsql;

# Node.js, from Alpine's own repo (nodejs-current tracks the current stable
# release) so `npm` and `php artisan` (needed by Wayfinder's dev-mode codegen)
# are both available in the same container.
RUN apk add --no-cache nodejs-current npm

# allow super user - set this if you use Composer as a
# super user at all times like in docker containers
ENV COMPOSER_ALLOW_SUPERUSER=1

# obtain composer using multi-stage build
COPY --from=composer:2.2.29 /usr/bin/composer /usr/bin/composer

#Here, we are copying only composer.json and composer.lock (instead of copying the entire source)
# right before doing composer install.
# This is enough to take advantage of docker cache and composer install will
# be executed only when composer.json or composer.lock have indeed changed!-
COPY ./composer.* ./

# install
RUN composer install --prefer-dist --no-dev --no-scripts --no-progress --no-interaction

# copy application files to the working directory
COPY . .

# run composer dump-autoload --optimize
RUN composer dump-autoload --optimize

# Dev image
# This stage is meant to be target-built into a separate image
# https://docs.docker.com/develop/develop-images/multistage-build/#stop-at-a-specific-build-stage
# https://docs.docker.com/compose/compose-file/#target
FROM app AS app_dev

# Xdebug has different modes / functionalities. We can default to 'off' and set to 'debug'
# when we run docker compose up if we need it
ENV XDEBUG_MODE=off

# Copy xdebug config file into container
COPY ./php/conf.d/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

# Install xdebug
RUN set -eux; \
	install-php-extensions xdebug

# The base "app" stage installs with --no-dev for a lean production image.
# Local dev tooling (Boost, Pint, Pest, etc. and artisan commands invoked
# via Wayfinder's dev-mode codegen) need the require-dev packages too.
RUN composer install --prefer-dist --no-scripts --no-progress --no-interaction \
    && composer dump-autoload --optimize

# Bake node_modules into the image, same as vendor above, so it doesn't need
# reinstalling on every container start.
RUN npm ci
