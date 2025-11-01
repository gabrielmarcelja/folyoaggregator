#!/usr/bin/env php
<?php
/**
 * Calcula tempo estimado para coletar todo histórico
 */

echo "\n╔════════════════════════════════════════════════════╗\n";
echo "║     ESTIMATIVA DE TEMPO PARA HISTÓRICO COMPLETO    ║\n";
echo "╚════════════════════════════════════════════════════╝\n\n";

// Configuração por tier
$tiers = [
    'TOP 10' => [
        'moedas' => 10,
        'dias_historico' => 1825, // 5 anos
        'candles_por_dia' => 24,  // hourly
        'segundos_por_request' => 0.5
    ],
    'TOP 11-50' => [
        'moedas' => 40,
        'dias_historico' => 365,   // 1 ano
        'candles_por_dia' => 24,
        'segundos_por_request' => 0.5
    ],
    'TOP 51-200' => [
        'moedas' => 150,
        'dias_historico' => 90,    // 3 meses
        'candles_por_dia' => 6,    // 4h candles
        'segundos_por_request' => 0.5
    ],
    'TOP 201-500' => [
        'moedas' => 300,
        'dias_historico' => 30,    // 1 mês
        'candles_por_dia' => 1,    // daily
        'segundos_por_request' => 0.5
    ]
];

$total_requests = 0;
$total_candles = 0;
$total_time_seconds = 0;

foreach ($tiers as $tier => $config) {
    $requests = $config['moedas'];
    $candles = $config['moedas'] * $config['dias_historico'] * $config['candles_por_dia'];
    $time = $requests * $config['segundos_por_request'];

    $total_requests += $requests;
    $total_candles += $candles;
    $total_time_seconds += $time;

    echo "═══════════════════════════════════════════════════\n";
    echo "📊 $tier ({$config['moedas']} moedas)\n";
    echo "───────────────────────────────────────────────────\n";
    echo "  Histórico: {$config['dias_historico']} dias\n";
    echo "  Candles: " . number_format($candles) . "\n";
    echo "  Tempo coleta: " . round($time/60, 1) . " minutos\n";
}

echo "\n═══════════════════════════════════════════════════\n";
echo "📈 TOTAIS\n";
echo "───────────────────────────────────────────────────\n";
echo "  Total moedas: 500\n";
echo "  Total candles: " . number_format($total_candles) . "\n";
echo "  Tempo de coleta: " . round($total_time_seconds/3600, 1) . " horas\n";
echo "  Tamanho estimado: " . round($total_candles * 100 / 1024 / 1024, 1) . " MB\n";

echo "\n═══════════════════════════════════════════════════\n";
echo "⏱️ CRONOGRAMA COM PARALELIZAÇÃO\n";
echo "───────────────────────────────────────────────────\n";
echo "  Com 10 processos paralelos: " . round($total_time_seconds/3600/10, 1) . " horas\n";
echo "  Com 20 processos paralelos: " . round($total_time_seconds/3600/20, 1) . " horas\n";

// Progresso atual
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
use FolyoAggregator\Core\Database;

$db = Database::getInstance();
$progress = $db->fetchOne("
    SELECT
        COUNT(DISTINCT asset_id) as moedas_com_historico,
        COUNT(*) as total_candles,
        MIN(timestamp) as oldest_data
    FROM historical_ohlcv
");

echo "\n═══════════════════════════════════════════════════\n";
echo "✅ PROGRESSO ATUAL\n";
echo "───────────────────────────────────────────────────\n";
echo "  Moedas com histórico: {$progress['moedas_com_historico']}/500\n";
echo "  Candles salvos: " . number_format($progress['total_candles']) . "\n";
echo "  Dados desde: {$progress['oldest_data']}\n";

$percent = round($progress['moedas_com_historico'] / 500 * 100, 1);
echo "\n  [";
$bar_length = 40;
$filled = round($bar_length * $percent / 100);
echo str_repeat("█", $filled);
echo str_repeat("░", $bar_length - $filled);
echo "] $percent%\n";

echo "\n💡 Para acelerar, podemos rodar múltiplos coletores!\n\n";