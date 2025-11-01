<?php
/**
 * FolyoAggregator - Cryptocurrency Data Aggregator
 * Main entry point for the application
 */

// Display errors during development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set timezone
date_default_timezone_set('UTC');

// Define root path
define('ROOT_PATH', dirname(__DIR__));

// Get request URI
$requestUri = $_SERVER['REQUEST_URI'];

// Route API requests to api.php
if (strpos($requestUri, '/api/') === 0) {
    require_once 'api.php';
    exit;
}

// Check if this is a test request
if ($requestUri === '/test') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'message' => 'FolyoAggregator is running!',
        'timestamp' => date('Y-m-d H:i:s'),
        'php_version' => phpversion(),
        'server' => $_SERVER['SERVER_NAME']
    ], JSON_PRETTY_PRINT);
    exit;
}

// For the home page, get system stats
$systemStats = [];
try {
    require_once ROOT_PATH . '/vendor/autoload.php';
    $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
    $dotenv->load();

    $db = \FolyoAggregator\Core\Database::getInstance();

    // Get asset count
    $assetCount = $db->fetchOne("SELECT COUNT(*) as count FROM assets");
    $systemStats['assets'] = (int)($assetCount['count'] ?? 0);

    // Get price count
    $priceCount = $db->fetchOne("SELECT COUNT(*) as count FROM prices");
    $systemStats['prices'] = (int)($priceCount['count'] ?? 0);

    // Get historical count
    $historicalCount = $db->fetchOne("SELECT COUNT(*) as count FROM historical_ohlcv");
    $systemStats['historical'] = (int)($historicalCount['count'] ?? 0);

    // Get last price update
    $lastUpdate = $db->fetchOne("SELECT MAX(timestamp) as last_update FROM prices");
    $systemStats['last_update'] = $lastUpdate['last_update'] ?? 'N/A';

    // Calculate data age
    if ($lastUpdate && $lastUpdate['last_update']) {
        $lastUpdateTime = strtotime($lastUpdate['last_update']);
        $now = time();
        $ageSeconds = $now - $lastUpdateTime;
        $systemStats['data_age_seconds'] = $ageSeconds;

        if ($ageSeconds < 120) {
            $systemStats['data_status'] = 'fresh'; // < 2 minutes
        } elseif ($ageSeconds < 300) {
            $systemStats['data_status'] = 'recent'; // < 5 minutes
        } else {
            $systemStats['data_status'] = 'stale'; // > 5 minutes
        }
    } else {
        $systemStats['data_status'] = 'unknown';
    }

} catch (Exception $e) {
    // Silently fail, show basic page
    error_log("FolyoAggregator stats error: " . $e->getMessage());
    $systemStats = [
        'assets' => 0,
        'prices' => 0,
        'historical' => 0,
        'last_update' => 'N/A',
        'data_status' => 'unknown',
        'error' => $e->getMessage()
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FolyoAggregator - Cryptocurrency Data Aggregator</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            text-align: center;
            padding: 2rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
            max-width: 600px;
            margin: 0 20px;
        }
        h1 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        .status {
            background: rgba(0, 255, 0, 0.2);
            padding: 10px 20px;
            border-radius: 25px;
            display: inline-block;
            margin: 1rem 0;
            font-weight: 500;
        }
        .info {
            margin-top: 2rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 5px;
        }
        .info p {
            margin: 0.5rem 0;
            font-size: 0.9rem;
        }
        .api-endpoint {
            background: rgba(0, 0, 0, 0.2);
            padding: 5px 10px;
            border-radius: 3px;
            font-family: monospace;
            display: inline-block;
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 FolyoAggregator</h1>
        <p>Cryptocurrency Data Aggregator API</p>
        <div class="status">✓ System Operational</div>

        <div class="info">
            <h3>System Information</h3>
            <p>PHP Version: <?php echo phpversion(); ?></p>
            <p>Server: <?php echo $_SERVER['SERVER_NAME']; ?></p>
            <p>Time: <?php echo date('Y-m-d H:i:s T'); ?></p>
        </div>

        <div class="info">
            <h3>Live System Stats</h3>
            <?php if (isset($systemStats['error'])): ?>
                <p style="color: #f87171;">Error loading stats: <?php echo htmlspecialchars($systemStats['error']); ?></p>
            <?php endif; ?>
            <p>Assets: <strong><?php echo number_format($systemStats['assets']); ?></strong></p>
            <p>Price Records: <strong><?php echo number_format($systemStats['prices']); ?></strong></p>
            <p>Historical Candles: <strong><?php echo number_format($systemStats['historical']); ?></strong></p>
            <?php if ($systemStats['last_update'] !== 'N/A'): ?>
                <p>Last Price Update: <strong><?php echo $systemStats['last_update']; ?></strong></p>
                <?php if (isset($systemStats['data_age_seconds'])): ?>
                    <p>Data Age: <strong>
                        <?php
                        $age = $systemStats['data_age_seconds'];
                        if ($age < 60) {
                            echo $age . ' seconds ago';
                        } elseif ($age < 3600) {
                            echo floor($age / 60) . ' minutes ago';
                        } else {
                            echo floor($age / 3600) . ' hours ago';
                        }
                        ?>
                    </strong>
                    <?php if ($systemStats['data_status'] === 'fresh'): ?>
                        <span style="color: #4ade80;">✓ Fresh</span>
                    <?php elseif ($systemStats['data_status'] === 'recent'): ?>
                        <span style="color: #fbbf24;">⚠ Recent</span>
                    <?php else: ?>
                        <span style="color: #f87171;">✗ Stale</span>
                    <?php endif; ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
            <p style="font-size: 0.85rem; margin-top: 1rem; opacity: 0.8;">
                <em>Real-time data collection: 30-second intervals</em>
            </p>
        </div>

        <div class="info">
            <h3>API Endpoints</h3>
            <p style="font-size: 0.85rem; margin-bottom: 1rem; opacity: 0.9;">Click to test each endpoint</p>

            <div style="text-align: left; margin-top: 1rem;">
                <p style="font-weight: bold; margin-bottom: 0.5rem;">Health & Status:</p>
                <div class="api-endpoint">
                    <a href="/api/health" style="color: #fff; text-decoration: none;">/api/health</a>
                </div>
                <div class="api-endpoint">
                    <a href="/api/status" style="color: #fff; text-decoration: none;">/api/status</a>
                </div>
                <div class="api-endpoint">
                    <a href="/api/stats" style="color: #fff; text-decoration: none;">/api/stats</a>
                </div>
            </div>

            <div style="text-align: left; margin-top: 1rem;">
                <p style="font-weight: bold; margin-bottom: 0.5rem;">Assets & Listings:</p>
                <div class="api-endpoint">
                    <a href="/api/listings?start=1&limit=10" style="color: #fff; text-decoration: none;">/api/listings</a>
                </div>
                <div class="api-endpoint">
                    <a href="/api/assets/BTC" style="color: #fff; text-decoration: none;">/api/assets/{symbol}</a>
                </div>
                <div class="api-endpoint">
                    <a href="/api/assets/search?q=bitcoin" style="color: #fff; text-decoration: none;">/api/assets/search</a>
                </div>
                <div class="api-endpoint">
                    <a href="/api/assets/market-overview" style="color: #fff; text-decoration: none;">/api/assets/market-overview</a>
                </div>
            </div>

            <div style="text-align: left; margin-top: 1rem;">
                <p style="font-weight: bold; margin-bottom: 0.5rem;">Prices & Historical:</p>
                <div class="api-endpoint">
                    <a href="/api/prices/BTC" style="color: #fff; text-decoration: none;">/api/prices/{symbol}</a>
                </div>
                <div class="api-endpoint">
                    <a href="/api/historical/BTC?period=7d" style="color: #fff; text-decoration: none;">/api/historical/{symbol}</a>
                </div>
                <div class="api-endpoint">
                    <a href="/api/ohlcv/BTC?timeframe=4h&limit=10" style="color: #fff; text-decoration: none;">/api/ohlcv/{symbol}</a>
                </div>
                <div class="api-endpoint">
                    <a href="/api/chart/BTC" style="color: #fff; text-decoration: none;">/api/chart/{symbol}</a>
                </div>
            </div>

            <div style="text-align: left; margin-top: 1rem;">
                <p style="font-weight: bold; margin-bottom: 0.5rem;">Exchanges:</p>
                <div class="api-endpoint">
                    <a href="/api/exchanges" style="color: #fff; text-decoration: none;">/api/exchanges</a>
                </div>
            </div>
        </div>

        <div class="info">
            <h3>Test Endpoint</h3>
            <div class="api-endpoint">
                <a href="/test" style="color: #fff; text-decoration: none;">/test</a>
            </div>
        </div>
    </div>
</body>
</html>