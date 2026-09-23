# One recipe for every architecture check. What differs between them is an argument, not a file:
# the PHP version and whatever the tool needs installed beside it.
#
# PHP_VERSION defaults to the project's own, so a tool follows the application unless it cannot.
# PHPArkitect cannot: it parses with nikic/php-parser 4, which refuses to run under PHP 8.4, so the
# compose file pins it. The day it ships a php-parser 5 release, that pin disappears and nothing
# else changes.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-alpine

# Which subdirectory holds this tool's composer.json, and what it needs from the distribution.
ARG TOOL
ARG PACKAGES=""

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/composer \
    PATH=/tool/vendor/bin:$PATH

RUN if [ -n "$PACKAGES" ]; then apk add --no-cache $PACKAGES; fi \
 && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /tool
COPY ${TOOL}/composer.json ${TOOL}/composer.lock* ./
RUN composer install --no-interaction --no-progress

WORKDIR /src
