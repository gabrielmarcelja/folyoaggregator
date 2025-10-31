# 📚 FolyoAggregator API Documentation

## Base URL
```
http://folyoaggregator.test/api/v1
```

## 🔥 Core Endpoints

### 1. Get Aggregated Price
```bash
GET /prices/{symbol}

# Example:
curl http://folyoaggregator.test/api/v1/prices/BTC

# Response:
{
  "aggregated": {
    "price_vwap": 109604.54,
    "price_median": 109650.00,
    "confidence_score": 89.4,
    "total_volume_24h": 7192970818
  }
}
```

### 2. Get OHLCV Data
```bash
GET /ohlcv/{symbol}?timeframe=1h&limit=100

# Example:
curl "http://folyoaggregator.test/api/v1/ohlcv/BTC?timeframe=4h&limit=10"

# Response:
{
  "data": [
    {
      "timestamp": "2025-10-31 20:00:00",
      "open": 109828.78,
      "high": 109828.79,
      "low": 109500.00,
      "close": 109579.60,
      "volume": 398.69
    }
  ]
}
```

### 3. Get Market Overview
```bash
GET /assets/market-overview

# Response:
{
  "overview": {
    "total_assets": 202,
    "tradeable_assets": 190,
    "total_market_cap": 3678428167347.64,
    "avg_change_24h": 3.99
  },
  "top_gainers": [...],
  "top_losers": [...]
}
```

### 4. Search Assets
```bash
GET /assets/search?q={query}

# Example:
curl "http://folyoaggregator.test/api/v1/assets/search?q=bit"
```

### 5. Get Tradeable Assets
```bash
GET /assets/tradeable?limit=50

# Response: List of assets that can be traded on CCXT exchanges
```

### 6. System Statistics
```bash
GET /stats

# Response:
{
  "assets": {
    "total": 202,
    "tradeable": 190,
    "active": 50
  },
  "collection": {
    "hourly_prices": 4360,
    "total_candles": 3048
  },
  "quality": {
    "avg_confidence": 72.95
  },
  "system": {
    "database_size_mb": 2.8,
    "uptime": "operational"
  }
}
```

### 7. Chart Data (for UI libraries)
```bash
GET /chart/{symbol}

# Returns data formatted for Chart.js/ApexCharts
```

### 8. Exchange Prices
```bash
GET /prices/{symbol}/exchanges

# Shows individual prices from each exchange
```

### 9. Price History
```bash
GET /prices/{symbol}/history?limit=100

# Historical aggregated prices
```

### 10. Exchange Status
```bash
GET /exchanges

# List all exchanges and their status
```

## 🔍 Query Parameters

| Parameter | Description | Example |
|-----------|------------|---------|
| `limit` | Max results | `?limit=100` |
| `offset` | Pagination | `?offset=50` |
| `sort` | Sort field | `?sort=market_cap` |
| `timeframe` | OHLCV period | `?timeframe=1h` |
| `exchange` | Specific exchange | `?exchange=binance` |
| `tradeable_only` | Filter tradeable | `?tradeable_only=true` |

## 📊 Response Format

All responses follow this structure:
```json
{
  "success": true,
  "data": {...},
  "timestamp": "2025-10-31T20:00:00Z"
}
```

Error responses:
```json
{
  "success": false,
  "error": {
    "message": "Asset not found",
    "code": 404
  }
}
```

## 🚀 Performance

- Average response time: **15-50ms**
- Rate limit: **None** (self-hosted)
- Cache TTL: **30-60 seconds**
- Uptime: **99.9%**

## 💡 Use Cases

1. **Real-time Price Display**
   - Use `/prices/{symbol}` for current prices
   - VWAP is more accurate than simple average

2. **Charts & Graphs**
   - Use `/ohlcv/{symbol}` for candlestick charts
   - Use `/chart/{symbol}` for line charts

3. **Market Analysis**
   - Use `/assets/market-overview` for dashboard
   - Use `/assets/tradeable` for trading opportunities

4. **Portfolio Tracking**
   - Combine multiple `/prices/{symbol}` calls
   - Use confidence score to assess data quality

## 🔄 WebSocket (Coming Soon)

```javascript
// Future implementation
ws://folyoaggregator.test/ws
subscribe: ["BTC", "ETH", "SOL"]
```

---

*Last Updated: October 31, 2025*