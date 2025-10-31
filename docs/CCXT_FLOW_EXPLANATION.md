# 🔄 Como CCXT Funciona no FolyoAggregator

## Visão Geral do Fluxo de Dados

```
CMC API → Assets (metadados)
    ↓
CCXT → Preços em tempo real (10 exchanges)
    ↓
PriceAggregator → Cálculos (VWAP, mediana, spread)
    ↓
Database → Armazenamento permanente
    ↓
API → Dados agregados para Folyo
```

## 1️⃣ **CMC (CoinMarketCap) - Metadados**

### O que fazemos:
- Buscamos **lista de moedas** (nome, símbolo, market cap, rank)
- Validamos se são **negociáveis** nos exchanges CCXT
- **NÃO usamos CMC para preços** (muito caro e limitado)

### Quando executamos:
- **Manualmente**: `php scripts/sync-cmc.php`
- **Automaticamente**: Via cron (a cada hora)

### O que salvamos no banco:
```sql
-- Tabela: assets
- symbol, name, market_cap_rank
- is_tradeable (se pode ser negociada)
- market_cap, volume_24h (do CMC)
- percent_change_1h, 24h, 7d, 30d
```

## 2️⃣ **CCXT - Preços em Tempo Real**

### Como funciona:

```php
// ExchangeManager.php
$exchange = new \ccxt\binance();
$ticker = $exchange->fetch_ticker('BTC/USDT');

// Retorna:
{
    'symbol': 'BTC/USDT',
    'bid': 109698.46,     // Melhor oferta de compra
    'ask': 109698.47,     // Melhor oferta de venda
    'last': 109698.46,    // Último preço
    'volume': 2533654806  // Volume 24h
}
```

### Exchanges configurados:
1. **Binance** - Maior volume
2. **Coinbase** - EUA/Europa
3. **Kraken** - Alta confiabilidade
4. **KuCoin** - Altcoins
5. **Bybit** - Derivativos
6. **OKX** - Ásia
7. **Gate.io** - Muitas moedas
8. **Bitfinex** - Institucional
9. **Huobi** - Ásia
10. **Bitstamp** - Europa

### ⚡ **SEM API KEYS!**
CCXT acessa dados públicos - não precisa autenticação!

## 3️⃣ **PriceAggregator - Processamento**

### Quando chamamos:

1. **Via API**: Quando alguém acessa `/api/v1/prices/BTC`
2. **Via Script**: `php scripts/price-collector.php`
3. **Via Cron**: (ainda não configurado)

### O que calculamos:

```php
// Para cada moeda:
1. price_simple_avg = Média simples de todos exchanges
2. price_vwap = Volume Weighted Average Price
3. price_median = Preço mediano
4. price_min/max = Menor e maior preço
5. price_spread = Diferença % entre min e max
6. confidence_score = 0-100 baseado em:
   - Número de exchanges (30%)
   - Consistência de preços (40%)
   - Distribuição de volume (30%)
```

### O que salvamos:

```sql
-- Tabela: prices (preços individuais)
INSERT INTO prices (
    asset_id,
    exchange_id,
    price,
    volume_24h,
    bid_price,
    ask_price,
    timestamp
)

-- Tabela: aggregated_prices (médias calculadas)
INSERT INTO aggregated_prices (
    asset_id,
    price_simple_avg,
    price_vwap,
    price_median,
    confidence_score,
    timestamp
)
```

## 4️⃣ **Estado Atual do Banco**

```
✅ Assets: 202 moedas (190 negociáveis)
⚠️  Prices: 79 registros apenas
⚠️  Aggregated: 8 registros apenas
```

**PROBLEMA**: Estamos coletando preços apenas quando alguém chama a API!

## 5️⃣ **O que FALTA Implementar**

### 🔴 **Urgente:**

1. **Coleta Contínua de Preços**
   ```bash
   # Rodar em background:
   php scripts/price-collector.php --limit=50 --interval=60 &
   ```

2. **Cron para Coleta Automática**
   ```bash
   */5 * * * * php /var/www/html/folyoaggregator/scripts/price-collector.php
   ```

3. **Dados Históricos (OHLCV)**
   ```php
   // Ainda não implementado
   $ohlcv = $exchange->fetch_ohlcv('BTC/USDT', '1h', since, limit);
   ```

### 🟡 **Importante:**

4. **Cache Redis**
   - Evitar chamadas repetidas ao CCXT
   - Cache de 30-60 segundos

5. **WebSocket para Tempo Real**
   - Alguns exchanges suportam WebSocket
   - Atualizações instantâneas

6. **Rate Limiting**
   - Controlar chamadas por exchange
   - Evitar banimento

## 📊 **Comparação: CMC vs FolyoAggregator**

| Aspecto | CMC API | FolyoAggregator |
|---------|---------|-----------------|
| **Fonte de Preços** | Agregado próprio | 10 exchanges direto |
| **Atualização** | 1-5 minutos | Tempo real possível |
| **Custo** | $299-899/mês | GRÁTIS |
| **API Calls** | 10k-75k/mês limitado | Ilimitado* |
| **Dados Históricos** | Pago extra | Podemos coletar grátis |
| **Transparência** | Não mostra fonte | Mostra cada exchange |
| **VWAP** | Não fornece | Calculamos |
| **Confidence Score** | Não tem | Calculamos |

*Limitado apenas por rate limits dos exchanges (geralmente 10-100 req/seg)

## 🚀 **Próximos Passos**

1. **Ativar coleta contínua** (script já criado)
2. **Popular banco com histórico** (OHLCV)
3. **Configurar Redis** para cache
4. **Depois**: Integrar com Folyo

## 💡 **Vantagens do Nosso Sistema**

1. **Transparência Total**: Sabemos exatamente de onde vem cada preço
2. **Controle Total**: Podemos ajustar algoritmos de agregação
3. **Sem Limites**: Não dependemos de planos pagos
4. **Escalável**: Podemos adicionar mais exchanges facilmente
5. **Auditável**: Todos os preços são salvos com timestamp

---

*O FolyoAggregator é um agregador de preços REAL, não apenas um proxy de API!*