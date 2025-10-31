<?php
namespace FolyoAggregator\Services;

use Exception;

/**
 * CoinMarketCap API Client
 * Handles all interactions with CoinMarketCap API
 */
class CoinMarketCapClient {
    private string $apiKey;
    private string $baseUrl = 'https://pro-api.coinmarketcap.com';
    private int $timeout = 30;
    private bool $debug = false;

    public function __construct(string $apiKey = null) {
        // Use provided key or get from environment or use Folyo's key
        $this->apiKey = $apiKey ?? env('CMC_API_KEY', 'dfd1ef151785484daf455a67e0523574');
        $this->debug = env('APP_DEBUG', false);
    }

    /**
     * Get cryptocurrency listings sorted by market cap
     *
     * @param int $start Starting rank (1-based)
     * @param int $limit Number of results (max 5000)
     * @param string $convert Currency to convert prices to
     * @return array
     */
    public function getListings(int $start = 1, int $limit = 100, string $convert = 'USD'): array {
        $endpoint = '/v1/cryptocurrency/listings/latest';

        $params = [
            'start' => $start,
            'limit' => min($limit, 5000), // CMC max is 5000
            'convert' => $convert,
            'sort' => 'market_cap',
            'sort_dir' => 'desc'
        ];

        return $this->makeRequest($endpoint, $params);
    }

    /**
     * Get cryptocurrency metadata (logo, description, etc)
     *
     * @param array $ids Array of CMC IDs
     * @return array
     */
    public function getMetadata(array $ids): array {
        if (empty($ids)) {
            throw new Exception('No IDs provided for metadata request');
        }

        $endpoint = '/v2/cryptocurrency/info';

        $params = [
            'id' => implode(',', array_slice($ids, 0, 100)) // Max 100 IDs per request
        ];

        return $this->makeRequest($endpoint, $params);
    }

    /**
     * Get latest quotes for specific cryptocurrencies
     *
     * @param array $symbols Cryptocurrency symbols
     * @param string $convert Currency to convert to
     * @return array
     */
    public function getQuotes(array $symbols, string $convert = 'USD'): array {
        if (empty($symbols)) {
            throw new Exception('No symbols provided for quotes request');
        }

        $endpoint = '/v2/cryptocurrency/quotes/latest';

        $params = [
            'symbol' => implode(',', array_slice($symbols, 0, 120)), // Max ~120 symbols
            'convert' => $convert
        ];

        return $this->makeRequest($endpoint, $params);
    }

    /**
     * Get global metrics (total market cap, dominance, etc)
     *
     * @param string $convert Currency to convert to
     * @return array
     */
    public function getGlobalMetrics(string $convert = 'USD'): array {
        $endpoint = '/v1/global-metrics/quotes/latest';

        $params = [
            'convert' => $convert
        ];

        return $this->makeRequest($endpoint, $params);
    }

    /**
     * Make API request to CoinMarketCap
     *
     * @param string $endpoint
     * @param array $params
     * @return array
     * @throws Exception
     */
    private function makeRequest(string $endpoint, array $params = []): array {
        $url = $this->baseUrl . $endpoint;

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        if ($this->debug) {
            echo "CMC Request: $url\n";
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'X-CMC_PRO_API_KEY: ' . $this->apiKey,
                'Accept: application/json',
                'Accept-Encoding: gzip'
            ],
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            throw new Exception("CMC API request failed: $error");
        }

        if ($httpCode !== 200) {
            throw new Exception("CMC API returned HTTP $httpCode: $response");
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from CMC API: " . json_last_error_msg());
        }

        if (!isset($data['status'])) {
            throw new Exception("Invalid CMC API response structure");
        }

        // Check for API errors
        if (isset($data['status']['error_code']) && $data['status']['error_code'] !== 0) {
            throw new Exception(
                "CMC API error {$data['status']['error_code']}: " .
                ($data['status']['error_message'] ?? 'Unknown error')
            );
        }

        if ($this->debug && isset($data['status']['credit_count'])) {
            echo "CMC Credits used: {$data['status']['credit_count']}\n";
        }

        return $data;
    }

    /**
     * Test API connectivity and key validity
     *
     * @return bool
     */
    public function testConnection(): bool {
        try {
            // Try to get just 1 listing to test the API
            $response = $this->getListings(1, 1);
            return isset($response['data']) && count($response['data']) > 0;
        } catch (Exception $e) {
            if ($this->debug) {
                echo "CMC connection test failed: " . $e->getMessage() . "\n";
            }
            return false;
        }
    }

    /**
     * Get remaining API credits (if available in response)
     * Note: This is usually shown in paid plans
     *
     * @return array|null
     */
    public function getApiCredits(): ?array {
        try {
            // Make a minimal request
            $response = $this->getListings(1, 1);

            if (isset($response['status'])) {
                return [
                    'credit_count' => $response['status']['credit_count'] ?? null,
                    'notice' => $response['status']['notice'] ?? null,
                    'timestamp' => $response['status']['timestamp'] ?? null
                ];
            }

            return null;
        } catch (Exception $e) {
            return null;
        }
    }
}