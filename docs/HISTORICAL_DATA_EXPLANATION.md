# 📈 Dados Históricos via CCXT - Explicação Completa

## O que a CCXT Pode Buscar

### 🕐 **Timeframes Disponíveis**
```python
'1m'  → Candles de 1 minuto
'5m'  → Candles de 5 minutos
'15m' → Candles de 15 minutos
'30m' → Candles de 30 minutos
'1h'  → Candles de 1 hora
'4h'  → Candles de 4 horas
'1d'  → Candles diários
'1w'  → Candles semanais
'1M'  → Candles mensais
```

### 📅 **Quanto Histórico Cada Exchange Tem**

| Exchange | Histórico Disponível | Desde Quando |
|----------|---------------------|--------------|
| **Binance** | 5+ anos | 2017 |
| **Coinbase** | 7+ anos | 2015 |
| **Kraken** | 8+ anos | 2014 |
| **Bitfinex** | 9+ anos | 2013 |
| **Bitstamp** | 10+ anos | 2011 |

### 🔍 **Como Funciona**

```php
// EXEMPLO REAL do nosso código:
$exchange = new \ccxt\binance();

// Buscar últimos 30 dias de dados
$symbol = 'BTC/USDT';
$timeframe = '1h';
$since = strtotime('-30 days') * 1000;

// CCXT vai na Binance e busca:
$candles = $exchange->fetch_ohlcv($symbol, $timeframe, $since);

// Retorna array com:
// [timestamp, open, high, low, close, volume]
```

## 🤔 **De Onde Vêm Esses Dados?**

### Os exchanges GUARDAM TUDO!

1. **Cada trade executado** → Salvo no banco deles
2. **Agregado em candles** → OHLCV por período
3. **Armazenado forever** → Anos de histórico

### Binance, por exemplo:
- Tem BILHÕES de trades salvos
- Cada trade: preço, volume, timestamp
- Agregados em candles de 1m, 5m, 15m, etc
- API pública fornece até 1000 candles por chamada

## 💡 **Por Que Isso é Incrível?**

### Sem CCXT:
```
CMC API: "Histórico? Pague $899/mês"
CryptoCompare: "Histórico? $249/mês"
```

### Com CCXT:
```
Binance: "Aqui, 5 anos de dados, grátis"
Coinbase: "Quer desde 2015? Toma"
Kraken: "Precisa de 2014? Sem problema"
```

## 📊 **O Que Fazemos com Esses Dados**

### 1. **Coletamos via CCXT**
```bash
php scripts/collect-historical.php --days=365 --timeframe=1d
```

### 2. **Salvamos no Nosso Banco**
```sql
INSERT INTO historical_ohlcv (
    asset_id,
    exchange_id,
    timestamp,
    open_price,
    high_price,
    low_price,
    close_price,
    volume
)
```

### 3. **Usamos para Análises**
- Gráficos de candlestick
- Médias móveis
- RSI, MACD, Bollinger Bands
- Backtesting de estratégias
- Machine Learning

## 🎯 **Exemplo Prático**

### Pergunta: "Qual foi o preço do BTC em 1º de janeiro de 2025?"

**Sem nosso sistema:**
```
CMC: "Pague $899/mês para API histórica"
```

**Com nosso sistema:**
```php
// Buscamos na Binance
$jan1 = strtotime('2025-01-01') * 1000;
$candle = $exchange->fetch_ohlcv('BTC/USDT', '1d', $jan1, 1);

// Resposta GRÁTIS:
"1º Jan 2025: Open $92,785, Close $94,612"
```

## 📈 **Limitações e Soluções**

### Limitações:
1. **Rate limits** → 10-100 requisições/segundo
2. **Max candles** → 500-1000 por chamada
3. **Dados muito antigos** → Alguns exchanges não têm

### Nossas Soluções:
1. **Coleta incremental** → Um pouco por vez
2. **Cache local** → Salvar tudo no banco
3. **Múltiplos exchanges** → Redundância de dados

## 🚀 **Capacidade do Sistema**

### Já Coletamos:
- ✅ 30 dias de dados (4h) para 16 moedas
- ✅ 7 dias de dados (1h) para BTC
- ✅ Total: 3,048 candles históricos

### Podemos Coletar:
- 📊 5+ anos de dados diários
- 📊 1 ano de dados horários
- 📊 30 dias de dados de 5 minutos
- 📊 Para 200+ criptomoedas

### Estimativa de Espaço:
```
200 moedas × 365 dias × 24 horas = 1,752,000 candles/ano
× 100 bytes por registro = ~175 MB/ano

Ou seja: ANOS de dados em menos de 1GB!
```

## 🎉 **Conclusão**

**SIM, a CCXT busca dados históricos REAIS dos exchanges!**

- Não é simulação
- Não é aproximação
- São dados REAIS de trades REAIS
- De dias, meses e ANOS atrás
- Totalmente GRÁTIS!

**É como ter acesso ao arquivo histórico da bolsa de valores, mas para crypto!**