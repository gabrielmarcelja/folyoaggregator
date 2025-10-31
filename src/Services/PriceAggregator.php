<?php
namespace FolyoAggregator\Services;

use FolyoAggregator\Core\Database;
use FolyoAggregator\Exchanges\ExchangeManager;
use Exception;

/**
 * Price Aggregator Service
 * Aggregates prices from multiple exchanges using various methods
 */
class PriceAggregator {
    private Database $db;
    private ExchangeManager $exchangeManager;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->exchangeManager = new ExchangeManager();
    }

    /**
     * Fetch and aggregate prices for a specific symbol
     *
     * @param string $symbol
     * @return array
     */
    public function aggregatePrices(string $symbol): array {
        // Get asset ID from database
        $asset = $this->db->fetchOne(
            "SELECT id, symbol, name FROM assets WHERE symbol = ? AND is_active = 1",
            [$symbol]
        );

        if (!$asset) {
            throw new Exception("Asset $symbol not found");
        }

        // Fetch prices from all exchanges
        $exchangePrices = $this->exchangeManager->fetchTickerFromAll($symbol);

        if (empty($exchangePrices)) {
            throw new Exception("No price data available for $symbol");
        }

        // Store individual exchange prices
        $this->storePrices($asset['id'], $exchangePrices);

        // Calculate aggregated values
        $aggregated = $this->calculateAggregatedPrice($exchangePrices);

        // Store aggregated price
        $this->storeAggregatedPrice($asset['id'], $aggregated);

        return [
            'symbol' => $symbol,
            'name' => $asset['name'],
            'aggregated' => $aggregated,
            'exchanges' => $exchangePrices,
            'timestamp' => time()
        ];
    }

    /**
     * Calculate aggregated price from exchange data
     *
     * @param array $exchangePrices
     * @return array
     */
    private function calculateAggregatedPrice(array $exchangePrices): array {
        $prices = [];
        $volumes = [];
        $totalVolume = 0;

        foreach ($exchangePrices as $data) {
            if ($data['price'] && $data['price'] > 0) {
                $prices[] = $data['price'];
                $volume = $data['volume_24h'] ?? 0;
                $volumes[] = $volume;
                $totalVolume += $volume;
            }
        }

        if (empty($prices)) {
            throw new Exception("No valid prices to aggregate");
        }

        // Calculate simple average
        $simpleAvg = array_sum($prices) / count($prices);

        // Calculate median
        sort($prices);
        $count = count($prices);
        $median = ($count % 2 == 0)
            ? ($prices[$count/2 - 1] + $prices[$count/2]) / 2
            : $prices[floor($count/2)];

        // Calculate VWAP (Volume Weighted Average Price)
        $vwap = null;
        if ($totalVolume > 0) {
            $weightedSum = 0;
            foreach ($exchangePrices as $data) {
                if ($data['price'] && $data['volume_24h']) {
                    $weightedSum += $data['price'] * $data['volume_24h'];
                }
            }
            $vwap = $weightedSum / $totalVolume;
        }

        // Calculate spread
        $minPrice = min($prices);
        $maxPrice = max($prices);
        $spread = (($maxPrice - $minPrice) / $minPrice) * 100;

        // Calculate confidence score (0-100)
        $confidence = $this->calculateConfidenceScore($prices, $volumes, count($exchangePrices));

        return [
            'price_simple_avg' => round($simpleAvg, 8),
            'price_vwap' => $vwap ? round($vwap, 8) : null,
            'price_median' => round($median, 8),
            'price_min' => round($minPrice, 8),
            'price_max' => round($maxPrice, 8),
            'price_spread' => round($spread, 4),
            'total_volume_24h' => round($totalVolume, 8),
            'exchange_count' => count($prices),
            'confidence_score' => round($confidence, 2)
        ];
    }

    /**
     * Calculate confidence score based on various factors
     *
     * @param array $prices
     * @param array $volumes
     * @param int $totalExchanges
     * @return float
     */
    private function calculateConfidenceScore(array $prices, array $volumes, int $totalExchanges): float {
        $score = 0;

        // Factor 1: Number of exchanges (max 30 points)
        $exchangeScore = min(30, ($totalExchanges / 10) * 30);
        $score += $exchangeScore;

        // Factor 2: Price consistency (max 40 points)
        $avg = array_sum($prices) / count($prices);
        $variance = 0;
        foreach ($prices as $price) {
            $variance += pow($price - $avg, 2);
        }
        $stdDev = sqrt($variance / count($prices));
        $coefficientOfVariation = ($stdDev / $avg) * 100;

        // Lower coefficient of variation = higher consistency
        $consistencyScore = max(0, 40 - $coefficientOfVariation);
        $score += $consistencyScore;

        // Factor 3: Volume distribution (max 30 points)
        if (count($volumes) > 0 && array_sum($volumes) > 0) {
            $totalVolume = array_sum($volumes);
            $maxVolume = max($volumes);
            $volumeConcentration = $maxVolume / $totalVolume;

            // Lower concentration = better distribution
            $volumeScore = (1 - $volumeConcentration) * 30;
            $score += $volumeScore;
        }

        return min(100, max(0, $score));
    }

    /**
     * Store individual exchange prices
     *
     * @param int $assetId
     * @param array $exchangePrices
     */
    private function storePrices(int $assetId, array $exchangePrices): void {
        $timestamp = date('Y-m-d H:i:s');

        foreach ($exchangePrices as $exchangeId => $data) {
            try {
                // Get exchange ID from database
                $exchange = $this->db->fetchOne(
                    "SELECT id FROM exchanges WHERE exchange_id = ?",
                    [$exchangeId]
                );

                if (!$exchange) {
                    continue;
                }

                $this->db->insert('prices', [
                    'asset_id' => $assetId,
                    'exchange_id' => $exchange['id'],
                    'price' => $data['price'],
                    'volume_24h' => $data['volume_24h'],
                    'bid_price' => $data['bid'],
                    'ask_price' => $data['ask'],
                    'high_24h' => $data['high_24h'],
                    'low_24h' => $data['low_24h'],
                    'change_24h_percent' => $data['change_24h_percent'],
                    'timestamp' => $timestamp
                ]);
            } catch (Exception $e) {
                error_log("Failed to store price for exchange $exchangeId: " . $e->getMessage());
            }
        }
    }

    /**
     * Store aggregated price
     *
     * @param int $assetId
     * @param array $aggregated
     */
    private function storeAggregatedPrice(int $assetId, array $aggregated): void {
        try {
            $this->db->insert('aggregated_prices', array_merge(
                ['asset_id' => $assetId],
                $aggregated,
                ['timestamp' => date('Y-m-d H:i:s')]
            ));
        } catch (Exception $e) {
            error_log("Failed to store aggregated price: " . $e->getMessage());
        }
    }

    /**
     * Get latest aggregated price for an asset
     *
     * @param string $symbol
     * @return array|null
     */
    public function getLatestPrice(string $symbol): ?array {
        $result = $this->db->fetchOne("
            SELECT
                a.symbol,
                a.name,
                ap.*
            FROM aggregated_prices ap
            JOIN assets a ON a.id = ap.asset_id
            WHERE a.symbol = ?
            ORDER BY ap.timestamp DESC
            LIMIT 1
        ", [$symbol]);

        return $result ?: null;
    }

    /**
     * Get price history for an asset
     *
     * @param string $symbol
     * @param int $limit
     * @return array
     */
    public function getPriceHistory(string $symbol, int $limit = 100): array {
        return $this->db->fetchAll("
            SELECT
                a.symbol,
                ap.*
            FROM aggregated_prices ap
            JOIN assets a ON a.id = ap.asset_id
            WHERE a.symbol = ?
            ORDER BY ap.timestamp DESC
            LIMIT ?
        ", [$symbol, $limit]);
    }
}