#!/bin/bash

# Estratégia Inteligente: Aumenta gradualmente

echo "🧠 Estratégia Inteligente de Paralelização"
echo "==========================================="
echo ""

# FASE 1: Começar com 3 coletores
echo "📍 FASE 1 (Agora): 3 coletores"
echo "   - Seguro, sem risco de ban"
echo "   - 3x mais rápido"
echo ""

# Comando para 3 coletores
cat << 'EOF'
# TOP 10 - Atualização a cada 30s
php scripts/price-collector.php --limit=10 --interval=30 &

# TOP 11-30 - Atualização a cada 60s
php scripts/price-collector.php --symbols=DOT,MATIC,SHIB,LINK,UNI,BCH,ATOM,LTC,ETC,XLM --interval=60 &

# TOP 31-50 - Atualização a cada 120s
php scripts/price-collector.php --symbols=NEAR,FIL,ALGO,VET,HBAR,ARB,ICP,OP,INJ,IMX --interval=120 &
EOF

echo ""
echo "📍 FASE 2 (Após 1 dia): Aumentar para 5"
echo "   - Monitorar logs por erros 429 (rate limit)"
echo "   - Se OK, continuar"
echo ""

echo "📍 FASE 3 (Após 2 dias): Máximo de 7"
echo "   - Limite seguro"
echo "   - 7x mais rápido"
echo ""

echo "⚠️  NUNCA fazer:"
echo "   ❌ 10+ coletores simultâneos"
echo "   ❌ Intervals < 30 segundos"
echo "   ❌ Ignorar erros 429"
echo ""

echo "✅ Estratégia Segura:"
echo "   1. Use delays entre requests"
echo "   2. Rotacione entre exchanges"
echo "   3. Implemente retry com backoff"
echo "   4. Monitore logs constantemente"