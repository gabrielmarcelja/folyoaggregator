#!/usr/bin/env php
<?php
/**
 * Coleta HISTÓRICO COMPLETO de cada ativo com paginação
 * Faz múltiplas requisições para coletar desde o lançamento até hoje
 * Timeframe: 4h (melhor equilíbrio entre detalhe e quantidade)
 */

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use FolyoAggregator\Core\Database;
use FolyoAggregator\Exchanges\ExchangeManager;

// ANSI colors
$GREEN = "\033[0;32m";
$YELLOW = "\033[0;33m";
$BLUE = "\033[0;34m";
$RED = "\033[0;31m";
$CYAN = "\033[0;36m";
$RESET = "\033[0m";
$BOLD = "\033[1m";

echo "{$BLUE}╔════════════════════════════════════════════════════════════╗{$RESET}\n";
echo "{$BLUE}║{$RESET}  {$BOLD}{$CYAN}Coleta de Histórico COMPLETO - Paginado (TOP 50){$RESET}      {$BLUE}║{$RESET}\n";
echo "{$BLUE}╚════════════════════════════════════════════════════════════╝{$RESET}\n\n";

$db = Database::getInstance();
$exchangeManager = new ExchangeManager();

// Configuration
$topLimit = 50;
$timeframe = '4h';
$exchangeId = 'binance';
$maxCandlesPerRequest = 1000; // Binance limit
$delayBetweenRequests = 300000; // 0.3 seconds (microseconds)

// Known asset launch dates (for better accuracy)
$assetLaunchDates = [
    'BTC' => '2010-07-18',  // First trading on exchange
    'ETH' => '2015-08-07',  // Ethereum launch
    'XRP' => '2013-08-04',
    'LTC' => '2011-10-13',
    'DOGE' => '2013-12-15',
    'BNB' => '2017-07-25',
    'ADA' => '2017-10-02',
    'SOL' => '2020-04-10',
    'MATIC' => '2019-04-26',
    'DOT' => '2020-08-19',
    'AVAX' => '2020-09-22',
    'LINK' => '2017-09-20',
    'UNI' => '2020-09-17',
    'ATOM' => '2019-03-14',
    'BCH' => '2017-08-01',
    'XLM' => '2014-08-01',
    'SHIB' => '2020-08-01',
    'TON' => '2021-08-01',
    'NEAR' => '2020-10-13',
    'APT' => '2022-10-19',
];

// Get TOP 50 tradeable assets
$assets = $db->fetchAll("
    SELECT
        id,
        symbol,
        name,
        market_cap_rank,
        cmc_first_historical_data,
        date_added
    FROM assets
    WHERE is_active = 1
    AND is_tradeable = 1
    AND market_cap_rank <= ?
    ORDER BY market_cap_rank ASC
", [$topLimit]);

echo "{$BOLD}📊 Configuração:{$RESET}\n";
echo "  Ativos: {$CYAN}TOP {$topLimit}{$RESET}\n";
echo "  Timeframe: {$CYAN}{$timeframe}{$RESET} (6 candles/dia)\n";
echo "  Exchange: {$CYAN}{$exchangeId}{$RESET}\n";
echo "  Estratégia: {$CYAN}Paginação automática{$RESET} (até dados mais antigos disponíveis)\n";
echo "  Candles por request: {$CYAN}{$maxCandlesPerRequest}{$RESET}\n\n";

// Connect to exchange
try {
    $exchange = $exchangeManager->getExchange($exchangeId);
    if (!$exchange) {
        throw new Exception("Failed to connect to {$exchangeId}");
    }

    if (!$exchange->markets) {
        echo "🔄 Carregando markets do {$exchangeId}...\n";
        $exchange->load_markets();
    }
    echo "{$GREEN}✓{$RESET} Conectado ao {$exchangeId}\n\n";
} catch (Exception $e) {
    echo "{$RED}❌ Erro ao conectar: {$e->getMessage()}{$RESET}\n";
    exit(1);
}

// Get exchange DB ID
$exchangeDb = $db->fetchOne("SELECT id FROM exchanges WHERE exchange_id = ?", [$exchangeId]);
if (!$exchangeDb) {
    echo "{$RED}❌ Exchange não encontrado no banco{$RESET}\n";
    exit(1);
}
$exchangeDbId = $exchangeDb['id'];

echo "{$GREEN}🚀 Iniciando coleta completa com paginação...{$RESET}\n";
echo str_repeat('═', 75) . "\n\n";

// Statistics
$totalCandles = 0;
$successful = 0;
$failed = 0;
$skipped = 0;

foreach ($assets as $index => $asset) {
    $num = $index + 1;
    $symbol = $asset['symbol'];
    $rank = $asset['market_cap_rank'];

    echo sprintf("{$BOLD}[%2d/%2d]{$RESET} #%-3d {$CYAN}%-8s{$RESET} ", $num, $topLimit, $rank, $symbol);

    try {
        // Try USDT pair first
        $tradingPair = "{$symbol}/USDT";
        if (!isset($exchange->markets[$tradingPair])) {
            $tradingPair = "{$symbol}/USD";
            if (!isset($exchange->markets[$tradingPair])) {
                $tradingPair = "{$symbol}/BUSD";
                if (!isset($exchange->markets[$tradingPair])) {
                    echo "{$YELLOW}Market not found{$RESET}\n";
                    $failed++;
                    continue;
                }
            }
        }

        // Determine start date (earliest possible)
        $startDate = null;
        if (isset($assetLaunchDates[$symbol])) {
            $startDate = $assetLaunchDates[$symbol];
        } elseif (!empty($asset['cmc_first_historical_data'])) {
            $startDate = date('Y-m-d', strtotime($asset['cmc_first_historical_data']));
        } elseif (!empty($asset['date_added'])) {
            $startDate = date('Y-m-d', strtotime($asset['date_added']));
        } else {
            // Default: 5 years ago
            $startDate = date('Y-m-d', strtotime('-5 years'));
        }

        $startTimestamp = strtotime($startDate) * 1000; // CCXT uses milliseconds
        $now = time() * 1000;

        // Check what we already have
        $existingData = $db->fetchOne("
            SELECT
                COUNT(*) as count,
                MIN(timestamp) as oldest,
                MAX(timestamp) as newest
            FROM historical_ohlcv
            WHERE asset_id = ?
            AND exchange_id = ?
            AND timeframe = ?
        ", [$asset['id'], $exchangeDbId, $timeframe]);

        $hasData = $existingData && $existingData['count'] > 0;
        $oldestTimestamp = $hasData ? strtotime($existingData['oldest']) * 1000 : $now;

        // Calculate expected candles
        $daysSinceLaunch = ($now - $startTimestamp) / (1000 * 86400);
        $expectedCandles = (int)($daysSinceLaunch * 6); // 6 candles per day for 4h timeframe

        echo sprintf("(Esperados: ~%d) ", $expectedCandles);

        // If we already have most of the data, skip
        if ($hasData && $existingData['count'] >= $expectedCandles * 0.95) {
            $daysCovered = (strtotime($existingData['newest']) - strtotime($existingData['oldest'])) / 86400;
            echo "{$GREEN}✓ Completo: {$existingData['count']} candles (" . round($daysCovered) . " dias){$RESET}\n";
            $skipped++;
            $successful++;
            continue;
        }

        // Paginated collection - go backwards from oldest to start date
        $totalCollected = 0;
        $pagesCollected = 0;
        $currentSince = $startTimestamp;
        $noMoreData = false;

        echo "Coletando";

        while (!$noMoreData && $currentSince < $now) {
            // Fetch one page
            $ohlcv = $exchange->fetch_ohlcv($tradingPair, $timeframe, $currentSince, $maxCandlesPerRequest);

            if (empty($ohlcv)) {
                // No more data available
                $noMoreData = true;
                break;
            }

            // Insert data
            $insertCount = 0;
            $updateCount = 0;

            foreach ($ohlcv as $candle) {
                $timestamp = date('Y-m-d H:i:s', $candle[0] / 1000);

                // Check if already exists
                $existing = $db->fetchOne("
                    SELECT id FROM historical_ohlcv
                    WHERE asset_id = ?
                    AND exchange_id = ?
                    AND timeframe = ?
                    AND timestamp = ?
                ", [$asset['id'], $exchangeDbId, $timeframe, $timestamp]);

                if ($existing) {
                    $updateCount++;
                } else {
                    // Insert new
                    $db->insert('historical_ohlcv', [
                        'asset_id' => $asset['id'],
                        'exchange_id' => $exchangeDbId,
                        'timeframe' => $timeframe,
                        'timestamp' => $timestamp,
                        'open_price' => $candle[1],
                        'high_price' => $candle[2],
                        'low_price' => $candle[3],
                        'close_price' => $candle[4],
                        'volume' => $candle[5]
                    ]);
                    $insertCount++;
                }
            }

            $totalCollected += count($ohlcv);
            $pagesCollected++;

            // Update current since to last candle timestamp + 1ms
            $lastCandle = end($ohlcv);
            $currentSince = $lastCandle[0] + 1;

            // If we got less than max, we've reached the end
            if (count($ohlcv) < $maxCandlesPerRequest) {
                $noMoreData = true;
            }

            // Show progress
            echo ".";
            flush();

            // Delay to avoid rate limiting
            usleep($delayBetweenRequests);
        }

        $totalCandles += $totalCollected;
        $successful++;

        // Get final stats
        $finalData = $db->fetchOne("
            SELECT
                COUNT(*) as count,
                MIN(timestamp) as oldest,
                MAX(timestamp) as newest
            FROM historical_ohlcv
            WHERE asset_id = ?
            AND exchange_id = ?
            AND timeframe = ?
        ", [$asset['id'], $exchangeDbId, $timeframe]);

        $daysCovered = (strtotime($finalData['newest']) - strtotime($finalData['oldest'])) / 86400;
        $coverage = ($finalData['count'] / max($expectedCandles, 1)) * 100;

        echo sprintf(
            " {$GREEN}✓{$RESET} Total: {$CYAN}%d candles{$RESET} (%d páginas) | %d dias | {$BOLD}%.1f%%{$RESET} cobertura\n",
            $finalData['count'],
            $pagesCollected,
            round($daysCovered),
            $coverage
        );

    } catch (Exception $e) {
        echo " {$RED}Error: " . substr($e->getMessage(), 0, 40) . "...{$RESET}\n";
        $failed++;

        // If rate limited, wait longer
        if (strpos($e->getMessage(), 'rate') !== false || strpos($e->getMessage(), '429') !== false) {
            echo "{$YELLOW}⏸️  Rate limited, aguardando 30s...{$RESET}\n";
            sleep(30);
        } elseif (strpos($e->getMessage(), 'IP') !== false) {
            echo "{$RED}⏸️  IP ban detectado, aguardando 60s...{$RESET}\n";
            sleep(60);
        }
    }
}

// Final statistics
echo "\n" . str_repeat('═', 75) . "\n";
echo "{$GREEN}{$BOLD}✅ Coleta Completa Finalizada!{$RESET}\n\n";

echo "{$BOLD}📊 Resumo da Execução:{$RESET}\n";
echo "  Sucesso: {$GREEN}{$successful}{$RESET} ativos\n";
echo "  Pulados (completos): {$YELLOW}{$skipped}{$RESET} ativos\n";
echo "  Falharam: {$RED}{$failed}{$RESET} ativos\n";
echo "  Total de candles nesta execução: {$CYAN}" . number_format($totalCandles) . "{$RESET}\n\n";

// Database statistics
$dbStats = $db->fetchOne("
    SELECT
        COUNT(DISTINCT asset_id) as unique_assets,
        COUNT(*) as total_records,
        MIN(timestamp) as oldest,
        MAX(timestamp) as newest
    FROM historical_ohlcv
    WHERE timeframe = ?
", [$timeframe]);

echo "{$BOLD}💾 Estatísticas Finais do Banco (timeframe {$timeframe}):{$RESET}\n";
echo "  Total de registros: {$CYAN}" . number_format($dbStats['total_records']) . "{$RESET}\n";
echo "  Ativos únicos: {$CYAN}{$dbStats['unique_assets']}{$RESET}\n";
echo "  Período: {$CYAN}{$dbStats['oldest']}{$RESET} → {$CYAN}{$dbStats['newest']}{$RESET}\n";

$totalDays = (strtotime($dbStats['newest']) - strtotime($dbStats['oldest'])) / 86400;
$avgCandlesPerAsset = $dbStats['total_records'] / max($dbStats['unique_assets'], 1);
$avgDaysPerAsset = $avgCandlesPerAsset / 6; // 6 candles per day for 4h

echo "  Cobertura temporal: {$CYAN}" . round($totalDays) . "{$RESET} dias (amplitude total)\n";
echo "  Média por ativo: {$CYAN}" . round($avgCandlesPerAsset) . "{$RESET} candles (~" . round($avgDaysPerAsset) . " dias)\n";

// Show top assets by coverage
$topCoverage = $db->fetchAll("
    SELECT
        a.symbol,
        a.market_cap_rank,
        COUNT(h.id) as candles,
        MIN(h.timestamp) as oldest,
        MAX(h.timestamp) as newest,
        DATEDIFF(MAX(h.timestamp), MIN(h.timestamp)) as days
    FROM historical_ohlcv h
    JOIN assets a ON h.asset_id = a.id
    WHERE h.timeframe = ?
    AND a.market_cap_rank <= ?
    GROUP BY h.asset_id, a.symbol, a.market_cap_rank
    ORDER BY candles DESC
    LIMIT 10
", [$timeframe, $topLimit]);

echo "\n{$BOLD}🏆 TOP 10 Ativos com Mais Dados:{$RESET}\n";
foreach ($topCoverage as $i => $item) {
    echo sprintf(
        "  %2d. {$CYAN}%-6s{$RESET} #%-3d: {$BOLD}%5d{$RESET} candles | %4d dias | %s → %s\n",
        $i + 1,
        $item['symbol'],
        $item['market_cap_rank'],
        $item['candles'],
        $item['days'],
        date('Y-m-d', strtotime($item['oldest'])),
        date('Y-m-d', strtotime($item['newest']))
    );
}

echo "\n{$GREEN}✓ Processo concluído com sucesso!{$RESET}\n\n";
echo "{$BOLD}💡 Dica:{$RESET} Execute novamente para continuar coletando dados faltantes.\n\n";
