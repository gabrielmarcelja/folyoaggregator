#!/usr/bin/env php
<?php
/**
 * Continuous Price Collector for FolyoAggregator
 * Collects real-time prices from exchanges via CCXT
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load dependencies
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use FolyoAggregator\Services\PriceAggregator;
use FolyoAggregator\Core\Database;

// Parse CLI arguments
$options = getopt('', ['symbols:', 'interval:', 'limit:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
╔═══════════════════════════════════════════════════════════════════╗
║                  Price Collector Script                          ║
╚═══════════════════════════════════════════════════════════════════╝

Usage:
  php scripts/price-collector.php [OPTIONS]

Options:
  --symbols=LIST    Comma-separated list of symbols (default: top tradeable)
  --interval=N      Collection interval in seconds (default: 30)
  --limit=N         Number of top coins to track (default: 20)
  --help            Show this help message

Examples:
  php scripts/price-collector.php --limit=10
  php scripts/price-collector.php --symbols=BTC,ETH,SOL --interval=10
  php scripts/price-collector.php --limit=50 --interval=60

HELP;
    exit(0);
}

// Configuration
$interval = (int)($options['interval'] ?? 30); // Default: 30 seconds
$limit = (int)($options['limit'] ?? 20); // Default: top 20 coins

// ANSI colors
$GREEN = "\033[0;32m";
$YELLOW = "\033[0;33m";
$BLUE = "\033[0;34m";
$RED = "\033[0;31m";
$CYAN = "\033[0;36m";
$RESET = "\033[0m";
$BOLD = "\033[1m";

echo "{$BLUE}╔═══════════════════════════════════════════════════════╗{$RESET}\n";
echo "{$BLUE}║{$RESET}       {$BOLD}{$CYAN}FolyoAggregator Price Collector{$RESET}              {$BLUE}║{$RESET}\n";
echo "{$BLUE}╚═══════════════════════════════════════════════════════╝{$RESET}\n\n";

// Get symbols to track
$db = Database::getInstance();
$symbols = [];

if (isset($options['symbols'])) {
    // Use provided symbols
    $symbols = array_map('trim', explode(',', strtoupper($options['symbols'])));
} else {
    // Get top tradeable assets from database
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
echo "  Tracking: {$CYAN}" . count($symbols) . "{$RESET} symbols\n";
echo "  Interval: {$CYAN}{$interval}{$RESET} seconds\n";
echo "  Symbols: {$CYAN}" . implode(', ', array_slice($symbols, 0, 10));
if (count($symbols) > 10) {
    echo " ... +" . (count($symbols) - 10) . " more";
}
echo "{$RESET}\n\n";

// Initialize price aggregator
$aggregator = new PriceAggregator();

// Continuous collection loop
$iteration = 0;
$startTime = time();

echo "{$GREEN}Starting price collection...{$RESET}\n";
echo str_repeat('─', 60) . "\n";

// Signal handler for graceful shutdown
$running = true;
pcntl_signal(SIGTERM, function() use (&$running) { $running = false; });
pcntl_signal(SIGINT, function() use (&$running) { $running = false; });

while ($running) {
    $iteration++;
    $batchStart = microtime(true);

    echo "\n{$BOLD}Iteration #{$iteration}{$RESET} - " . date('Y-m-d H:i:s') . "\n";

    $successful = 0;
    $failed = 0;
    $totalVolume = 0;

    foreach ($symbols as $symbol) {
        try {
            // Aggregate prices for this symbol
            $result = $aggregator->aggregatePrices($symbol);

            if ($result && isset($result['aggregated'])) {
                $successful++;
                $price = $result['aggregated']['price_vwap'] ?? 0;
                $volume = $result['aggregated']['total_volume_24h'] ?? 0;
                $confidence = $result['aggregated']['confidence_score'] ?? 0;
                $exchanges = $result['aggregated']['exchange_count'] ?? 0;
                $totalVolume += $volume;

                // Color code confidence score
                $confColor = $confidence >= 80 ? $GREEN : ($confidence >= 60 ? $YELLOW : $RED);

                echo sprintf(
                    "  %s%-6s%s: $%-12.2f | Vol: $%-10s | %sConf: %5.1f%%%s | Exch: %d\n",
                    $CYAN,
                    $symbol,
                    $RESET,
                    $price,
                    number_format($volume, 0),
                    $confColor,
                    $confidence,
                    $RESET,
                    $exchanges
                );
            } else {
                $failed++;
                echo "  {$RED}✗{$RESET} {$symbol}: Failed to aggregate\n";
            }

            // Small delay between symbols to avoid rate limiting
            usleep(100000); // 0.1 second

        } catch (Exception $e) {
            $failed++;
            echo "  {$RED}✗{$RESET} {$symbol}: " . $e->getMessage() . "\n";
        }

        // Check for signals
        pcntl_signal_dispatch();
        if (!$running) break;
    }

    // Summary for this iteration
    $batchTime = round(microtime(true) - $batchStart, 2);
    echo "\n{$BOLD}Summary:{$RESET}\n";
    echo "  Successful: {$GREEN}{$successful}{$RESET} | Failed: {$RED}{$failed}{$RESET}\n";
    echo "  Total Volume: {$CYAN}$" . number_format($totalVolume, 0) . "{$RESET}\n";
    echo "  Batch Time: {$CYAN}{$batchTime}s{$RESET}\n";

    // Runtime statistics
    $runtime = time() - $startTime;
    $hours = floor($runtime / 3600);
    $minutes = floor(($runtime % 3600) / 60);
    $seconds = $runtime % 60;
    echo "  Total Runtime: ";
    if ($hours > 0) echo "{$hours}h ";
    if ($minutes > 0 || $hours > 0) echo "{$minutes}m ";
    echo "{$seconds}s\n";

    // Wait for next iteration
    if ($running) {
        echo "\n{$YELLOW}Waiting {$interval} seconds for next collection...{$RESET}\n";
        echo str_repeat('─', 60);

        // Sleep in small chunks to check for signals
        for ($i = 0; $i < $interval; $i++) {
            sleep(1);
            pcntl_signal_dispatch();
            if (!$running) break;
        }
    }
}

// Graceful shutdown
echo "\n\n{$YELLOW}Shutting down price collector...{$RESET}\n";

// Final statistics
$runtime = time() - $startTime;
echo "\n{$BOLD}Final Statistics:{$RESET}\n";
echo "  Total Iterations: {$iteration}\n";
echo "  Total Runtime: " . gmdate("H:i:s", $runtime) . "\n";
echo "  Average Time per Iteration: " . round($runtime / max(1, $iteration), 2) . "s\n";

echo "\n{$GREEN}✓ Price collector stopped gracefully{$RESET}\n\n";