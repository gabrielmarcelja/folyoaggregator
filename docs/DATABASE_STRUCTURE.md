# 🗄️ Estrutura do Banco de Dados - FolyoAggregator

**Database:** `folyoaggregator`
**Engine:** MariaDB/MySQL
**Charset:** utf8mb4

---

## 📊 Visão Geral das Tabelas

| # | Tabela | Registros | Tamanho | Função |
|---|--------|-----------|---------|--------|
| 1 | **historical_ohlcv** | 421,983 | 41.6 MB | 📈 Dados históricos OHLCV (candles) |
| 2 | **prices** | 50,058 | 6.5 MB | ⚡ Preços em tempo real por exchange |
| 3 | **aggregated_prices** | 7,956 | 1.5 MB | 🎯 Preços agregados (VWAP) |
| 4 | **assets** | 151 | 1.4 MB | 💎 Metadados dos ativos |
| 5 | **asset_urls** | 1,648 | 0.2 MB | 🔗 URLs (explorer, github, etc) |
| 6 | **asset_descriptions** | 149 | 0.1 MB | 📝 Descrições dos ativos |
| 7 | **exchanges** | 10 | 0.02 MB | 🏢 Configuração das exchanges |
| 8 | **symbol_mappings** | 30 | 0.02 MB | 🔄 Mapeamento CMC ↔ Exchange |
| 9 | **cmc_sync_log** | 5 | 0.02 MB | 📋 Log de sincronizações CMC |
| 10-13 | Outras | - | - | Auxiliares |

**Total do Banco:** ~50 MB de dados + 110 MB de índices

---

## 🏗️ Estrutura Detalhada das Tabelas

### 1. 💎 **ASSETS** (Tabela Principal)

**Função:** Armazena todos os metadados dos ativos/criptomoedas

**Campos Principais (58 campos total):**

#### 🔑 Identificação
```
id                   INT             Primary Key, auto_increment
cmc_id               INT             ID na CoinMarketCap
symbol               VARCHAR(20)     Ex: BTC, ETH, XRP
name                 VARCHAR(100)    Nome completo
slug                 VARCHAR(100)    Slug único (ex: bitcoin)
```

#### 📊 Dados de Mercado
```
market_cap_rank      INT             Ranking por market cap (#1, #2, etc)
market_cap           DECIMAL(30,2)   Capitalização de mercado
volume_24h           DECIMAL(30,2)   Volume 24 horas
market_cap_dominance DECIMAL(10,4)   % de dominância do mercado
fully_diluted_mcap   DECIMAL(30,2)   Market cap totalmente diluído
```

#### 💰 Preço e Mudanças
```
price_usd            DECIMAL(30,8)   Preço atual em USD
percent_change_1h    DECIMAL(10,4)   Mudança 1 hora
percent_change_24h   DECIMAL(10,4)   Mudança 24 horas
percent_change_7d    DECIMAL(10,4)   Mudança 7 dias
percent_change_30d   DECIMAL(10,4)   Mudança 30 dias
percent_change_60d   DECIMAL(10,2)   Mudança 60 dias
percent_change_90d   DECIMAL(10,2)   Mudança 90 dias
```

#### 🪙 Supply
```
circulating_supply   DECIMAL(30,8)   Supply em circulação
total_supply         DECIMAL(30,8)   Supply total
max_supply           DECIMAL(30,8)   Supply máximo
```

#### 📝 Metadados
```
description          TEXT            Descrição completa
icon_url             VARCHAR(255)    URL do ícone/logo
website_url          VARCHAR(255)    Site oficial
category             VARCHAR(100)    Categoria (DeFi, Layer 1, etc)
tags                 LONGTEXT        Tags JSON
platform             VARCHAR(100)    Plataforma (Ethereum, BNB Chain, etc)
```

#### 🔗 Links Sociais e Técnicos
```
twitter_username     VARCHAR(100)
telegram_channel     VARCHAR(100)
reddit_url           VARCHAR(255)
github_url           VARCHAR(255)
whitepaper_url       VARCHAR(255)
explorer_urls        LONGTEXT        URLs de explorers (JSON)
```

#### ⚙️ Trading
```
is_active            BOOLEAN         Ativo/Inativo
is_tradeable         BOOLEAN         Negociável em exchanges
tradeable_exchanges  LONGTEXT        Lista de exchanges (JSON)
num_market_pairs     INT             Número de pares de trading
```

#### 📅 Datas
```
date_added           DATE            Data de adição à CMC
date_launched        DATE            Data de lançamento
created_at           TIMESTAMP
updated_at           TIMESTAMP
cmc_last_sync        TIMESTAMP       Última sync com CMC
```

**Exemplo de Dados:**

```sql
┌─────┬────────┬──────────┬──────┬───────────────────┬──────────────┐
│ id  │ symbol │   name   │ rank │    market_cap     │  volume_24h  │
├─────┼────────┼──────────┼──────┼───────────────────┼──────────────┤
│  1  │  BTC   │ Bitcoin  │  1   │ 2,183,942,343,494 │ 61,230,649K  │
│  2  │  ETH   │ Ethereum │  2   │   464,810,036,308 │ 38,019,390K  │
│  7  │  XRP   │   XRP    │  4   │   150,844,495,913 │  4,685,671K  │
└─────┴────────┴──────────┴──────┴───────────────────┴──────────────┘
```

**Índices:**
- PRIMARY KEY (id)
- UNIQUE KEY (slug)
- INDEX (symbol, market_cap_rank, is_active, cmc_id)

---

### 2. 📈 **HISTORICAL_OHLCV** (Dados Históricos)

**Função:** Armazena dados OHLCV (candlestick) históricos de todos os ativos

**Estrutura:**

```sql
id               BIGINT          Primary Key, auto_increment
asset_id         INT             FK → assets.id
exchange_id      INT             FK → exchanges.id
timeframe        ENUM            '1m', '5m', '15m', '30m', '1h', '4h', '1d', '1w', 'month'
timestamp        TIMESTAMP       Data/hora do candle
open_price       DECIMAL(20,8)   Preço de abertura
high_price       DECIMAL(20,8)   Preço máximo
low_price        DECIMAL(20,8)   Preço mínimo
close_price      DECIMAL(20,8)   Preço de fechamento
volume           DECIMAL(30,8)   Volume negociado
trades_count     INT             Número de trades (opcional)
created_at       TIMESTAMP       Criação do registro
```

**Dados Armazenados:**

```
Total de Candles: 421,983
Timeframes disponíveis:
  - 4h:  414,315 candles (principal)
  - 1h:    2,668 candles (5 ativos)
  - 1d:    5,000 candles (10 ativos)

Cobertura Temporal:
  - Data mais antiga: 2017-08-17
  - Data mais recente: 2025-11-01
  - Amplitude: 8.2 anos

Média por ativo: 2,930 candles (~480 dias)
```

**Exemplo de Dados (BTC):**

```sql
┌─────────────────────┬───────────┬──────────────┬──────────────┬─────────────┐
│     timestamp       │ timeframe │  open_price  │  close_price │   volume    │
├─────────────────────┼───────────┼──────────────┼──────────────┼─────────────┤
│ 2025-11-01 04:00:00 │    4h     │ 110,226.38   │  110,140.79  │   591.88    │
│ 2025-11-01 00:00:00 │    4h     │ 109,608.01   │  110,226.37  │  1,329.53   │
│ 2025-10-31 20:00:00 │    4h     │ 109,828.78   │  109,562.75  │   399.55    │
└─────────────────────┴───────────┴──────────────┴──────────────┴─────────────┘
```

**Índices:**
- PRIMARY KEY (id)
- INDEX (asset_id, exchange_id, timestamp, timeframe)
- UNIQUE KEY (asset_id, exchange_id, timeframe, timestamp)

---

### 3. ⚡ **PRICES** (Preços em Tempo Real)

**Função:** Armazena preços atuais de cada exchange individualmente

**Estrutura:**

```sql
id                    BIGINT          Primary Key
asset_id              INT             FK → assets.id
exchange_id           INT             FK → exchanges.id
price                 DECIMAL(20,8)   Preço atual
volume_24h            DECIMAL(20,8)   Volume 24h
bid_price             DECIMAL(20,8)   Preço de compra
ask_price             DECIMAL(20,8)   Preço de venda
high_24h              DECIMAL(20,8)   Máxima 24h
low_24h               DECIMAL(20,8)   Mínima 24h
change_24h_percent    DECIMAL(10,4)   Mudança % 24h
timestamp             TIMESTAMP       Horário da coleta
created_at            TIMESTAMP
```

**Dados Armazenados:**

```
Total de Registros: 50,058
Atualização: A cada minuto
Retenção: Últimas 24-48 horas
```

**Exemplo:**

```sql
┌────────┬───────────┬────────────┬────────────┬─────────────────────┐
│ symbol │ exchange  │   price    │ volume_24h │     timestamp       │
├────────┼───────────┼────────────┼────────────┼─────────────────────┤
│  BTC   │  binance  │ 110,140.79 │ 5,300,333K │ 2025-11-01 08:27:28 │
│  BTC   │ coinbase  │ 110,155.32 │ 2,100,450K │ 2025-11-01 08:27:29 │
│  BTC   │  kraken   │ 110,130.45 │   980,123K │ 2025-11-01 08:27:30 │
└────────┴───────────┴────────────┴────────────┴─────────────────────┘
```

**Índices:**
- PRIMARY KEY (id)
- INDEX (asset_id, exchange_id, timestamp)

---

### 4. 🎯 **AGGREGATED_PRICES** (Preços Agregados)

**Função:** Preços consolidados de todas as exchanges com cálculo de VWAP

**Estrutura:**

```sql
id                 BIGINT          Primary Key
asset_id           INT             FK → assets.id
price_simple_avg   DECIMAL(20,8)   Média simples
price_vwap         DECIMAL(20,8)   Volume-Weighted Average Price ⭐
price_median       DECIMAL(20,8)   Mediana
price_min          DECIMAL(20,8)   Preço mínimo entre exchanges
price_max          DECIMAL(20,8)   Preço máximo entre exchanges
price_spread       DECIMAL(10,4)   Spread % (max-min)
total_volume_24h   DECIMAL(30,8)   Volume total agregado
exchange_count     TINYINT         Número de exchanges
confidence_score   DECIMAL(5,2)    Score de confiança (0-100)
timestamp          TIMESTAMP       Horário da agregação
created_at         TIMESTAMP
```

**Dados Armazenados:**

```
Total de Registros: 7,956
Frequência de agregação: A cada 10-15 minutos
Retenção: Últimas 24-48 horas
```

**Exemplo:**

```sql
┌────────┬─────────────┬──────────────┬─────────────────┬────────┬────────┐
│ symbol │ price_vwap  │ simple_avg   │ total_volume_24h│ exch.  │ confid.│
├────────┼─────────────┼──────────────┼─────────────────┼────────┼────────┤
│  BTC   │ 110,173.99  │  110,158.63  │  5,300,333,375  │   10   │  89.92 │
│  ETH   │   3,865.33  │    3,865.06  │  4,396,954,129  │   10   │  89.32 │
│  XRP   │       2.50  │        2.50  │    750,997,044  │   10   │  90.62 │
│  BNB   │   1,086.51  │    1,086.56  │    707,098,417  │    7   │  69.85 │
└────────┴─────────────┴──────────────┴─────────────────┴────────┴────────┘
```

**Cálculo do VWAP:**
```
VWAP = Σ(price × volume) / Σ(volume)
```

**Confidence Score:**
```
Baseado em:
- Número de exchanges (quanto mais, melhor)
- Spread entre preços (quanto menor, melhor)
- Volume total (quanto maior, melhor)
- Disponibilidade de dados
```

**Índices:**
- PRIMARY KEY (id)
- INDEX (asset_id, timestamp, confidence_score)

---

### 5. 🏢 **EXCHANGES** (Exchanges/Corretoras)

**Função:** Configuração e status das exchanges integradas

**Estrutura:**

```sql
id                      INT             Primary Key
exchange_id             VARCHAR(50)     ID interno (ex: 'binance')
name                    VARCHAR(100)    Nome completo
is_active               BOOLEAN         Ativo/Inativo
api_status              VARCHAR(50)     Status da API
rate_limit_per_minute   INT             Limite de requests/minuto
last_successful_fetch   TIMESTAMP       Última coleta bem-sucedida
last_error_at           TIMESTAMP       Último erro
last_error_message      TEXT            Mensagem do erro
created_at              TIMESTAMP
updated_at              TIMESTAMP
```

**Exchanges Cadastradas:**

```sql
┌────┬────────────┬──────────────┬──────────┬─────────────┬─────────────────────┐
│ id │ exchange_id│     name     │  active  │ rate_limit  │ last_successful     │
├────┼────────────┼──────────────┼──────────┼─────────────┼─────────────────────┤
│  1 │  binance   │   Binance    │    1     │    1200     │ 2025-11-01 08:28:01 │
│  2 │  coinbase  │ Coinbase Pro │    1     │     600     │ 2025-11-01 08:28:02 │
│  3 │  kraken    │   Kraken     │    1     │      60     │ 2025-11-01 08:27:59 │
│  4 │  kucoin    │   KuCoin     │    1     │    1800     │ 2025-11-01 08:27:59 │
│  5 │  bybit     │   Bybit      │    1     │    1000     │ 2025-11-01 08:28:00 │
│  6 │  okx       │     OKX      │    1     │     600     │ 2025-11-01 08:28:00 │
│  7 │  gate      │   Gate.io    │    1     │     900     │ 2025-11-01 08:28:01 │
│  8 │  bitfinex  │  Bitfinex    │    1     │      90     │ 2025-11-01 08:27:57 │
│  9 │  huobi     │    Huobi     │    1     │     100     │ 2025-11-01 08:28:01 │
│ 10 │  bitstamp  │  Bitstamp    │    1     │     600     │ 2025-11-01 08:27:37 │
└────┴────────────┴──────────────┴──────────┴─────────────┴─────────────────────┘

Status: Todas ativas e operacionais ✅
```

---

### 6. 📝 **ASSET_DESCRIPTIONS** (Descrições)

**Função:** Armazena descrições detalhadas dos ativos

**Estrutura:**

```sql
id               INT             Primary Key
asset_id         INT             FK → assets.id
description      LONGTEXT        Descrição completa
language         VARCHAR(10)     Idioma (en, pt, etc)
created_at       TIMESTAMP
updated_at       TIMESTAMP
```

**Dados:**
- 149 ativos com descrição (98.7% de cobertura)
- Idioma principal: Inglês (en)

---

### 7. 🔗 **ASSET_URLS** (URLs dos Ativos)

**Função:** Armazena todas as URLs relacionadas aos ativos

**Estrutura:**

```sql
id               INT             Primary Key
asset_id         INT             FK → assets.id
url_type         VARCHAR(50)     Tipo de URL
url              VARCHAR(500)    URL completa
created_at       TIMESTAMP
```

**Tipos de URL:**

```
website          - Site oficial
explorer         - Block explorers (múltiplos)
source_code      - GitHub / repositório
technical_doc    - Whitepaper / documentação
message_board    - Fóruns / comunidades
reddit           - Subreddit oficial
announcement     - Canais de anúncios
chat             - Telegram / Discord
```

**Exemplo (Bitcoin):**

```sql
┌────────┬─────────────────┬────────────────────────────────────────┐
│ symbol │    url_type     │                  url                   │
├────────┼─────────────────┼────────────────────────────────────────┤
│  BTC   │    website      │ https://bitcoin.org/                   │
│  BTC   │    explorer     │ https://blockchain.info/               │
│  BTC   │    explorer     │ https://blockchair.com/bitcoin         │
│  BTC   │  source_code    │ https://github.com/bitcoin/bitcoin     │
│  BTC   │ technical_doc   │ https://bitcoin.org/bitcoin.pdf        │
│  BTC   │ message_board   │ https://bitcointalk.org                │
│  BTC   │    reddit       │ https://reddit.com/r/bitcoin           │
└────────┴─────────────────┴────────────────────────────────────────┘

Total: 1,648 URLs armazenadas
```

---

### 8. 🔄 **SYMBOL_MAPPINGS** (Mapeamentos)

**Função:** Mapeia símbolos entre CoinMarketCap e exchanges (diferenças)

**Estrutura:**

```sql
id               INT             Primary Key
cmc_symbol       VARCHAR(20)     Símbolo na CMC
exchange_id      VARCHAR(50)     Exchange
exchange_symbol  VARCHAR(20)     Símbolo na exchange
created_at       TIMESTAMP
```

**Exemplo:**

```sql
┌─────────────┬────────────┬──────────────────┬──────────────────┐
│ cmc_symbol  │ exchange   │ exchange_symbol  │     motivo       │
├─────────────┼────────────┼──────────────────┼──────────────────┤
│    MATIC    │  binance   │      POL         │ Rebranding       │
│    MIOTA    │  binance   │      IOTA        │ Prefixo "M"      │
│    BCHSV    │  binance   │      BSV         │ Nome diferente   │
└─────────────┴────────────┴──────────────────┴──────────────────┘

Total: 30 mapeamentos
```

---

### 9. 📋 **CMC_SYNC_LOG** (Log de Sincronizações)

**Função:** Auditoria das sincronizações com CoinMarketCap API

**Estrutura:**

```sql
id                  INT             Primary Key
sync_type           VARCHAR(50)     Tipo de sync
status              VARCHAR(20)     completed / failed / running
coins_processed     INT             Quantidade processada
start_time          TIMESTAMP       Início
end_time            TIMESTAMP       Fim
error_message       TEXT            Erro (se houver)
created_at          TIMESTAMP
```

**Dados:**
- 5 sincronizações registradas
- Tipos: metadata, prices, listings
- Última sync: Bem-sucedida

---

## 🔗 Relacionamentos entre Tabelas

```
assets (1) ←─── (N) historical_ohlcv
   │
   ├──────────── (N) prices
   │
   ├──────────── (N) aggregated_prices
   │
   ├──────────── (N) asset_descriptions
   │
   └──────────── (N) asset_urls


exchanges (1) ←─ (N) historical_ohlcv
    │
    └─────────── (N) prices


symbol_mappings (N) → (1) exchanges
```

---

## 📊 Resumo dos Dados Armazenados

### Por Tipo de Dado:

#### 1️⃣ **Dados Históricos** (Principal)
```
✅ 421,983 candles OHLCV
✅ 144 ativos com histórico
✅ Cobertura: 2017-08-17 até hoje (8.2 anos)
✅ Timeframe principal: 4h (6 candles/dia)
✅ BTC/ETH: 17,973 candles cada (~8 anos)
```

#### 2️⃣ **Dados de Mercado**
```
✅ 151 ativos cadastrados
✅ Market cap, volume, dominância
✅ Mudanças de preço (1h, 24h, 7d, 30d, 60d, 90d)
✅ Supply (circulating, total, max)
✅ Ranking por market cap
```

#### 3️⃣ **Metadados**
```
✅ 149 descrições (98.7%)
✅ 1,648 URLs (explorers, github, social, etc)
✅ 151 logos/ícones (100%)
✅ Tags e categorias
✅ Datas de lançamento
```

#### 4️⃣ **Preços em Tempo Real**
```
✅ 50,058 registros de preços
✅ 7,956 agregações VWAP
✅ 10 exchanges ativas
✅ Atualização contínua (1 min)
```

---

## 🎯 Queries Úteis

### Obter dados completos de um ativo:

```sql
-- Dados básicos
SELECT * FROM assets WHERE symbol = 'BTC';

-- Com descrição
SELECT a.*, ad.description
FROM assets a
LEFT JOIN asset_descriptions ad ON a.id = ad.asset_id
WHERE a.symbol = 'BTC';

-- Com URLs
SELECT a.symbol, au.url_type, au.url
FROM assets a
LEFT JOIN asset_urls au ON a.id = au.asset_id
WHERE a.symbol = 'BTC';
```

### Obter histórico de um ativo:

```sql
-- Últimos 30 dias
SELECT timestamp, open_price, high_price, low_price, close_price, volume
FROM historical_ohlcv
WHERE asset_id = (SELECT id FROM assets WHERE symbol = 'BTC')
  AND timeframe = '4h'
  AND timestamp >= NOW() - INTERVAL 30 DAY
ORDER BY timestamp ASC;
```

### Obter preço agregado atual:

```sql
SELECT
    a.symbol,
    ap.price_vwap as preco,
    ap.total_volume_24h as volume,
    ap.exchange_count as exchanges,
    ap.confidence_score as confianca
FROM aggregated_prices ap
JOIN assets a ON ap.asset_id = a.id
WHERE a.symbol = 'BTC'
ORDER BY ap.timestamp DESC
LIMIT 1;
```

### Listar TOP 10 por market cap:

```sql
SELECT
    market_cap_rank,
    symbol,
    name,
    market_cap,
    volume_24h,
    percent_change_24h
FROM assets
WHERE is_active = 1
ORDER BY market_cap_rank ASC
LIMIT 10;
```

---

## 📈 Performance e Otimizações

### Índices Criados:

```sql
-- assets
INDEX idx_symbol (symbol)
INDEX idx_rank (market_cap_rank)
INDEX idx_active (is_active)

-- historical_ohlcv
INDEX idx_asset_time (asset_id, timestamp)
INDEX idx_timeframe (timeframe)
UNIQUE KEY unique_candle (asset_id, exchange_id, timeframe, timestamp)

-- prices
INDEX idx_asset_exchange (asset_id, exchange_id)
INDEX idx_timestamp (timestamp)

-- aggregated_prices
INDEX idx_asset_time (asset_id, timestamp)
INDEX idx_confidence (confidence_score)
```

### Tamanho Total:
- **Dados:** ~50 MB
- **Índices:** ~110 MB
- **Total:** ~160 MB

---

## ✅ Conclusão

O banco de dados FolyoAggregator está estruturado para:

✅ **Armazenar histórico completo** de até 8+ anos
✅ **Dados em tempo real** de 10 exchanges
✅ **Metadados ricos** (98.7% de cobertura)
✅ **Agregação VWAP** com score de confiança
✅ **Performance otimizada** com índices apropriados
✅ **Escalabilidade** para adicionar mais ativos
✅ **Compatibilidade** com formato CMC

**Total de dados úteis:** 421,983 candles históricos + 50K preços em tempo real + metadados completos de 151 ativos.
