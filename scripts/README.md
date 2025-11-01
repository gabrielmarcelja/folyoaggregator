# 📁 Scripts Directory - Complete Reference

**FolyoAggregator Scripts Documentation**

All scripts are organized by purpose into subdirectories. This guide provides complete documentation for each script.

---

## 📂 Directory Structure

```
scripts/
├── setup/              # Initial setup (run once)
├── collection/         # Data collection
├── daemon/            # Daemon management
├── maintenance/       # Maintenance & monitoring
├── config/           # Configuration files
├── utils/            # Utility scripts
└── obsolete/         # Archived old scripts
```

---

## 🚀 Quick Start

**New installation?** Follow this order:

```bash
1. php scripts/setup/migrate.php
2. php scripts/setup/sync-cmc.php --limit=200
3. php scripts/setup/sync-metadata.php
4. php scripts/collection/collect-full-history-paginated.php
5. Install cron jobs: crontab scripts/config/aggregator-cron.txt
   (includes 30-second price collection + daily gap filling)
```

**See [QUICKSTART.md](QUICKSTART.md) for detailed step-by-step guide.**

**Data Collection Strategy:**
- **Historical data** (8 years): collect-full-history-paginated.php (run once)
- **Real-time prices** (30s intervals): price-collector.php (via cron every minute)
- **Gap filling** (daily): fill-recent-gap.php (via cron at 4 AM)

---

## 📋 Setup Scripts

Scripts for initial system setup (run once).

### migrate.php

**Purpose:** Create and update database schema

**Usage:**
```bash
php scripts/setup/migrate.php
```

**What it does:**
- Creates all database tables
- Tracks executed migrations
- Handles schema updates
- Creates indexes

**When to run:** First time setup, and after pulling updates that include new migrations

**Output:**
```
Running migrations...
✓ 001_create_assets_table.sql
✓ 002_create_prices_table.sql
...
All migrations completed successfully!
```

---

### sync-cmc.php

**Purpose:** Synchronize cryptocurrency data from CoinMarketCap API

**Usage:**
```bash
# Sync TOP 200 assets
php scripts/setup/sync-cmc.php --limit=200

# Sync TOP 50 only
php scripts/setup/sync-cmc.php --limit=50

# Test mode (dry run)
php scripts/setup/sync-cmc.php --test

# Show help
php scripts/setup/sync-cmc.php --help
```

**What it does:**
- Fetches latest cryptocurrency listings from CMC
- Updates market cap, price, volume data
- Downloads logos
- Syncs metadata (descriptions, URLs, tags)
- Handles pagination automatically

**Options:**
- `--limit=N` - Number of assets to sync (default: 100)
- `--test` - Dry run mode (shows what would be synced)
- `--force` - Force re-sync even if recently synced

**When to run:**
- Initial setup: Once
- Maintenance: Every hour (via cron)

**Requirements:** CoinMarketCap API key in `.env`

---

### sync-metadata.php

**Purpose:** Sync complete metadata (descriptions, URLs, social links)

**Usage:**
```bash
php scripts/setup/sync-metadata.php
```

**What it does:**
- Fetches detailed crypto-info from CMC
- Updates descriptions
- Syncs website URLs
- Adds blockchain explorers
- Updates social media links
- Syncs tags and categories

**When to run:**
- After sync-cmc.php (initial setup)
- Weekly (via cron) to get updated metadata

**Note:** This is more detailed than sync-cmc.php and takes longer

---

## 📊 Collection Scripts

Scripts for collecting price and historical data.

### price-collector.php

**Purpose:** Collect real-time prices from multiple exchanges (30-second intervals)

**Usage:**
```bash
# Collect TOP 50 assets, update every 30 seconds (RECOMMENDED)
php scripts/collection/price-collector.php --limit=50 --interval=30

# Collect TOP 100, 60-second intervals
php scripts/collection/price-collector.php --limit=100 --interval=60

# Specific assets only
php scripts/collection/price-collector.php --symbols=BTC,ETH,SOL --interval=30

# Show help
php scripts/collection/price-collector.php --help
```

**Options:**
- `--limit=N` - Number of top assets to collect (default: 20)
- `--interval=N` - Seconds between collections (default: 30)
- `--symbols=X,Y,Z` - Collect specific symbols only
- `--help` - Show detailed help message

**What it does:**
- Connects to 10 exchanges (Binance, Coinbase, Kraken, etc.)
- Fetches real-time prices
- Calculates VWAP (Volume-Weighted Average Price)
- Computes confidence score
- Stores individual exchange prices
- Aggregates into unified price

**Exchanges used:**
1. Binance (primary)
2. Coinbase
3. Kraken
4. KuCoin
5. Bybit
6. OKX
7. Gate.io
8. Bitfinex
9. Huobi
10. Bitstamp

**When to run:**
- **Recommended:** Every minute via cron (runs for ~50 seconds with 30s intervals)
- **Alternative:** As daemon via price-daemon.sh (continuous background process)

**Competitive frequency:** 30-second updates match CoinMarketCap's real-time data frequency

**Recommended cron:**
```bash
# Every minute, 30-second intervals
* * * * * cd /var/www/html/folyoaggregator && php scripts/collection/price-collector.php --limit=50 --interval=30 >> logs/price-collector.log 2>&1
```

---

### collect-historical.php

**Purpose:** Collect historical OHLCV data for specific assets

**Usage:**
```bash
# Collect 1 year of BTC history (4h timeframe)
php scripts/collection/collect-historical.php --symbol=BTC --days=365 --timeframe=4h

# Collect 30 days of ETH history (1h timeframe)
php scripts/collection/collect-historical.php --symbol=ETH --days=30 --timeframe=1h

# Specific exchange
php scripts/collection/collect-historical.php --symbol=BTC --days=365 --exchange=binance
```

**Options:**
- `--symbol=X` - Cryptocurrency symbol (required)
- `--days=N` - Number of days to collect (default: 365)
- `--timeframe=X` - Candle timeframe: 1h, 4h, 1d (default: 4h)
- `--exchange=X` - Specific exchange (default: binance)

**Timeframe guide:**
- `1h` - Ideal for 24h-7d charts (24 candles/day)
- `4h` - Ideal for 7d-1y charts (6 candles/day) **[RECOMMENDED]**
- `1d` - Ideal for multi-year charts (1 candle/day)

**When to run:**
- Initial setup: Use collect-full-history-paginated.php instead
- Maintenance: For specific asset updates

---

### collect-full-history-paginated.php ⭐ **RECOMMENDED**

**Purpose:** Collect complete historical data for all TOP assets with pagination

**Usage:**
```bash
# Collect full history for TOP 50 (default)
php scripts/collection/collect-full-history-paginated.php

# Collect for TOP 150
php scripts/collection/collect-full-history-paginated.php --limit=150
```

**What it does:**
- Automatically paginates to bypass 1000 candle limits
- Uses known launch dates for accuracy
- Calculates coverage percentage
- Skips already-completed assets
- Displays TOP 10 coverage summary
- Collects up to 8 years of data per asset

**Smart features:**
- **Auto-pagination:** Handles exchange limits automatically
- **Resume support:** Skips assets with >95% complete data
- **Coverage tracking:** Shows data quality per asset
- **Fallback exchanges:** Tries multiple exchanges if one fails

**Expected runtime:** 30-60 minutes for TOP 50 assets

**Output example:**
```
Collecting BTC (rank #1)...
  Page 1: 1000 candles (2017-08-17)
  Page 2: 1000 candles (2018-06-15)
  ...
  Page 18: 973 candles (2025-11-01)
✓ BTC: 17,973 candles (8.2 years coverage)

Total: 421,983 candles across 144 assets
```

**When to run:**
- Initial setup: Once
- Maintenance: Monthly for new assets

**This is the BEST script for historical collection!**

---

### fill-recent-gap.php ⚡ **NEW**

**Purpose:** Fill missing candles between last historical data and current time

**Usage:**
```bash
# Daily gap filling (recommended)
php scripts/collection/fill-recent-gap.php

# Fill gaps for TOP 100 assets
php scripts/collection/fill-recent-gap.php --limit=100

# Check last 7 days for gaps
php scripts/collection/fill-recent-gap.php --lookback=7

# Fill only 1h timeframe
php scripts/collection/fill-recent-gap.php --timeframe=1h
```

**Options:**
- `--limit=N` - Number of top assets (default: 50)
- `--timeframe=TF` - Timeframe: 1h, 4h, or both (default: both)
- `--lookback=DAYS` - Days to check for gaps (default: 3)

**What it does:**
- Detects missing candles between last data point and now
- Fetches missing candles from exchanges (Binance)
- Fills gaps in both 1h and 4h timeframes
- Skips assets with no gaps
- Avoids duplicate insertions
- Handles exchange rate limits gracefully

**Why you need this:**
The full history collector runs once to get 8 years of data. However, there's a gap between:
- **Last historical candle** (e.g., yesterday at 04:00)
- **Current time** (e.g., today at 16:00)

This script fills that gap daily, ensuring continuous historical data for graphs.

**Recommended cron:**
```bash
# Daily at 4 AM
0 4 * * * cd /var/www/html/folyoaggregator && php scripts/collection/fill-recent-gap.php --limit=50 >> logs/gap-filler.log 2>&1

# Weekly comprehensive check (Sundays at 5 AM)
0 5 * * 0 cd /var/www/html/folyoaggregator && php scripts/collection/fill-recent-gap.php --limit=100 --lookback=7 >> logs/gap-filler-weekly.log 2>&1
```

**Example output:**
```
╔═══════════════════════════════════════════════════════════════╗
║       FolyoAggregator Gap Filler                          ║
╚═══════════════════════════════════════════════════════════════╝

Configuration:
  Assets: TOP 50
  Timeframes: 1h, 4h
  Lookback: 3 days
  Started: 2025-11-01 04:00:00

✓ Connected to binance

🚀 Starting gap fill for 50 assets...
──────────────────────────────────────────────────────────────────

[ 1/50] #1   BTC      ✓ Filled 2 gap(s), 36 candles
[ 2/50] #2   ETH      ✓ Filled 2 gap(s), 36 candles
[ 3/50] #3   USDT     ✓ No gaps
...

══════════════════════════════════════════════════════════════════
Summary:
  Assets Processed: 50
  Assets with Gaps: 12
  Total Gaps Filled: 24
  Total Candles Inserted: 864
  Errors: 0
  Completed: 2025-11-01 04:02:15

✓ Gap filling completed successfully
```

**When to run:**
- **Daily at 4 AM:** Catch up on yesterday's missing candles
- **Weekly:** Comprehensive 7-day gap check
- **After downtime:** If your price collector was offline

**Works with:** price-collector.php (real-time prices) for complete data coverage

---

## 🔧 Daemon Management

Scripts for managing background processes.

### price-daemon.sh

**Purpose:** Manage price collector as a daemon

**Usage:**
```bash
# Start daemon
./scripts/daemon/price-daemon.sh start

# Stop daemon
./scripts/daemon/price-daemon.sh stop

# Restart daemon
./scripts/daemon/price-daemon.sh restart

# Check status
./scripts/daemon/price-daemon.sh status
```

**What it does:**
- Starts price-collector.php as background process
- Manages PID file
- Handles log rotation
- Provides status checking

**PID file:** `/var/run/folyoaggregator-price-collector.pid`
**Log file:** `logs/price-collector.log`

**When to run:** After initial setup to start continuous price collection

---

### cron-sync.sh

**Purpose:** Wrapper for CMC sync with logging (called by cron)

**Usage:**
```bash
./scripts/daemon/cron-sync.sh
```

**What it does:**
- Wraps sync-cmc.php call
- Adds timestamp to logs
- Handles error logging
- Rotates old logs (keeps last 30 days)

**Used by:** Cron jobs (see config/aggregator-cron.txt)

**Don't call directly** - Let cron handle it

---

## 🛠️ Maintenance Scripts

Scripts for system maintenance, monitoring, and health checks.

### backup-migrate.sh ⭐ **IMPORTANT**

**Purpose:** Complete backup and migration tool

**Usage:**
```bash
# Create full backup
./scripts/maintenance/backup-migrate.sh backup

# Restore from backup
./scripts/maintenance/backup-migrate.sh restore <backup-file.tar.gz>

# Quick database export (SQL only)
./scripts/maintenance/backup-migrate.sh quick-export

# Show migration instructions
./scripts/maintenance/backup-migrate.sh migrate
```

**What it backs up:**
- Complete database (SQL dump)
- All PHP code
- Configuration files
- Environment files
- Apache configs
- Logs (last 7 days)

**Backup location:** `backups/folyoaggregator-backup-YYYY-MM-DD-HHMMSS.tar.gz`

**Restore process:**
1. Extracts backup
2. Imports database
3. Restores files
4. Regenerates configs
5. Sets permissions

**When to run:**
- Daily (via cron at 3 AM)
- Before major updates
- Before migrations
- Before system changes

**CRITICAL for production!**

---

### health-check.php

**Purpose:** Monitor system health and report status

**Usage:**
```bash
# Human-readable output
php scripts/maintenance/health-check.php

# JSON output (for monitoring tools)
php scripts/maintenance/health-check.php --json
```

**What it checks:**
1. **Database connectivity** - Can connect to database
2. **Data freshness** - Prices updated recently (<5 min)
3. **Exchange health** - % of exchanges operational
4. **Historical data** - Complete data coverage
5. **Disk space** - Available storage

**Status levels:**
- `healthy` - All systems operational (exit code 0)
- `warning` - Some issues detected (exit code 1)
- `unhealthy` - Critical issues (exit code 1)

**Output example:**
```
╔═══════════════════════════════════════════════════════════╗
║         FolyoAggregator Health Check Report               ║
╠═══════════════════════════════════════════════════════════╣
║ Timestamp: 2025-11-01 15:30:00 UTC                        ║
║ Overall Status: HEALTHY                                   ║
╚═══════════════════════════════════════════════════════════╝

[✓] Database
    Status: Healthy
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
    Message: 10/10 exchanges operational
```

**When to run:**
- On-demand for troubleshooting
- Via cron every 5 minutes
- Before deployments
- After updates

**Integration:** Can be used with monitoring tools (Nagios, Zabbix, etc.) via `--json` flag

---

### generate-stats.php

**Purpose:** Generate comprehensive daily statistics report

**Usage:**
```bash
# Display report
php scripts/maintenance/generate-stats.php

# Save report to logs/daily-stats/
php scripts/maintenance/generate-stats.php --save
```

**What it reports:**
1. **Assets Overview** - Total assets, market cap, volume
2. **Price Collection** - Collections in last 24h by exchange
3. **Historical Data** - Total candles, coverage by timeframe
4. **TOP 10 Coverage** - Detailed coverage for top cryptocurrencies
5. **Top Performers** - Biggest gainers and losers (24h)
6. **Exchange Health** - Status of all 10 exchanges
7. **Database Size** - Total size and breakdown by table

**Output format:** Beautiful ASCII table report

**Saved reports:** `logs/daily-stats/YYYY-MM-DD.txt`

**When to run:**
- Daily via cron (2 AM)
- On-demand for reports
- Before/after major updates

**Perfect for:**
- Daily monitoring
- Performance tracking
- Capacity planning
- Status reports

---

### system-stats.php

**Purpose:** Quick system statistics overview

**Usage:**
```bash
php scripts/maintenance/system-stats.php
```

**What it shows:**
- Total assets and coverage
- Database statistics
- Collection performance
- Cost comparison vs CMC
- Readiness status

**Simpler than generate-stats.php** - Good for quick checks

**When to run:** Ad-hoc status checks

---

### check-migration-ready.php

**Purpose:** Verify if system is ready to replace CoinMarketCap

**Usage:**
```bash
php scripts/maintenance/check-migration-ready.php
```

**Checks:**
- [✓] Database setup complete
- [✓] Assets synced (>100)
- [✓] Historical data collected (>100K candles)
- [✓] Price collection active (<5 min old)
- [✓] Exchanges operational (>80%)
- [✓] Metadata complete (>90%)
- [✓] API endpoints working

**Output:**
```
╔═══════════════════════════════════════════════════════════╗
║         Migration Readiness Check                         ║
╠═══════════════════════════════════════════════════════════╣
║ Status: READY ✓                                           ║
╠═══════════════════════════════════════════════════════════╣
║ [✓] Database: 151 assets                                  ║
║ [✓] Historical: 421,983 candles                           ║
║ [✓] Exchanges: 10/10 operational                          ║
║ [✓] Price freshness: 0.5 minutes                          ║
║ [✓] Metadata: 98.7% complete                              ║
╚═══════════════════════════════════════════════════════════╝

✓ System is READY to replace CoinMarketCap!
```

**When to run:** Before migrating from CMC to FolyoAggregator

---

### monitor-collectors.sh

**Purpose:** Real-time monitoring dashboard for data collection

**Usage:**
```bash
./scripts/maintenance/monitor-collectors.sh
```

**What it shows:**
- Real-time price collection status
- Active collectors (PID, uptime)
- Recent logs
- Database growth
- Exchange status

**Updates:** Auto-refreshes every 5 seconds

**When to run:** During data collection to monitor progress

**Tip:** Use in a separate terminal while running collectors

---

## ⚙️ Configuration Files

### aggregator-cron.txt

**Purpose:** Complete cron configuration for automated maintenance

**Usage:**
```bash
# View configuration
cat scripts/config/aggregator-cron.txt

# Install cron jobs
crontab scripts/config/aggregator-cron.txt

# View active cron jobs
crontab -l
```

**Configured jobs:**

1. **Price Collection** (every minute)
   ```cron
   * * * * * php scripts/collection/price-collector.php --limit=50
   ```

2. **CMC Quick Sync** (every hour)
   ```cron
   0 * * * * php scripts/setup/sync-cmc.php --limit=200
   ```

3. **CMC Full Sync** (daily at 1 AM)
   ```cron
   0 1 * * * php scripts/setup/sync-cmc.php --limit=200 --force
   ```

4. **Metadata Sync** (daily at 2 AM)
   ```cron
   0 2 * * * php scripts/setup/sync-metadata.php
   ```

5. **Daily Statistics** (daily at 2:30 AM)
   ```cron
   30 2 * * * php scripts/maintenance/generate-stats.php --save
   ```

6. **Daily Backup** (daily at 3 AM)
   ```cron
   0 3 * * * ./scripts/maintenance/backup-migrate.sh backup
   ```

7. **Health Check** (every 5 minutes)
   ```cron
   */5 * * * * php scripts/maintenance/health-check.php --json
   ```

8. **Database Optimization** (weekly, Sunday at 4 AM)
   ```cron
   0 4 * * 0 mysql -u folyo_user -p'password' folyoaggregator -e "OPTIMIZE TABLE historical_ohlcv, prices, aggregated_prices"
   ```

9. **Log Rotation** (daily at 5 AM)
   ```cron
   0 5 * * * find logs/ -name "*.log" -mtime +30 -delete
   ```

**IMPORTANT:** Edit paths and credentials before installing!

---

## 🔧 Utility Scripts

### estimate-completion.php

**Purpose:** Calculate time estimates for historical data collection

**Usage:**
```bash
php scripts/utils/estimate-completion.php
```

**What it calculates:**
- Time per asset
- Total time for different tiers
- Parallelization benefits
- ETA for completion

**Output example:**
```
Historical Collection Time Estimates
────────────────────────────────────
Single asset (8 years): ~2 minutes
TOP 50: ~100 minutes (1.67 hours)
TOP 200: ~400 minutes (6.67 hours)

With 4 parallel collectors:
TOP 50: ~25 minutes
TOP 200: ~100 minutes
```

**When to use:** Planning historical collection strategy

---

## 📦 Obsolete Scripts

The `obsolete/` directory contains 16 archived scripts that are no longer recommended:

**Why obsolete:**
- Superseded by better versions (e.g., paginated collectors)
- One-time use scripts (range-specific collectors)
- Demo/educational scripts
- Redundant functionality

**Scripts in obsolete/:**
- collect-full-history.php (use paginated version)
- collect-1year-history.php (redundant)
- Various bash orchestration scripts
- Range-specific collectors (201-300, 301-500)
- Old crontab.txt (use aggregator-cron.txt)

**Can they be deleted?** Yes, but kept for reference

---

## 🎯 Common Workflows

### New Installation
```bash
1. php scripts/setup/migrate.php
2. php scripts/setup/sync-cmc.php --limit=200
3. php scripts/setup/sync-metadata.php
4. php scripts/collection/collect-full-history-paginated.php
5. ./scripts/daemon/price-daemon.sh start
6. crontab scripts/config/aggregator-cron.txt
```

### Daily Maintenance (Automated)
```
Cron handles:
- Price collection (every minute)
- CMC sync (hourly)
- Health checks (every 5 min)
- Daily backup (3 AM)
- Statistics (2:30 AM)
```

### Manual Health Check
```bash
php scripts/maintenance/health-check.php
php scripts/maintenance/system-stats.php
./scripts/maintenance/monitor-collectors.sh
```

### Backup Before Update
```bash
./scripts/maintenance/backup-migrate.sh backup
# Update code
php scripts/setup/migrate.php  # Run new migrations
php scripts/maintenance/check-migration-ready.php
```

### Troubleshooting Data Issues
```bash
# Check what's missing
php scripts/maintenance/check-migration-ready.php

# Re-sync assets
php scripts/setup/sync-cmc.php --limit=200 --force

# Collect missing history
php scripts/collection/collect-full-history-paginated.php

# Verify health
php scripts/maintenance/health-check.php
```

---

## 📊 Script Execution Order (Dependency Graph)

```
Initial Setup:
├─ migrate.php (creates database)
├─ sync-cmc.php (fetches assets)
│   └─ sync-metadata.php (detailed metadata)
└─ collect-full-history-paginated.php (historical data)

Continuous Operation:
├─ price-collector.php (real-time prices)
│   └─ price-daemon.sh (daemon manager)
└─ cron jobs via aggregator-cron.txt

Maintenance:
├─ backup-migrate.sh (backups)
├─ health-check.php (monitoring)
├─ generate-stats.php (reports)
└─ system-stats.php (quick status)
```

---

## 🆘 Getting Help

**Script not working?**

1. Check logs: `tail -f logs/<script-name>.log`
2. Run health check: `php scripts/maintenance/health-check.php`
3. Verify setup: `php scripts/maintenance/check-migration-ready.php`
4. Check cron logs: `grep CRON /var/log/syslog`

**Common issues:**

| Issue | Solution |
|-------|----------|
| "Access denied" | Check .env database credentials |
| "CMC API error" | Verify CMC_API_KEY in .env |
| "Exchange timeout" | Normal - system retries automatically |
| "No historical data" | Run collect-full-history-paginated.php |
| "Cron not running" | Check crontab -l and /var/log/syslog |

---

## 📝 Best Practices

1. **Always backup before updates**
   ```bash
   ./scripts/maintenance/backup-migrate.sh backup
   ```

2. **Run health checks regularly**
   ```bash
   php scripts/maintenance/health-check.php
   ```

3. **Monitor logs**
   ```bash
   tail -f logs/price-collector.log
   tail -f logs/cmc-sync.log
   ```

4. **Test in staging first**
   - Clone production data
   - Test scripts
   - Verify results

5. **Use recommended scripts**
   - ⭐ collect-full-history-paginated.php (NOT collect-full-history.php)
   - ⭐ aggregator-cron.txt (NOT crontab.txt)
   - ⭐ backup-migrate.sh (daily!)

---

**For quick start guide, see: [QUICKSTART.md](QUICKSTART.md)**
**For API documentation, see: [../README.md](../README.md)**
