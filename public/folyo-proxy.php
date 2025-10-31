<?php
/**
 * FolyoAggregator Proxy for Folyo Platform
 *
 * This proxy allows Folyo to use FolyoAggregator data
 * while maintaining compatibility with the existing API structure
 */

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Load dependencies
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

use FolyoAggregator\Core\Database;
use FolyoAggregator\Services\PriceAggregator;

// Get request parameters
$action = $_GET['action'] ?? 'listings';
$convert = $_GET['convert'] ?? 'USD';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$start = isset($_GET['start']) ? (int)$_GET['start'] : 1;
$symbol = $_GET['symbol'] ?? '';
$ids = $_GET['ids'] ?? '';

try {
    $db = Database::getInstance();
    $aggregator = new PriceAggregator();

    switch ($action) {
        case 'listings':
            // Get cryptocurrency listings (CMC compatible)
            $offset = $start - 1; // Convert to 0-based offset

            $assets = $db->fetchAll("
                SELECT
                    cmc_id as id,
                    symbol,
                    name,
                    cmc_slug as slug,
                    market_cap_rank as rank,
                    market_cap_rank as cmc_rank,
                    circulating_supply,
                    total_supply,
                    max_supply,
                    num_market_pairs,
                    date_added,
                    tags,
                    platform_id,
                    platform_name,
                    token_address
                FROM assets
                WHERE is_active = 1
                AND market_cap_rank IS NOT NULL
                ORDER BY market_cap_rank ASC
                LIMIT ? OFFSET ?
            ", [$limit, $offset]);

            // Add quote data for each asset
            foreach ($assets as &$asset) {
                // Get latest aggregated price
                $priceData = $aggregator->getLatestPrice($asset['symbol']);

                // Prepare quote data (CMC compatible structure)
                $asset['quote'] = [
                    $convert => [
                        'price' => $priceData['price_vwap'] ?? 0,
                        'volume_24h' => $priceData['total_volume_24h'] ?? 0,
                        'volume_change_24h' => null,
                        'percent_change_1h' => $db->fetchOne("
                            SELECT percent_change_1h
                            FROM assets
                            WHERE symbol = ?
                        ", [$asset['symbol']])['percent_change_1h'] ?? 0,
                        'percent_change_24h' => $db->fetchOne("
                            SELECT percent_change_24h
                            FROM assets
                            WHERE symbol = ?
                        ", [$asset['symbol']])['percent_change_24h'] ?? 0,
                        'percent_change_7d' => $db->fetchOne("
                            SELECT percent_change_7d
                            FROM assets
                            WHERE symbol = ?
                        ", [$asset['symbol']])['percent_change_7d'] ?? 0,
                        'percent_change_30d' => $db->fetchOne("
                            SELECT percent_change_30d
                            FROM assets
                            WHERE symbol = ?
                        ", [$asset['symbol']])['percent_change_30d'] ?? 0,
                        'market_cap' => $db->fetchOne("
                            SELECT market_cap
                            FROM assets
                            WHERE symbol = ?
                        ", [$asset['symbol']])['market_cap'] ?? 0,
                        'market_cap_dominance' => $db->fetchOne("
                            SELECT market_cap_dominance
                            FROM assets
                            WHERE symbol = ?
                        ", [$asset['symbol']])['market_cap_dominance'] ?? 0,
                        'fully_diluted_market_cap' => $db->fetchOne("
                            SELECT fully_diluted_market_cap
                            FROM assets
                            WHERE symbol = ?
                        ", [$asset['symbol']])['fully_diluted_market_cap'] ?? 0,
                        'last_updated' => date('c')
                    ]
                ];

                // Parse tags JSON
                $asset['tags'] = json_decode($asset['tags'], true) ?? [];
            }

            // Return CMC-compatible response
            echo json_encode([
                'status' => [
                    'timestamp' => date('c'),
                    'error_code' => 0,
                    'error_message' => null,
                    'elapsed' => 10,
                    'credit_count' => 1,
                    'notice' => 'Data provided by FolyoAggregator'
                ],
                'data' => $assets
            ], JSON_PRETTY_PRINT);
            break;

        case 'quotes':
            // Get latest quotes for specific symbols
            $symbols = array_map('trim', explode(',', strtoupper($symbol)));
            $data = [];

            foreach ($symbols as $sym) {
                if (empty($sym)) continue;

                // Get asset info
                $asset = $db->fetchOne("
                    SELECT * FROM assets
                    WHERE symbol = ? AND is_active = 1
                ", [$sym]);

                if ($asset) {
                    // Get aggregated price
                    $priceData = $aggregator->aggregatePrices($sym);

                    $data[$sym] = [
                        'id' => $asset['cmc_id'],
                        'name' => $asset['name'],
                        'symbol' => $sym,
                        'slug' => $asset['cmc_slug'],
                        'is_active' => 1,
                        'is_fiat' => 0,
                        'circulating_supply' => $asset['circulating_supply'],
                        'total_supply' => $asset['total_supply'],
                        'max_supply' => $asset['max_supply'],
                        'date_added' => $asset['date_added'],
                        'num_market_pairs' => $asset['num_market_pairs'],
                        'cmc_rank' => $asset['market_cap_rank'],
                        'last_updated' => date('c'),
                        'tags' => json_decode($asset['tags'], true) ?? [],
                        'quote' => [
                            $convert => [
                                'price' => $priceData['aggregated']['price_vwap'] ?? 0,
                                'volume_24h' => $priceData['aggregated']['total_volume_24h'] ?? 0,
                                'percent_change_1h' => $asset['percent_change_1h'],
                                'percent_change_24h' => $asset['percent_change_24h'],
                                'percent_change_7d' => $asset['percent_change_7d'],
                                'percent_change_30d' => $asset['percent_change_30d'],
                                'market_cap' => $asset['market_cap'],
                                'fully_diluted_market_cap' => $asset['fully_diluted_market_cap'],
                                'last_updated' => date('c')
                            ]
                        ]
                    ];
                }
            }

            echo json_encode([
                'status' => [
                    'timestamp' => date('c'),
                    'error_code' => 0,
                    'error_message' => null,
                    'elapsed' => 10,
                    'credit_count' => 1,
                    'notice' => 'Data provided by FolyoAggregator'
                ],
                'data' => $data
            ], JSON_PRETTY_PRINT);
            break;

        case 'info':
            // Get metadata for specific IDs
            $idList = array_map('trim', explode(',', $ids));
            $data = [];

            foreach ($idList as $cmcId) {
                if (!is_numeric($cmcId)) continue;

                $asset = $db->fetchOne("
                    SELECT * FROM assets
                    WHERE cmc_id = ? AND is_active = 1
                ", [$cmcId]);

                if ($asset) {
                    $data[$cmcId] = [
                        'id' => $asset['cmc_id'],
                        'name' => $asset['name'],
                        'symbol' => $asset['symbol'],
                        'category' => 'coin',
                        'description' => '',
                        'slug' => $asset['cmc_slug'],
                        'logo' => $asset['icon_url'],
                        'subreddit' => '',
                        'tags' => json_decode($asset['tags'], true) ?? [],
                        'tag-names' => json_decode($asset['tags'], true) ?? [],
                        'tag-groups' => [],
                        'platform' => $asset['platform_id'] ? [
                            'id' => $asset['platform_id'],
                            'name' => $asset['platform_name'],
                            'token_address' => $asset['token_address']
                        ] : null,
                        'date_added' => $asset['date_added'],
                        'date_launched' => null,
                        'is_hidden' => 0
                    ];
                }
            }

            echo json_encode([
                'status' => [
                    'timestamp' => date('c'),
                    'error_code' => 0,
                    'error_message' => null,
                    'elapsed' => 10,
                    'credit_count' => 1,
                    'notice' => 'Data provided by FolyoAggregator'
                ],
                'data' => $data
            ], JSON_PRETTY_PRINT);
            break;

        case 'global-metrics':
            // Get global market metrics
            $metrics = $db->fetchOne("
                SELECT
                    COUNT(*) as active_cryptocurrencies,
                    SUM(market_cap) as total_market_cap,
                    SUM(volume_24h) as total_volume_24h,
                    AVG(percent_change_24h) as market_cap_change_24h
                FROM assets
                WHERE is_active = 1
                AND market_cap > 0
            ");

            // Get BTC dominance
            $btcDominance = $db->fetchOne("
                SELECT
                    (market_cap / (SELECT SUM(market_cap) FROM assets WHERE is_active = 1)) * 100 as dominance
                FROM assets
                WHERE symbol = 'BTC'
            ")['dominance'] ?? 0;

            // Get ETH dominance
            $ethDominance = $db->fetchOne("
                SELECT
                    (market_cap / (SELECT SUM(market_cap) FROM assets WHERE is_active = 1)) * 100 as dominance
                FROM assets
                WHERE symbol = 'ETH'
            ")['dominance'] ?? 0;

            echo json_encode([
                'status' => [
                    'timestamp' => date('c'),
                    'error_code' => 0,
                    'error_message' => null,
                    'elapsed' => 10,
                    'credit_count' => 1,
                    'notice' => 'Data provided by FolyoAggregator'
                ],
                'data' => [
                    'active_cryptocurrencies' => (int)$metrics['active_cryptocurrencies'],
                    'total_cryptocurrencies' => (int)$metrics['active_cryptocurrencies'],
                    'active_market_pairs' => 0,
                    'active_exchanges' => 10,
                    'total_exchanges' => 10,
                    'eth_dominance' => round($ethDominance, 2),
                    'btc_dominance' => round($btcDominance, 2),
                    'defi_volume_24h' => null,
                    'defi_volume_24h_reported' => null,
                    'defi_market_cap' => null,
                    'defi_24h_percentage_change' => null,
                    'stablecoin_volume_24h' => null,
                    'stablecoin_volume_24h_reported' => null,
                    'stablecoin_market_cap' => null,
                    'stablecoin_24h_percentage_change' => null,
                    'derivatives_volume_24h' => null,
                    'derivatives_volume_24h_reported' => null,
                    'derivatives_24h_percentage_change' => null,
                    'quote' => [
                        $convert => [
                            'total_market_cap' => (float)$metrics['total_market_cap'],
                            'total_volume_24h' => (float)$metrics['total_volume_24h'],
                            'total_volume_24h_reported' => (float)$metrics['total_volume_24h'],
                            'altcoin_volume_24h' => null,
                            'altcoin_volume_24h_reported' => null,
                            'altcoin_market_cap' => null,
                            'last_updated' => date('c')
                        ]
                    ],
                    'last_updated' => date('c')
                ]
            ], JSON_PRETTY_PRINT);
            break;

        default:
            throw new Exception("Unknown action: $action");
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => [
            'timestamp' => date('c'),
            'error_code' => 500,
            'error_message' => $e->getMessage(),
            'elapsed' => 0,
            'credit_count' => 0
        ],
        'data' => null
    ], JSON_PRETTY_PRINT);
}