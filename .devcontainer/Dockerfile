FROM mcr.microsoft.com/devcontainers/base:bookworm
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    php-cli php-mysql php-curl php-mbstring php-xml php-zip php-gd php-intl php-opcache \
    mariadb-server mariadb-client curl ca-certificates openssl \
    && rm -rf /var/lib/apt/lists/*
# WP-CLI is downloaded only on first manual setup, not a production dependency.
