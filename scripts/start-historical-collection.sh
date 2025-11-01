#!/bin/bash

echo "╔════════════════════════════════════════════════════════╗"
echo "║     🕐 INICIANDO COLETA DE DADOS HISTÓRICOS           ║"
echo "╚════════════════════════════════════════════════════════╝"
echo ""

LOG_DIR="/var/www/html/folyoaggregator/logs/historical"
mkdir -p "$LOG_DIR"

# Função para coletar histórico
collect_symbol() {
    local SYMBOL=$1
    local DAYS=$2
    local TIMEFRAME=$3
    local EXCHANGE=$4

    echo "📊 Coletando $SYMBOL - $DAYS dias - Timeframe $TIMEFRAME - Exchange $EXCHANGE"

    php /var/www/html/folyoaggregator/scripts/collect-historical.php \
        --symbol="$SYMBOL" \
        --days="$DAYS" \
        --timeframe="$TIMEFRAME" \
        --exchange="$EXCHANGE" \
        >> "$LOG_DIR/${SYMBOL}_${EXCHANGE}.log" 2>&1 &

    echo "   PID: $!"
}

echo "🥇 FASE 1: TOP 10 MOEDAS (1 ano de histórico)"
echo "───────────────────────────────────────────────"

# TOP 10 - Binance (melhor liquidez)
collect_symbol "BTC" "365" "1h" "binance"
sleep 1
collect_symbol "ETH" "365" "1h" "binance"
sleep 1
collect_symbol "BNB" "365" "1h" "binance"
sleep 1
collect_symbol "SOL" "365" "1h" "binance"
sleep 1
collect_symbol "XRP" "365" "1h" "binance"
sleep 1

echo ""
echo "🥈 FASE 2: TOP 11-30 (6 meses de histórico)"
echo "───────────────────────────────────────────────"

collect_symbol "ADA" "180" "4h" "binance"
sleep 1
collect_symbol "DOGE" "180" "4h" "binance"
sleep 1
collect_symbol "AVAX" "180" "4h" "binance"
sleep 1
collect_symbol "DOT" "180" "4h" "binance"
sleep 1
collect_symbol "MATIC" "180" "4h" "binance"
sleep 1

echo ""
echo "🥉 FASE 3: TOP 31-50 (3 meses de histórico)"
echo "───────────────────────────────────────────────"

collect_symbol "LINK" "90" "4h" "binance"
sleep 1
collect_symbol "UNI" "90" "4h" "binance"
sleep 1
collect_symbol "ATOM" "90" "4h" "binance"
sleep 1
collect_symbol "LTC" "90" "4h" "binance"
sleep 1
collect_symbol "BCH" "90" "4h" "binance"

echo ""
echo "═══════════════════════════════════════════════════════"
echo "✅ 15 COLETORES HISTÓRICOS INICIADOS!"
echo "═══════════════════════════════════════════════════════"
echo ""
echo "📊 Monitorar progresso:"
echo "   tail -f $LOG_DIR/BTC_binance.log"
echo "   tail -f $LOG_DIR/ETH_binance.log"
echo ""
echo "📈 Ver quantos processos estão rodando:"
echo "   ps aux | grep collect-historical | grep -v grep | wc -l"
echo ""
echo "⏹️  Para parar todos:"
echo "   pkill -f collect-historical"
echo ""