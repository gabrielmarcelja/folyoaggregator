#!/usr/bin/env php
<?php
/**
 * CoinMarketCap Sync Script
 * Synchronizes cryptocurrency data from CMC to FolyoAggregator
 *
 * Usage:
 *   php scripts/sync-cmc.php --limit=100
 *   php scripts/sync-cmc.php --limit=500 --no-validate
 *   php scripts/sync-cmc.php --test
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load dependencies
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->load();

use FolyoAggregator\Services\CmcSyncService;
use FolyoAggregator\Services\CoinMarketCapClient;
use FolyoAggregator\Services\SymbolValidator;
use FolyoAggregator\Core\Database;

// Parse CLI arguments
$options = getopt('', ['limit:', 'no-validate', 'test', 'help']);

// Show help if requested
if (isset($options['help'])) {
    echo <<<HELP
╔═══════════════════════════════════════════════════════════════════╗
║                  CoinMarketCap Sync Script                       ║
╚═══════════════════════════════════════════════════════════════════╝

Usage:
  php scripts/sync-cmc.php [OPTIONS]

Options:
  --limit=N         Number of top coins to sync (default: 100)
                    Recommended values: 100, 200, 500, 1000

  --no-validate     Skip CCXT exchange validation (faster but less accurate)

  --test            Test mode: sync only top 10 coins with validation

  --help            Show this help message

Examples:
  php scripts/sync-cmc.php --limit=100
  php scripts/sync-cmc.php --limit=500 --no-validate
  php scripts/sync-cmc.php --test

Notes:
  - API Key: Uses the CMC API key from .env or Folyo's key
  - Rate Limits: Script respects CMC rate limits (2s between batches)
  - Validation: Checks if coins are tradeable on CCXT exchanges
  - Database: Updates existing coins or adds new ones

HELP;
    exit(0);
}

// Parse options
$isTest = isset($options['test']);
$limit = $isTest ? 10 : (int)($options['limit'] ?? 100);
$validate = !isset($options['no-validate']);

// Validate limit
if (!$isTest && ($limit < 1 || $limit > 5000)) {
    echo "❌ Error: Limit must be between 1 and 5000\n";
    exit(1);
}

// ANSI color codes
$GREEN = "\033[0;32m";
$YELLOW = "\033[0;33m";
$BLUE = "\033[0;34m";
$RED = "\033[0;31m";
$CYAN = "\033[0;36m";
$RESET = "\033[0m";
$BOLD = "\033[1m";

// Display header
echo "{$BLUE}╔═══════════════════════════════════════════════════════╗{$RESET}\n";
echo "{$BLUE}║{$RESET}       {$BOLD}{$CYAN}CoinMarketCap Synchronization Script{$RESET}        {$BLUE}║{$RESET}\n";
echo "{$BLUE}╚═══════════════════════════════════════════════════════╝{$RESET}\n\n";

// Display configuration
echo "{$BOLD}Configuration:{$RESET}\n";
echo "  Mode: " . ($isTest ? "{$YELLOW}TEST MODE{$RESET}" : "{$GREEN}PRODUCTION{$RESET}") . "\n";
echo "  Top coins to sync: {$CYAN}$limit{$RESET}\n";
echo "  CCXT validation: " . ($validate ? "{$GREEN}Enabled{$RESET}" : "{$YELLOW}Disabled{$RESET}") . "\n";
echo "  CMC API key: {$CYAN}" . substr(env('CMC_API_KEY', 'not set'), 0, 10) . "...{$RESET}\n\n";

// Test mode checks
if ($isTest) {
    echo "{$YELLOW}Running in TEST MODE - Testing connectivity first...{$RESET}\n";
    echo str_repeat('─', 60) . "\n\n";

    // Test 1: CMC API connectivity
    echo "1. Testing CoinMarketCap API...\n";
    try {
        $cmcClient = new CoinMarketCapClient();
        if ($cmcClient->testConnection()) {
            echo "   {$GREEN}✅ CMC API is accessible{$RESET}\n";

            // Get API credits info
            $credits = $cmcClient->getApiCredits();
            if ($credits && isset($credits['credit_count'])) {
                echo "   Credits used: {$credits['credit_count']}\n";
            }
        } else {
            echo "   {$RED}❌ CMC API connection failed{$RESET}\n";
            exit(1);
        }
    } catch (Exception $e) {
        echo "   {$RED}❌ CMC API error: " . $e->getMessage() . "{$RESET}\n";
        exit(1);
    }

    // Test 2: Database connectivity
    echo "\n2. Testing database connection...\n";
    try {
        $db = Database::getInstance();
        $test = $db->fetchOne("SELECT 1 as test");
        if ($test) {
            echo "   {$GREEN}✅ Database connected{$RESET}\n";

            // Get current asset count
            $assetCount = $db->fetchOne("SELECT COUNT(*) as count FROM assets")['count'];
            echo "   Current assets in database: {$assetCount}\n";
        }
    } catch (Exception $e) {
        echo "   {$RED}❌ Database error: " . $e->getMessage() . "{$RESET}\n";
        exit(1);
    }

    // Test 3: CCXT validation
    if ($validate) {
        echo "\n3. Testing CCXT exchange connections...\n";
        try {
            $validator = new SymbolValidator();
            $testSymbols = ['BTC', 'ETH'];

            foreach ($testSymbols as $symbol) {
                $result = $validator->validate($symbol);
                $status = $result['tradeable'] ? "{$GREEN}✅ Tradeable{$RESET}" : "{$YELLOW}⚠️  Not tradeable{$RESET}";
                echo "   $symbol: $status";
                if ($result['tradeable']) {
                    echo " (found on {$result['exchange_count']} exchanges)";
                }
                echo "\n";
            }
        } catch (Exception $e) {
            echo "   {$YELLOW}⚠️  CCXT validation warning: " . $e->getMessage() . "{$RESET}\n";
        }
    }

    echo "\n" . str_repeat('─', 60) . "\n";
    echo "{$GREEN}All tests passed! Starting sync...{$RESET}\n\n";
}

// Record start time
$startTime = microtime(true);

try {
    // Initialize sync service
    $syncService = new CmcSyncService();

    // Perform synchronization
    $stats = $syncService->syncTopCoins($limit, $validate, $isTest ? 'test' : 'manual');

    // Calculate duration
    $duration = round(microtime(true) - $startTime, 2);

    // Display results
    echo "\n" . str_repeat('═', 60) . "\n";
    echo "{$GREEN}{$BOLD}✅ Synchronization Complete!{$RESET}\n\n";

    echo "{$BOLD}📊 Statistics:{$RESET}\n";
    echo "  • Processed: {$CYAN}{$stats['processed']}{$RESET} coins\n";
    echo "  • Added: {$GREEN}{$stats['added']}{$RESET} new coins\n";
    echo "  • Updated: {$BLUE}{$stats['updated']}{$RESET} existing coins\n";
    echo "  • Skipped: {$YELLOW}{$stats['skipped']}{$RESET} coins\n";

    if ($validate) {
        echo "  • Validated: {$CYAN}{$stats['validated']}{$RESET} coins\n";
        echo "  • Tradeable: {$GREEN}{$stats['tradeable']}{$RESET} coins";

        if ($stats['validated'] > 0) {
            $tradeablePercent = round(($stats['tradeable'] / $stats['validated']) * 100, 1);
            echo " ({$tradeablePercent}% success rate)";
        }
        echo "\n";
    }

    if ($stats['errors'] > 0) {
        echo "  • Errors: {$RED}{$stats['errors']}{$RESET} coins\n";
    }

    echo "\n  ⏱️  Duration: {$CYAN}{$duration} seconds{$RESET}\n";
    echo "  💾 Memory peak: {$CYAN}" . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB{$RESET}\n";

    // Get last sync info from database
    $lastSync = $syncService->getLastSync();
    if ($lastSync) {
        echo "\n{$BOLD}📅 Sync Record:{$RESET}\n";
        echo "  • Sync ID: #{$lastSync['id']}\n";
        echo "  • Type: {$lastSync['sync_type']}\n";
        echo "  • Status: {$lastSync['status']}\n";
    }

    // Show next steps
    echo "\n{$BOLD}📝 Next Steps:{$RESET}\n";
    echo "  1. Review the synced coins: {$CYAN}mariadb -u root -p1976 folyoaggregator{$RESET}\n";
    echo "     {$CYAN}SELECT symbol, name, is_tradeable FROM assets ORDER BY market_cap_rank LIMIT 20;{$RESET}\n";
    echo "  2. Test price aggregation: {$CYAN}php demo.php{$RESET}\n";
    echo "  3. Set up cron job for regular syncs:\n";
    echo "     {$CYAN}0 * * * * cd /var/www/html/folyoaggregator && php scripts/sync-cmc.php --limit=100{$RESET}\n";

    if ($isTest) {
        echo "\n{$YELLOW}Test mode completed successfully!{$RESET}\n";
        echo "Run without --test flag to sync more coins.\n";
    }

    echo "\n";
    exit(0);

} catch (Exception $e) {
    // Display error
    echo "\n" . str_repeat('═', 60) . "\n";
    echo "{$RED}{$BOLD}❌ Synchronization Failed!{$RESET}\n\n";
    echo "{$RED}Error: " . $e->getMessage() . "{$RESET}\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";

    // Log to file
    $errorLog = __DIR__ . '/../logs/cmc-sync-error.log';
    $errorMessage = date('Y-m-d H:i:s') . " - Sync failed: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n";
    file_put_contents($errorLog, $errorMessage, FILE_APPEND);
    echo "\nError logged to: {$errorLog}\n\n";

    exit(1);
}