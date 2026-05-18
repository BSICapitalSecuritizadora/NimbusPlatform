#!/bin/bash

set -e

echo "Installing Composer dependencies..."
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "Building frontend assets..."
npm ci
npm run build

echo "Clearing and rebuilding application cache..."
php artisan optimize:clear
php artisan optimize

echo "Running database migrations..."
php artisan migrate --force

echo "Deployment complete."
