#!/usr/bin/env php
<?php
/**
 * FolyoAggregator Gap Filler
 * Fills missing candles between last historical data and current time
 *
 * Usage: php scripts/collection/fill-recent-gap.php [OPTIONS]
 *
 * This script should run daily at 4 AM to catch up on missing candles
 * that weren't captured by the price collector.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->load();

use FolyoAggregator\Core\Database;
use FolyoAggregator\Exchanges\ExchangeManager;

// Parse CLI arguments
$options = getopt('', ['limit:', 'timeframe:', 'lookback:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
╔═══════════════════════════════════════════════════════════════════╗
║                    Gap Filling Script                             ║
╚═══════════════════════════════════════════════════════════════════╝

Usage:
  php scripts/collection/fill-recent-gap.php [OPTIONS]

Options:
  --limit=N         Number of top assets to process (default: 50)
  --timeframe=TF    Timeframe to collect (1h, 4h, or both) (default: both)
  --lookback=DAYS   How many days back to check for gaps (default: 3)
  --help            Show this help message

Examples:
  php scripts/collection/fill-recent-gap.php
  php scripts/collection/fill-recent-gap.php --limit=100
  php scripts/collection/fill-recent-gap.php --timeframe=1h --lookback=7

Recommended Cron:
  # Daily at 4 AM
  0 4 * * * cd /var/www/html/folyoaggregator && php scripts/collection/fill-recent-gap.php --limit=50 >> logs/gap-filler.log 2>&1

HELP;
    exit(0);
}

// Configuration
$limit = (int)($options['limit'] ?? 50);
$timeframeOption = $options['timeframe'] ?? 'both';
$lookbackDays = (int)($options['lookback'] ?? 3);

// Determine which timeframes to process
$timeframes = [];
if ($timeframeOption === 'both') {
    $timeframes = ['1h', '4h'];
} else {
    $timeframes = [$timeframeOption];
}

// ANSI colors
$GREEN = "\033[0;32m";
$YELLOW = "\033[0;33m";
$BLUE = "\033[0;34m";
$RED = "\033[0;31m";
$CYAN = "\033[0;36m";
$RESET = "\033[0m";
$BOLD = "\033[1m";

echo "{$BLUE}╔═══════════════════════════════════════════════════════════════╗{$RESET}\n";
echo "{$BLUE}║{$RESET}       {$BOLD}{$CYAN}FolyoAggregator Gap Filler{$RESET}                          {$BLUE}║{$RESET}\n";
echo "{$BLUE}╚═══════════════════════════════════════════════════════════════╝{$RESET}\n\n";

echo "{$BOLD}Configuration:{$RESET}\n";
echo "  Assets: {$CYAN}TOP {$limit}{$RESET}\n";
echo "  Timeframes: {$CYAN}" . implode(', ', $timeframes) . "{$RESET}\n";
echo "  Lookback: {$CYAN}{$lookbackDays} days{$RESET}\n";
echo "  Started: {$CYAN}" . date('Y-m-d H:i:s') . "{$RESET}\n\n";

$db = Database::getInstance();
$exchangeManager = new ExchangeManager();
$exchangeId = 'binance';

// Connect to exchange
try {
    $exchange = $exchangeManager->getExchange($exchangeId);
    if (!$exchange) {
        throw new Exception("Failed to connect to {$exchangeId}");
    }

    if (!$exchange->markets) {
        echo "Loading {$exchangeId} markets...\n";
        $exchange->load_markets();
    }
    echo "{$GREEN}✓{$RESET} Connected to {$exchangeId}\n\n";
} catch (Exception $e) {
    echo "{$RED}❌ Connection error: {$e->getMessage()}{$RESET}\n";
    exit(1);
}

// Get exchange DB ID
$exchangeDb = $db->fetchOne("SELECT id FROM exchanges WHERE exchange_id = ?", [$exchangeId]);
if (!$exchangeDb) {
    echo "{$RED}❌ Exchange not found in database{$RESET}\n";
    exit(1);
}
$exchangeDbId = $exchangeDb['id'];

// Get top tradeable assets
$assets = $db->fetchAll("
    SELECT
        id,
        symbol,
        name,
        market_cap_rank
    FROM assets
    WHERE is_active = 1
    AND is_tradeable = 1
    AND market_cap_rank <= ?
    ORDER BY market_cap_rank ASC
", [$limit]);

echo "{$GREEN}🚀 Starting gap fill for " . count($assets) . " assets...{$RESET}\n";
echo str_repeat('─', 70) . "\n\n";

// Statistics
$totalGapsFilled = 0;
$totalCandlesInserted = 0;
$assetsProcessed = 0;
$assetsWithGaps = 0;
$errors = 0;

// Calculate lookback timestamp
$lookbackTimestamp = date('Y-m-d H:i:s', strtotime("-{$lookbackDays} days"));

foreach ($assets as $index => $asset) {
    $num = $index + 1;
    $symbol = $asset['symbol'];
    $rank = $asset['market_cap_rank'];

    echo sprintf("{$BOLD}[%2d/%2d]{$RESET} #%-3d {$CYAN}%-8s{$RESET} ", $num, count($assets), $rank, $symbol);

    try {
        // Find trading pair
        $tradingPair = "{$symbol}/USDT";
        if (!isset($exchange->markets[$tradingPair])) {
            $tradingPair = "{$symbol}/USD";
            if (!isset($exchange->markets[$tradingPair])) {
                echo "{$YELLOW}No market{$RESET}\n";
                continue;
            }
        }

        $gapsFilledForAsset = 0;
        $candlesInsertedForAsset = 0;

        // Process each timeframe
        foreach ($timeframes as $timeframe) {
            // Find the newest candle we have
            $lastCandle = $db->fetchOne("
                SELECT MAX(timestamp) as last_timestamp
                FROM historical_ohlcv
                WHERE asset_id = ?
                AND exchange_id = ?
                AND timeframe = ?
            ", [$asset['id'], $exchangeDbId, $timeframe]);

            if (!$lastCandle || !$lastCandle['last_timestamp']) {
                // No historical data at all - this is a job for the full history collector
                continue;
            }

            $lastTimestamp = strtotime($lastCandle['last_timestamp']);
            $currentTimestamp = time();
            $gapHours = ($currentTimestamp - $lastTimestamp) / 3600;

            // Timeframe interval in hours
            $intervalHours = ($timeframe === '1h') ? 1 : 4;

            // Only fill if gap is significant (more than 2 intervals)
            if ($gapHours < ($intervalHours * 2)) {
                continue; // No significant gap
            }

            // Fetch missing candles
            $since = $lastTimestamp * 1000; // CCXT uses milliseconds
            $limit = min(1000, ceil($gapHours / $intervalHours) + 10); // Extra buffer

            $ohlcv = $exchange->fetch_ohlcv($tradingPair, $timeframe, $since, $limit);

            if (empty($ohlcv)) {
                continue;
            }

            // Insert candles (skip duplicates)
            $inserted = 0;
            foreach ($ohlcv as $candle) {
                $candleTimestamp = date('Y-m-d H:i:s', $candle[0] / 1000);

                // Check if already exists
                $exists = $db->fetchOne("
                    SELECT id FROM historical_ohlcv
                    WHERE asset_id = ?
                    AND exchange_id = ?
                    AND timeframe = ?
                    AND timestamp = ?
                ", [$asset['id'], $exchangeDbId, $timeframe, $candleTimestamp]);

                if ($exists) {
                    continue; // Skip duplicate
                }

                // Insert new candle
                $db->query("
                    INSERT INTO historical_ohlcv (
                        asset_id,
                        exchange_id,
                        timeframe,
                        timestamp,
                        open_price,
                        high_price,
                        low_price,
                        close_price,
                        volume
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ", [
                    $asset['id'],
                    $exchangeDbId,
                    $timeframe,
                    $candleTimestamp,
                    $candle[1], // open
                    $candle[2], // high
                    $candle[3], // low
                    $candle[4], // close
                    $candle[5]  // volume
                ]);

                $inserted++;
            }

            if ($inserted > 0) {
                $gapsFilledForAsset++;
                $candlesInsertedForAsset += $inserted;
            }

            // Small delay to avoid rate limits
            usleep(200000); // 0.2 seconds
        }

        // Output result for this asset
        if ($gapsFilledForAsset > 0) {
            echo "{$GREEN}✓ Filled {$gapsFilledForAsset} gap(s), {$candlesInsertedForAsset} candles{$RESET}\n";
            $totalGapsFilled += $gapsFilledForAsset;
            $totalCandlesInserted += $candlesInsertedForAsset;
            $assetsWithGaps++;
        } else {
            echo "{$GREEN}✓ No gaps{$RESET}\n";
        }

        $assetsProcessed++;

    } catch (Exception $e) {
        echo "{$RED}✗ Error: " . $e->getMessage() . "{$RESET}\n";
        $errors++;
    }
}

// Summary
echo "\n" . str_repeat('═', 70) . "\n";
echo "{$BOLD}Summary:{$RESET}\n";
echo "  Assets Processed: {$CYAN}{$assetsProcessed}{$RESET}\n";
echo "  Assets with Gaps: {$YELLOW}{$assetsWithGaps}{$RESET}\n";
echo "  Total Gaps Filled: {$GREEN}{$totalGapsFilled}{$RESET}\n";
echo "  Total Candles Inserted: {$GREEN}{$totalCandlesInserted}{$RESET}\n";
echo "  Errors: {$RED}{$errors}{$RESET}\n";
echo "  Completed: {$CYAN}" . date('Y-m-d H:i:s') . "{$RESET}\n";

if ($totalCandlesInserted > 0) {
    echo "\n{$GREEN}✓ Gap filling completed successfully{$RESET}\n\n";
    exit(0);
} else {
    echo "\n{$YELLOW}ℹ No gaps found - all data is up to date{$RESET}\n\n";
    exit(0);
}
