#!/bin/bash

echo "╔════════════════════════════════════════════════════════╗"
echo "║   🚀 COLETA MASSIVA DE HISTÓRICO - TOP 500            ║"
echo "╚════════════════════════════════════════════════════════╝"
echo ""
echo "📊 ESTRATÉGIA: 100% foco em histórico completo"
echo "───────────────────────────────────────────────────────"
echo ""

# Create logs directory
mkdir -p /var/www/html/folyoaggregator/logs/history

# Function to collect history for a symbol
collect_history() {
    local SYMBOL=$1
    local RANK=$2
    local YEARS=$3

    echo "[Rank #$RANK] Coletando $SYMBOL - $YEARS anos de histórico"

    # Calculate days
    DAYS=$((YEARS * 365))

    # Determine timeframe based on age
    if [ $YEARS -gt 5 ]; then
        TIMEFRAME="1d"  # Daily for very old coins
    elif [ $YEARS -gt 2 ]; then
        TIMEFRAME="4h"  # 4 hour for medium age
    else
        TIMEFRAME="1h"  # Hourly for recent
    fi

    # Try binance first (best liquidity)
    php /var/www/html/folyoaggregator/scripts/collect-historical.php \
        --symbol="$SYMBOL" \
        --days="$DAYS" \
        --timeframe="$TIMEFRAME" \
        --exchange="binance" \
        > "/var/www/html/folyoaggregator/logs/history/${SYMBOL}.log" 2>&1

    # Check if successful
    if grep -q "completed" "/var/www/html/folyoaggregator/logs/history/${SYMBOL}.log"; then
        echo "  ✅ $SYMBOL concluído"
    else
        echo "  ⚠️ $SYMBOL falhou, tentando coinbase..."
        # Try coinbase as backup
        php /var/www/html/folyoaggregator/scripts/collect-historical.php \
            --symbol="$SYMBOL" \
            --days="$DAYS" \
            --timeframe="$TIMEFRAME" \
            --exchange="coinbase" \
            >> "/var/www/html/folyoaggregator/logs/history/${SYMBOL}.log" 2>&1
    fi
}

# Export function for parallel execution
export -f collect_history

echo "🥇 FASE 1: TOP 10 (Histórico COMPLETO)"
echo "───────────────────────────────────────────────────────"
echo ""

# TOP 10 with known ages
collect_history "BTC" "1" "15" &  # Since 2009
collect_history "ETH" "2" "9" &   # Since 2015
collect_history "BNB" "4" "7" &   # Since 2017
collect_history "SOL" "5" "4" &   # Since 2020
collect_history "XRP" "3" "11" &  # Since 2013

wait  # Wait for first batch

echo ""
echo "🥈 FASE 2: TOP 11-50 (Histórico médio)"
echo "───────────────────────────────────────────────────────"
echo ""

# Next batch - run 10 in parallel
collect_history "ADA" "6" "7" &
collect_history "DOGE" "7" "11" &
collect_history "AVAX" "8" "4" &
collect_history "TRX" "9" "6" &
collect_history "DOT" "10" "4" &
collect_history "MATIC" "11" "5" &
collect_history "TON" "12" "5" &
collect_history "LINK" "13" "7" &
collect_history "ICP" "14" "3" &
collect_history "SHIB" "15" "3" &

# Run in batches of 10 to avoid overwhelming
sleep 5

collect_history "BCH" "16" "7" &
collect_history "LTC" "17" "13" &
collect_history "NEAR" "18" "4" &
collect_history "UNI" "19" "4" &
collect_history "LEO" "20" "5" &

wait

echo ""
echo "🥉 FASE 3: TOP 51-200 (3 anos padrão)"
echo "───────────────────────────────────────────────────────"
echo ""

# Get list from database
mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -N -e "
    SELECT symbol, market_cap_rank
    FROM assets
    WHERE is_tradeable = 1
    AND market_cap_rank BETWEEN 51 AND 200
    ORDER BY market_cap_rank
    LIMIT 30
" | while read SYMBOL RANK; do
    collect_history "$SYMBOL" "$RANK" "3" &

    # Control parallelism
    if (( $(jobs -r | wc -l) >= 10 )); then
        wait -n  # Wait for any job to finish
    fi
done

wait  # Wait for all remaining jobs

echo ""
echo "═══════════════════════════════════════════════════════"
echo "📊 VERIFICANDO RESULTADOS"
echo "═══════════════════════════════════════════════════════"

# Check results
mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -e "
SELECT
    'Ativos com histórico' as Métrica,
    COUNT(DISTINCT asset_id) as Total
FROM historical_ohlcv
UNION ALL
SELECT
    'Total de candles' as Métrica,
    COUNT(*) as Total
FROM historical_ohlcv
UNION ALL
SELECT
    'Dados mais antigos' as Métrica,
    DATE(MIN(timestamp)) as Total
FROM historical_ohlcv;"

echo ""
echo "✅ COLETA MASSIVA INICIADA!"
echo ""
echo "📁 Logs em: /var/www/html/folyoaggregator/logs/history/"
echo "📊 Monitor: tail -f logs/history/*.log"
echo ""