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
// Asset Endpoints
// ===========================

$router->get('/assets', function() {
    $db = Database::getInstance();

    $assets = $db->fetchAll("
        SELECT symbol, name, slug, market_cap_rank
        FROM assets
        WHERE is_active = 1
        ORDER BY market_cap_rank ASC
    ");

    return [
        'success' => true,
        'data' => $assets,
        'count' => count($assets),
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
// Handle the request
// ===========================
$router->handle();