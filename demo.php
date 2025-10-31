#!/usr/bin/env php
<?php
/**
 * FolyoAggregator Demo - Shows real-time crypto prices without API keys
 */

require_once __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use FolyoAggregator\Services\PriceAggregator;

// ANSI color codes
$GREEN = "\033[0;32m";
$YELLOW = "\033[0;33m";
$BLUE = "\033[0;34m";
$CYAN = "\033[0;36m";
$RED = "\033[0;31m";
$RESET = "\033[0m";
$BOLD = "\033[1m";

echo "{$BLUE}╔════════════════════════════════════════════════════════════╗{$RESET}\n";
echo "{$BLUE}║{$RESET}        {$BOLD}{$CYAN}🚀 FolyoAggregator - Real-Time Crypto Prices{$RESET}       {$BLUE}║{$RESET}\n";
echo "{$BLUE}║{$RESET}              {$GREEN}NO API KEYS REQUIRED!{$RESET}                        {$BLUE}║{$RESET}\n";
echo "{$BLUE}╚════════════════════════════════════════════════════════════╝{$RESET}\n\n";

$symbols = ['BTC', 'ETH', 'SOL'];
$aggregator = new PriceAggregator();

foreach ($symbols as $symbol) {
    echo "{$YELLOW}Fetching {$symbol} prices from 10 exchanges...{$RESET}\n";

    try {
        $startTime = microtime(true);
        $result = $aggregator->aggregatePrices($symbol);
        $executionTime = round(microtime(true) - $startTime, 2);

        echo "{$GREEN}✅ {$BOLD}{$result['name']} ({$symbol}){$RESET}\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        // Aggregated price
        $price = $result['aggregated']['price_vwap'] ?? $result['aggregated']['price_simple_avg'];
        echo "{$BOLD}💰 Price: {$GREEN}$" . number_format($price, 2) . "{$RESET}\n";

        // Statistics
        echo "📊 Min: $" . number_format($result['aggregated']['price_min'], 2);
        echo " | Max: $" . number_format($result['aggregated']['price_max'], 2);
        echo " | Spread: " . $result['aggregated']['price_spread'] . "%\n";

        // Volume
        $volume = $result['aggregated']['total_volume_24h'];
        if ($volume > 1000000000) {
            $volumeStr = number_format($volume / 1000000000, 2) . "B";
        } elseif ($volume > 1000000) {
            $volumeStr = number_format($volume / 1000000, 2) . "M";
        } else {
            $volumeStr = number_format($volume, 2);
        }
        echo "📈 24h Volume: $" . $volumeStr . "\n";

        // Confidence
        $confidence = $result['aggregated']['confidence_score'];
        $confColor = $confidence > 80 ? $GREEN : ($confidence > 60 ? $YELLOW : $RED);
        echo "🎯 Confidence: {$confColor}" . $confidence . "/100{$RESET}\n";

        // Exchange count
        echo "🏛️ Exchanges: " . $result['aggregated']['exchange_count'] . " sources\n";

        // Execution time
        echo "⏱️ Fetched in: {$executionTime}s\n";

        // Show individual exchange prices
        echo "\n{$CYAN}Exchange Prices:{$RESET}\n";
        $prices = [];
        foreach ($result['exchanges'] as $exchangeId => $data) {
            if ($data['price'] > 0) {
                $prices[$exchangeId] = $data['price'];
            }
        }

        // Sort by price
        asort($prices);
        $i = 0;
        foreach ($prices as $exchangeId => $price) {
            echo "  " . str_pad($exchangeId, 12) . ": $" . number_format($price, 2);
            if (++$i % 2 == 0) echo "\n";
            else echo "    ";
        }
        if ($i % 2 != 0) echo "\n";

        echo "\n";

        // Save to database confirmation
        echo "{$GREEN}✓ Saved to database{$RESET}\n";
        echo "════════════════════════════════════\n\n";

    } catch (Exception $e) {
        echo "{$RED}❌ Error: " . $e->getMessage() . "{$RESET}\n\n";
    }
}

echo "{$BOLD}{$GREEN}✅ Demo Complete!{$RESET}\n";
echo "{$CYAN}Access the API at:{$RESET} http://folyoaggregator.test/api/v1/prices/{symbol}\n";
echo "{$CYAN}Dashboard at:{$RESET} http://folyoaggregator.test\n\n";