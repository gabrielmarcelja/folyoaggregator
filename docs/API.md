# FolyoAggregator API Documentation

## Base URL
```
http://folyoaggregator.test/api
```

## Endpoints

### 1. Market Listings (CMC-Compatible)
**GET** `/listings`

Returns cryptocurrency listings ordered by market cap rank, compatible with CoinMarketCap format.

**Parameters:**
- `start` (optional): Starting rank (default: 1)
- `limit` (optional): Number of results (default: 100, max: 500)
- `convert` (optional): Currency conversion (default: USD)

**Example:**
```bash
curl "http://folyoaggregator.test/api/listings?limit=10"
```

### 2. Asset Price (Aggregated)
**GET** `/prices/{symbol}`

Returns aggregated price data from multiple exchanges with VWAP calculation.

**Example:**
```bash
curl "http://folyoaggregator.test/api/prices/BTC"
```

**Response includes:**
- Simple average price
- VWAP (Volume-Weighted Average Price)
- Median price
- Min/Max prices
- Price spread
- Total 24h volume
- Exchange count
- Confidence score
- Individual exchange prices

### 3. Historical OHLCV
**GET** `/ohlcv/{symbol}`

Returns historical OHLCV data for a specific asset.

**Parameters:**
- `timeframe`: 1h, 4h, 1d, 1w (default: 4h)
- `limit`: Number of candles (default: 100)
- `start_date`: Start date (YYYY-MM-DD)
- `end_date`: End date (YYYY-MM-DD)

**Example:**
```bash
curl "http://folyoaggregator.test/api/ohlcv/BTC?timeframe=4h&limit=50"
```

### 4. Chart Data
**GET** `/chart/{symbol}`

Returns chart-ready data optimized for visualization.

**Parameters:**
- `timeframe`: 1h, 4h, 1d, 1w
- `days`: Number of days (1, 7, 30, 90, 365)

### 5. System Statistics
**GET** `/stats`

Returns system statistics including:
- Total assets tracked
- Total price records
- Total exchanges
- Database size
- Collection status

### 6. Health Check
**GET** `/health`

Returns API health status.

### 7. Market Overview
**GET** `/assets/market-overview`

Returns market overview statistics:
- Total market cap
- 24h volume
- Market dominance
- Top gainers/losers

### 8. Asset Search
**GET** `/assets/search`

Search for assets by name or symbol.

**Parameters:**
- `q`: Search query (min 2 characters)
- `limit`: Results limit (default: 20)

### 9. Asset Details
**GET** `/assets/{symbol}`

Returns detailed information about a specific asset including:
- Basic info (name, symbol, rank)
- Price data
- Market data
- Metadata (description, links, tags)
- Platform information

### 10. Exchange Status
**GET** `/exchanges/{exchange_id}/status`

Check the status of a specific exchange.

## Response Format

All responses follow this format:

```json
{
  "success": true,
  "data": { ... },
  "timestamp": "2025-10-31T23:30:00+00:00"
}
```

Error responses:
```json
{
  "success": false,
  "error": {
    "message": "Error description",
    "code": 404
  },
  "timestamp": "2025-10-31T23:30:00+00:00"
}
```

## Rate Limits
- No rate limits currently implemented
- Recommended: 100 requests per minute

## Authentication
- No authentication required for public endpoints

## CORS
- CORS is enabled for all origins
- Supports GET, POST, OPTIONS methods

## Integration Example (Folyo Platform)

Replace CMC API calls with FolyoAggregator:

```php
// Before (CMC)
$url = "https://pro-api.coinmarketcap.com/v1/cryptocurrency/listings/latest";
$response = fetchWithAPIKey($url, $cmcApiKey);

// After (FolyoAggregator)
$url = "http://folyoaggregator.test/api/listings";
$response = fetch($url);  // No API key needed!
```

## Status

- ✅ **143/202** assets with historical data (70.8%)
- ✅ **75,247** historical candles collected
- ✅ **10** exchanges integrated
- ✅ **Real-time collector** running (5-minute intervals)
- ✅ **CMC-compatible endpoints** ready

## Next Steps

1. Complete Folyo integration
2. Add WebSocket support for real-time updates
3. Implement caching layer
4. Add more exchanges