<?php
require_once 'vendor/autoload.php';

// Conectar na Binance
$exchange = new \ccxt\binance();

echo "=== DEMONSTRAÇÃO: Como CCXT busca dados HISTÓRICOS ===\n\n";

// Buscar últimas 24 horas de BTC (candles de 1 hora)
$symbol = 'BTC/USDT';
$timeframe = '1h';
$since = strtotime('-24 hours') * 1000; // CCXT usa milliseconds

echo "Buscando: Últimas 24 horas de $symbol (candles de $timeframe)\n";
echo "Data início: " . date('Y-m-d H:i:s', $since/1000) . "\n\n";

// BUSCAR DADOS HISTÓRICOS
$candles = $exchange->fetch_ohlcv($symbol, $timeframe, $since, 24);

echo "Dados retornados pela Binance:\n";
echo "─────────────────────────────────────────\n";

foreach ($candles as $candle) {
    // Formato: [timestamp, open, high, low, close, volume]
    $time = date('Y-m-d H:i', $candle[0]/1000);
    $open = number_format($candle[1], 2);
    $high = number_format($candle[2], 2);
    $low = number_format($candle[3], 2);
    $close = number_format($candle[4], 2);
    $volume = number_format($candle[5], 0);

    echo "$time | O: \$$open | H: \$$high | L: \$$low | C: \$$close | Vol: $volume BTC\n";
}

echo "\n=== DADOS AINDA MAIS ANTIGOS ===\n\n";

// Buscar dados de 30 dias atrás
$thirtyDaysAgo = strtotime('-30 days') * 1000;
$oldCandles = $exchange->fetch_ohlcv($symbol, '1d', $thirtyDaysAgo, 30);

echo "Últimos 30 dias (candles diários):\n";
foreach (array_slice($oldCandles, -5) as $candle) {
    $date = date('Y-m-d', $candle[0]/1000);
    $close = number_format($candle[4], 2);
    echo "$date: \$$close\n";
}

echo "\n✅ CCXT pode buscar MESES ou até ANOS de histórico!\n";
echo "   (Depende do exchange - Binance tem dados desde 2017)\n";