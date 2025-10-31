#!/usr/bin/env php
<?php
/**
 * Test API BTC price aggregation
 */

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use FolyoAggregator\Services\PriceAggregator;

echo "Testing BTC price aggregation...\n\n";

try {
    $aggregator = new PriceAggregator();
    $result = $aggregator->aggregatePrices('BTC');

    echo "✅ Success! BTC Price Data:\n";
    echo "============================\n";
    echo "Symbol: " . $result['symbol'] . "\n";
    echo "Name: " . $result['name'] . "\n\n";

    echo "📊 Aggregated Prices:\n";
    echo "  Simple Average: $" . number_format($result['aggregated']['price_simple_avg'], 2) . "\n";
    echo "  VWAP: $" . number_format($result['aggregated']['price_vwap'] ?? 0, 2) . "\n";
    echo "  Median: $" . number_format($result['aggregated']['price_median'], 2) . "\n";
    echo "  Min: $" . number_format($result['aggregated']['price_min'], 2) . "\n";
    echo "  Max: $" . number_format($result['aggregated']['price_max'], 2) . "\n";
    echo "  Spread: " . $result['aggregated']['price_spread'] . "%\n";
    echo "  Exchanges Used: " . $result['aggregated']['exchange_count'] . "\n";
    echo "  Confidence Score: " . $result['aggregated']['confidence_score'] . "/100\n\n";

    echo "💱 Exchange Prices:\n";
    foreach ($result['exchanges'] as $exchangeId => $data) {
        echo "  " . str_pad($exchangeId, 10) . ": $" . number_format($data['price'], 2) . "\n";
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Details: " . $e->getTraceAsString() . "\n";
}