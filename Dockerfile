ARG PHP_VERSION=8.3
FROM wordpress:cli AS cli
FROM wordpress:php${PHP_VERSION}-apache
COPY --from=cli /usr/local/bin/wp /usr/local/bin/wp
# These extensions are for the development container, not the production VPS.
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libzip-dev \
    && for ext in pdo_mysql intl zip; do if ! php -m | grep -iqx "$ext"; then docker-php-ext-install -j2 "$ext" || exit 1; fi; done \
    && rm -rf /var/lib/apt/lists/*
