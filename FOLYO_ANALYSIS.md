# Análise Completa da Plataforma Folyo

## 📊 Visão Geral
Folyo é uma plataforma completa de tracking de criptomoedas que atualmente usa a API do **CoinMarketCap** como fonte principal de dados. A plataforma oferece visualização de preços, portfolio management, dados de DEX e múltiplas funcionalidades avançadas.

## 🏗️ Arquitetura Atual

### Stack Tecnológico
- **Frontend**: HTML, CSS, JavaScript (Vanilla JS)
- **Backend**: PHP (proxy para API)
- **Banco de Dados**: MariaDB/MySQL
- **Cache**: Redis (com fallback para arquivo)
- **APIs Externas**:
  - CoinMarketCap (principal)
  - DexScreener (para dados DEX)
  - Alternative.me (Fear & Greed Index)

### Estrutura de Diretórios
```
/var/www/html/folyo/
├── api/              # Backend PHP (proxy.php, auth.php)
├── assets/           # Imagens e recursos
├── cache/            # Cache de dados
├── css/              # Estilos (themes.css, style.css, modal.css)
├── currency/         # Páginas de detalhes de moedas
├── database/         # Schema SQL
├── dex/              # Interface DEX
├── js/               # Lógica JavaScript
└── portfolio/        # Gerenciamento de portfolio
```

## 🔌 Integração com CoinMarketCap

### Endpoints Utilizados
1. **Listings** (`/v1/cryptocurrency/listings/latest`)
   - Lista principal de criptomoedas
   - Suporta paginação e conversão de moeda

2. **Global Metrics** (`/v1/global-metrics/quotes/latest`)
   - Market cap total
   - Volume 24h global
   - Dominância BTC/ETH

3. **Crypto Info** (`/v2/cryptocurrency/info`)
   - Detalhes completos da moeda
   - Links, descrição, tecnologia

4. **Quotes** (`/v2/cryptocurrency/quotes/latest`)
   - Preços atuais
   - Market cap, volume, supply

5. **OHLCV Historical** (`/v2/cryptocurrency/ohlcv/historical`)
   - Dados históricos para gráficos
   - Candlesticks

### Proxy PHP (`api/proxy.php`)
- Resolve problemas de CORS
- Centraliza API key
- Transforma dados do DexScreener para formato CMC
- Cache de respostas

## 💾 Banco de Dados

### Tabelas Principais
1. **users** - Usuários da plataforma
2. **portfolios** - Carteiras de investimento
3. **transactions** - Compras/vendas registradas
4. **portfolio_holdings** (VIEW) - Holdings calculados

### Funcionalidades de Portfolio
- Múltiplas carteiras por usuário
- Registro de compra/venda
- Cálculo de P&L
- Histórico de transações
- Suporte a múltiplas moedas fiduciárias

## 🎨 Interface (Frontend)

### Componentes JavaScript
- **app.js** - Aplicação principal
- **api-client.js** - Cliente API centralizado
- **portfolio.js** - Gestão de portfolios (29KB)
- **currency-detail.js** - Detalhes de moedas
- **chart.js** - Gráficos e visualizações
- **dex-*.js** - Funcionalidades DEX
- **auth-manager.js** - Autenticação

### Funcionalidades da Interface
1. **Dashboard Principal**
   - Lista de criptomoedas
   - Ordenação e filtros
   - Busca em tempo real
   - Auto-refresh (60 segundos)

2. **Seletor de Moeda Fiduciária**
   - Suporte a 30+ moedas (USD, EUR, BRL, etc.)
   - Conversão automática de valores

3. **Tema Dark/Light**
   - Persistência via LocalStorage
   - Transição suave

4. **Global Stats**
   - Total de criptomoedas
   - Market cap total
   - Volume 24h
   - Fear & Greed Index

## 📊 Dados Utilizados do CoinMarketCap

### Dados por Criptomoeda
- **ID e Símbolo** (BTC, ETH, etc.)
- **Nome completo**
- **Preço atual**
- **Market Cap**
- **Volume 24h**
- **Circulação**
- **Mudança % (1h, 24h, 7d)**
- **Rank por market cap**
- **Logo** (via CDN do CMC)

### Dados Históricos
- **OHLCV** (Open, High, Low, Close, Volume)
- **Múltiplos timeframes** (1h, 1d, 1w, etc.)
- **Sparklines** para mini-gráficos

## 🔄 Como Migrar para FolyoAggregator

### Vantagens da Migração
1. **Sem limites de API** - Uso ilimitado
2. **Sem custos mensais** - Gratuito para sempre
3. **Dados de 10+ exchanges** - Maior precisão
4. **VWAP** - Preço médio ponderado por volume
5. **Controle total** - Customize conforme necessário

### Pontos de Integração

#### 1. Substituir proxy.php
```php
// Em vez de chamar CoinMarketCap:
$url = "https://pro-api.coinmarketcap.com/v1/cryptocurrency/listings/latest";

// Chamar FolyoAggregator:
$url = "http://folyoaggregator.test/api/v1/assets";
```

#### 2. Mapear Dados
| CoinMarketCap | FolyoAggregator |
|---------------|-----------------|
| `data[].quote.USD.price` | `data.aggregated.price_vwap` |
| `data[].quote.USD.volume_24h` | `data.aggregated.total_volume_24h` |
| `data[].quote.USD.percent_change_24h` | Calcular baseado em histórico |
| `data[].circulating_supply` | Adicionar campo em assets |
| `data[].cmc_rank` | `market_cap_rank` |

#### 3. Funcionalidades Adicionais
- **Confidence Score** - Qualidade dos dados (0-100)
- **Exchange Breakdown** - Preços por exchange
- **Spread Analysis** - Diferença min/max
- **Aggregation Method** - Simple avg, VWAP, median

## 🚀 Próximos Passos Recomendados

1. **Criar adapter** para compatibilidade com formato CMC
2. **Implementar endpoints** faltantes no FolyoAggregator
3. **Adicionar histórico OHLCV** ao aggregator
4. **Sistema de cache** similar ao Folyo
5. **Migração gradual** - começar com alguns endpoints

## 📈 Melhorias Possíveis

1. **WebSocket** para atualizações em tempo real
2. **Worker automático** para coleta contínua
3. **API GraphQL** para queries flexíveis
4. **Machine Learning** para previsões
5. **Alertas de preço** personalizados

## 🎯 Conclusão

A plataforma Folyo é bem estruturada e completa. A migração para o FolyoAggregator é **totalmente viável** e trará benefícios significativos em termos de:
- **Economia** (sem custos de API)
- **Performance** (dados agregados de múltiplas fontes)
- **Confiabilidade** (score de confiança)
- **Flexibilidade** (controle total sobre os dados)

O FolyoAggregator já possui a base necessária para substituir o CoinMarketCap. Precisamos apenas adicionar alguns endpoints específicos e adaptar o formato de resposta para manter compatibilidade.