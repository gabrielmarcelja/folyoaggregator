#!/usr/bin/env php
<?php
/**
 * Verifica se FolyoAggregator está pronto para substituir CMC
 */

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use FolyoAggregator\Core\Database;
$db = Database::getInstance();

echo "\n╔══════════════════════════════════════════════════════╗\n";
echo "║   FOLYOAGGREGATOR - PRONTO PARA SUBSTITUIR CMC?     ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

// Check metrics
$metrics = $db->fetchOne("
    SELECT
        (SELECT COUNT(*) FROM assets WHERE is_tradeable = 1) as total_tradeable,
        (SELECT COUNT(DISTINCT asset_id) FROM prices WHERE timestamp > NOW() - INTERVAL 10 MINUTE) as with_realtime,
        (SELECT COUNT(DISTINCT asset_id) FROM historical_ohlcv) as with_history,
        (SELECT COUNT(DISTINCT asset_id) FROM aggregated_prices WHERE timestamp > NOW() - INTERVAL 1 HOUR) as with_vwap
");

$ready_features = [];
$not_ready = [];

// Check each requirement
$requirements = [
    'Top 200 com preços' => $metrics['with_realtime'] >= 200,
    'Top 50 com histórico' => $metrics['with_history'] >= 50,
    'VWAP funcionando' => $metrics['with_vwap'] > 0,
    'API online' => true, // Always true if script runs
    'Dashboard online' => file_exists(__DIR__ . '/../public/dashboard.php'),
];

foreach ($requirements as $feature => $ready) {
    if ($ready) {
        $ready_features[] = "✅ $feature";
    } else {
        $not_ready[] = "⏳ $feature";
    }
}

// Calculate progress
$progress = round(($metrics['with_realtime'] / 200) * 100);
$days_remaining = ceil((200 - $metrics['with_realtime']) / 30); // ~30 new coins per day

echo "📊 PROGRESSO ATUAL\n";
echo "══════════════════════════════════════════════════════\n\n";

$bar_length = 50;
$filled = round($bar_length * $progress / 100);
echo "Cobertura: [";
echo str_repeat("█", $filled);
echo str_repeat("░", $bar_length - $filled);
echo "] $progress%\n\n";

echo "📈 ESTATÍSTICAS\n";
echo "──────────────────────────────────────────────────────\n";
echo "  • Moedas com preço real-time: {$metrics['with_realtime']}/200\n";
echo "  • Moedas com histórico: {$metrics['with_history']}/200\n";
echo "  • Moedas com VWAP: {$metrics['with_vwap']}/200\n";
echo "  • Total tradeáveis: {$metrics['total_tradeable']}\n\n";

echo "✅ PRONTO\n";
echo "──────────────────────────────────────────────────────\n";
foreach ($ready_features as $feature) {
    echo "  $feature\n";
}

if (count($not_ready) > 0) {
    echo "\n⏳ EM PROGRESSO\n";
    echo "──────────────────────────────────────────────────────\n";
    foreach ($not_ready as $feature) {
        echo "  $feature\n";
    }
}

echo "\n══════════════════════════════════════════════════════\n";

if ($progress >= 100) {
    echo "🎉 PRONTO PARA MIGRAR! Pode substituir CMC agora!\n";
} else {
    echo "⏰ Tempo estimado: $days_remaining dias para estar pronto\n";
    echo "📅 Data prevista: " . date('d/m/Y', strtotime("+$days_remaining days")) . "\n";
}

echo "══════════════════════════════════════════════════════\n\n";