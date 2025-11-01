<?php
/**
 * Sync assets ranked 301-500 from CoinMarketCap
 */

require_once __DIR__ . '/../vendor/autoload.php';

use FolyoAggregator\Database\Database;

$db = Database::getInstance();

echo "\n";
echo "📊 Sincronizando TOP 301-500 da CoinMarketCap\n";
echo "═══════════════════════════════════════════════\n\n";

$apiKey = getenv('CMC_API_KEY');
if (!$apiKey) {
    echo "⚠️  CMC_API_KEY not set, using free tier (may be limited)\n\n";
}

$baseUrl = 'https://pro-api.coinmarketcap.com/v1/cryptocurrency/listings/latest';
$headers = [
    'X-CMC_PRO_API_KEY' => $apiKey,
    'Accept' => 'application/json'
];

$totalAdded = 0;
$batchSize = 100;

// Fetch ranks 301-400
echo "📥 Buscando ranks 301-400...\n";
$params = [
    'start' => 301,
    'limit' => $batchSize,
    'convert' => 'USD',
    'sort' => 'market_cap',
    'sort_dir' => 'desc'
];

$context = stream_context_create([
    'http' => [
        'header' => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
        'ignore_errors' => true
    ]
]);

$url = $baseUrl . '?' . http_build_query($params);
$response = @file_get_contents($url, false, $context);

if ($response) {
    $data = json_decode($response, true);

    if (isset($data['data'])) {
        foreach ($data['data'] as $coin) {
            try {
                // Check if already exists
                $existing = $db->query(
                    "SELECT id FROM assets WHERE cmc_id = ?",
                    [$coin['id']]
                )->fetch();

                if (!$existing) {
                    // Insert new asset
                    $db->query("
                        INSERT INTO assets (
                            cmc_id, name, symbol, slug,
                            market_cap_rank, is_tradeable,
                            circulating_supply, total_supply, max_supply,
                            price_usd, market_cap, volume_24h,
                            percent_change_1h, percent_change_24h, percent_change_7d
                        ) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ", [
                        $coin['id'],
                        $coin['name'],
                        $coin['symbol'],
                        $coin['slug'],
                        $coin['cmc_rank'],
                        $coin['circulating_supply'] ?? null,
                        $coin['total_supply'] ?? null,
                        $coin['max_supply'] ?? null,
                        $coin['quote']['USD']['price'] ?? 0,
                        $coin['quote']['USD']['market_cap'] ?? 0,
                        $coin['quote']['USD']['volume_24h'] ?? 0,
                        $coin['quote']['USD']['percent_change_1h'] ?? 0,
                        $coin['quote']['USD']['percent_change_24h'] ?? 0,
                        $coin['quote']['USD']['percent_change_7d'] ?? 0
                    ]);

                    echo "   ✅ {$coin['symbol']} (#{$coin['cmc_rank']}) adicionado\n";
                    $totalAdded++;
                }
            } catch (Exception $e) {
                echo "   ❌ Erro ao adicionar {$coin['symbol']}: " . $e->getMessage() . "\n";
            }
        }
    }
}

sleep(2);

// Fetch ranks 401-500
echo "\n📥 Buscando ranks 401-500...\n";
$params['start'] = 401;

$url = $baseUrl . '?' . http_build_query($params);
$response = @file_get_contents($url, false, $context);

if ($response) {
    $data = json_decode($response, true);

    if (isset($data['data'])) {
        foreach ($data['data'] as $coin) {
            try {
                // Check if already exists
                $existing = $db->query(
                    "SELECT id FROM assets WHERE cmc_id = ?",
                    [$coin['id']]
                )->fetch();

                if (!$existing) {
                    // Insert new asset
                    $db->query("
                        INSERT INTO assets (
                            cmc_id, name, symbol, slug,
                            market_cap_rank, is_tradeable,
                            circulating_supply, total_supply, max_supply,
                            price_usd, market_cap, volume_24h,
                            percent_change_1h, percent_change_24h, percent_change_7d
                        ) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ", [
                        $coin['id'],
                        $coin['name'],
                        $coin['symbol'],
                        $coin['slug'],
                        $coin['cmc_rank'],
                        $coin['circulating_supply'] ?? null,
                        $coin['total_supply'] ?? null,
                        $coin['max_supply'] ?? null,
                        $coin['quote']['USD']['price'] ?? 0,
                        $coin['quote']['USD']['market_cap'] ?? 0,
                        $coin['quote']['USD']['volume_24h'] ?? 0,
                        $coin['quote']['USD']['percent_change_1h'] ?? 0,
                        $coin['quote']['USD']['percent_change_24h'] ?? 0,
                        $coin['quote']['USD']['percent_change_7d'] ?? 0
                    ]);

                    echo "   ✅ {$coin['symbol']} (#{$coin['cmc_rank']}) adicionado\n";
                    $totalAdded++;
                }
            } catch (Exception $e) {
                echo "   ❌ Erro ao adicionar {$coin['symbol']}: " . $e->getMessage() . "\n";
            }
        }
    }
}

echo "\n";
echo "═══════════════════════════════════════════════\n";
echo "✅ Sincronização concluída!\n";
echo "   Total de novos ativos: $totalAdded\n";

// Show current status
$stats = $db->query("
    SELECT
        COUNT(*) as total,
        MIN(market_cap_rank) as min_rank,
        MAX(market_cap_rank) as max_rank
    FROM assets
    WHERE is_tradeable = 1
")->fetch();

echo "\n📊 Status do banco:\n";
echo "   Total de ativos: {$stats['total']}\n";
echo "   Ranks: #{$stats['min_rank']} até #{$stats['max_rank']}\n";
echo "\n";