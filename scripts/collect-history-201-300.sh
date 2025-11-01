#!/bin/bash

echo "╔════════════════════════════════════════════════════════╗"
echo "║   📊 COLETANDO HISTÓRICO DOS ATIVOS 201-300           ║"
echo "╚════════════════════════════════════════════════════════╝"
echo ""

LOG_DIR="/var/www/html/folyoaggregator/logs/history"
mkdir -p "$LOG_DIR"

# Get assets ranked 201-300 without history
ASSETS=$(mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -N -e "
    SELECT a.symbol, a.market_cap_rank
    FROM assets a
    WHERE a.is_tradeable = 1
    AND a.market_cap_rank BETWEEN 201 AND 300
    AND a.id NOT IN (
        SELECT DISTINCT asset_id FROM historical_ohlcv
    )
    ORDER BY a.market_cap_rank
" 2>/dev/null)

TOTAL=$(echo "$ASSETS" | grep -c ".")
echo "📊 Encontrados $TOTAL ativos rank 201-300 sem histórico"
echo ""

COUNT=0
SUCCESS=0
FAILED=0

# Function to collect with fallback exchanges
collect_with_fallback() {
    local SYMBOL=$1
    local RANK=$2
    local COLLECTED=0

    # Try each exchange in order of preference
    for EXCHANGE in binance kucoin bybit coinbase kraken huobi gateio; do
        echo -n "   $EXCHANGE... "

        php /var/www/html/folyoaggregator/scripts/collect-historical.php \
            --symbol="$SYMBOL" \
            --days="365" \
            --timeframe="4h" \
            --exchange="$EXCHANGE" \
            > "$LOG_DIR/${SYMBOL}_${RANK}_${EXCHANGE}.log" 2>&1 &

        # Wait for process (max 30 seconds)
        PID=$!
        SECONDS=0
        while kill -0 $PID 2>/dev/null && [ $SECONDS -lt 30 ]; do
            sleep 1
        done

        # Check if successful
        if grep -q "completed" "$LOG_DIR/${SYMBOL}_${RANK}_${EXCHANGE}.log" 2>/dev/null; then
            echo "✅"
            COLLECTED=1
            break
        else
            echo "❌"
            kill $PID 2>/dev/null
        fi
    done

    return $((1 - COLLECTED))
}

# Process each asset
echo "$ASSETS" | while read SYMBOL RANK; do
    if [ -z "$SYMBOL" ]; then
        continue
    fi

    COUNT=$((COUNT + 1))
    echo "[$COUNT/$TOTAL] $SYMBOL (Rank #$RANK):"

    if collect_with_fallback "$SYMBOL" "$RANK"; then
        SUCCESS=$((SUCCESS + 1))
        echo "   ✅ Histórico coletado com sucesso!"
    else
        FAILED=$((FAILED + 1))
        echo "   ⚠️ Falhou em todas exchanges"
    fi

    # Progress update every 10 assets
    if (( COUNT % 10 == 0 )); then
        PROGRESS=$(mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -N -e "SELECT COUNT(DISTINCT asset_id) FROM historical_ohlcv" 2>/dev/null)
        echo ""
        echo "📊 Progresso: $PROGRESS/300 ativos com histórico"
        echo ""
    fi
done

echo ""
echo "═══════════════════════════════════════════════════════"
echo "✅ COLETA FINALIZADA!"
echo "═══════════════════════════════════════════════════════"
echo ""

# Final statistics
mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -e "
SELECT
    'ESTATÍSTICAS FINAIS' as Status,
    '' as Total
UNION ALL
SELECT
    '────────────────────' as Status,
    '' as Total
UNION ALL
SELECT
    'Ativos com histórico' as Status,
    CONCAT(COUNT(DISTINCT asset_id), '/300') as Total
FROM historical_ohlcv
UNION ALL
SELECT
    'Total de candles' as Status,
    FORMAT(COUNT(*), 0) as Total
FROM historical_ohlcv
UNION ALL
SELECT
    'Taxa de sucesso' as Status,
    CONCAT(ROUND(COUNT(DISTINCT asset_id) / 300 * 100, 1), '%') as Total
FROM historical_ohlcv;"