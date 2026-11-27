ARG SDP_BASE_IMAGE=sdp-budget-base
FROM ${SDP_BASE_IMAGE}

RUN set -eux; \
    apt-get -o Acquire::Retries=3 update; \
    DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::Retries=3 install -y --no-install-recommends \
        mariadb-server nginx php8.3-fpm python3; \
    rm -rf /var/lib/apt/lists/*

ENTRYPOINT ["bash", "/opt/sdp/source/scripts/test-vps-budget.sh"]
