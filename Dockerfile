# Dev/test image for etel/sqlite-file-storage.
# PHP 8.5 = the minimum version supported by the library; the official image already
# bundles sqlite3, zlib, sodium and fileinfo, so only brotli is added for the codec,
# plus pcov as the (fast, coverage-only) driver behind phpunit --coverage-*.
FROM php:8.5-cli-alpine

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions brotli pcov @composer

WORKDIR /app
