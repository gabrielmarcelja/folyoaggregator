#!/bin/bash

echo "╔════════════════════════════════════════════════════════╗"
echo "║   📊 COLETANDO HISTÓRICO DOS ATIVOS 301-500           ║"
echo "╚════════════════════════════════════════════════════════╝"
echo ""

LOG_DIR="/var/www/html/folyoaggregator/logs/history"
mkdir -p "$LOG_DIR"

# Get assets ranked 301-500 without history
ASSETS=$(mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -N -e "
    SELECT a.symbol, a.market_cap_rank
    FROM assets a
    WHERE a.is_tradeable = 1
    AND a.market_cap_rank BETWEEN 301 AND 500
    AND a.id NOT IN (
        SELECT DISTINCT asset_id FROM historical_ohlcv
    )
    ORDER BY a.market_cap_rank
    LIMIT 50
" 2>/dev/null)

TOTAL=$(echo "$ASSETS" | grep -c ".")
echo "📊 Encontrados $TOTAL ativos rank 301-500 sem histórico (limitado a 50 por execução)"
echo ""

COUNT=0
SUCCESS=0
FAILED=0

# Function to collect with fallback exchanges and pairs
collect_with_fallback() {
    local SYMBOL=$1
    local RANK=$2
    local COLLECTED=0

    # Try different trading pairs
    for PAIR_SUFFIX in USDT USD BUSD BTC ETH; do
        # Try each exchange
        for EXCHANGE in binance kucoin bybit coinbase kraken okx gateio huobi bitfinex bitstamp; do
            echo -n "   $EXCHANGE ${SYMBOL}/${PAIR_SUFFIX}... "

            php /var/www/html/folyoaggregator/scripts/collect-historical.php \
                --symbol="${SYMBOL}" \
                --pair="${SYMBOL}/${PAIR_SUFFIX}" \
                --days="365" \
                --timeframe="4h" \
                --exchange="$EXCHANGE" \
                > "$LOG_DIR/${SYMBOL}_${RANK}_${EXCHANGE}_${PAIR_SUFFIX}.log" 2>&1 &

            # Wait for process (max 20 seconds)
            PID=$!
            SECONDS=0
            while kill -0 $PID 2>/dev/null && [ $SECONDS -lt 20 ]; do
                sleep 1
            done

            # Check if successful
            if grep -q "completed\|collected" "$LOG_DIR/${SYMBOL}_${RANK}_${EXCHANGE}_${PAIR_SUFFIX}.log" 2>/dev/null; then
                echo "✅"
                COLLECTED=1
                break 2  # Break both loops
            else
                echo "❌"
                kill $PID 2>/dev/null
            fi
        done

        if [ $COLLECTED -eq 1 ]; then
            break
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
        echo "   ⚠️ Falhou em todas exchanges e pares"

        # Add to failed list for manual review
        echo "$SYMBOL,$RANK" >> "$LOG_DIR/failed_301_500.csv"
    fi

    # Progress update every 10 assets
    if (( COUNT % 10 == 0 )); then
        PROGRESS=$(mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -N -e "SELECT COUNT(DISTINCT asset_id) FROM historical_ohlcv" 2>/dev/null)
        echo ""
        echo "📊 Progresso: $PROGRESS/500 ativos com histórico"
        echo ""
    fi
done

echo ""
echo "═══════════════════════════════════════════════════════"
echo "✅ BATCH FINALIZADO!"
echo "═══════════════════════════════════════════════════════"
echo ""

# Final statistics
mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -e "
SELECT
    'ESTATÍSTICAS DO BATCH' as Status,
    '' as Total
UNION ALL
SELECT
    '────────────────────' as Status,
    '' as Total
UNION ALL
SELECT
    'Processados neste batch' as Status,
    '$COUNT' as Total
UNION ALL
SELECT
    'Sucesso' as Status,
    '$SUCCESS' as Total
UNION ALL
SELECT
    'Falhou' as Status,
    '$FAILED' as Total
UNION ALL
SELECT
    '────────────────────' as Status,
    '' as Total
UNION ALL
SELECT
    'Ativos com histórico (total)' as Status,
    CONCAT(COUNT(DISTINCT asset_id), '/500') as Total
FROM historical_ohlcv
UNION ALL
SELECT
    'Total de candles' as Status,
    FORMAT(COUNT(*), 0) as Total
FROM historical_ohlcv;"

echo ""
echo "💡 Para tokens que falharam, verifique:"
echo "   - Se estão listados apenas em DEXs (Uniswap, PancakeSwap)"
echo "   - Se são tokens muito novos (menos de 1 ano)"
echo "   - Se usam símbolos diferentes nas exchanges"
echo "   - Lista de falhas em: $LOG_DIR/failed_301_500.csv"