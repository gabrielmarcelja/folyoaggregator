<?php
/**
 * FolyoAggregator API Entry Point
 * Handles all API requests
 */

// Error reporting for development
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Set timezone
date_default_timezone_set('UTC');

// Load autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

use FolyoAggregator\API\Router;
use FolyoAggregator\Core\Database;
use FolyoAggregator\Exchanges\ExchangeManager;
use FolyoAggregator\Services\PriceAggregator;

// Initialize router
$router = new Router();

// ===========================
// Health & Status Endpoints
// ===========================

$router->get('/health', function() {
    return [
        'success' => true,
        'status' => 'operational',
        'timestamp' => date('c')
    ];
});

$router->get('/status', function() {
    $db = Database::getInstance();

    // Check database connection
    try {
        $db->fetchOne("SELECT 1");
        $dbStatus = 'connected';
    } catch (Exception $e) {
        $dbStatus = 'disconnected';
    }

    // Get exchange statuses
    $exchanges = $db->fetchAll("
        SELECT exchange_id, api_status, last_successful_fetch
        FROM exchanges
        WHERE is_active = 1
    ");

    return [
        'success' => true,
        'database' => $dbStatus,
        'exchanges' => $exchanges,
        'timestamp' => date('c')
    ];
});

// ===========================
// CMC-Compatible Listings Endpoint
// ===========================

// Main listings endpoint - returns top assets by market cap
$router->get('/listings', function() {
    $db = Database::getInstance();

    $start = isset($_GET['start']) ? (int)$_GET['start'] : 1;
    $limit = isset($_GET['limit']) ? min(500, (int)$_GET['limit']) : 100;
    $convert = isset($_GET['convert']) ? $_GET['convert'] : 'USD';

    // Calculate offset from start parameter (CMC uses 1-based indexing)
    $offset = $start - 1;

    $assets = $db->fetchAll("
        SELECT
            a.id,
            a.cmc_id,
            a.symbol,
            a.name,
            a.slug,
            a.market_cap_rank as cmc_rank,
            a.is_active,
            a.circulating_supply,
            a.total_supply,
            a.max_supply,
            a.date_added,
            a.tags,
            a.platform,
            a.price_usd as price,
            a.market_cap,
            a.volume_24h,
            a.percent_change_1h,
            a.percent_change_24h,
            a.percent_change_7d,
            a.percent_change_30d,
            a.percent_change_60d,
            a.percent_change_90d,
            a.fully_diluted_market_cap,
            a.market_cap_dominance,
            a.logo_url,
            a.description,
            a.website_url
        FROM assets a
        WHERE a.is_active = 1
        AND a.market_cap_rank IS NOT NULL
        ORDER BY a.market_cap_rank ASC
        LIMIT ? OFFSET ?
    ", [$limit, $offset]);

    // Transform to CMC-compatible format
    $data = [];
    foreach ($assets as $asset) {
        $data[] = [
            'id' => $asset['cmc_id'] ?? $asset['id'],
            'name' => $asset['name'],
            'symbol' => $asset['symbol'],
            'slug' => $asset['slug'],
            'num_market_pairs' => 100, // Placeholder
            'date_added' => $asset['date_added'],
            'tags' => json_decode($asset['tags'] ?? '[]'),
            'max_supply' => $asset['max_supply'],
            'circulating_supply' => $asset['circulating_supply'],
            'total_supply' => $asset['total_supply'],
            'platform' => $asset['platform'] ? [
                'name' => $asset['platform'],
                'symbol' => $asset['platform']
            ] : null,
            'cmc_rank' => $asset['cmc_rank'],
            'self_reported_circulating_supply' => null,
            'self_reported_market_cap' => null,
            'tvl_ratio' => null,
            'last_updated' => date('c'),
            'quote' => [
                $convert => [
                    'price' => (float)$asset['price'],
                    'volume_24h' => (float)$asset['volume_24h'],
                    'volume_change_24h' => null,
                    'percent_change_1h' => (float)$asset['percent_change_1h'],
                    'percent_change_24h' => (float)$asset['percent_change_24h'],
                    'percent_change_7d' => (float)$asset['percent_change_7d'],
                    'percent_change_30d' => (float)$asset['percent_change_30d'],
                    'percent_change_60d' => (float)$asset['percent_change_60d'],
                    'percent_change_90d' => (float)$asset['percent_change_90d'],
                    'market_cap' => (float)$asset['market_cap'],
                    'market_cap_dominance' => (float)$asset['market_cap_dominance'],
                    'fully_diluted_market_cap' => (float)$asset['fully_diluted_market_cap'],
                    'tvl' => null,
                    'last_updated' => date('c')
                ]
            ]
        ];
    }

    return [
        'status' => [
            'timestamp' => date('c'),
            'error_code' => 0,
            'error_message' => null,
            'elapsed' => 10,
            'credit_count' => 1,
            'notice' => null,
            'total_count' => count($assets)
        ],
        'data' => $data
    ];
});

// ===========================
// Asset Endpoints
// ===========================

// Register specific routes first (before parameterized routes)
$router->get('/assets/search', function() {
    $db = Database::getInstance();

    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    $limit = isset($_GET['limit']) ? min(100, (int)$_GET['limit']) : 20;

    if (strlen($query) < 2) {
        throw new Exception("Search query must be at least 2 characters");
    }

    // Search by symbol or name
    $assets = $db->fetchAll("
        SELECT
            symbol,
            name,
            cmc_id,
            market_cap_rank,
            is_tradeable,
            market_cap,
            icon_url
        FROM assets
        WHERE is_active = 1
        AND (
            symbol LIKE ? OR
            name LIKE ?
        )
        ORDER BY
            CASE
                WHEN symbol = ? THEN 1
                WHEN symbol LIKE ? THEN 2
                WHEN name = ? THEN 3
                WHEN name LIKE ? THEN 4
                ELSE 5
            END,
            market_cap_rank ASC
        LIMIT ?
    ", [
        "%$query%",
        "%$query%",
        $query,
        "$query%",
        $query,
        "$query%",
        $limit
    ]);

    return [
        'success' => true,
        'query' => $query,
        'data' => $assets,
        'count' => count($assets),
        'timestamp' => date('c')
    ];
});

$router->get('/assets/tradeable', function() {
    $db = Database::getInstance();

    $limit = isset($_GET['limit']) ? min(500, (int)$_GET['limit']) : 100;

    $assets = $db->fetchAll("
        SELECT
            a.symbol,
            a.name,
            a.cmc_id,
            a.market_cap_rank,
            a.market_cap,
            a.volume_24h,
            a.percent_change_24h,
            a.icon_url,
            a.preferred_quote_currency,
            JSON_LENGTH(a.tradeable_exchanges) as exchange_count,
            a.tradeable_exchanges
        FROM assets a
        WHERE a.is_active = 1
        AND a.is_tradeable = 1
        ORDER BY a.market_cap_rank ASC
        LIMIT ?
    ", [$limit]);

    // Parse tradeable exchanges JSON
    foreach ($assets as &$asset) {
        $asset['tradeable_exchanges'] = json_decode($asset['tradeable_exchanges'], true) ?? [];
    }

    return [
        'success' => true,
        'data' => $assets,
        'count' => count($assets),
        'timestamp' => date('c')
    ];
});

$router->get('/assets/market-overview', function() {
    $db = Database::getInstance();

    // Get market overview statistics
    $overview = $db->fetchOne("
        SELECT
            COUNT(*) as total_assets,
            SUM(CASE WHEN is_tradeable = 1 THEN 1 ELSE 0 END) as tradeable_assets,
            SUM(market_cap) as total_market_cap,
            SUM(volume_24h) as total_volume_24h,
            AVG(percent_change_24h) as avg_change_24h,
            COUNT(CASE WHEN percent_change_24h > 0 THEN 1 END) as gainers,
            COUNT(CASE WHEN percent_change_24h < 0 THEN 1 END) as losers,
            COUNT(CASE WHEN percent_change_24h = 0 OR percent_change_24h IS NULL THEN 1 END) as unchanged
        FROM assets
        WHERE is_active = 1
        AND market_cap_rank IS NOT NULL
    ");

    // Get top gainers and losers
    $gainers = $db->fetchAll("
        SELECT symbol, name, percent_change_24h, market_cap_rank, icon_url
        FROM assets
        WHERE is_active = 1
        AND percent_change_24h IS NOT NULL
        AND market_cap_rank <= 200
        ORDER BY percent_change_24h DESC
        LIMIT 5
    ");

    $losers = $db->fetchAll("
        SELECT symbol, name, percent_change_24h, market_cap_rank, icon_url
        FROM assets
        WHERE is_active = 1
        AND percent_change_24h IS NOT NULL
        AND market_cap_rank <= 200
        ORDER BY percent_change_24h ASC
        LIMIT 5
    ");

    // Get last sync info
    $lastSync = $db->fetchOne("
        SELECT sync_type, status, coins_processed, start_time, end_time
        FROM cmc_sync_log
        WHERE status = 'completed'
        ORDER BY created_at DESC
        LIMIT 1
    ");

    return [
        'success' => true,
        'data' => [
            'overview' => $overview,
            'top_gainers' => $gainers,
            'top_losers' => $losers,
            'last_sync' => $lastSync
        ],
        'timestamp' => date('c')
    ];
});

$router->get('/assets', function() {
    $db = Database::getInstance();

    // Parse query parameters
    $limit = isset($_GET['limit']) ? min(1000, (int)$_GET['limit']) : 100;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $tradeable_only = isset($_GET['tradeable_only']) ? filter_var($_GET['tradeable_only'], FILTER_VALIDATE_BOOLEAN) : false;
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'market_cap_rank';

    // Build WHERE clause
    $where = ['is_active = 1'];
    $params = [];

    if ($tradeable_only) {
        $where[] = 'is_tradeable = 1';
    }

    // Validate sort field
    $valid_sorts = ['market_cap_rank', 'market_cap', 'volume_24h', 'symbol', 'name', 'percent_change_24h'];
    if (!in_array($sort, $valid_sorts)) {
        $sort = 'market_cap_rank';
    }

    $whereClause = implode(' AND ', $where);

    $assets = $db->fetchAll("
        SELECT
            symbol,
            name,
            slug,
            cmc_id,
            market_cap_rank,
            is_tradeable,
            market_cap,
            volume_24h,
            percent_change_1h,
            percent_change_24h,
            percent_change_7d,
            icon_url,
            preferred_quote_currency
        FROM assets
        WHERE $whereClause
        ORDER BY $sort ASC
        LIMIT ? OFFSET ?
    ", array_merge($params, [$limit, $offset]));

    // Get total count
    $total = $db->fetchOne("
        SELECT COUNT(*) as count
        FROM assets
        WHERE $whereClause
    ", $params)['count'];

    return [
        'success' => true,
        'data' => $assets,
        'pagination' => [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset,
            'count' => count($assets)
        ],
        'timestamp' => date('c')
    ];
});

$router->get('/assets/{symbol}', function($params) {
    $db = Database::getInstance();
    $symbol = strtoupper($params['symbol']);

    $asset = $db->fetchOne("
        SELECT *
        FROM assets
        WHERE symbol = ? AND is_active = 1
    ", [$symbol]);

    if (!$asset) {
        throw new Exception("Asset not found: $symbol");
    }

    // Parse JSON fields
    $asset['tags'] = json_decode($asset['tags'], true) ?? [];
    $asset['tradeable_exchanges'] = json_decode($asset['tradeable_exchanges'], true) ?? [];

    return [
        'success' => true,
        'data' => $asset,
        'timestamp' => date('c')
    ];
});

// ===========================
// Price Endpoints
// ===========================

$router->get('/prices/{symbol}', function($params) {
    $symbol = strtoupper($params['symbol']);
    $aggregator = new PriceAggregator();

    // Try to get cached price first
    $cached = $aggregator->getLatestPrice($symbol);

    // If cache is older than 30 seconds, fetch new prices
    $cacheAge = $cached ? (time() - strtotime($cached['timestamp'])) : PHP_INT_MAX;

    if ($cacheAge > env('CACHE_TTL_PRICES', 30)) {
        // Fetch fresh prices
        $data = $aggregator->aggregatePrices($symbol);
    } else {
        // Use cached data
        $data = [
            'symbol' => $symbol,
            'name' => $cached['name'],
            'aggregated' => [
                'price_simple_avg' => $cached['price_simple_avg'],
                'price_vwap' => $cached['price_vwap'],
                'price_median' => $cached['price_median'],
                'price_min' => $cached['price_min'],
                'price_max' => $cached['price_max'],
                'price_spread' => $cached['price_spread'],
                'total_volume_24h' => $cached['total_volume_24h'],
                'exchange_count' => $cached['exchange_count'],
                'confidence_score' => $cached['confidence_score']
            ],
            'cached' => true,
            'cache_age' => $cacheAge,
            'timestamp' => strtotime($cached['timestamp'])
        ];
    }

    return [
        'success' => true,
        'data' => $data,
        'timestamp' => date('c')
    ];
});

$router->get('/prices/{symbol}/exchanges', function($params) {
    $db = Database::getInstance();
    $symbol = strtoupper($params['symbol']);

    // Get asset ID
    $asset = $db->fetchOne("
        SELECT id, symbol, name
        FROM assets
        WHERE symbol = ? AND is_active = 1
    ", [$symbol]);

    if (!$asset) {
        throw new Exception("Asset not found: $symbol");
    }

    // Get latest prices from all exchanges
    $prices = $db->fetchAll("
        SELECT
            e.exchange_id,
            e.name as exchange_name,
            p.price,
            p.volume_24h,
            p.bid_price,
            p.ask_price,
            p.high_24h,
            p.low_24h,
            p.change_24h_percent,
            p.timestamp
        FROM prices p
        JOIN exchanges e ON e.id = p.exchange_id
        WHERE p.asset_id = ?
        AND p.timestamp = (
            SELECT MAX(timestamp)
            FROM prices
            WHERE asset_id = ?
        )
        ORDER BY p.price DESC
    ", [$asset['id'], $asset['id']]);

    return [
        'success' => true,
        'symbol' => $symbol,
        'name' => $asset['name'],
        'data' => $prices,
        'count' => count($prices),
        'timestamp' => date('c')
    ];
});

$router->get('/prices/{symbol}/history', function($params) {
    $symbol = strtoupper($params['symbol']);
    $limit = isset($_GET['limit']) ? min(1000, (int)$_GET['limit']) : 100;

    $aggregator = new PriceAggregator();
    $history = $aggregator->getPriceHistory($symbol, $limit);

    return [
        'success' => true,
        'symbol' => $symbol,
        'data' => $history,
        'count' => count($history),
        'timestamp' => date('c')
    ];
});

// ===========================
// Exchange Endpoints
// ===========================

$router->get('/exchanges', function() {
    $db = Database::getInstance();

    $exchanges = $db->fetchAll("
        SELECT
            exchange_id,
            name,
            is_active,
            api_status,
            rate_limit_per_minute,
            last_successful_fetch,
            last_error_at
        FROM exchanges
        ORDER BY name ASC
    ");

    return [
        'success' => true,
        'data' => $exchanges,
        'count' => count($exchanges),
        'timestamp' => date('c')
    ];
});

$router->get('/exchanges/{exchange_id}/status', function($params) {
    $exchangeId = $params['exchange_id'];
    $exchangeManager = new ExchangeManager();

    // Test connection
    $isConnected = $exchangeManager->testConnection($exchangeId);

    $db = Database::getInstance();
    $exchangeInfo = $db->fetchOne("
        SELECT *
        FROM exchanges
        WHERE exchange_id = ?
    ", [$exchangeId]);

    if (!$exchangeInfo) {
        throw new Exception("Exchange not found: $exchangeId");
    }

    return [
        'success' => true,
        'data' => [
            'exchange_id' => $exchangeId,
            'name' => $exchangeInfo['name'],
            'is_active' => (bool)$exchangeInfo['is_active'],
            'api_status' => $exchangeInfo['api_status'],
            'connection_test' => $isConnected ? 'success' : 'failed',
            'last_successful_fetch' => $exchangeInfo['last_successful_fetch'],
            'last_error' => [
                'timestamp' => $exchangeInfo['last_error_at'],
                'message' => $exchangeInfo['last_error_message']
            ]
        ],
        'timestamp' => date('c')
    ];
});

// ===========================
// OHLCV/Chart Endpoints
// ===========================

$router->get('/ohlcv/{symbol}', function($params) {
    $db = Database::getInstance();
    $symbol = strtoupper($params['symbol']);

    // Parse query parameters
    $timeframe = $_GET['timeframe'] ?? '1h';
    $limit = isset($_GET['limit']) ? min(1000, (int)$_GET['limit']) : 100;
    $exchange = $_GET['exchange'] ?? 'binance';

    // Get asset
    $asset = $db->fetchOne("
        SELECT id FROM assets WHERE symbol = ? AND is_active = 1
    ", [$symbol]);

    if (!$asset) {
        throw new Exception("Asset not found: $symbol");
    }

    // Get exchange
    $exchangeData = $db->fetchOne("
        SELECT id FROM exchanges WHERE exchange_id = ?
    ", [$exchange]);

    if (!$exchangeData) {
        throw new Exception("Exchange not found: $exchange");
    }

    // Get OHLCV data
    $ohlcv = $db->fetchAll("
        SELECT
            timestamp,
            open_price as open,
            high_price as high,
            low_price as low,
            close_price as close,
            volume
        FROM historical_ohlcv
        WHERE asset_id = ?
        AND exchange_id = ?
        AND timeframe = ?
        ORDER BY timestamp DESC
        LIMIT ?
    ", [$asset['id'], $exchangeData['id'], $timeframe, $limit]);

    // Reverse to get chronological order
    $ohlcv = array_reverse($ohlcv);

    return [
        'success' => true,
        'symbol' => $symbol,
        'timeframe' => $timeframe,
        'exchange' => $exchange,
        'data' => $ohlcv,
        'count' => count($ohlcv),
        'timestamp' => date('c')
    ];
});

$router->get('/chart/{symbol}', function($params) {
    $db = Database::getInstance();
    $symbol = strtoupper($params['symbol']);

    // Get last 24 hours of aggregated prices
    $prices = $db->fetchAll("
        SELECT
            ap.timestamp,
            ap.price_vwap as price,
            ap.total_volume_24h as volume,
            ap.confidence_score
        FROM aggregated_prices ap
        JOIN assets a ON a.id = ap.asset_id
        WHERE a.symbol = ?
        AND ap.timestamp > NOW() - INTERVAL 24 HOUR
        ORDER BY ap.timestamp ASC
    ", [$symbol]);

    // Format for charting libraries
    $chartData = [
        'labels' => array_column($prices, 'timestamp'),
        'datasets' => [
            [
                'label' => 'Price (VWAP)',
                'data' => array_column($prices, 'price'),
                'borderColor' => 'rgb(75, 192, 192)',
                'tension' => 0.1
            ]
        ]
    ];

    return [
        'success' => true,
        'symbol' => $symbol,
        'chart' => $chartData,
        'raw' => $prices,
        'timestamp' => date('c')
    ];
});

// Historical data endpoint with flexible periods (Folyo-compatible)
$router->get('/historical/{symbol}', function($params) {
    $db = Database::getInstance();
    $symbol = strtoupper($params['symbol']);

    // Parse query parameters
    $period = $_GET['period'] ?? '7d';  // 24h, 7d, 30d, 1y, all
    $timeframe = $_GET['timeframe'] ?? null;  // Auto-select if not specified
    $limit = isset($_GET['limit']) ? min(5000, (int)$_GET['limit']) : null;

    // Get asset
    $asset = $db->fetchOne("
        SELECT id, name FROM assets WHERE symbol = ? AND is_active = 1
    ", [$symbol]);

    if (!$asset) {
        throw new Exception("Asset not found: $symbol");
    }

    // Determine timeframe and time range based on period
    $timeRange = '';
    $autoTimeframe = '4h';

    switch ($period) {
        case '24h':
            $timeRange = 'AND h.timestamp >= NOW() - INTERVAL 24 HOUR';
            $autoTimeframe = '1h';  // Prefer hourly for 24h
            $limit = $limit ?? 24;
            break;
        case '7d':
            $timeRange = 'AND h.timestamp >= NOW() - INTERVAL 7 DAY';
            $autoTimeframe = '4h';
            $limit = $limit ?? 42;  // 7 days * 6 candles/day
            break;
        case '30d':
        case '1m':
            $timeRange = 'AND h.timestamp >= NOW() - INTERVAL 30 DAY';
            $autoTimeframe = '4h';
            $limit = $limit ?? 180;  // 30 days * 6 candles/day
            break;
        case '90d':
        case '3m':
            $timeRange = 'AND h.timestamp >= NOW() - INTERVAL 90 DAY';
            $autoTimeframe = '4h';
            $limit = $limit ?? 540;
            break;
        case '1y':
        case '365d':
            $timeRange = 'AND h.timestamp >= NOW() - INTERVAL 365 DAY';
            $autoTimeframe = '1d';  // Daily for 1 year
            $limit = $limit ?? 365;
            break;
        case 'all':
            $timeRange = '';
            $autoTimeframe = '4h';
            $limit = $limit ?? 2000;
            break;
        default:
            throw new Exception("Invalid period. Use: 24h, 7d, 30d, 90d, 1y, all");
    }

    // Use specified timeframe or auto-selected one
    $selectedTimeframe = $timeframe ?? $autoTimeframe;

    // Build query
    $query = "
        SELECT
            h.timestamp,
            h.open_price as open,
            h.high_price as high,
            h.low_price as low,
            h.close_price as close,
            h.volume,
            h.timeframe
        FROM historical_ohlcv h
        WHERE h.asset_id = ?
        AND h.timeframe = ?
        $timeRange
        ORDER BY h.timestamp ASC
    ";

    if ($limit) {
        $query .= " LIMIT ?";
        $data = $db->fetchAll($query, [$asset['id'], $selectedTimeframe, $limit]);
    } else {
        $data = $db->fetchAll($query, [$asset['id'], $selectedTimeframe]);
    }

    // If no data with selected timeframe, try fallback
    if (empty($data) && $timeframe === null) {
        $fallbackTimeframes = ['4h', '1h', '1d'];
        foreach ($fallbackTimeframes as $tf) {
            if ($tf === $selectedTimeframe) continue;

            $fallbackQuery = str_replace('LIMIT ?', '', $query);
            $fallbackData = $limit
                ? $db->fetchAll($query, [$asset['id'], $tf, $limit])
                : $db->fetchAll($fallbackQuery, [$asset['id'], $tf]);

            if (!empty($fallbackData)) {
                $data = $fallbackData;
                $selectedTimeframe = $tf;
                break;
            }
        }
    }

    // Format for different output types
    $format = $_GET['format'] ?? 'ohlcv';

    if ($format === 'simple') {
        // Simplified format (timestamp, price) for basic charts
        $formatted = array_map(function($candle) {
            return [
                'timestamp' => $candle['timestamp'],
                'price' => (float)$candle['close'],
                'volume' => (float)$candle['volume']
            ];
        }, $data);
    } else {
        // Full OHLCV format
        $formatted = array_map(function($candle) {
            return [
                'timestamp' => $candle['timestamp'],
                'open' => (float)$candle['open'],
                'high' => (float)$candle['high'],
                'low' => (float)$candle['low'],
                'close' => (float)$candle['close'],
                'volume' => (float)$candle['volume']
            ];
        }, $data);
    }

    return [
        'success' => true,
        'symbol' => $symbol,
        'name' => $asset['name'],
        'period' => $period,
        'timeframe' => $selectedTimeframe,
        'format' => $format,
        'data' => $formatted,
        'count' => count($formatted),
        'metadata' => [
            'first_timestamp' => !empty($formatted) ? $formatted[0]['timestamp'] : null,
            'last_timestamp' => !empty($formatted) ? end($formatted)['timestamp'] : null,
            'data_points' => count($formatted)
        ],
        'timestamp' => date('c')
    ];
});

// ===========================
// System Stats Endpoint
// ===========================

$router->get('/stats', function() {
    $db = Database::getInstance();

    $stats = $db->fetchOne("
        SELECT
            (SELECT COUNT(*) FROM assets WHERE is_active = 1) as total_assets,
            (SELECT COUNT(*) FROM assets WHERE is_tradeable = 1) as tradeable_assets,
            (SELECT COUNT(*) FROM prices WHERE timestamp > NOW() - INTERVAL 1 HOUR) as hourly_prices,
            (SELECT COUNT(*) FROM prices WHERE timestamp > NOW() - INTERVAL 24 HOUR) as daily_prices,
            (SELECT COUNT(*) FROM historical_ohlcv) as total_candles,
            (SELECT COUNT(DISTINCT asset_id) FROM prices WHERE timestamp > NOW() - INTERVAL 1 HOUR) as active_assets,
            (SELECT AVG(confidence_score) FROM aggregated_prices WHERE timestamp > NOW() - INTERVAL 1 HOUR) as avg_confidence,
            (SELECT SUM(total_volume_24h) FROM aggregated_prices WHERE timestamp > NOW() - INTERVAL 1 HOUR) as total_volume
    ");

    // Database size
    $dbSize = $db->fetchOne("
        SELECT
            SUM(data_length + index_length) / 1024 / 1024 as size_mb
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
    ");

    return [
        'success' => true,
        'data' => [
            'assets' => [
                'total' => (int)$stats['total_assets'],
                'tradeable' => (int)$stats['tradeable_assets'],
                'active' => (int)$stats['active_assets']
            ],
            'collection' => [
                'hourly_prices' => (int)$stats['hourly_prices'],
                'daily_prices' => (int)$stats['daily_prices'],
                'total_candles' => (int)$stats['total_candles']
            ],
            'quality' => [
                'avg_confidence' => round($stats['avg_confidence'], 2),
                'total_volume' => round($stats['total_volume'], 2)
            ],
            'system' => [
                'database_size_mb' => round($dbSize['size_mb'], 2),
                'uptime' => 'operational'
            ]
        ],
        'timestamp' => date('c')
    ];
});

// ===========================
// Handle the request
// ===========================
$router->handle();