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

// For the home page, show a basic info page
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
            <h3>API Endpoints (Active)</h3>
            <div class="api-endpoint">
                <a href="/api/v1/health" style="color: #fff; text-decoration: none;">/api/v1/health</a>
            </div>
            <div class="api-endpoint">
                <a href="/api/v1/status" style="color: #fff; text-decoration: none;">/api/v1/status</a>
            </div>
            <div class="api-endpoint">
                <a href="/api/v1/assets" style="color: #fff; text-decoration: none;">/api/v1/assets</a>
            </div>
            <div class="api-endpoint">
                <a href="/api/v1/prices/BTC" style="color: #fff; text-decoration: none;">/api/v1/prices/{symbol}</a>
            </div>
            <div class="api-endpoint">
                <a href="/api/v1/exchanges" style="color: #fff; text-decoration: none;">/api/v1/exchanges</a>
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