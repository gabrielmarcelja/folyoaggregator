#!/bin/bash

# Script para coletar histórico em paralelo

echo "📊 Coleta Paralela de Dados Históricos"
echo "======================================"
echo ""

# Função para coletar histórico de uma moeda
collect_history() {
    local symbol=$1
    local days=$2
    local timeframe=$3

    echo "[$(date +%H:%M:%S)] Coletando $symbol - $days dias - Timeframe: $timeframe"

    php /var/www/html/folyoaggregator/scripts/collect-historical.php \
        --symbol="$symbol" \
        --days="$days" \
        --timeframe="$timeframe" \
        > /var/www/html/folyoaggregator/logs/history_${symbol}.log 2>&1

    echo "[$(date +%H:%M:%S)] ✅ $symbol concluído"
}

# Export function for parallel execution
export -f collect_history

echo "🥇 FASE 1: TOP 10 (5 anos de histórico)"
echo "----------------------------------------"

# TOP 10 moedas - 5 anos de histórico
TOP10="BTC ETH BNB SOL XRP USDC ADA DOGE AVAX TRX"

# Rodar 5 em paralelo
echo "$TOP10" | tr ' ' '\n' | \
    xargs -P 5 -I {} bash -c 'collect_history "{}" 1825 "1h"'

echo ""
echo "🥈 FASE 2: TOP 11-50 (1 ano de histórico)"
echo "-----------------------------------------"

# TOP 11-50 - 1 ano
TOP50="DOT MATIC SHIB LINK UNI BCH ATOM LTC ETC XLM NEAR FIL ALGO VET HBAR ARB ICP OP INJ IMX"

# Rodar 10 em paralelo
echo "$TOP50" | tr ' ' '\n' | \
    xargs -P 10 -I {} bash -c 'collect_history "{}" 365 "4h"'

echo ""
echo "🥉 FASE 3: TOP 51-200 (3 meses)"
echo "--------------------------------"

# Aqui você adicionaria mais moedas...

echo ""
echo "✅ Coleta histórica paralela concluída!"
echo ""
echo "📈 Estatísticas:"
mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -e "
SELECT
    COUNT(DISTINCT asset_id) as 'Moedas com Histórico',
    COUNT(*) as 'Total Candles',
    MIN(timestamp) as 'Dados Desde',
    MAX(timestamp) as 'Dados Até'
FROM historical_ohlcv;"