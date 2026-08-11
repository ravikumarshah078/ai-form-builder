# Stage 1: Build the frontend assets
FROM node:20-alpine AS node-builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

# Stage 2: Build the PHP dependencies
FROM composer:2.6 AS composer-builder
WORKDIR /app
COPY composer*.json ./
# Install dependencies, ignoring platform requirements during build time
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs --no-scripts
COPY . .
# Copy compiled assets from node-builder
COPY --from=node-builder /app/public/build ./public/build
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# Stage 3: The final production image
FROM serversideup/php:8.4-fpm-nginx

# Switch to root to install php extensions
USER root

# Install required PHP extensions for Laravel and Phpspreadsheet/PhpWord
RUN install-php-extensions pdo_pgsql pgsql gd zip bcmath intl

# Copy the application files and ensure correct ownership
COPY --chown=www-data:www-data --from=composer-builder /app /var/www/html

# Switch back to the unprivileged www-data user
USER www-data
