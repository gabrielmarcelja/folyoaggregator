#!/usr/bin/env php
<?php
/**
 * FolyoAggregator Daily Statistics Generator
 * Generates comprehensive daily statistics report
 *
 * Usage: php scripts/maintenance/generate-stats.php [--save]
 *
 * Options:
 *   --save    Save report to logs/daily-stats/YYYY-MM-DD.txt
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use FolyoAggregator\Core\Database;

// Parse arguments
$saveReport = in_array('--save', $argv);

/**
 * Get asset statistics
 */
function getAssetStats() {
    $db = Database::getInstance();

    return $db->fetchOne("
        SELECT
            COUNT(*) as total_assets,
            SUM(CASE WHEN is_tradeable = 1 THEN 1 ELSE 0 END) as tradeable_assets,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_assets,
            COALESCE(SUM(market_cap), 0) as total_market_cap,
            COALESCE(SUM(volume_24h), 0) as total_volume_24h
        FROM assets
    ");
}

/**
 * Get price collection statistics
 */
function getPriceStats() {
    $db = Database::getInstance();

    $today = $db->fetchOne("
        SELECT COUNT(*) as count
        FROM prices
        WHERE DATE(updated_at) = CURDATE()
    ");

    $last24h = $db->fetchOne("
        SELECT COUNT(*) as count
        FROM prices
        WHERE updated_at >= NOW() - INTERVAL 24 HOUR
    ");

    $byExchange = $db->fetchAll("
        SELECT
            e.exchange_id,
            e.name,
            COUNT(p.id) as price_count
        FROM exchanges e
        LEFT JOIN prices p ON e.id = p.exchange_id
            AND p.updated_at >= NOW() - INTERVAL 24 HOUR
        GROUP BY e.id, e.name
        ORDER BY price_count DESC
    ");

    return [
        'today_count' => (int)$today['count'],
        'last_24h_count' => (int)$last24h['count'],
        'by_exchange' => $byExchange
    ];
}

/**
 * Get historical data statistics
 */
function getHistoricalStats() {
    $db = Database::getInstance();

    $overall = $db->fetchOne("
        SELECT
            COUNT(*) as total_candles,
            COUNT(DISTINCT asset_id) as assets_with_history,
            MIN(timestamp) as oldest_candle,
            MAX(timestamp) as newest_candle
        FROM historical_ohlcv
    ");

    $byTimeframe = $db->fetchAll("
        SELECT
            timeframe,
            COUNT(*) as count
        FROM historical_ohlcv
        GROUP BY timeframe
        ORDER BY FIELD(timeframe, '1h', '4h', '1d')
    ");

    $top10Coverage = $db->fetchAll("
        SELECT
            a.symbol,
            a.name,
            a.market_cap_rank,
            COUNT(h.id) as candle_count,
            MIN(h.timestamp) as oldest_candle,
            MAX(h.timestamp) as newest_candle,
            DATEDIFF(MAX(h.timestamp), MIN(h.timestamp)) as days_coverage
        FROM assets a
        LEFT JOIN historical_ohlcv h ON a.id = h.asset_id
        WHERE a.market_cap_rank <= 10
        GROUP BY a.id, a.symbol, a.name, a.market_cap_rank
        ORDER BY a.market_cap_rank
    ");

    return [
        'overall' => $overall,
        'by_timeframe' => $byTimeframe,
        'top10_coverage' => $top10Coverage
    ];
}

/**
 * Get exchange health
 */
function getExchangeHealth() {
    $db = Database::getInstance();

    return $db->fetchAll("
        SELECT
            exchange_id,
            name,
            is_active,
            api_status,
            last_successful_fetch,
            last_error_at,
            last_error_message,
            TIMESTAMPDIFF(MINUTE, last_successful_fetch, NOW()) as minutes_since_success
        FROM exchanges
        ORDER BY is_active DESC, last_successful_fetch DESC
    ");
}

/**
 * Get top performers (24h)
 */
function getTopPerformers() {
    $db = Database::getInstance();

    $gainers = $db->fetchAll("
        SELECT
            symbol,
            name,
            percent_change_24h,
            market_cap_rank
        FROM assets
        WHERE percent_change_24h IS NOT NULL
            AND market_cap_rank <= 200
        ORDER BY percent_change_24h DESC
        LIMIT 5
    ");

    $losers = $db->fetchAll("
        SELECT
            symbol,
            name,
            percent_change_24h,
            market_cap_rank
        FROM assets
        WHERE percent_change_24h IS NOT NULL
            AND market_cap_rank <= 200
        ORDER BY percent_change_24h ASC
        LIMIT 5
    ");

    return [
        'gainers' => $gainers,
        'losers' => $losers
    ];
}

/**
 * Get database size
 */
function getDatabaseSize() {
    $db = Database::getInstance();

    $tables = $db->fetchAll("
        SELECT
            table_name,
            ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb,
            table_rows
        FROM information_schema.TABLES
        WHERE table_schema = DATABASE()
        ORDER BY (data_length + index_length) DESC
    ");

    $totalSize = array_sum(array_column($tables, 'size_mb'));

    return [
        'total_mb' => round($totalSize, 2),
        'tables' => $tables
    ];
}

// Collect all statistics
$stats = [
    'generated_at' => date('Y-m-d H:i:s T'),
    'assets' => getAssetStats(),
    'prices' => getPriceStats(),
    'historical' => getHistoricalStats(),
    'exchanges' => getExchangeHealth(),
    'performers' => getTopPerformers(),
    'database' => getDatabaseSize()
];

// Generate report
$report = generateReport($stats);

// Output
echo $report;

// Save if requested
if ($saveReport) {
    $dir = dirname(__DIR__, 2) . '/logs/daily-stats';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = $dir . '/' . date('Y-m-d') . '.txt';
    file_put_contents($filename, $report);
    echo "\n\nReport saved to: " . $filename . "\n";
}

/**
 * Generate formatted report
 */
function generateReport($stats) {
    $report = "";

    $report .= "╔═══════════════════════════════════════════════════════════════════════════╗\n";
    $report .= "║         FolyoAggregator - Daily Statistics Report                         ║\n";
    $report .= "╠═══════════════════════════════════════════════════════════════════════════╣\n";
    $report .= "║ Generated: " . str_pad($stats['generated_at'], 61) . "║\n";
    $report .= "╚═══════════════════════════════════════════════════════════════════════════╝\n\n";

    // Assets Section
    $report .= "┌─────────────────────────────────────────────────────────────────────┐\n";
    $report .= "│ ASSETS OVERVIEW                                                     │\n";
    $report .= "├─────────────────────────────────────────────────────────────────────┤\n";
    $report .= "│ Total Assets: " . str_pad($stats['assets']['total_assets'], 55) . "│\n";
    $report .= "│ Tradeable: " . str_pad($stats['assets']['tradeable_assets'], 58) . "│\n";
    $report .= "│ Active: " . str_pad($stats['assets']['active_assets'], 61) . "│\n";
    $report .= "│ Total Market Cap: $" . str_pad(number_format($stats['assets']['total_market_cap'], 2), 53) . "│\n";
    $report .= "│ Total 24h Volume: $" . str_pad(number_format($stats['assets']['total_volume_24h'], 2), 53) . "│\n";
    $report .= "└─────────────────────────────────────────────────────────────────────┘\n\n";

    // Price Collection Section
    $report .= "┌─────────────────────────────────────────────────────────────────────┐\n";
    $report .= "│ PRICE COLLECTION (Last 24 Hours)                                    │\n";
    $report .= "├─────────────────────────────────────────────────────────────────────┤\n";
    $report .= "│ Today's Collections: " . str_pad(number_format($stats['prices']['today_count']), 49) . "│\n";
    $report .= "│ Last 24h Collections: " . str_pad(number_format($stats['prices']['last_24h_count']), 48) . "│\n";
    $report .= "│                                                                     │\n";
    $report .= "│ By Exchange:                                                        │\n";

    foreach ($stats['prices']['by_exchange'] as $ex) {
        $line = "│   " . str_pad($ex['name'], 20) . str_pad(number_format($ex['price_count']), 46);
        $report .= $line . "│\n";
    }

    $report .= "└─────────────────────────────────────────────────────────────────────┘\n\n";

    // Historical Data Section
    $report .= "┌─────────────────────────────────────────────────────────────────────┐\n";
    $report .= "│ HISTORICAL DATA                                                     │\n";
    $report .= "├─────────────────────────────────────────────────────────────────────┤\n";
    $report .= "│ Total Candles: " . str_pad(number_format($stats['historical']['overall']['total_candles']), 55) . "│\n";
    $report .= "│ Assets with History: " . str_pad($stats['historical']['overall']['assets_with_history'], 49) . "│\n";
    $report .= "│ Oldest Data: " . str_pad($stats['historical']['overall']['oldest_candle'], 57) . "│\n";
    $report .= "│ Newest Data: " . str_pad($stats['historical']['overall']['newest_candle'], 57) . "│\n";
    $report .= "│                                                                     │\n";
    $report .= "│ By Timeframe:                                                       │\n";

    foreach ($stats['historical']['by_timeframe'] as $tf) {
        $line = "│   " . str_pad($tf['timeframe'], 8) . str_pad(number_format($tf['count']) . " candles", 58);
        $report .= $line . "│\n";
    }

    $report .= "└─────────────────────────────────────────────────────────────────────┘\n\n";

    // TOP 10 Coverage
    $report .= "┌─────────────────────────────────────────────────────────────────────┐\n";
    $report .= "│ TOP 10 COVERAGE                                                     │\n";
    $report .= "├─────────────────────────────────────────────────────────────────────┤\n";

    foreach ($stats['historical']['top10_coverage'] as $asset) {
        $line = sprintf("│ #%-2d %-6s: %s candles (%d days)",
            $asset['market_cap_rank'],
            $asset['symbol'],
            str_pad(number_format($asset['candle_count']), 7),
            $asset['days_coverage']
        );
        $report .= str_pad($line, 71) . "│\n";
    }

    $report .= "└─────────────────────────────────────────────────────────────────────┘\n\n";

    // Top Performers
    $report .= "┌─────────────────────────────────────────────────────────────────────┐\n";
    $report .= "│ TOP 5 GAINERS (24h)                                                 │\n";
    $report .= "├─────────────────────────────────────────────────────────────────────┤\n";

    foreach ($stats['performers']['gainers'] as $gainer) {
        $line = sprintf("│ %-6s (%s%5.2f%%)  #%-3d",
            $gainer['symbol'],
            $gainer['percent_change_24h'] > 0 ? '+' : '',
            $gainer['percent_change_24h'],
            $gainer['market_cap_rank']
        );
        $report .= str_pad($line, 71) . "│\n";
    }

    $report .= "├─────────────────────────────────────────────────────────────────────┤\n";
    $report .= "│ TOP 5 LOSERS (24h)                                                  │\n";
    $report .= "├─────────────────────────────────────────────────────────────────────┤\n";

    foreach ($stats['performers']['losers'] as $loser) {
        $line = sprintf("│ %-6s (%5.2f%%)  #%-3d",
            $loser['symbol'],
            $loser['percent_change_24h'],
            $loser['market_cap_rank']
        );
        $report .= str_pad($line, 71) . "│\n";
    }

    $report .= "└─────────────────────────────────────────────────────────────────────┘\n\n";

    // Exchange Health
    $report .= "┌─────────────────────────────────────────────────────────────────────┐\n";
    $report .= "│ EXCHANGE HEALTH                                                     │\n";
    $report .= "├─────────────────────────────────────────────────────────────────────┤\n";

    foreach ($stats['exchanges'] as $ex) {
        $status = $ex['last_error_at'] ? '✗' : '✓';
        $minsAgo = $ex['minutes_since_success'] ?? 0;
        $line = sprintf("│ [%s] %-12s  Last success: %d min ago",
            $status,
            $ex['exchange_id'],
            $minsAgo
        );
        $report .= str_pad($line, 71) . "│\n";
    }

    $report .= "└─────────────────────────────────────────────────────────────────────┘\n\n";

    // Database Size
    $report .= "┌─────────────────────────────────────────────────────────────────────┐\n";
    $report .= "│ DATABASE SIZE                                                       │\n";
    $report .= "├─────────────────────────────────────────────────────────────────────┤\n";
    $report .= "│ Total: " . str_pad($stats['database']['total_mb'] . " MB", 64) . "│\n";
    $report .= "│                                                                     │\n";
    $report .= "│ Top 5 Tables:                                                       │\n";

    foreach (array_slice($stats['database']['tables'], 0, 5) as $table) {
        $line = sprintf("│   %-30s %7.2f MB  (%s rows)",
            $table['table_name'],
            $table['size_mb'],
            number_format($table['table_rows'])
        );
        $report .= str_pad($line, 71) . "│\n";
    }

    $report .= "└─────────────────────────────────────────────────────────────────────┘\n";

    return $report;
}
