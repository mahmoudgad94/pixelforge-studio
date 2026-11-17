# One image for every role (web, worker, scheduler, or all three): see docker/start.sh. Works on Railway, Render, Fly, ECS...
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM dunglas/frankenphp:1-php8.3
RUN install-php-extensions pdo_pgsql intl zip bcmath pcntl opcache \
 && printf 'opcache.enable=1\nopcache.validate_timestamps=0\nopcache.memory_consumption=128\nopcache.jit=tracing\nopcache.jit_buffer_size=32M\n' > /usr/local/etc/php/conf.d/zz-opcache.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize && chmod +x docker/start.sh && chown -R www-data:www-data storage bootstrap/cache
CMD ["docker/start.sh"]
