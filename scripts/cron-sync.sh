#!/bin/bash

# FolyoAggregator CMC Sync Cron Script
# This script runs the CMC sync and logs the output

# Change to the project directory
cd /var/www/html/folyoaggregator

# Set the log file path
LOG_DIR="/var/www/html/folyoaggregator/logs"
LOG_FILE="$LOG_DIR/cmc-sync-$(date +%Y%m%d).log"

# Create log directory if it doesn't exist
mkdir -p $LOG_DIR

# Add timestamp to log
echo "========================================" >> $LOG_FILE
echo "Sync started at $(date '+%Y-%m-%d %H:%M:%S')" >> $LOG_FILE
echo "========================================" >> $LOG_FILE

# Run the sync script
# Sync top 100 coins without validation for faster cron runs
/usr/bin/php scripts/sync-cmc.php --limit=100 --no-validate >> $LOG_FILE 2>&1

# Check exit status
if [ $? -eq 0 ]; then
    echo "Sync completed successfully at $(date '+%Y-%m-%d %H:%M:%S')" >> $LOG_FILE
else
    echo "Sync failed at $(date '+%Y-%m-%d %H:%M:%S')" >> $LOG_FILE
fi

echo "" >> $LOG_FILE

# Keep only the last 30 days of logs
find $LOG_DIR -name "cmc-sync-*.log" -mtime +30 -delete