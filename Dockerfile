# syntax=docker/dockerfile:1
#
# One image serving both halves of the CMS: nginx for the built SPA and PHP-FPM for the API,
# so the browser sees a single origin and frontend/src/lib/api.ts can keep calling the
# relative /api/v1. Built by `docker compose build`; never needs a host-side npm or composer.

FROM node:22-alpine AS spa
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json frontend/.nvmrc ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM composer:2 AS api
WORKDIR /build
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts
COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# 8.4, not 8.3: composer.json says "^8.3" but the locked Symfony 8 components require
# >= 8.4.1, and vendor/platform_check.php refuses to boot on anything older.
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
      nginx \
      supervisor \
      dcron \
      mariadb-client \
      tzdata \
      icu-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl opcache pcntl

ENV CMS_IMAGE=1

# Two settings, both worth their layer: PHP otherwise answers every API request with
# X-Powered-By: PHP/x.y.z, and validate_timestamps=0 is safe here precisely because a deploy
# always produces a fresh image -- the container restarts, so the opcode cache is rebuilt.
RUN printf 'expose_php = Off\nopcache.enable = 1\nopcache.memory_consumption = 128\nopcache.max_accelerated_files = 20000\nopcache.validate_timestamps = 0\n' \
    > /usr/local/etc/php/conf.d/cms.ini

WORKDIR /var/www/cms
COPY --from=api /build ./backend
COPY --from=spa /build/dist/ ./backend/public/
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/zz-cms-fpm.conf /usr/local/etc/php-fpm.d/zz-cms.conf
COPY docker/entrypoint.sh /usr/local/bin/cms-entrypoint

# storage/framework/{views,cache/data,sessions} are excluded from the build context by
# .dockerignore, so the image has to create the scratch paths Laravel insists on.
RUN chmod +x /usr/local/bin/cms-entrypoint \
 && mkdir -p /etc/nginx/cache /run/nginx \
 && mkdir -p backend/storage/framework/views backend/storage/framework/cache/data \
      backend/storage/framework/sessions backend/storage/app/public \
 && chown -R www-data:www-data backend/storage backend/bootstrap/cache backend/public \
 && nginx -t

WORKDIR /var/www/cms/backend
EXPOSE 8080

ENTRYPOINT ["cms-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
