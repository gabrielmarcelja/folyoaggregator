#!/bin/bash

# Script para iniciar múltiplos coletores em paralelo

echo "🚀 Iniciando Múltiplos Coletores FolyoAggregator"
echo "================================================"

# Kill existing collectors
echo "⏹️  Parando coletores existentes..."
pkill -f "price-collector.php" 2>/dev/null

sleep 2

# Coletor 1: TOP 10 (atualização a cada 30 segundos)
echo "▶️  Iniciando Coletor 1 (TOP 10)..."
nohup php /var/www/html/folyoaggregator/scripts/price-collector.php \
    --symbols=BTC,ETH,BNB,SOL,XRP,USDC,ADA,DOGE,AVAX,TRX \
    --interval=30 \
    > /var/www/html/folyoaggregator/logs/collector1.log 2>&1 &
echo "   PID: $!"

# Coletor 2: Moedas 11-30 (atualização a cada 60 segundos)
echo "▶️  Iniciando Coletor 2 (11-30)..."
nohup php /var/www/html/folyoaggregator/scripts/price-collector.php \
    --symbols=DOT,MATIC,SHIB,LINK,UNI,BCH,ATOM,LTC,ETC,XLM,NEAR,FIL,ALGO,VET,HBAR,ARB,ICP,OP,INJ,IMX \
    --interval=60 \
    > /var/www/html/folyoaggregator/logs/collector2.log 2>&1 &
echo "   PID: $!"

# Coletor 3: Moedas 31-50 (atualização a cada 120 segundos)
echo "▶️  Iniciando Coletor 3 (31-50)..."
nohup php /var/www/html/folyoaggregator/scripts/price-collector.php \
    --symbols=GRT,SAND,MANA,AXS,CHZ,ENJ,CRV,1INCH,BAT,COMP,ZRX,SUSHI,YFI,AAVE,MKR,SNX,UMA,REN,BAL,KNC \
    --interval=120 \
    > /var/www/html/folyoaggregator/logs/collector3.log 2>&1 &
echo "   PID: $!"

echo ""
echo "✅ 3 Coletores iniciados!"
echo ""
echo "📊 Monitoramento:"
echo "   tail -f logs/collector1.log  # TOP 10"
echo "   tail -f logs/collector2.log  # 11-30"
echo "   tail -f logs/collector3.log  # 31-50"
echo ""
echo "⏹️  Para parar todos: pkill -f price-collector.php"