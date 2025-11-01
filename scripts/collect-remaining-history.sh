#!/bin/bash

echo "╔════════════════════════════════════════════════════════╗"
echo "║   COLETANDO HISTÓRICO DOS 75 ATIVOS RESTANTES         ║"
echo "╚════════════════════════════════════════════════════════╝"
echo ""

LOG_DIR="/var/www/html/folyoaggregator/logs/history"
mkdir -p "$LOG_DIR"

# Get assets without history
ASSETS=$(mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -N -e "
    SELECT a.symbol
    FROM assets a
    WHERE a.is_tradeable = 1
    AND a.id NOT IN (
        SELECT DISTINCT asset_id FROM historical_ohlcv
    )
    AND a.market_cap_rank <= 200
    ORDER BY a.market_cap_rank
    LIMIT 75
" 2>/dev/null)

TOTAL=$(echo "$ASSETS" | wc -l)
echo "📊 Encontrados $TOTAL ativos sem histórico"
echo ""

COUNT=0

# Function to collect with fallback exchanges
collect_with_fallback() {
    local SYMBOL=$1
    local SUCCESS=0

    # Try each exchange in order of preference
    for EXCHANGE in binance kucoin bybit coinbase kraken huobi gateio; do
        echo -n "   Tentando $EXCHANGE... "

        php /var/www/html/folyoaggregator/scripts/collect-historical.php \
            --symbol="$SYMBOL" \
            --days="365" \
            --timeframe="4h" \
            --exchange="$EXCHANGE" \
            > "$LOG_DIR/${SYMBOL}_${EXCHANGE}.log" 2>&1 &

        # Wait for process to complete (max 30 seconds)
        PID=$!
        SECONDS=0
        while kill -0 $PID 2>/dev/null && [ $SECONDS -lt 30 ]; do
            sleep 1
        done

        # Check if successful
        if grep -q "completed" "$LOG_DIR/${SYMBOL}_${EXCHANGE}.log" 2>/dev/null; then
            echo "✅"
            SUCCESS=1
            break
        else
            echo "❌"
            kill $PID 2>/dev/null
        fi
    done

    return $SUCCESS
}

# Process each asset
echo "$ASSETS" | while read SYMBOL; do
    if [ -z "$SYMBOL" ]; then
        continue
    fi

    COUNT=$((COUNT + 1))
    echo "[$COUNT/$TOTAL] Coletando $SYMBOL..."

    if collect_with_fallback "$SYMBOL"; then
        echo "   ✅ $SYMBOL concluído!"
    else
        echo "   ⚠️ $SYMBOL falhou em todas exchanges"
    fi

    # Progress update
    if (( COUNT % 10 == 0 )); then
        COLLECTED=$(mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -N -e "SELECT COUNT(DISTINCT asset_id) FROM historical_ohlcv" 2>/dev/null)
        echo ""
        echo "📊 Progresso: $COLLECTED/202 ativos com histórico"
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
    CONCAT(COUNT(DISTINCT asset_id), '/202') as Total
FROM historical_ohlcv
UNION ALL
SELECT
    'Total de candles' as Status,
    FORMAT(COUNT(*), 0) as Total
FROM historical_ohlcv
UNION ALL
SELECT
    'Taxa de sucesso' as Status,
    CONCAT(ROUND(COUNT(DISTINCT asset_id) / 202 * 100, 1), '%') as Total
FROM historical_ohlcv;"

echo ""
echo "🎯 Sistema quase pronto para substituir CMC!"