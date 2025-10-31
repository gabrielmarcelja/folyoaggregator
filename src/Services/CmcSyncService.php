<?php
namespace FolyoAggregator\Services;

use FolyoAggregator\Core\Database;
use Exception;

/**
 * CoinMarketCap Synchronization Service
 * Synchronizes cryptocurrency data from CoinMarketCap to local database
 */
class CmcSyncService {
    private Database $db;
    private CoinMarketCapClient $cmcClient;
    private SymbolValidator $validator;
    private ?int $syncLogId = null;
    private array $stats = [
        'processed' => 0,
        'added' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
        'validated' => 0,
        'tradeable' => 0
    ];
    private bool $debug = false;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->cmcClient = new CoinMarketCapClient();
        $this->validator = new SymbolValidator();
        $this->debug = env('APP_DEBUG', false);
    }

    /**
     * Sync top N cryptocurrencies from CMC
     *
     * @param int $limit Number of coins to sync
     * @param bool $validateTradeability Check if tradeable on CCXT exchanges
     * @param string $initiatedBy Who initiated the sync
     * @return array Sync statistics
     */
    public function syncTopCoins(int $limit = 100, bool $validateTradeability = true, string $initiatedBy = 'manual'): array {
        $this->resetStats();
        $this->startSync('incremental', $initiatedBy);

        try {
            echo "Starting CoinMarketCap sync for top $limit cryptocurrencies...\n";
            echo str_repeat('─', 50) . "\n\n";

            // CMC allows up to 5000 per request, but we'll use smaller batches
            $batchSize = min(100, $limit);
            $batches = (int)ceil($limit / $batchSize);

            for ($i = 0; $i < $batches; $i++) {
                $start = ($i * $batchSize) + 1;
                $currentBatchSize = min($batchSize, $limit - ($i * $batchSize));

                echo "📥 Fetching batch " . ($i + 1) . "/$batches (ranks $start-" . ($start + $currentBatchSize - 1) . ")...\n";

                try {
                    $response = $this->cmcClient->getListings($start, $currentBatchSize);

                    if (!isset($response['data'])) {
                        throw new Exception("Invalid CMC response structure");
                    }

                    echo "   Retrieved " . count($response['data']) . " coins\n";

                    // Process each coin in the batch
                    foreach ($response['data'] as $coinData) {
                        $this->processCoin($coinData, $validateTradeability);
                    }

                    // Update sync log with progress
                    $this->updateSyncProgress();

                    // Rate limiting between batches
                    if ($i < $batches - 1) {
                        echo "\n⏸  Waiting 2 seconds before next batch...\n\n";
                        sleep(2);
                    }

                } catch (Exception $e) {
                    echo "❌ Error fetching batch: " . $e->getMessage() . "\n";
                    $this->stats['errors']++;
                    continue;
                }
            }

            $this->endSync('completed');

            echo "\n" . str_repeat('═', 50) . "\n";
            echo "✅ Synchronization completed!\n";

        } catch (Exception $e) {
            $this->endSync('failed', $e->getMessage());
            throw $e;
        }

        return $this->stats;
    }

    /**
     * Process a single coin from CMC data
     *
     * @param array $coinData
     * @param bool $validateTradeability
     */
    private function processCoin(array $coinData, bool $validateTradeability): void {
        $this->stats['processed']++;

        try {
            $symbol = $coinData['symbol'];
            $name = $coinData['name'];
            $cmcId = $coinData['id'];

            // Get USD quote data
            $quote = $coinData['quote']['USD'] ?? null;

            if (!$quote) {
                echo "   ⏭  Skipping $symbol - No USD quote data\n";
                $this->stats['skipped']++;
                return;
            }

            // Check if asset already exists
            $existing = $this->db->fetchOne(
                "SELECT id, symbol, name, is_tradeable, cmc_id FROM assets WHERE cmc_id = ? OR (symbol = ? AND cmc_id IS NULL)",
                [$cmcId, $symbol]
            );

            // Validate tradeability if requested
            $tradeabilityInfo = ['tradeable' => false, 'exchanges' => [], 'tradeability_score' => 0];
            if ($validateTradeability && !in_array($symbol, ['USDT', 'USDC', 'BUSD'])) {
                $this->stats['validated']++;
                $tradeabilityInfo = $this->validator->validate($symbol);
                if ($tradeabilityInfo['tradeable']) {
                    $this->stats['tradeable']++;
                }
            }

            // Prepare asset data
            $assetData = [
                'cmc_id' => $cmcId,
                'symbol' => $symbol,
                'name' => $name,
                'slug' => $coinData['slug'],
                'cmc_slug' => $coinData['slug'],
                'market_cap_rank' => $coinData['cmc_rank'] ?? $coinData['rank'] ?? null,
                'is_tradeable' => $tradeabilityInfo['tradeable'] ? 1 : 0,
                'circulating_supply' => $this->sanitizeNumeric($coinData['circulating_supply']),
                'total_supply' => $this->sanitizeNumeric($coinData['total_supply']),
                'max_supply' => $this->sanitizeNumeric($coinData['max_supply']),
                'market_cap' => $this->sanitizeNumeric($quote['market_cap']),
                'fully_diluted_market_cap' => $this->sanitizeNumeric($quote['fully_diluted_market_cap']),
                'volume_24h' => $this->sanitizeNumeric($quote['volume_24h']),
                'volume_change_24h' => $this->sanitizeNumeric($quote['volume_change_24h'] ?? null),
                'percent_change_1h' => $this->sanitizeNumeric($quote['percent_change_1h']),
                'percent_change_24h' => $this->sanitizeNumeric($quote['percent_change_24h']),
                'percent_change_7d' => $this->sanitizeNumeric($quote['percent_change_7d']),
                'percent_change_30d' => $this->sanitizeNumeric($quote['percent_change_30d'] ?? null),
                'market_cap_dominance' => $this->sanitizeNumeric($quote['market_cap_dominance'] ?? null),
                'num_market_pairs' => $coinData['num_market_pairs'] ?? null,
                'date_added' => isset($coinData['date_added']) ? date('Y-m-d', strtotime($coinData['date_added'])) : null,
                'tags' => json_encode($coinData['tags'] ?? []),
                'platform_id' => $coinData['platform']['id'] ?? null,
                'platform_name' => $coinData['platform']['name'] ?? null,
                'token_address' => $coinData['platform']['token_address'] ?? null,
                'preferred_quote_currency' => $this->getPreferredQuote($tradeabilityInfo),
                'tradeable_exchanges' => json_encode($this->getExchangeNames($tradeabilityInfo)),
                'last_tradeable_check' => $validateTradeability ? date('Y-m-d H:i:s') : null,
                'cmc_last_sync' => date('Y-m-d H:i:s'),
                'is_active' => 1
            ];

            // Handle logo URL
            $assetData['icon_url'] = "https://s2.coinmarketcap.com/static/img/coins/64x64/{$cmcId}.png";

            if ($existing) {
                // Update existing asset
                $assetId = $existing['id'];
                unset($assetData['is_active']); // Don't override manual deactivation

                // If not validating tradeability, keep existing tradeable status
                if (!$validateTradeability) {
                    unset($assetData['is_tradeable']);
                    unset($assetData['tradeable_exchanges']);
                    unset($assetData['preferred_quote_currency']);
                    unset($assetData['last_tradeable_check']);
                }

                $this->db->update('assets', $assetData, ['id' => $assetId]);
                $this->stats['updated']++;

                $status = $tradeabilityInfo['tradeable'] ? '✅ Tradeable' : '⚠️  Not tradeable';
                $exchanges = $tradeabilityInfo['tradeable'] ?
                    " on " . count($tradeabilityInfo['exchanges']) . " exchanges" : "";
                echo "   📝 Updated: #$cmcId $symbol ($name) - $status$exchanges\n";

            } else {
                // Insert new asset
                $assetId = $this->db->insert('assets', $assetData);
                $this->stats['added']++;

                $status = $tradeabilityInfo['tradeable'] ? '✅ Tradeable' : '⚠️  Not tradeable';
                $exchanges = $tradeabilityInfo['tradeable'] ?
                    " on " . count($tradeabilityInfo['exchanges']) . " exchanges" : "";
                echo "   ✅ Added: #$cmcId $symbol ($name) - $status$exchanges\n";
            }

        } catch (Exception $e) {
            $this->stats['errors']++;
            echo "   ❌ Error processing {$coinData['symbol']}: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Get preferred quote currency from tradeability info
     *
     * @param array $tradeabilityInfo
     * @return string
     */
    private function getPreferredQuote(array $tradeabilityInfo): string {
        if (!empty($tradeabilityInfo['preferred_pair'])) {
            $parts = explode('/', $tradeabilityInfo['preferred_pair']);
            return $parts[1] ?? 'USDT';
        }
        return 'USDT';
    }

    /**
     * Get list of exchange names from tradeability info
     *
     * @param array $tradeabilityInfo
     * @return array
     */
    private function getExchangeNames(array $tradeabilityInfo): array {
        $exchanges = [];
        foreach ($tradeabilityInfo['exchanges'] ?? [] as $exchange) {
            $exchanges[] = $exchange['exchange'];
        }
        return array_unique($exchanges);
    }

    /**
     * Sanitize numeric value
     *
     * @param mixed $value
     * @return float|null
     */
    private function sanitizeNumeric($value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (float)$value : null;
    }

    /**
     * Start sync logging
     *
     * @param string $syncType
     * @param string $initiatedBy
     */
    private function startSync(string $syncType, string $initiatedBy): void {
        $this->syncLogId = $this->db->insert('cmc_sync_log', [
            'sync_type' => $syncType,
            'initiated_by' => $initiatedBy,
            'start_time' => date('Y-m-d H:i:s'),
            'status' => 'running',
            'memory_peak_mb' => round(memory_get_peak_usage() / 1024 / 1024, 2)
        ]);
    }

    /**
     * Update sync progress
     */
    private function updateSyncProgress(): void {
        if (!$this->syncLogId) return;

        $this->db->update('cmc_sync_log', [
            'coins_processed' => $this->stats['processed'],
            'coins_added' => $this->stats['added'],
            'coins_updated' => $this->stats['updated'],
            'coins_skipped' => $this->stats['skipped'],
            'coins_validated' => $this->stats['validated'],
            'tradeable_found' => $this->stats['tradeable'],
            'errors_count' => $this->stats['errors'],
            'memory_peak_mb' => round(memory_get_peak_usage() / 1024 / 1024, 2)
        ], ['id' => $this->syncLogId]);
    }

    /**
     * End sync logging
     *
     * @param string $status
     * @param string|null $errorDetails
     */
    private function endSync(string $status, ?string $errorDetails = null): void {
        if (!$this->syncLogId) return;

        // Get start time to calculate duration
        $startRecord = $this->db->fetchOne(
            "SELECT start_time FROM cmc_sync_log WHERE id = ?",
            [$this->syncLogId]
        );

        $duration = null;
        if ($startRecord && $startRecord['start_time']) {
            $duration = time() - strtotime($startRecord['start_time']);
        }

        $this->db->update('cmc_sync_log', [
            'coins_processed' => $this->stats['processed'],
            'coins_added' => $this->stats['added'],
            'coins_updated' => $this->stats['updated'],
            'coins_skipped' => $this->stats['skipped'],
            'coins_validated' => $this->stats['validated'],
            'tradeable_found' => $this->stats['tradeable'],
            'errors_count' => $this->stats['errors'],
            'error_details' => $errorDetails,
            'end_time' => date('Y-m-d H:i:s'),
            'duration_seconds' => $duration,
            'memory_peak_mb' => round(memory_get_peak_usage() / 1024 / 1024, 2),
            'status' => $status
        ], ['id' => $this->syncLogId]);
    }

    /**
     * Reset statistics
     */
    private function resetStats(): void {
        $this->stats = [
            'processed' => 0,
            'added' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'validated' => 0,
            'tradeable' => 0
        ];
    }

    /**
     * Get sync statistics
     *
     * @return array
     */
    public function getStats(): array {
        return $this->stats;
    }

    /**
     * Get last sync info
     *
     * @return array|null
     */
    public function getLastSync(): ?array {
        return $this->db->fetchOne(
            "SELECT * FROM cmc_sync_log ORDER BY created_at DESC LIMIT 1"
        );
    }
}