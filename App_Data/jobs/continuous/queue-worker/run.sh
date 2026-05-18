#!/bin/bash
echo "Starting Laravel queue worker..."
cd /home/site/wwwroot
php artisan queue:work --sleep=3 --tries=1 --timeout=420 --max-time=3600
echo "Queue worker stopped. Restarting..."
