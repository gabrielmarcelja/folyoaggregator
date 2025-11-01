#!/usr/bin/env php
<?php
/**
 * Coleta HISTÓRICO COMPLETO de cada ativo
 * Desde o primeiro dia de trading até hoje
 */

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use FolyoAggregator\Core\Database;
use FolyoAggregator\Services\ExchangeManager;

$db = Database::getInstance();
$exchangeManager = new ExchangeManager();

echo "\n╔════════════════════════════════════════════════════════╗\n";
echo "║   COLETA DE HISTÓRICO COMPLETO - TOP 500 ATIVOS       ║\n";
echo "╚════════════════════════════════════════════════════════╝\n\n";

// Get top 500 tradeable assets
$assets = $db->fetchAll("
    SELECT
        id,
        symbol,
        name,
        cmc_first_historical_data,
        date_added,
        market_cap_rank
    FROM assets
    WHERE is_tradeable = 1
    ORDER BY market_cap_rank ASC
    LIMIT 500
");

echo "📊 Encontrados " . count($assets) . " ativos tradeáveis\n\n";

// Asset age mapping (conhecido)
$assetAges = [
    'BTC' => '2009-01-03',  // Bitcoin genesis
    'ETH' => '2015-07-30',  // Ethereum launch
    'XRP' => '2013-08-04',
    'LTC' => '2011-10-07',
    'DOGE' => '2013-12-06',
    'BNB' => '2017-07-25',
    'ADA' => '2017-10-01',
    'SOL' => '2020-04-10',
    'MATIC' => '2019-04-26',
    'DOT' => '2020-08-19',
    'AVAX' => '2020-09-21',
    'LINK' => '2017-09-19',
    'UNI' => '2020-09-16',
    // Adicionar mais conforme necessário
];

foreach ($assets as $index => $asset) {
    $num = $index + 1;
    echo "═══════════════════════════════════════════════════════\n";
    echo "[$num/500] {$asset['symbol']} - {$asset['name']}\n";
    echo "Rank: #{$asset['market_cap_rank']}\n";

    // Determine start date
    $startDate = null;

    // Priority 1: Known launch date
    if (isset($assetAges[$asset['symbol']])) {
        $startDate = $assetAges[$asset['symbol']];
        echo "📅 Data conhecida: $startDate\n";
    }
    // Priority 2: CMC first historical data
    elseif (!empty($asset['cmc_first_historical_data'])) {
        $startDate = date('Y-m-d', strtotime($asset['cmc_first_historical_data']));
        echo "📅 Primeira data CMC: $startDate\n";
    }
    // Priority 3: Date added to CMC
    elseif (!empty($asset['date_added'])) {
        $startDate = date('Y-m-d', strtotime($asset['date_added']));
        echo "📅 Adicionado CMC: $startDate\n";
    }
    // Default: 3 years
    else {
        $startDate = date('Y-m-d', strtotime('-3 years'));
        echo "📅 Padrão: 3 anos\n";
    }

    $days = (strtotime('now') - strtotime($startDate)) / 86400;
    $years = round($days / 365, 1);

    echo "📊 Coletando: $years anos de histórico ($days dias)\n";

    // Determine timeframe based on age
    if ($days > 365 * 3) {
        $timeframe = '1d';  // Daily for very old
    } elseif ($days > 365) {
        $timeframe = '4h';  // 4 hour for old
    } else {
        $timeframe = '1h';  // Hourly for recent
    }

    // Try multiple exchanges in order of preference
    $exchanges = ['binance', 'coinbase', 'kraken', 'kucoin', 'bybit'];
    $collected = false;

    foreach ($exchanges as $exchange) {
        try {
            echo "  Tentando $exchange... ";

            $cmd = sprintf(
                'php %s/collect-historical.php --symbol=%s --days=%d --timeframe=%s --exchange=%s 2>&1',
                __DIR__,
                escapeshellarg($asset['symbol']),
                (int)$days,
                $timeframe,
                $exchange
            );

            $output = shell_exec($cmd);

            if (strpos($output, 'completed') !== false) {
                echo "✅ Sucesso!\n";
                $collected = true;
                break;
            } else {
                echo "❌ Falhou\n";
            }

        } catch (Exception $e) {
            echo "❌ Erro: " . $e->getMessage() . "\n";
        }

        // Small delay to avoid rate limits
        usleep(500000); // 0.5 seconds
    }

    if (!$collected) {
        echo "⚠️ Não foi possível coletar {$asset['symbol']}\n";
    }

    // Progress update every 10 assets
    if ($num % 10 == 0) {
        $progress = round(($num / 500) * 100, 1);
        echo "\n🎯 PROGRESSO GERAL: $progress% ($num/500)\n\n";
    }

    // Prevent overwhelming the system
    if ($num % 5 == 0) {
        echo "💤 Pausa de 2 segundos...\n";
        sleep(2);
    }
}

echo "\n╔════════════════════════════════════════════════════════╗\n";
echo "║   ✅ COLETA DE HISTÓRICO COMPLETA!                    ║\n";
echo "╚════════════════════════════════════════════════════════╝\n\n";

// Final statistics
$stats = $db->fetchOne("
    SELECT
        COUNT(DISTINCT asset_id) as total_assets,
        COUNT(*) as total_candles,
        MIN(timestamp) as oldest_data,
        MAX(timestamp) as newest_data
    FROM historical_ohlcv
");

echo "📊 ESTATÍSTICAS FINAIS:\n";
echo "  • Ativos com histórico: {$stats['total_assets']}\n";
echo "  • Total de candles: " . number_format($stats['total_candles']) . "\n";
echo "  • Dados desde: {$stats['oldest_data']}\n";
echo "  • Dados até: {$stats['newest_data']}\n\n";