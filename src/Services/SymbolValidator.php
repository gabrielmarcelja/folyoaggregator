<?php
namespace FolyoAggregator\Services;

use FolyoAggregator\Exchanges\ExchangeManager;
use Exception;

/**
 * Symbol Validator Service
 * Validates if a cryptocurrency symbol is tradeable on CCXT exchanges
 */
class SymbolValidator {
    private ExchangeManager $exchangeManager;
    private array $quotePreferences = ['USDT', 'BUSD', 'USDC', 'USD', 'EUR', 'BTC', 'ETH'];
    private array $marketsCache = [];
    private bool $debug = false;

    // Symbols that are NOT tradeable as base currencies
    private array $nonTradeableSymbols = [
        // Stablecoins (used as quote currencies)
        'USDT', 'USDC', 'BUSD', 'DAI', 'TUSD', 'USDP', 'GUSD', 'FRAX', 'LUSD', 'USTC',
        // Exchange tokens (often restricted)
        'LEO', 'BGB', 'OKB', 'KCS', 'HT', 'CRO', 'GT', 'MX',
        // Wrapped tokens (use unwrapped versions)
        'WBTC', 'WETH', 'WBNB', 'WAVAX',
        // Deprecated or renamed
        'LUNA', 'FTT'
    ];

    public function __construct() {
        $this->exchangeManager = new ExchangeManager();
        $this->debug = env('APP_DEBUG', false);
    }

    /**
     * Check if symbol is tradeable on exchanges
     *
     * @param string $symbol Cryptocurrency symbol
     * @return array ['tradeable' => bool, 'exchanges' => array, 'preferred_pair' => string]
     */
    public function validate(string $symbol): array {
        $symbol = strtoupper(trim($symbol));

        // Quick check for known non-tradeable symbols
        if (in_array($symbol, $this->nonTradeableSymbols)) {
            return [
                'tradeable' => false,
                'exchanges' => [],
                'preferred_pair' => null,
                'reason' => 'Non-tradeable symbol type (stablecoin, exchange token, or wrapped token)',
                'exchange_count' => 0
            ];
        }

        $supportedExchanges = [];
        $preferredPair = null;
        $allPairs = [];

        // Get active exchanges
        $activeExchanges = $this->exchangeManager->getActiveExchanges();

        if (empty($activeExchanges)) {
            // If no exchanges initialized, try to connect to main ones
            $mainExchanges = ['binance', 'coinbase', 'kraken', 'kucoin', 'bybit'];
            foreach ($mainExchanges as $exchangeId) {
                $this->exchangeManager->connectExchange($exchangeId);
            }
            $activeExchanges = $this->exchangeManager->getActiveExchanges();
        }

        foreach ($activeExchanges as $exchangeId) {
            try {
                $exchange = $this->exchangeManager->getExchange($exchangeId);

                if (!$exchange) {
                    continue;
                }

                // Load markets if not loaded (with caching)
                if (!isset($this->marketsCache[$exchangeId])) {
                    if (!$exchange->markets) {
                        $exchange->load_markets();
                    }
                    $this->marketsCache[$exchangeId] = $exchange->markets;
                }

                $markets = $this->marketsCache[$exchangeId];

                // Try different quote currencies
                foreach ($this->quotePreferences as $quote) {
                    $pair = "$symbol/$quote";

                    if (isset($markets[$pair])) {
                        $marketInfo = $markets[$pair];

                        // Check if market is active
                        if (isset($marketInfo['active']) && !$marketInfo['active']) {
                            continue;
                        }

                        $exchangeData = [
                            'exchange' => $exchangeId,
                            'pair' => $pair,
                            'quote' => $quote,
                            'active' => $marketInfo['active'] ?? true,
                            'type' => $marketInfo['type'] ?? 'spot'
                        ];

                        // Add limits info if available
                        if (isset($marketInfo['limits'])) {
                            $exchangeData['limits'] = [
                                'amount_min' => $marketInfo['limits']['amount']['min'] ?? null,
                                'amount_max' => $marketInfo['limits']['amount']['max'] ?? null,
                                'price_min' => $marketInfo['limits']['price']['min'] ?? null,
                                'price_max' => $marketInfo['limits']['price']['max'] ?? null
                            ];
                        }

                        $supportedExchanges[] = $exchangeData;
                        $allPairs[] = $pair;

                        // Set preferred pair (first USDT pair found, or first pair if no USDT)
                        if (!$preferredPair || ($quote === 'USDT' && strpos($preferredPair, 'USDT') === false)) {
                            $preferredPair = $pair;
                        }

                        break; // Found a valid pair for this exchange, move to next
                    }
                }
            } catch (Exception $e) {
                if ($this->debug) {
                    echo "Error checking $symbol on $exchangeId: " . $e->getMessage() . "\n";
                }
                continue;
            }
        }

        // Calculate tradeability score (0-100)
        $tradeabilityScore = $this->calculateTradeabilityScore($supportedExchanges);

        return [
            'tradeable' => count($supportedExchanges) > 0,
            'exchanges' => $supportedExchanges,
            'preferred_pair' => $preferredPair,
            'exchange_count' => count($supportedExchanges),
            'unique_pairs' => array_values(array_unique($allPairs)),
            'tradeability_score' => $tradeabilityScore,
            'reason' => count($supportedExchanges) === 0 ? 'Not found on any active exchange' : null
        ];
    }

    /**
     * Batch validate multiple symbols
     *
     * @param array $symbols
     * @return array
     */
    public function validateBatch(array $symbols): array {
        $results = [];

        // Pre-load markets for all exchanges to optimize
        $this->preloadMarkets();

        $total = count($symbols);
        $current = 0;

        foreach ($symbols as $symbol) {
            $current++;
            if ($this->debug) {
                echo "Validating $symbol ($current/$total)...\n";
            }

            $results[$symbol] = $this->validate($symbol);

            // Small delay to prevent overwhelming exchanges
            if ($current % 10 === 0) {
                usleep(100000); // 0.1 second delay every 10 symbols
            }
        }

        return $results;
    }

    /**
     * Preload markets for all active exchanges to optimize batch validation
     */
    private function preloadMarkets(): void {
        $activeExchanges = $this->exchangeManager->getActiveExchanges();

        foreach ($activeExchanges as $exchangeId) {
            try {
                if (!isset($this->marketsCache[$exchangeId])) {
                    $exchange = $this->exchangeManager->getExchange($exchangeId);
                    if ($exchange) {
                        if (!$exchange->markets) {
                            $exchange->load_markets();
                        }
                        $this->marketsCache[$exchangeId] = $exchange->markets;
                    }
                }
            } catch (Exception $e) {
                if ($this->debug) {
                    echo "Failed to preload markets for $exchangeId: " . $e->getMessage() . "\n";
                }
            }
        }
    }

    /**
     * Calculate tradeability score based on exchange availability
     *
     * @param array $exchanges
     * @return int Score from 0-100
     */
    private function calculateTradeabilityScore(array $exchanges): int {
        if (empty($exchanges)) {
            return 0;
        }

        $score = 0;
        $maxScore = 100;

        // Major exchanges have higher weight
        $exchangeWeights = [
            'binance' => 25,
            'coinbase' => 20,
            'kraken' => 15,
            'kucoin' => 10,
            'bybit' => 10,
            'okx' => 8,
            'gate' => 5,
            'bitfinex' => 4,
            'huobi' => 2,
            'bitstamp' => 1
        ];

        foreach ($exchanges as $exchangeData) {
            $exchangeId = $exchangeData['exchange'];
            $weight = $exchangeWeights[$exchangeId] ?? 1;
            $score += $weight;
        }

        // Cap at 100
        return min($maxScore, $score);
    }

    /**
     * Get list of non-tradeable symbols
     *
     * @return array
     */
    public function getNonTradeableSymbols(): array {
        return $this->nonTradeableSymbols;
    }

    /**
     * Clear markets cache
     */
    public function clearCache(): void {
        $this->marketsCache = [];
    }
}