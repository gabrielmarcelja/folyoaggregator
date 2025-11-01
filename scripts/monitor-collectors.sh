#!/bin/bash

# Script de monitoramento dos coletores

clear
echo "╔════════════════════════════════════════════════════════╗"
echo "║     MONITORAMENTO DE COLETORES - FOLYOAGGREGATOR      ║"
echo "╚════════════════════════════════════════════════════════╝"
echo ""

while true; do
    # Clear previous info
    tput cup 5 0

    # Check collectors status
    COLLECTORS=$(ps aux | grep price-collector | grep -v grep | wc -l)

    echo "📊 STATUS DOS COLETORES"
    echo "═══════════════════════════════════════════════════════"
    echo "🟢 Coletores Ativos: $COLLECTORS/3"
    echo ""

    # Show process info
    echo "📍 PROCESSOS:"
    ps aux | grep price-collector | grep -v grep | awk '{print "   PID:", $2, "| CPU:", $3"%", "| MEM:", $4"%", "| Tempo:", $10}'
    echo ""

    # Database stats
    echo "💾 ESTATÍSTICAS DO BANCO:"
    mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -e "
    SELECT
        CONCAT('   Preços (1 min): ', COUNT(*)) as stat
    FROM prices
    WHERE timestamp > NOW() - INTERVAL 1 MINUTE
    UNION ALL
    SELECT CONCAT('   Preços (10 min): ', COUNT(*))
    FROM prices
    WHERE timestamp > NOW() - INTERVAL 10 MINUTE
    UNION ALL
    SELECT CONCAT('   Total no banco: ', COUNT(*))
    FROM prices;" 2>/dev/null | grep -v stat

    echo ""
    echo "📈 COLETA POR COLETOR:"
    echo "   Coletor 1 (TOP 10): A cada 30 segundos"
    echo "   Coletor 2 (11-30): A cada 60 segundos"
    echo "   Coletor 3 (31-50): A cada 120 segundos"
    echo ""
    echo "═══════════════════════════════════════════════════════"
    echo "Atualizando em 10 segundos... (Ctrl+C para sair)"

    sleep 10
done