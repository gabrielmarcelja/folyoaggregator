#!/usr/bin/env php
<?php
/**
 * Test script to fetch prices from exchanges without API keys
 */

require_once __DIR__ . '/vendor/autoload.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use FolyoAggregator\Exchanges\ExchangeManager;
use FolyoAggregator\Services\PriceAggregator;

echo "===========================================\n";
echo "Testing Price Fetching (No API Keys Needed)\n";
echo "===========================================\n\n";

// Test Binance directly with CCXT
echo "Testing Binance with CCXT...\n";
try {
    $exchange = new \ccxt\binance([
        'enableRateLimit' => true
    ]);

    // Fetch BTC price
    $ticker = $exchange->fetch_ticker('BTC/USDT');
    echo "✅ Binance BTC/USDT Price: $" . number_format($ticker['last'], 2) . "\n";
    echo "   24h Volume: " . number_format($ticker['baseVolume'], 2) . " BTC\n";
    echo "   24h Change: " . ($ticker['percentage'] > 0 ? '+' : '') . number_format($ticker['percentage'], 2) . "%\n\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n\n";
}

// Test multiple exchanges
echo "Testing Multiple Exchanges...\n";
echo "----------------------------\n";

$exchanges = ['binance', 'coinbase', 'kraken', 'bitstamp'];
$symbol = 'BTC/USDT';
$prices = [];

foreach ($exchanges as $exchangeId) {
    try {
        $className = "\\ccxt\\$exchangeId";
        $exchange = new $className(['enableRateLimit' => true]);

        // Adjust symbol for different exchanges
        $testSymbol = $symbol;
        if ($exchangeId === 'coinbase') {
            $testSymbol = 'BTC/USD';  // Coinbase uses USD
        }
        if ($exchangeId === 'kraken') {
            $testSymbol = 'BTC/USD';  // Kraken also uses USD
        }

        $ticker = $exchange->fetch_ticker($testSymbol);
        $price = $ticker['last'];
        $prices[] = $price;

        echo "✅ $exchangeId: $" . number_format($price, 2) . "\n";

    } catch (Exception $e) {
        echo "❌ $exchangeId: Failed - " . substr($e->getMessage(), 0, 50) . "...\n";
    }
}

if (count($prices) > 0) {
    echo "\n----------------------------\n";
    echo "📊 Aggregated Results:\n";
    echo "   Average Price: $" . number_format(array_sum($prices) / count($prices), 2) . "\n";
    echo "   Min Price: $" . number_format(min($prices), 2) . "\n";
    echo "   Max Price: $" . number_format(max($prices), 2) . "\n";
    echo "   Spread: " . number_format(((max($prices) - min($prices)) / min($prices)) * 100, 2) . "%\n";
}

echo "\n✅ As you can see, NO API KEYS NEEDED for public price data!\n\n";