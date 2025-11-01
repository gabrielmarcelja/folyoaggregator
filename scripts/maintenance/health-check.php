#!/usr/bin/env php
<?php
/**
 * FolyoAggregator Health Check
 * Monitors system health and reports status
 *
 * Usage: php scripts/maintenance/health-check.php [--json]
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use FolyoAggregator\Core\Database;

// Parse arguments
$jsonOutput = in_array('--json', $argv);

/**
 * Check database connectivity
 */
function checkDatabase() {
    try {
        $db = Database::getInstance();
        $result = $db->fetchOne("SELECT COUNT(*) as count FROM assets");
        return [
            'status' => 'healthy',
            'assets_count' => $result['count'],
            'message' => 'Database connected'
        ];
    } catch (Exception $e) {
        return [
            'status' => 'unhealthy',
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

/**
 * Check if data is being updated recently
 */
function checkDataFreshness() {
    try {
        $db = Database::getInstance();

        // Check latest price update
        $latestPrice = $db->fetchOne("
            SELECT MAX(updated_at) as latest
            FROM prices
        ");

        if ($latestPrice && $latestPrice['latest']) {
            $latestTime = strtotime($latestPrice['latest']);
            $now = time();
            $ageMinutes = ($now - $latestTime) / 60;

            if ($ageMinutes <= 5) {
                return [
                    'status' => 'healthy',
                    'last_update' => $latestPrice['latest'],
                    'age_minutes' => round($ageMinutes, 1),
                    'message' => 'Data is fresh'
                ];
            } else {
                return [
                    'status' => 'warning',
                    'last_update' => $latestPrice['latest'],
                    'age_minutes' => round($ageMinutes, 1),
                    'message' => 'Data may be stale (>' . round($ageMinutes) . ' minutes old)'
                ];
            }
        }

        return [
            'status' => 'unhealthy',
            'message' => 'No price data found'
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Check exchange connectivity
 */
function checkExchanges() {
    try {
        $db = Database::getInstance();

        $stats = $db->fetchOne("
            SELECT
                COUNT(*) as total_exchanges,
                SUM(CASE WHEN last_error_at IS NULL THEN 1 ELSE 0 END) as healthy_exchanges,
                SUM(CASE WHEN last_error_at IS NOT NULL THEN 1 ELSE 0 END) as error_exchanges
            FROM exchanges
        ");

        $healthPercentage = ($stats['healthy_exchanges'] / $stats['total_exchanges']) * 100;

        if ($healthPercentage >= 80) {
            $status = 'healthy';
            $message = $stats['healthy_exchanges'] . '/' . $stats['total_exchanges'] . ' exchanges operational';
        } elseif ($healthPercentage >= 50) {
            $status = 'warning';
            $message = $stats['error_exchanges'] . ' exchanges with errors';
        } else {
            $status = 'unhealthy';
            $message = 'Multiple exchange failures';
        }

        return [
            'status' => $status,
            'total' => (int)$stats['total_exchanges'],
            'healthy' => (int)$stats['healthy_exchanges'],
            'errors' => (int)$stats['error_exchanges'],
            'health_percentage' => round($healthPercentage, 1),
            'message' => $message
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Check historical data coverage
 */
function checkHistoricalData() {
    try {
        $db = Database::getInstance();

        $stats = $db->fetchOne("
            SELECT
                COUNT(DISTINCT asset_id) as assets_with_history,
                COUNT(*) as total_candles,
                MIN(timestamp) as oldest_data,
                MAX(timestamp) as newest_data
            FROM historical_ohlcv
        ");

        if ($stats['total_candles'] > 100000) {
            return [
                'status' => 'healthy',
                'assets_with_history' => (int)$stats['assets_with_history'],
                'total_candles' => (int)$stats['total_candles'],
                'oldest_data' => $stats['oldest_data'],
                'newest_data' => $stats['newest_data'],
                'message' => 'Historical data complete'
            ];
        } elseif ($stats['total_candles'] > 0) {
            return [
                'status' => 'warning',
                'assets_with_history' => (int)$stats['assets_with_history'],
                'total_candles' => (int)$stats['total_candles'],
                'message' => 'Historical data incomplete'
            ];
        } else {
            return [
                'status' => 'unhealthy',
                'message' => 'No historical data'
            ];
        }
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Check disk space
 */
function checkDiskSpace() {
    $dbPath = '/var/www/html/folyoaggregator';
    $freeSpace = disk_free_space($dbPath);
    $totalSpace = disk_total_space($dbPath);
    $usedPercentage = (($totalSpace - $freeSpace) / $totalSpace) * 100;

    $freeGB = round($freeSpace / 1024 / 1024 / 1024, 2);

    if ($usedPercentage < 80) {
        $status = 'healthy';
        $message = $freeGB . ' GB free';
    } elseif ($usedPercentage < 90) {
        $status = 'warning';
        $message = 'Disk space running low: ' . $freeGB . ' GB free';
    } else {
        $status = 'unhealthy';
        $message = 'Critical: Low disk space (' . $freeGB . ' GB free)';
    }

    return [
        'status' => $status,
        'free_gb' => $freeGB,
        'used_percentage' => round($usedPercentage, 1),
        'message' => $message
    ];
}

/**
 * Determine overall health status
 */
function getOverallStatus($checks) {
    $hasUnhealthy = false;
    $hasWarning = false;

    foreach ($checks as $check) {
        if ($check['status'] === 'unhealthy' || $check['status'] === 'error') {
            $hasUnhealthy = true;
        } elseif ($check['status'] === 'warning') {
            $hasWarning = true;
        }
    }

    if ($hasUnhealthy) {
        return 'unhealthy';
    } elseif ($hasWarning) {
        return 'warning';
    } else {
        return 'healthy';
    }
}

// Run all checks
$checks = [
    'database' => checkDatabase(),
    'data_freshness' => checkDataFreshness(),
    'exchanges' => checkExchanges(),
    'historical_data' => checkHistoricalData(),
    'disk_space' => checkDiskSpace()
];

$overallStatus = getOverallStatus($checks);

$result = [
    'status' => $overallStatus,
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => $checks
];

// Output
if ($jsonOutput) {
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    exit($overallStatus === 'healthy' ? 0 : 1);
} else {
    // Human-readable output
    $colors = [
        'healthy' => "\033[32m",   // Green
        'warning' => "\033[33m",   // Yellow
        'unhealthy' => "\033[31m", // Red
        'error' => "\033[31m",     // Red
        'reset' => "\033[0m"
    ];

    echo "\n";
    echo "╔═══════════════════════════════════════════════════════════════╗\n";
    echo "║         FolyoAggregator Health Check Report                  ║\n";
    echo "╠═══════════════════════════════════════════════════════════════╣\n";
    echo "║ Timestamp: " . date('Y-m-d H:i:s T') . "                              ║\n";

    $statusColor = $colors[$overallStatus] ?? $colors['reset'];
    $statusText = strtoupper($overallStatus);
    echo "║ Overall Status: {$statusColor}{$statusText}{$colors['reset']}                                       ║\n";
    echo "╚═══════════════════════════════════════════════════════════════╝\n\n";

    foreach ($checks as $name => $check) {
        $checkName = ucwords(str_replace('_', ' ', $name));
        $statusIcon = $check['status'] === 'healthy' ? '✓' : ($check['status'] === 'warning' ? '⚠' : '✗');
        $statusColor = $colors[$check['status']] ?? $colors['reset'];

        echo "{$statusColor}[{$statusIcon}] {$checkName}{$colors['reset']}\n";
        echo "    Status: {$statusColor}" . ucfirst($check['status']) . "{$colors['reset']}\n";
        echo "    Message: " . $check['message'] . "\n";

        // Show additional details
        foreach ($check as $key => $value) {
            if ($key !== 'status' && $key !== 'message' && !is_array($value)) {
                echo "    " . ucwords(str_replace('_', ' ', $key)) . ": " . $value . "\n";
            }
        }
        echo "\n";
    }

    exit($overallStatus === 'healthy' ? 0 : 1);
}
