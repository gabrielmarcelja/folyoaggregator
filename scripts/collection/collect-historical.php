#!/usr/bin/env php
<?php
/**
 * Historical OHLCV Data Collector for FolyoAggregator
 * Collects historical candlestick data from exchanges
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load dependencies
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->load();

use FolyoAggregator\Core\Database;
use FolyoAggregator\Exchanges\ExchangeManager;

// Parse CLI arguments
$options = getopt('', ['symbol:', 'timeframe:', 'days:', 'exchange:', 'limit:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
╔═══════════════════════════════════════════════════════════════════╗
║                  Historical OHLCV Collector                      ║
╚═══════════════════════════════════════════════════════════════════╝

Usage:
  php scripts/collect-historical.php [OPTIONS]

Options:
  --symbol=BTC      Symbol to collect (default: all top 20)
  --timeframe=1h    Timeframe: 1m, 5m, 15m, 30m, 1h, 4h, 1d (default: 1h)
  --days=30         Number of days of history (default: 30)
  --exchange=binance Specific exchange (default: binance)
  --limit=20        Number of symbols to process (default: 20)
  --help            Show this help message

Examples:
  php scripts/collect-historical.php --symbol=BTC --timeframe=1h --days=7
  php scripts/collect-historical.php --timeframe=4h --days=30 --limit=50
  php scripts/collect-historical.php --exchange=coinbase --limit=10

HELP;
    exit(0);
}

// Configuration
$symbol = $options['symbol'] ?? null;
$timeframe = $options['timeframe'] ?? '1h';
$days = (int)($options['days'] ?? 30);
$exchangeId = $options['exchange'] ?? 'binance';
$limit = (int)($options['limit'] ?? 20);

// ANSI colors
$GREEN = "\033[0;32m";
$YELLOW = "\033[0;33m";
$BLUE = "\033[0;34m";
$RED = "\033[0;31m";
$CYAN = "\033[0;36m";
$RESET = "\033[0m";
$BOLD = "\033[1m";

echo "{$BLUE}╔═══════════════════════════════════════════════════════╗{$RESET}\n";
echo "{$BLUE}║{$RESET}       {$BOLD}{$CYAN}Historical OHLCV Data Collector{$RESET}             {$BLUE}║{$RESET}\n";
echo "{$BLUE}╚═══════════════════════════════════════════════════════╝{$RESET}\n\n";

// Initialize
$db = Database::getInstance();
$exchangeManager = new ExchangeManager();

// Get symbols to process
$symbols = [];
if ($symbol) {
    $symbols = [strtoupper($symbol)];
} else {
    // Get top tradeable assets
    $assets = $db->fetchAll("
        SELECT symbol
        FROM assets
        WHERE is_active = 1
        AND is_tradeable = 1
        ORDER BY market_cap_rank ASC
        LIMIT ?
    ", [$limit]);
    $symbols = array_column($assets, 'symbol');
}

echo "{$BOLD}Configuration:{$RESET}\n";
echo "  Exchange: {$CYAN}{$exchangeId}{$RESET}\n";
echo "  Timeframe: {$CYAN}{$timeframe}{$RESET}\n";
echo "  Days of history: {$CYAN}{$days}{$RESET}\n";
echo "  Symbols: {$CYAN}" . count($symbols) . "{$RESET} (" . implode(', ', array_slice($symbols, 0, 5));
if (count($symbols) > 5) echo " ...";
echo ")\n\n";

// Connect to exchange
try {
    $exchange = $exchangeManager->getExchange($exchangeId);
    if (!$exchange) {
        throw new Exception("Failed to connect to {$exchangeId}");
    }

    // Load markets if needed
    if (!$exchange->markets) {
        echo "Loading {$exchangeId} markets...\n";
        $exchange->load_markets();
    }
} catch (Exception $e) {
    echo "{$RED}Error connecting to {$exchangeId}: " . $e->getMessage() . "{$RESET}\n";
    exit(1);
}

// Calculate time range
$since = strtotime("-{$days} days") * 1000; // CCXT uses milliseconds
$now = time() * 1000;

echo "{$GREEN}Starting historical data collection...{$RESET}\n";
echo str_repeat('─', 60) . "\n\n";

// Process each symbol
$totalCandles = 0;
$successful = 0;
$failed = 0;

foreach ($symbols as $sym) {
    $tradingPair = "{$sym}/USDT";

    echo "{$BOLD}{$sym}{$RESET}: ";

    try {
        // Check if market exists
        if (!isset($exchange->markets[$tradingPair])) {
            // Try USD pair
            $tradingPair = "{$sym}/USD";
            if (!isset($exchange->markets[$tradingPair])) {
                echo "{$YELLOW}Market not found{$RESET}\n";
                $failed++;
                continue;
            }
        }

        // Fetch OHLCV data
        $ohlcv = $exchange->fetch_ohlcv($tradingPair, $timeframe, $since);

        if (empty($ohlcv)) {
            echo "{$YELLOW}No data available{$RESET}\n";
            $failed++;
            continue;
        }

        // Get asset and exchange IDs
        $asset = $db->fetchOne("SELECT id FROM assets WHERE symbol = ?", [$sym]);
        $exchangeDb = $db->fetchOne("SELECT id FROM exchanges WHERE exchange_id = ?", [$exchangeId]);

        if (!$asset || !$exchangeDb) {
            echo "{$RED}Asset/Exchange not in database{$RESET}\n";
            $failed++;
            continue;
        }

        $assetId = $asset['id'];
        $exchangeDbId = $exchangeDb['id'];

        // Prepare batch insert
        $insertCount = 0;
        $updateCount = 0;

        foreach ($ohlcv as $candle) {
            // CCXT format: [timestamp, open, high, low, close, volume]
            $timestamp = date('Y-m-d H:i:s', $candle[0] / 1000);

            // Check if already exists
            $existing = $db->fetchOne("
                SELECT id FROM historical_ohlcv
                WHERE asset_id = ?
                AND exchange_id = ?
                AND timeframe = ?
                AND timestamp = ?
            ", [$assetId, $exchangeDbId, $timeframe, $timestamp]);

            if ($existing) {
                // Update existing
                $db->update('historical_ohlcv', [
                    'open_price' => $candle[1],
                    'high_price' => $candle[2],
                    'low_price' => $candle[3],
                    'close_price' => $candle[4],
                    'volume' => $candle[5]
                ], ['id' => $existing['id']]);
                $updateCount++;
            } else {
                // Insert new
                $db->insert('historical_ohlcv', [
                    'asset_id' => $assetId,
                    'exchange_id' => $exchangeDbId,
                    'timeframe' => $timeframe,
                    'timestamp' => $timestamp,
                    'open_price' => $candle[1],
                    'high_price' => $candle[2],
                    'low_price' => $candle[3],
                    'close_price' => $candle[4],
                    'volume' => $candle[5]
                ]);
                $insertCount++;
            }
        }

        $totalCandles += count($ohlcv);
        $successful++;

        // Display result
        $lastPrice = $ohlcv[count($ohlcv) - 1][4]; // Last close price
        echo sprintf(
            "{$GREEN}✓{$RESET} %d candles (new: %d, updated: %d) | Last: $%.2f\n",
            count($ohlcv),
            $insertCount,
            $updateCount,
            $lastPrice
        );

        // Small delay to avoid rate limiting
        usleep(500000); // 0.5 second

    } catch (Exception $e) {
        echo "{$RED}Error: " . $e->getMessage() . "{$RESET}\n";
        $failed++;

        // If rate limited, wait longer
        if (strpos($e->getMessage(), 'rate') !== false) {
            echo "{$YELLOW}Rate limited, waiting 10 seconds...{$RESET}\n";
            sleep(10);
        }
    }
}

// Summary
echo "\n" . str_repeat('═', 60) . "\n";
echo "{$GREEN}{$BOLD}Collection Complete!{$RESET}\n\n";

echo "{$BOLD}Summary:{$RESET}\n";
echo "  Successful: {$GREEN}{$successful}{$RESET} symbols\n";
echo "  Failed: {$RED}{$failed}{$RESET} symbols\n";
echo "  Total candles: {$CYAN}" . number_format($totalCandles) . "{$RESET}\n";
echo "  Timeframe: {$timeframe}\n";
echo "  Exchange: {$exchangeId}\n";

// Check database
$dbStats = $db->fetchOne("
    SELECT
        COUNT(DISTINCT asset_id) as unique_assets,
        COUNT(*) as total_records,
        MIN(timestamp) as oldest,
        MAX(timestamp) as newest
    FROM historical_ohlcv
    WHERE exchange_id = (SELECT id FROM exchanges WHERE exchange_id = ?)
    AND timeframe = ?
", [$exchangeId, $timeframe]);

echo "\n{$BOLD}Database Statistics:{$RESET}\n";
echo "  Total records: " . number_format($dbStats['total_records']) . "\n";
echo "  Unique assets: {$dbStats['unique_assets']}\n";
echo "  Date range: {$dbStats['oldest']} to {$dbStats['newest']}\n";

echo "\n{$GREEN}✓ Historical data collection completed{$RESET}\n\n";