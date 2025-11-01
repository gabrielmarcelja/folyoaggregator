# 🚀 Quick Start Guide - FolyoAggregator

**Get your cryptocurrency data aggregation system running in 10 minutes!**

---

## ⚡ Prerequisites

Before starting, ensure you have:

- ✅ PHP 8.1 or higher
- ✅ MariaDB/MySQL database
- ✅ Apache web server
- ✅ Composer installed
- ✅ CoinMarketCap API key (free tier: https://coinmarketcap.com/api/)

---

## 📋 Step-by-Step Setup

### Step 1: Install Dependencies

```bash
cd /var/www/html/folyoaggregator
composer install
```

### Step 2: Configure Environment

```bash
# Copy environment template
cp .env.example .env

# Edit .env with your credentials
nano .env
```

**Required settings in `.env`:**
```env
# Database
DB_HOST=localhost
DB_NAME=folyoaggregator
DB_USER=folyo_user
DB_PASS=your_secure_password

# CoinMarketCap API
CMC_API_KEY=your_cmc_api_key_here
```

### Step 3: Create Database

```bash
mysql -u root -p
```

```sql
CREATE DATABASE folyoaggregator CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'folyo_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON folyoaggregator.* TO 'folyo_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Step 4: Run Database Migrations

```bash
php scripts/setup/migrate.php
```

**Expected output:**
```
✓ All migrations executed successfully
✓ Database structure created
```

### Step 5: Sync Asset Data from CoinMarketCap

```bash
# Sync TOP 200 cryptocurrencies
php scripts/setup/sync-cmc.php --limit=200
```

**Expected output:**
```
✓ Synced 200 assets
✓ Metadata updated
✓ Logos downloaded
```

This takes ~2-3 minutes and populates your database with cryptocurrency information.

### Step 6: Sync Complete Metadata

```bash
php scripts/setup/sync-metadata.php
```

**Expected output:**
```
✓ Descriptions synced
✓ URLs updated
✓ Tags added
```

### Step 7: Collect Historical Price Data

```bash
# This collects up to 8 years of historical data
# Takes 30-60 minutes depending on your connection
php scripts/collection/collect-full-history-paginated.php
```

**Expected output:**
```
Collecting history for BTC...
✓ 17,973 candles collected (8 years)
...
Total: 421,983 candles across 144 assets
```

**Pro tip:** This runs in the background. You can monitor progress in logs/:
```bash
tail -f logs/full-history-paginated.log
```

### Step 8: Start Real-Time Price Collector

```bash
# Start as daemon
./scripts/daemon/price-daemon.sh start
```

**Or run manually** (for testing):
```bash
php scripts/collection/price-collector.php --limit=50 --interval=60
```

**Expected output:**
```
✓ Collecting prices from 10 exchanges
✓ BTC: $110,245 (VWAP from 10 sources)
✓ ETH: $3,867 (VWAP from 10 sources)
...
```

### Step 9: Configure Automated Jobs (Cron)

```bash
# Install cron jobs
crontab -e
```

**Copy the configuration from:**
```bash
cat scripts/config/aggregator-cron.txt
```

**Essential cron jobs:**
```cron
# Price collection every minute (TOP 50)
* * * * * cd /var/www/html/folyoaggregator && php scripts/collection/price-collector.php --limit=50 --interval=50 >> logs/price-collector.log 2>&1

# Sync CMC data every hour (metadata updates)
0 * * * * cd /var/www/html/folyoaggregator && php scripts/setup/sync-cmc.php --limit=200 >> logs/cmc-sync.log 2>&1

# Generate daily statistics at 2 AM
0 2 * * * cd /var/www/html/folyoaggregator && php scripts/maintenance/generate-stats.php --save >> logs/stats.log 2>&1

# Daily backup at 3 AM
0 3 * * * /var/www/html/folyoaggregator/scripts/maintenance/backup-migrate.sh backup >> logs/backup.log 2>&1
```

### Step 10: Verify Everything Works

```bash
# Check system health
php scripts/maintenance/health-check.php

# Test API endpoints
curl http://folyoaggregator.test/api/health
curl http://folyoaggregator.test/api/listings?limit=5
curl http://folyoaggregator.test/api/assets/BTC
curl "http://folyoaggregator.test/api/historical/BTC?period=7d"

# View system statistics
php scripts/maintenance/system-stats.php
```

**Expected health check output:**
```
╔═══════════════════════════════════════════════════════════╗
║         FolyoAggregator Health Check Report               ║
╠═══════════════════════════════════════════════════════════╣
║ Timestamp: 2025-11-01 15:30:00 UTC                        ║
║ Overall Status: HEALTHY                                   ║
╚═══════════════════════════════════════════════════════════╝

[✓] Database
    Status: Healthy
    Assets Count: 151
    Message: Database connected

[✓] Data Freshness
    Status: Healthy
    Last Update: 2025-11-01 15:29:45
    Age Minutes: 0.3
    Message: Data is fresh

[✓] Exchanges
    Status: Healthy
    Total: 10
    Healthy: 10
    Health Percentage: 100%
    Message: 10/10 exchanges operational
```

---

## 🎉 Success! What's Next?

Your FolyoAggregator is now running! Here's what you can do:

### Access Your Data

**Dashboard:**
```
http://folyoaggregator.test/dashboard.php
```

**API Endpoints:**
```
http://folyoaggregator.test/api/
```

### Monitor Your System

```bash
# Real-time price collector monitoring
./scripts/maintenance/monitor-collectors.sh

# Check if system is ready to replace CMC
php scripts/maintenance/check-migration-ready.php

# View comprehensive statistics
php scripts/maintenance/system-stats.php

# Daily statistics report
php scripts/maintenance/generate-stats.php
```

### Backup Your Data

```bash
# Create backup
./scripts/maintenance/backup-migrate.sh backup

# Backups saved to: backups/folyoaggregator-backup-YYYY-MM-DD-HHMMSS.tar.gz
```

---

## 🐛 Troubleshooting

### Issue: "Access denied for user"
**Solution:** Check your .env database credentials match what you created in MySQL.

### Issue: "No historical data"
**Solution:** Run the historical collector:
```bash
php scripts/collection/collect-full-history-paginated.php
```

### Issue: "Exchange timeout"
**Solution:** Some exchanges may have rate limits. The system will retry automatically. Check:
```bash
tail -f logs/price-collector.log
```

### Issue: "API returns empty"
**Solution:** Make sure you've run all setup steps, especially:
1. Migration (Step 4)
2. CMC Sync (Step 5)
3. Historical collection (Step 7)

### Issue: "Cron jobs not running"
**Solution:** Verify cron is installed and check cron logs:
```bash
grep CRON /var/log/syslog
tail -f logs/price-collector.log
```

---

## 📚 Next Steps

- Read the complete [scripts/README.md](README.md) for detailed script documentation
- Check the main [README.md](../README.md) for full API documentation
- Review [scripts/config/aggregator-cron.txt](config/aggregator-cron.txt) for all available cron jobs

---

## ⏱️ Time Estimates

- **Setup (Steps 1-6):** 5-10 minutes
- **Historical Collection (Step 7):** 30-60 minutes (can run in background)
- **Configuration (Steps 8-9):** 5 minutes
- **Total:** ~15-20 minutes + background jobs

---

## 🆘 Need Help?

If you encounter issues not covered here:

1. Check logs in `/var/www/html/folyoaggregator/logs/`
2. Run health check: `php scripts/maintenance/health-check.php`
3. Review the main README.md troubleshooting section
4. Check [scripts/README.md](README.md) for detailed script documentation

---

**Welcome to FolyoAggregator! 🎉**

You're now running a professional cryptocurrency data aggregation system with:
- ✅ 8 years of historical data
- ✅ Real-time prices from 10 exchanges
- ✅ CMC-compatible API
- ✅ No rate limits
- ✅ Full control over your data
