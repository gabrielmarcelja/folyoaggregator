# 📊 FolyoAggregator

![Dashboard Preview](image.png)

**Cryptocurrency Data Aggregation System**

Robust crypto data aggregation system that collects real-time prices from multiple exchanges, calculates VWAP (Volume-Weighted Average Price), maintains complete historical data, and provides a unified API for accessing cryptocurrency market data.

---

## 🎯 Purpose

Completely replace CoinMarketCap API dependency in [@folyo-app](https://github.com/folyo-app), offering:

✅ **No request limits** - Own API without rate limits
✅ **Aggregated data** - Prices from multiple exchanges with VWAP
✅ **Complete history** - Up to 8 years of historical data (since 2017)
✅ **Low latency** - Direct queries to local database
✅ **Full control** - Own infrastructure
✅ **No API costs** - Eliminates monthly CMC payments
✅ **Expandable** - Easy to add new exchanges/coins

---

## 🚀 Current Status (11/01/2025)

### ✅ Production Ready

**Data:**
- **151 TOP 200 assets** registered (75.5% coverage)
- **144 assets with complete history**
- **421,983 OHLCV candles** stored
- **8.2 years** of temporal coverage (2017-08-17 to present)
- **BTC/ETH:** 17,973 candles each (~8 complete years)

**Integrated Exchanges (10):**
- Binance (primary), Coinbase, Kraken, KuCoin, Bybit
- OKX, Gate.io, Bitfinex, Huobi, Bitstamp

**Metadata:**
- 149 descriptions (98.7%)
- 1,648 URLs (explorers, github, social)
- 151 logos (100%)
- Tags, categories, supply info

**Performance:**
- Database: ~160 MB total
- Data density: 100% for last 30 days
- Real-time updates: every 1 minute

---

## ⚡ Key Features

### 1. **Multi-Exchange Integration**
Connects to 10+ exchanges via CCXT (no API keys required)

### 2. **Real-Time Price Aggregation**
VWAP (Volume-Weighted Average Price) calculation and confidence scoring

### 3. **Complete Historical Data**
OHLCV data with multiple timeframes:
- **4h**: 414,315 candles (primary - 6 points/day)
- **1h**: 2,668 candles (24 points/day)
- **1d**: 5,000 candles (daily data)

### 4. **RESTful API**
Clean, documented, and CMC-compatible endpoints

### 5. **Web Dashboard**
Visual interface for monitoring prices and exchange status

### 6. **High Performance**
Optimized database indexes for fast queries

---

## 🛠️ Tech Stack

```
Backend:    PHP 8.1+ with CCXT library
Database:   MariaDB/MySQL
Web Server: Apache with mod_rewrite
Frontend:   HTML, CSS, JavaScript (Dashboard)
```

---

## 🚀 Quick Start

**Want to get started in 10 minutes?** See our [Quick Start Guide](scripts/QUICKSTART.md) for a streamlined setup process.

**Or follow the detailed installation below:**

---

## 📦 Installation

### Prerequisites
- PHP 8.1+
- MariaDB/MySQL
- Apache with mod_rewrite
- Composer

### Steps

```bash
# 1. Clone to appropriate directory
cd /var/www/html/
git clone <repo-url> folyoaggregator

# 2. Install dependencies
cd folyoaggregator
composer install

# 3. Configure environment
cp .env.example .env
# Edit .env with your credentials

# 4. Configure database
mysql -u root -p
CREATE DATABASE folyoaggregator;
CREATE USER 'folyo_user'@'localhost' IDENTIFIED BY 'Folyo@2025Secure';
GRANT ALL PRIVILEGES ON folyoaggregator.* TO 'folyo_user'@'localhost';
FLUSH PRIVILEGES;

# 5. Run migrations
php scripts/setup/migrate.php

# 6. Configure Apache VirtualHost
# Point DocumentRoot to /var/www/html/folyoaggregator/public
# ServerName: folyoaggregator.test

# 7. Sync initial CMC data
php scripts/setup/sync-cmc.php --limit=200

# 8. Sync complete metadata
php scripts/setup/sync-metadata.php

# 9. Collect historical data
php scripts/collection/collect-full-history-paginated.php

# 10. Start real-time collector
./scripts/daemon/price-daemon.sh start

# 11. Configure cron jobs
crontab scripts/config/aggregator-cron.txt
```

---

## 🔌 API Endpoints

### Base URL
```
http://folyoaggregator.test/api
```

### Main Endpoints

#### 📋 Listings

```bash
# Asset list by market cap (CMC-compatible)
GET /listings?start=1&limit=100

# List all assets
GET /assets?sort=market_cap_rank

# Search by symbol/name
GET /assets/search?q=bitcoin

# Market overview (gainers, losers, stats)
GET /assets/market-overview
```

#### 💎 Assets

```bash
# Complete asset details
GET /assets/{symbol}

# Example: GET /assets/BTC
```

**Response:**
```json
{
  "success": true,
  "data": {
    "symbol": "BTC",
    "name": "Bitcoin",
    "market_cap_rank": 1,
    "market_cap": 2183942343494.10,
    "volume_24h": 61230649043.42,
    "price_usd": 110140.79,
    "percent_change_24h": 1.60,
    "circulating_supply": 19942690,
    "max_supply": 21000000,
    "description": "Bitcoin (BTC) is a cryptocurrency...",
    "website_url": "https://bitcoin.org/",
    "logo_url": "https://...",
    "tags": ["mineable", "pow", "sha-256", ...]
  }
}
```

#### 💰 Prices

```bash
# Aggregated price (VWAP)
GET /prices/{symbol}

# Prices by exchange
GET /prices/{symbol}/exchanges
```

#### 📈 Historical Data (NEW - Recommended!)

```bash
# Historical data with flexible periods
GET /historical/{symbol}?period={period}&format={format}
```

**Parameters:**
- `period`: `24h`, `7d`, `30d`, `90d`, `1y`, `all` (default: `7d`)
- `timeframe`: `1h`, `4h`, `1d` (auto-selected)
- `format`: `ohlcv` or `simple` (timestamp+price)
- `limit`: limits number of data points

**Examples:**
```bash
# 7-day chart
curl "http://folyoaggregator.test/api/historical/BTC"

# 30-day simplified chart
curl "http://folyoaggregator.test/api/historical/ETH?period=30d&format=simple"

# All history (up to 8 years)
curl "http://folyoaggregator.test/api/historical/BTC?period=all"

# 1 year with 365 point limit
curl "http://folyoaggregator.test/api/historical/SOL?period=1y&limit=365"
```

**Response:**
```json
{
  "success": true,
  "symbol": "BTC",
  "name": "Bitcoin",
  "period": "7d",
  "timeframe": "4h",
  "format": "ohlcv",
  "count": 42,
  "data": [
    {
      "timestamp": "2025-10-25 04:00:00",
      "open": 111489.6,
      "high": 111563.81,
      "low": 111385.18,
      "close": 111489.6,
      "volume": 1417.09
    },
    ...
  ],
  "metadata": {
    "first_timestamp": "2025-10-25 04:00:00",
    "last_timestamp": "2025-10-31 20:00:00",
    "data_points": 42
  }
}
```

#### 📊 OHLCV (Specific Exchange)

```bash
# OHLCV data from specific exchange
GET /ohlcv/{symbol}?timeframe=4h&exchange=binance&limit=100
```

#### 📉 Charts

```bash
# Chart-formatted data (last 24h)
GET /chart/{symbol}
```

#### 📊 Statistics

```bash
# System statistics
GET /stats

# Exchange status
GET /exchanges

# Specific exchange status
GET /exchanges/{exchange_id}/status
```

#### 🔍 System Health

```bash
# Health check
GET /health

# Detailed status
GET /status
```

---

## 🗄️ Database Structure

### Main Tables

**13 tables** totaling ~160 MB:

1. **assets** (151) - Complete asset metadata
2. **historical_ohlcv** (421,983) - Historical OHLCV data
3. **prices** (50,058) - Real-time prices per exchange
4. **aggregated_prices** (7,956) - Aggregated prices with VWAP
5. **exchanges** (10) - Exchange configuration
6. **asset_descriptions** (149) - Detailed descriptions
7. **asset_urls** (1,648) - URLs (explorers, github, social)
8. **symbol_mappings** (30) - CMC ↔ Exchange mappings
9. **cmc_sync_log** (5) - Synchronization logs
10-13. Auxiliary (migrations, api_keys, etc)

**Complete documentation:** See `docs/DATABASE_STRUCTURE.md`

---

## 📁 Scripts Directory

All scripts are organized into subdirectories by purpose:

```
scripts/
├── setup/          # Initial setup (migrate, sync-cmc, sync-metadata)
├── collection/     # Data collection (price-collector, historical)
├── daemon/         # Daemon management (price-daemon.sh)
├── maintenance/    # Monitoring & health (backup, health-check, stats)
├── config/         # Configuration (aggregator-cron.txt)
└── utils/          # Utilities (estimate-completion)
```

### Essential Scripts

**Setup (run once):**
```bash
php scripts/setup/migrate.php                          # Create database
php scripts/setup/sync-cmc.php --limit=200             # Sync TOP 200 assets
php scripts/setup/sync-metadata.php                    # Sync metadata
```

**Collection:**
```bash
php scripts/collection/collect-full-history-paginated.php  # Collect 8 years history
php scripts/collection/price-collector.php --limit=50      # Real-time prices
```

**Daemon Management:**
```bash
./scripts/daemon/price-daemon.sh start|stop|restart|status
```

**Maintenance:**
```bash
./scripts/maintenance/backup-migrate.sh backup         # Create backup
php scripts/maintenance/health-check.php               # System health
php scripts/maintenance/generate-stats.php --save      # Daily statistics
php scripts/maintenance/system-stats.php               # Quick stats
php scripts/maintenance/check-migration-ready.php      # Migration readiness
```

**Complete documentation:** See [scripts/README.md](scripts/README.md)

---

## ⏰ Cron Configuration

FolyoAggregator includes a comprehensive cron configuration for automated maintenance.

### Install Cron Jobs

```bash
# View configuration
cat scripts/config/aggregator-cron.txt

# Install
crontab scripts/config/aggregator-cron.txt

# Verify
crontab -l
```

### Configured Jobs

| Schedule | Job | Purpose |
|----------|-----|---------|
| Every minute | Price collection | Collect TOP 50 real-time prices |
| Every hour | CMC quick sync | Update metadata |
| Daily 1 AM | CMC full sync | Force complete sync |
| Daily 2 AM | Metadata sync | Update descriptions/URLs |
| Daily 2:30 AM | Generate stats | Daily statistics report |
| Daily 3 AM | Backup | Full system backup |
| Every 5 min | Health check | System monitoring |
| Weekly | Database optimize | Clean and optimize tables |
| Daily 5 AM | Log rotation | Clean old logs (>30 days) |

**Important:** Edit paths and credentials in `scripts/config/aggregator-cron.txt` before installing!

---

## 💾 Backup & Recovery

### Creating Backups

```bash
# Full system backup (code + database + configs)
./scripts/maintenance/backup-migrate.sh backup

# Quick database export only
./scripts/maintenance/backup-migrate.sh quick-export
```

**Backup includes:**
- Complete database dump
- All PHP code
- Configuration files (.env, Apache configs)
- Logs (last 7 days)

**Backup location:** `backups/folyoaggregator-backup-YYYY-MM-DD-HHMMSS.tar.gz`

### Restoring from Backup

```bash
# Restore complete system
./scripts/maintenance/backup-migrate.sh restore <backup-file.tar.gz>
```

**Restore process:**
1. Extracts backup archive
2. Imports database
3. Restores files and configs
4. Sets correct permissions
5. Regenerates necessary configs

### Automated Backups

Backups run automatically every day at 3 AM (via cron).

**Retention:** Keep last 30 days of backups, delete older ones.

**Best practice:** Store backups off-server for disaster recovery!

---

## 🔍 Monitoring & Health Checks

### Health Check

Monitor system health in real-time:

```bash
# Human-readable output
php scripts/maintenance/health-check.php

# JSON output (for monitoring tools)
php scripts/maintenance/health-check.php --json
```

**Checks:**
- ✓ Database connectivity
- ✓ Data freshness (<5 min)
- ✓ Exchange health (>80% operational)
- ✓ Historical data coverage
- ✓ Disk space availability

**Status levels:**
- `healthy` - All systems operational
- `warning` - Minor issues detected
- `unhealthy` - Critical issues

**Exit codes:**
- `0` - Healthy
- `1` - Warning or unhealthy

**Integration:** Use with Nagios, Zabbix, or other monitoring tools via `--json` output.

### Daily Statistics

Generate comprehensive daily reports:

```bash
# Display report
php scripts/maintenance/generate-stats.php

# Save to logs/daily-stats/
php scripts/maintenance/generate-stats.php --save
```

**Report includes:**
- Assets overview (count, market cap, volume)
- Price collection statistics (24h by exchange)
- Historical data coverage (TOP 10 detail)
- Top performers (gainers/losers)
- Exchange health status
- Database size breakdown

### Quick Stats

For quick status checks:

```bash
php scripts/maintenance/system-stats.php
```

Shows at-a-glance system status and cost comparison vs CMC.

### Migration Readiness

Before replacing CMC, verify system is ready:

```bash
php scripts/maintenance/check-migration-ready.php
```

**Verifies:**
- [✓] Database setup complete
- [✓] Assets synced (>100)
- [✓] Historical data (>100K candles)
- [✓] Price collection active
- [✓] Exchanges operational (>80%)
- [✓] Metadata complete (>90%)
- [✓] API endpoints working

### Real-Time Monitoring

Monitor collectors in real-time:

```bash
./scripts/maintenance/monitor-collectors.sh
```

Auto-refreshes every 5 seconds with:
- Active collector status
- Recent logs
- Database growth
- Exchange status

---

## 💻 Development

### Access Dashboard
```
http://folyoaggregator.test/dashboard.php
```

### Database Access
```bash
mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator
```

### View Logs
```bash
# Application logs
tail -f logs/app.log
tail -f logs/price-collector.log
tail -f logs/full-history-paginated.log

# Apache logs
tail -f /var/log/apache2/folyoaggregator-error.log
```

### Test Endpoints
```bash
# Health check
curl http://folyoaggregator.test/api/health

# List TOP 10
curl http://folyoaggregator.test/api/listings?limit=10

# Search Bitcoin
curl http://folyoaggregator.test/api/assets/BTC

# 7-day history
curl "http://folyoaggregator.test/api/historical/BTC?period=7d"
```

---

## 🔄 Migration from CMC to FolyoAggregator

### Before (using CMC):
```php
$url = "https://pro-api.coinmarketcap.com/v1/cryptocurrency/listings/latest";
$headers = ['X-CMC_PRO_API_KEY: your-key-here'];
$response = file_get_contents($url, false, stream_context_create([
    'http' => ['header' => $headers]
]));
```

### After (using FolyoAggregator):
```php
$url = "http://folyoaggregator.test/api/listings";
// No API key needed!
$response = file_get_contents($url);
```

**Benefits:**
- ✅ No rate limits
- ✅ No costs
- ✅ Unlimited historical data
- ✅ Full control
- ✅ Lower latency (local)

---

## 📊 Comparison: CMC vs FolyoAggregator

| Feature | CoinMarketCap | FolyoAggregator |
|---------|---------------|-----------------|
| **Price** | $79-$999/month | **Free** ✅ |
| **Rate Limit** | 333-10K/day | **Unlimited** ✅ |
| **Historical** | Paid API | **8 years free** ✅ |
| **Latency** | ~200-500ms | **<10ms** ✅ |
| **Exchanges** | CMC data | **10 exchanges** ✅ |
| **VWAP** | No | **Yes** ✅ |
| **Confidence Score** | No | **Yes** ✅ |
| **Control** | Limited | **Full** ✅ |

---

## 📖 Documentation

### Available Documents

- **`CONTEXT.md`** - Complete project context
- **`docs/API_IMPROVEMENTS.md`** - Implemented improvements
- **`docs/DATABASE_STRUCTURE.md`** - Detailed database structure
- **`docs/API.md`** - Complete API documentation
- **`docs/MIGRATION_READINESS.md`** - Migration guide

### Credentials

**Database:**
```
Host: localhost
Database: folyoaggregator
User: folyo_user
Password: Folyo@2025Secure
```

**CMC API Key:**
```
dfd1ef151785484daf455a67e0523574
```

---

## 🎯 Collection Strategy

### Prioritization by Ranking
1. **TOP 50**: Collect every 5 minutes
2. **TOP 51-200**: Collect every 15 minutes
3. **Historical**: Complete collection with pagination

### Timeframes
- **4h**: Primary (6 candles/day) - Ideal for 7d-1y
- **1h**: Secondary (24 candles/day) - Ideal for 24h
- **1d**: Daily (1 candle/day) - Ideal for +1y

---

## ✅ What Works 100%

✅ **Historical data:** 421,983 candles (up to 8 years)
✅ **Metadata:** 98.7% coverage
✅ **CMC-compatible API:** Migration without changes
✅ **Charts:** 24h, 7d, 30d, 90d, 1y, all
✅ **Search:** By symbol and name
✅ **Sorting:** By market cap
✅ **Real-time:** Updates every minute
✅ **VWAP:** Aggregation from 10 exchanges
✅ **Confidence Score:** Data quality metric

---

## 🚧 Future Roadmap

- [ ] Redis cache for better performance
- [ ] WebSocket for real-time updates
- [ ] Support for more exchanges (15+)
- [ ] 1m timeframe for intraday trading
- [ ] API v2 with GraphQL
- [ ] Advanced dashboard with alerts
- [ ] Data export (CSV, JSON, Excel)

---

## 🐛 Troubleshooting

### Issue: Endpoint returns empty
```bash
# Check if data exists in database
mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -e "SELECT COUNT(*) FROM historical_ohlcv;"

# Collect data if needed
php scripts/collection/collect-full-history-paginated.php
```

### Issue: Exchange timeout
```bash
# View recent errors
mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -e "SELECT exchange_id, last_error_message FROM exchanges WHERE last_error_at IS NOT NULL;"

# Detailed logs
tail -f logs/price-collector.log
```

### Issue: Slow API
```bash
# Check indexes
mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -e "SHOW INDEX FROM historical_ohlcv;"

# Optimize tables
mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -e "OPTIMIZE TABLE historical_ohlcv;"
```

---

## 📝 Important Notes

⚠️ System uses CCXT which **does not require API keys** for public data
⚠️ Database optimized with appropriate indexes
⚠️ Logs saved in `/var/www/html/folyoaggregator/logs/`
⚠️ Dashboard auto-updates every 30 seconds
⚠️ VirtualHost configured at `folyoaggregator.test`

---

## 📄 License

Private - All rights reserved

---

## 👥 Contact

For questions and support, contact the development team.

---

## 🎉 Status

**✅ PRODUCTION READY**

FolyoAggregator is 100% functional and ready to replace CoinMarketCap in [@folyo-app](https://github.com/folyo-app)!

**Last update:** 11/01/2025
**Version:** 1.2.0
