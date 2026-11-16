# Single container: web server + queue worker + scheduler. Works on Railway, Render, Fly, ECS...
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM dunglas/frankenphp:1-php8.3
RUN install-php-extensions pdo_pgsql intl zip bcmath pcntl opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize && chmod +x docker/start.sh && chown -R www-data:www-data storage bootstrap/cache
CMD ["docker/start.sh"]
