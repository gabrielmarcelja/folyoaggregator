#!/bin/bash

echo "╔════════════════════════════════════════════════════════╗"
echo "║   🚀 COLETANDO OS ÚLTIMOS 300 ATIVOS (201-500)        ║"
echo "╚════════════════════════════════════════════════════════╝"
echo ""

LOG_DIR="/var/www/html/folyoaggregator/logs/history"
mkdir -p "$LOG_DIR"

# Count current progress
CURRENT=$(mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -N -e "SELECT COUNT(DISTINCT asset_id) FROM historical_ohlcv" 2>/dev/null)
echo "📊 Progresso atual: $CURRENT/500 ativos"
echo ""

# Function for parallel collection
collect_asset() {
    local SYMBOL=$1
    local RANK=$2

    # Try multiple exchanges
    for EXCHANGE in binance kucoin bybit coinbase kraken; do
        php /var/www/html/folyoaggregator/scripts/collect-historical.php \
            --symbol="$SYMBOL" \
            --days="1095" \
            --timeframe="4h" \
            --exchange="$EXCHANGE" \
            > "$LOG_DIR/${SYMBOL}_${RANK}_${EXCHANGE}.log" 2>&1

        # Check if successful
        if grep -q "completed" "$LOG_DIR/${SYMBOL}_${RANK}_${EXCHANGE}.log" 2>/dev/null; then
            echo "  ✅ $SYMBOL (Rank #$RANK) - $EXCHANGE"
            return 0
        fi
    done

    echo "  ❌ $SYMBOL (Rank #$RANK) - Falhou em todas exchanges"
    return 1
}

export -f collect_asset

echo "🔄 COLETANDO RANKS 201-500"
echo "───────────────────────────────────────────────────────"
echo ""

# Get uncollected assets ranked 201-500
mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -N -e "
    SELECT a.symbol, a.market_cap_rank
    FROM assets a
    WHERE a.is_tradeable = 1
    AND a.market_cap_rank BETWEEN 201 AND 500
    AND a.id NOT IN (
        SELECT DISTINCT asset_id FROM historical_ohlcv
    )
    ORDER BY a.market_cap_rank
    LIMIT 300
" 2>/dev/null | while read SYMBOL RANK; do

    # Launch in background with parallelism control
    collect_asset "$SYMBOL" "$RANK" &

    # Limit to 15 parallel processes
    while (( $(jobs -r | wc -l) >= 15 )); do
        sleep 0.5
    done

    # Progress update
    if (( RANK % 20 == 0 )); then
        wait  # Wait for current batch
        PROGRESS=$(mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -N -e "SELECT COUNT(DISTINCT asset_id) FROM historical_ohlcv" 2>/dev/null)
        echo ""
        echo "📊 Atualização: $PROGRESS/500 ativos com histórico"
        echo ""
    fi
done

# Wait for all remaining jobs
echo ""
echo "⏳ Finalizando últimos processos..."
wait

echo ""
echo "═══════════════════════════════════════════════════════"
echo "✅ COLETA CONCLUÍDA!"
echo "═══════════════════════════════════════════════════════"
echo ""

# Final report
mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -e "
SELECT
    'RELATÓRIO FINAL' as Status,
    '' as Total
UNION ALL
SELECT
    '───────────────' as Status,
    '' as Total
UNION ALL
SELECT
    'Ativos com histórico' as Status,
    CONCAT(COUNT(DISTINCT asset_id), '/500') as Total
FROM historical_ohlcv
UNION ALL
SELECT
    'Total de candles' as Status,
    FORMAT(COUNT(*), 0) as Total
FROM historical_ohlcv
UNION ALL
SELECT
    'Período coberto' as Status,
    CONCAT(
        YEAR(MIN(timestamp)), ' - ',
        YEAR(MAX(timestamp))
    ) as Total
FROM historical_ohlcv
UNION ALL
SELECT
    'Taxa de sucesso' as Status,
    CONCAT(
        ROUND(COUNT(DISTINCT asset_id) / 500 * 100, 1), '%'
    ) as Total
FROM historical_ohlcv;"

echo ""