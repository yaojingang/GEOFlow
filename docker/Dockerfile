ARG PHP_CLI_IMAGE=php:8.4-cli-bookworm
ARG PECL_REDIS_VERSION=6.3.0

FROM ${PHP_CLI_IMAGE}

ARG PECL_REDIS_VERSION

WORKDIR /var/www/html

# 使用国内 Debian 源，加速 apt 安装（Bookworm：deb822 或经典 sources.list）
RUN set -eux; \
    if [ -f /etc/apt/sources.list.d/debian.sources ]; then \
        sed -i 's|deb.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list.d/debian.sources; \
        sed -i 's|security.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list.d/debian.sources; \
    elif [ -f /etc/apt/sources.list ]; then \
        sed -i 's|deb.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list; \
        sed -i 's|security.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list; \
    fi

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libsqlite3-dev \
        libzip-dev \
        postgresql-client \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        gd \
        mbstring \
        pdo_pgsql \
        pdo_sqlite \
        pcntl \
        zip \
    && pecl install "redis-${PECL_REDIS_VERSION}" \
    && docker-php-ext-enable redis \
    && apt-get purge -y \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libsqlite3-dev \
        libzip-dev \
    && rm -rf /tmp/pear ~/.pearrc \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    COMPOSER_PROCESS_TIMEOUT=2000

# 本地 Compose 会把工作区挂载到 /var/www/html。开发镜像只保留 PHP、
# Composer、入口脚本和运行时配置，避免将会被挂载覆盖的源码、vendor
# 以及本机 storage 产物重复写入镜像。生产镜像由 Dockerfile.prod 构建。

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
COPY docker/php/opcache-dev.ini /usr/local/etc/php/conf.d/zz-opcache-dev.ini
COPY docker/php/php-docker-overrides.ini /usr/local/etc/php/conf.d/99-overrides.ini
COPY docker/entrypoint.sh /usr/local/bin/geoflow-entrypoint

RUN chmod +x /usr/local/bin/geoflow-entrypoint

ENTRYPOINT ["/usr/local/bin/geoflow-entrypoint"]
CMD ["php", "artisan", "about"]
