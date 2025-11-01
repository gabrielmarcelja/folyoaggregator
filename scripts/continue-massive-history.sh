#!/bin/bash

echo "╔════════════════════════════════════════════════════════╗"
echo "║   ⚡ CONTINUANDO COLETA MASSIVA - TOP 82-500          ║"
echo "╚════════════════════════════════════════════════════════╝"
echo ""

LOG_DIR="/var/www/html/folyoaggregator/logs/history"
mkdir -p "$LOG_DIR"

# Function to collect in parallel
collect_batch() {
    local SYMBOL=$1
    local RANK=$2

    php /var/www/html/folyoaggregator/scripts/collect-historical.php \
        --symbol="$SYMBOL" \
        --days="1095" \
        --timeframe="4h" \
        --exchange="binance" \
        > "$LOG_DIR/${SYMBOL}_${RANK}.log" 2>&1 &
}

echo "📊 Coletando TOP 82-500 (3 anos de histórico cada)"
echo "───────────────────────────────────────────────────────"
echo ""

# Get assets without history yet
mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -N -e "
    SELECT DISTINCT a.symbol, a.market_cap_rank
    FROM assets a
    WHERE a.is_tradeable = 1
    AND a.market_cap_rank BETWEEN 82 AND 500
    AND a.id NOT IN (
        SELECT DISTINCT asset_id FROM historical_ohlcv
    )
    ORDER BY a.market_cap_rank
" | while read SYMBOL RANK; do

    echo "[Rank #$RANK] Iniciando $SYMBOL..."
    collect_batch "$SYMBOL" "$RANK"

    # Control parallelism - max 20 parallel
    while (( $(jobs -r | wc -l) >= 20 )); do
        sleep 1
    done

    # Progress update every 10
    if (( RANK % 10 == 0 )); then
        echo ""
        echo "🎯 Processados até rank #$RANK"
        COLLECTED=$(mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -N -e "SELECT COUNT(DISTINCT asset_id) FROM historical_ohlcv" 2>/dev/null)
        echo "📊 Total com histórico: $COLLECTED/500"
        echo ""
    fi
done

# Wait for all jobs to complete
echo ""
echo "⏳ Aguardando conclusão dos últimos processos..."
wait

echo ""
echo "═══════════════════════════════════════════════════════"
echo "✅ COLETA CONTINUADA CONCLUÍDA!"
echo "═══════════════════════════════════════════════════════"
echo ""

# Final stats
mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -e "
SELECT
    'RESULTADO FINAL' as Métrica,
    '' as Total
UNION ALL
SELECT
    'Ativos com histórico' as Métrica,
    COUNT(DISTINCT asset_id) as Total
FROM historical_ohlcv
UNION ALL
SELECT
    'Total de candles' as Métrica,
    FORMAT(COUNT(*), 0) as Total
FROM historical_ohlcv
UNION ALL
SELECT
    'Dados mais antigos' as Métrica,
    DATE(MIN(timestamp)) as Total
FROM historical_ohlcv
UNION ALL
SELECT
    'Tamanho do banco (MB)' as Métrica,
    ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) as Total
FROM information_schema.TABLES
WHERE table_schema = 'folyoaggregator';"