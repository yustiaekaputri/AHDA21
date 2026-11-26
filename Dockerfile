ARG PHP_OS_IMAGE=ubuntu:24.04
FROM ${PHP_OS_IMAGE}
ARG PHP_VERSION=8.3
ARG WORDPRESS_VERSION=6.9
ARG WORDPRESS_SHA256=5b36390233e32fef68cb5f66435bb32bdd50e0b3dfa5750aceb2de3c5993d720
RUN --mount=type=secret,id=sdp_proxy_ca,mode=0444 \
    set -eux; \
    if [ -r /run/secrets/sdp_proxy_ca ]; then \
        sed -i 's|http://|https://|g' /etc/apt/sources.list.d/*.sources; \
        printf 'Acquire::https::CaInfo "/run/secrets/sdp_proxy_ca";\n' > /etc/apt/apt.conf.d/99sdp-build-ca; \
    fi; \
    apt-get -o Acquire::Retries=3 update; \
    DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::Retries=3 install -y --no-install-recommends \
        apache2 ca-certificates curl unzip \
        "libapache2-mod-php${PHP_VERSION}" "php${PHP_VERSION}-cli" \
        "php${PHP_VERSION}-curl" "php${PHP_VERSION}-gd" "php${PHP_VERSION}-intl" \
        "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-mysql" \
        "php${PHP_VERSION}-opcache" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-zip"; \
    apt-get clean; \
    if [ -e /etc/apt/apt.conf.d/99sdp-build-ca ]; then rm /etc/apt/apt.conf.d/99sdp-build-ca; fi; \
    find /var/lib/apt/lists -type f -delete
RUN --mount=type=secret,id=sdp_proxy_ca \
    set -eux; \
    if [ -r /run/secrets/sdp_proxy_ca ]; then \
        cat /etc/ssl/certs/ca-certificates.crt /run/secrets/sdp_proxy_ca > /tmp/sdp-build-ca.crt; \
        export CURL_CA_BUNDLE=/tmp/sdp-build-ca.crt; \
    fi; \
    mkdir -p /var/www/html/wp-content/languages /var/www/html/wp-content/uploads; \
    curl -fsSL "https://wordpress.org/wordpress-${WORDPRESS_VERSION}.tar.gz" -o /tmp/wordpress.tar.gz; \
    echo "${WORDPRESS_SHA256}  /tmp/wordpress.tar.gz" | sha256sum -c; \
    tar -xzf /tmp/wordpress.tar.gz --strip-components=1 -C /var/www/html; \
    curl -fsSL "https://downloads.wordpress.org/translation/core/${WORDPRESS_VERSION}/id_ID.zip" -o /tmp/sdp-id.zip; \
    unzip -q /tmp/sdp-id.zip -d /var/www/html/wp-content/languages; \
    if ! curl -fsSL --retry 2 https://raw.githubusercontent.com/wp-cli/builds/05989a431e982caf17cd94508ff9b4c5fb12ae44/phar/wp-cli.phar -o /usr/local/bin/wp; then \
        curl -fsSL --retry 2 https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar -o /usr/local/bin/wp; \
    fi; \
    echo "ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c  /usr/local/bin/wp" | sha256sum -c; \
    chmod 755 /usr/local/bin/wp; \
    chown www-data:www-data /var/www/html/wp-content/uploads; \
    for sapi in apache2 cli; do ln -s /etc/sdp/development.ini "/etc/php/${PHP_VERSION}/$sapi/conf.d/99-sdp.ini"; done; \
    rm /var/www/html/index.html /tmp/wordpress.tar.gz /tmp/sdp-id.zip; \
    if [ -e /tmp/sdp-build-ca.crt ]; then rm /tmp/sdp-build-ca.crt; fi
RUN --mount=type=bind,source=.,target=/tmp/sdp-source,readonly \
    set -eux; \
    install -D -m 0644 /tmp/sdp-source/config/development-wordpress.php /var/www/html/wp-config.php; \
    install -D -m 0644 /tmp/sdp-source/config/apache.conf /etc/apache2/conf-enabled/sdp.conf; \
    install -D -m 0644 /tmp/sdp-source/config/php.ini /etc/sdp/development.ini; \
    cp -R /tmp/sdp-source/plugin/ /var/www/html/wp-content/plugins/sekolah-directory-pro; \
    cp -R /tmp/sdp-source/theme/ /var/www/html/wp-content/themes/sekolah-directory-theme; \
    mkdir -p /opt/sdp/tests; \
    cp -R /tmp/sdp-source/scripts/ /opt/sdp/scripts; \
    cp -R /tmp/sdp-source/tests/localwp/ /tmp/sdp-source/tests/integration/ /opt/sdp/tests/; \
    chmod -R a+rX /var/www/html/wp-content/plugins/sekolah-directory-pro \
        /var/www/html/wp-content/themes/sekolah-directory-theme /opt/sdp
WORKDIR /var/www/html
EXPOSE 80
CMD ["apache2ctl", "-D", "FOREGROUND"]
