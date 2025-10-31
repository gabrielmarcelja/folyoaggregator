<?php
namespace FolyoAggregator\Exchanges;

use ccxt\Exchange;
use Exception;
use FolyoAggregator\Core\Database;

/**
 * Exchange Manager - Manages connections to cryptocurrency exchanges
 * Uses CCXT library for unified API access
 */
class ExchangeManager {
    private array $exchanges = [];
    private Database $db;
    private array $supportedExchanges = [
        'binance',
        'coinbase',
        'kraken',
        'kucoin',
        'bybit',
        'okx',
        'gate',
        'bitfinex',
        'huobi',
        'bitstamp'
    ];

    public function __construct() {
        $this->db = Database::getInstance();
        $this->initializeExchanges();
    }

    /**
     * Initialize exchange connections
     */
    private function initializeExchanges(): void {
        foreach ($this->supportedExchanges as $exchangeId) {
            try {
                // Get exchange config from database
                $exchangeData = $this->db->fetchOne(
                    "SELECT * FROM exchanges WHERE exchange_id = ? AND is_active = 1",
                    [$exchangeId]
                );

                if ($exchangeData) {
                    $this->connectExchange($exchangeId);
                }
            } catch (Exception $e) {
                error_log("Failed to initialize exchange $exchangeId: " . $e->getMessage());
            }
        }
    }

    /**
     * Connect to a specific exchange
     *
     * @param string $exchangeId
     * @return Exchange|null
     */
    public function connectExchange(string $exchangeId): ?Exchange {
        try {
            $className = "\\ccxt\\$exchangeId";

            if (!class_exists($className)) {
                throw new Exception("Exchange class $className not found");
            }

            // Get API credentials from environment
            $apiKey = env(strtoupper($exchangeId) . '_API_KEY');
            $apiSecret = env(strtoupper($exchangeId) . '_API_SECRET');

            $config = [
                'enableRateLimit' => true,
                'rateLimit' => $this->getRateLimit($exchangeId),
            ];

            // Add credentials if available (for private endpoints)
            if ($apiKey && $apiSecret) {
                $config['apiKey'] = $apiKey;
                $config['secret'] = $apiSecret;
            }

            // Special configurations for specific exchanges
            if ($exchangeId === 'kucoin' && env('KUCOIN_API_PASSPHRASE')) {
                $config['password'] = env('KUCOIN_API_PASSPHRASE');
            }

            $exchange = new $className($config);
            $this->exchanges[$exchangeId] = $exchange;

            // Update last successful connection
            $this->updateExchangeStatus($exchangeId, 'operational');

            return $exchange;

        } catch (Exception $e) {
            error_log("Failed to connect to $exchangeId: " . $e->getMessage());
            $this->updateExchangeStatus($exchangeId, 'offline', $e->getMessage());
            return null;
        }
    }

    /**
     * Get exchange instance
     *
     * @param string $exchangeId
     * @return Exchange|null
     */
    public function getExchange(string $exchangeId): ?Exchange {
        if (!isset($this->exchanges[$exchangeId])) {
            return $this->connectExchange($exchangeId);
        }
        return $this->exchanges[$exchangeId];
    }

    /**
     * Fetch ticker data for a symbol from an exchange
     *
     * @param string $exchangeId
     * @param string $symbol
     * @return array|null
     */
    public function fetchTicker(string $exchangeId, string $symbol): ?array {
        try {
            $exchange = $this->getExchange($exchangeId);
            if (!$exchange) {
                return null;
            }

            // Convert symbol format (e.g., BTC -> BTC/USDT)
            $marketSymbol = $this->formatSymbol($symbol, $exchangeId);

            // Load markets if not loaded
            if (!$exchange->markets) {
                $exchange->load_markets();
            }

            // Check if market exists
            if (!isset($exchange->markets[$marketSymbol])) {
                return null;
            }

            $ticker = $exchange->fetch_ticker($marketSymbol);

            // Update successful fetch timestamp
            $this->updateExchangeStatus($exchangeId, 'operational');

            return $this->normalizeTicker($ticker, $exchangeId);

        } catch (Exception $e) {
            error_log("Failed to fetch ticker from $exchangeId: " . $e->getMessage());
            $this->updateExchangeStatus($exchangeId, 'degraded', $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch ticker data from all active exchanges
     *
     * @param string $symbol
     * @return array
     */
    public function fetchTickerFromAll(string $symbol): array {
        $results = [];

        foreach ($this->exchanges as $exchangeId => $exchange) {
            $ticker = $this->fetchTicker($exchangeId, $symbol);
            if ($ticker) {
                $results[$exchangeId] = $ticker;
            }
        }

        return $results;
    }

    /**
     * Format symbol for specific exchange
     *
     * @param string $symbol
     * @param string $exchangeId
     * @return string
     */
    private function formatSymbol(string $symbol, string $exchangeId): string {
        // Most exchanges use symbol/USDT format
        // You can customize this based on exchange requirements
        $baseSymbol = strtoupper($symbol);

        // For stablecoins, don't add /USDT
        if (in_array($baseSymbol, ['USDT', 'USDC', 'BUSD', 'DAI'])) {
            return $baseSymbol . '/USD';
        }

        return $baseSymbol . '/USDT';
    }

    /**
     * Normalize ticker data from different exchanges
     *
     * @param array $ticker
     * @param string $exchangeId
     * @return array
     */
    private function normalizeTicker(array $ticker, string $exchangeId): array {
        return [
            'exchange' => $exchangeId,
            'symbol' => $ticker['symbol'] ?? null,
            'price' => $ticker['last'] ?? null,
            'bid' => $ticker['bid'] ?? null,
            'ask' => $ticker['ask'] ?? null,
            'volume_24h' => $ticker['quoteVolume'] ?? $ticker['baseVolume'] ?? null,
            'high_24h' => $ticker['high'] ?? null,
            'low_24h' => $ticker['low'] ?? null,
            'change_24h_percent' => $ticker['percentage'] ?? null,
            'timestamp' => $ticker['timestamp'] ?? time() * 1000,
        ];
    }

    /**
     * Get rate limit for exchange
     *
     * @param string $exchangeId
     * @return int
     */
    private function getRateLimit(string $exchangeId): int {
        $result = $this->db->fetchOne(
            "SELECT rate_limit_per_minute FROM exchanges WHERE exchange_id = ?",
            [$exchangeId]
        );

        if ($result && $result['rate_limit_per_minute']) {
            // Convert per minute to milliseconds between requests
            return (int)(60000 / $result['rate_limit_per_minute']);
        }

        // Default: 100 requests per minute (600ms between requests)
        return 600;
    }

    /**
     * Update exchange status in database
     *
     * @param string $exchangeId
     * @param string $status
     * @param string|null $errorMessage
     */
    private function updateExchangeStatus(string $exchangeId, string $status, ?string $errorMessage = null): void {
        try {
            $data = [
                'api_status' => $status,
                'last_successful_fetch' => ($status === 'operational') ? date('Y-m-d H:i:s') : null,
            ];

            if ($errorMessage) {
                $data['last_error_at'] = date('Y-m-d H:i:s');
                $data['last_error_message'] = substr($errorMessage, 0, 500);
            }

            $this->db->update('exchanges', $data, ['exchange_id' => $exchangeId]);
        } catch (Exception $e) {
            error_log("Failed to update exchange status: " . $e->getMessage());
        }
    }

    /**
     * Get list of active exchanges
     *
     * @return array
     */
    public function getActiveExchanges(): array {
        return array_keys($this->exchanges);
    }

    /**
     * Test exchange connection
     *
     * @param string $exchangeId
     * @return bool
     */
    public function testConnection(string $exchangeId): bool {
        try {
            $exchange = $this->getExchange($exchangeId);
            if (!$exchange) {
                return false;
            }

            // Try to load markets as a connection test
            $exchange->load_markets();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}