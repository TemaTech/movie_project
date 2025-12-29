#!/bin/bash

# Production Cron Setup Script
# This script commands to set up the correct cron job on the production server.
# It is intended to be run manually or as part of a setup process.

CRON_JOB="* * * * * cd /var/www/movie-ranking && /usr/bin/docker compose -f docker-compose.prod.yml exec -T app php artisan schedule:run >> /dev/null 2>&1"

echo "Setting up production cron job..."

# Check if the job already exists
(crontab -l 2>/dev/null | grep -F "php artisan schedule:run") && echo "Cron job already exists (checking for needed updates...)"

# Backup current crontab
crontab -l > /tmp/crontab.bak 2>/dev/null || true

# Remove old cron job if it exists (the one running directly on host)
crontab -l 2>/dev/null | grep -v "php artisan schedule:run" > /tmp/crontab.new || true

# Add new cron job
echo "$CRON_JOB" >> /tmp/crontab.new

# Install new crontab
crontab /tmp/crontab.new
rm /tmp/crontab.new

echo "✅ Production cron job updated successfully."
echo "Current crontab:"
crontab -l
