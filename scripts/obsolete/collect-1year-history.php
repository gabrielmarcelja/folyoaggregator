#!/usr/bin/env php
<?php
/**
 * Coleta 1 ANO de histórico para TOP 50 ativos
 * Timeframe: 4h (bom equilíbrio entre detalhe e quantidade)
 * ~2190 candles por ativo (365 dias * 6 candles/dia)
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

echo "{$BLUE}╔══════════════════════════════════════════════════════════╗{$RESET}\n";
echo "{$BLUE}║{$RESET}   {$BOLD}{$CYAN}Coleta de 1 ANO de Histórico - TOP 50 Ativos{$RESET}       {$BLUE}║{$RESET}\n";
echo "{$BLUE}╚══════════════════════════════════════════════════════════╝{$RESET}\n\n";

$db = Database::getInstance();
$exchangeManager = new ExchangeManager();

// Configuration
$topLimit = 50;
$timeframe = '4h';
$days = 365;
$exchangeId = 'binance';

// Get TOP 50 tradeable assets
$assets = $db->fetchAll("
    SELECT
        id,
        symbol,
        name,
        market_cap_rank
    FROM assets
    WHERE is_active = 1
    AND is_tradeable = 1
    AND market_cap_rank <= ?
    ORDER BY market_cap_rank ASC
", [$topLimit]);

echo "{$BOLD}📊 Configuração:{$RESET}\n";
echo "  Ativos: {$CYAN}TOP {$topLimit}{$RESET}\n";
echo "  Timeframe: {$CYAN}{$timeframe}{$RESET} (6 candles/dia)\n";
echo "  Período: {$CYAN}{$days} dias{$RESET} (1 ano)\n";
echo "  Exchange: {$CYAN}{$exchangeId}{$RESET}\n";
echo "  Candles esperados: {$CYAN}~2,190{$RESET} por ativo\n\n";

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

// Calculate time range
$since = strtotime("-{$days} days") * 1000; // CCXT uses milliseconds
$now = time() * 1000;

echo "{$GREEN}🚀 Iniciando coleta...{$RESET}\n";
echo str_repeat('═', 70) . "\n\n";

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
            // Try USD pair
            $tradingPair = "{$symbol}/USD";
            if (!isset($exchange->markets[$tradingPair])) {
                // Try BUSD pair
                $tradingPair = "{$symbol}/BUSD";
                if (!isset($exchange->markets[$tradingPair])) {
                    echo "{$YELLOW}Market not found{$RESET}\n";
                    $failed++;
                    continue;
                }
            }
        }

        // Check if we already have recent data
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

        // If we already have 1 year of data, skip
        if ($existingData && $existingData['count'] > 2000) {
            $daysCovered = (strtotime($existingData['newest']) - strtotime($existingData['oldest'])) / 86400;
            if ($daysCovered >= 350) {
                echo "{$GREEN}✓ Já possui {$existingData['count']} candles (" . round($daysCovered) . " dias){$RESET}\n";
                $skipped++;
                $successful++;
                continue;
            }
        }

        // Fetch OHLCV data
        echo "Fetching... ";
        $ohlcv = $exchange->fetch_ohlcv($tradingPair, $timeframe, $since, 1500); // Binance limit

        if (empty($ohlcv)) {
            echo "{$YELLOW}No data{$RESET}\n";
            $failed++;
            continue;
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
                // Update existing
                $db->update('historical_ohlcv', [
                    'open_price' => $candle[1],
                    'high_price' => $candle[2],
                    'low_price' => $candle[3],
                    'close_price' => $candle[4],
                    'volume' => $candle[5]
                ], ['id' => $existing['id']]);
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

        $totalCandles += count($ohlcv);
        $successful++;

        // Calculate coverage
        $firstDate = date('Y-m-d', $ohlcv[0][0] / 1000);
        $lastDate = date('Y-m-d', $ohlcv[count($ohlcv) - 1][0] / 1000);
        $daysCovered = (strtotime($lastDate) - strtotime($firstDate)) / 86400;

        echo sprintf(
            "{$GREEN}✓ %d candles{$RESET} (new: {$CYAN}%d{$RESET}, upd: %d) | %d dias | %s → %s\n",
            count($ohlcv),
            $insertCount,
            $updateCount,
            round($daysCovered),
            $firstDate,
            $lastDate
        );

        // Small delay to avoid rate limiting
        usleep(300000); // 0.3 second

    } catch (Exception $e) {
        echo "{$RED}Error: " . substr($e->getMessage(), 0, 50) . "...{$RESET}\n";
        $failed++;

        // If rate limited, wait longer
        if (strpos($e->getMessage(), 'rate') !== false || strpos($e->getMessage(), '429') !== false) {
            echo "{$YELLOW}⏸️  Rate limited, aguardando 10s...{$RESET}\n";
            sleep(10);
        }
    }
}

// Final statistics
echo "\n" . str_repeat('═', 70) . "\n";
echo "{$GREEN}{$BOLD}✅ Coleta Finalizada!{$RESET}\n\n";

echo "{$BOLD}📊 Resumo:{$RESET}\n";
echo "  Sucesso: {$GREEN}{$successful}{$RESET} ativos\n";
echo "  Pulados (já tinham dados): {$YELLOW}{$skipped}{$RESET} ativos\n";
echo "  Falharam: {$RED}{$failed}{$RESET} ativos\n";
echo "  Total de candles coletados: {$CYAN}" . number_format($totalCandles) . "{$RESET}\n\n";

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

echo "{$BOLD}💾 Estatísticas do Banco (timeframe {$timeframe}):{$RESET}\n";
echo "  Total de registros: {$CYAN}" . number_format($dbStats['total_records']) . "{$RESET}\n";
echo "  Ativos únicos: {$CYAN}{$dbStats['unique_assets']}{$RESET}\n";
echo "  Período: {$CYAN}{$dbStats['oldest']}{$RESET} → {$CYAN}{$dbStats['newest']}{$RESET}\n";

$totalDays = (strtotime($dbStats['newest']) - strtotime($dbStats['oldest'])) / 86400;
echo "  Cobertura: {$CYAN}" . round($totalDays) . "{$RESET} dias\n";

echo "\n{$GREEN}✓ Processo concluído com sucesso!{$RESET}\n\n";
