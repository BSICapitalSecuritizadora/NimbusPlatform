#!/bin/bash
echo "Starting Laravel scheduler..."
cd /home/site/wwwroot
php artisan schedule:work
echo "Scheduler stopped. Restarting..."
