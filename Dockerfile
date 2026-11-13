# ============================================================
# Dockerfile — Render.com web service
#
# Render has no native PHP runtime, so the app is packaged as a
# Docker image. The base image is Apache + PHP 8.3, which is what
# public/.htaccess already expects (mod_rewrite, mod_headers,
# mod_deflate, mod_expires).
#
# The document root is /var/www/html/public, never the project root:
# that is what keeps .env, certs/, vendor/ and final_app.sql outside
# the web root.
# ============================================================
FROM php:8.3-apache

# --- 1. PHP extensions the app actually uses -------------------
# pdo_mysql  — db.php and session_store.php
# mysqli     — _import_sql.php (the deployment importer)
# mbstring   — composer.json / ext-mbstring
# curl       — include/s3.php and the email API transport
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        libonig-dev \
        libcurl4-openssl-dev \
        unzip; \
    for ext in pdo_mysql mysqli mbstring; do \
        if ! php -m | grep -qix "$ext"; then \
            docker-php-ext-install -j"$(nproc)" "$ext"; \
        fi; \
    done; \
    if ! php -m | grep -qix curl; then \
        docker-php-ext-install -j"$(nproc)" curl; \
    fi; \
    a2enmod rewrite headers deflate expires; \
    rm -rf /var/lib/apt/lists/*

# Composer is needed at build time only; the binary is not the app.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies first so a source-only change reuses this layer.
# vendor/ is gitignored, so it must be rebuilt inside the image.
# Composer runs as root during the build, hence the explicit opt-in.
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts \
        --prefer-dist --optimize-autoloader

# The application itself. .dockerignore keeps secrets, uploads and the
# Android/TWA project out of the build context.
COPY . .

# --- 2. Runtime configuration ----------------------------------
COPY docker/php.ini /usr/local/etc/php/conf.d/99-islafind.ini
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/islafind-entrypoint.sh

RUN sed -i 's/\r$//' /usr/local/bin/islafind-entrypoint.sh \
    && chmod +x /usr/local/bin/islafind-entrypoint.sh \
    && a2ensite 000-default \
    && mkdir -p public/uploads \
    && chown -R www-data:www-data public/uploads \
    && ln -sf /proc/self/fd/1 /var/log/apache2/access.log \
    && ln -sf /proc/self/fd/2 /var/log/apache2/error.log

# Render routes to the port in $PORT (default 10000). The entrypoint
# rewrites Apache's Listen/vhost before Apache starts.
EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/islafind-entrypoint.sh"]
CMD ["apache2-foreground"]
