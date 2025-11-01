#!/bin/bash

echo "📊 Sincronizando TOP 301-500 da CoinMarketCap"
echo "═══════════════════════════════════════════════"
echo ""

# CMC API configuration
API_KEY="dfd1ef151785484daf455a67e0523574"
BASE_URL="https://pro-api.coinmarketcap.com/v1/cryptocurrency/listings/latest"

# Fetch ranks 301-400
echo "📥 Buscando ranks 301-400..."
curl -s -H "X-CMC_PRO_API_KEY: $API_KEY" \
  "${BASE_URL}?start=301&limit=100&convert=USD" \
  -o /tmp/cmc_301_400.json

if [ -f /tmp/cmc_301_400.json ]; then
  # Process each coin and insert into database
  cat /tmp/cmc_301_400.json | python3 -c "
import json
import sys
import subprocess

data = json.load(sys.stdin)
if 'data' in data:
    for coin in data['data']:
        try:
            # Check if already exists
            check_cmd = f\"\"\"mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -N -e \"SELECT COUNT(*) FROM assets WHERE cmc_id = {coin['id']}\" 2>/dev/null\"\"\"
            exists = subprocess.check_output(check_cmd, shell=True).decode().strip()

            if exists == '0':
                # Prepare values
                symbol = coin['symbol'].replace(\"'\", \"''\")
                name = coin['name'].replace(\"'\", \"''\")
                slug = coin['slug'].replace(\"'\", \"''\")

                # Insert into database
                insert_cmd = f\"\"\"mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -e \"
                INSERT INTO assets (
                    cmc_id, name, symbol, slug, market_cap_rank, is_tradeable,
                    circulating_supply, total_supply, max_supply,
                    price_usd, market_cap, volume_24h,
                    percent_change_1h, percent_change_24h, percent_change_7d
                ) VALUES (
                    {coin['id']}, '{name}', '{symbol}', '{slug}', {coin['cmc_rank']}, 1,
                    {coin.get('circulating_supply', 'NULL')},
                    {coin.get('total_supply', 'NULL')},
                    {coin.get('max_supply', 'NULL')},
                    {coin['quote']['USD'].get('price', 0)},
                    {coin['quote']['USD'].get('market_cap', 0)},
                    {coin['quote']['USD'].get('volume_24h', 0)},
                    {coin['quote']['USD'].get('percent_change_1h', 0)},
                    {coin['quote']['USD'].get('percent_change_24h', 0)},
                    {coin['quote']['USD'].get('percent_change_7d', 0)}
                )\" 2>/dev/null\"\"\"
                subprocess.run(insert_cmd, shell=True)
                print(f'   ✅ {symbol} (#{coin[\"cmc_rank\"]}) adicionado')
        except Exception as e:
            print(f'   ❌ Erro: {e}')
"
fi

echo ""
sleep 2

# Fetch ranks 401-500
echo "📥 Buscando ranks 401-500..."
curl -s -H "X-CMC_PRO_API_KEY: $API_KEY" \
  "${BASE_URL}?start=401&limit=100&convert=USD" \
  -o /tmp/cmc_401_500.json

if [ -f /tmp/cmc_401_500.json ]; then
  # Process each coin and insert into database
  cat /tmp/cmc_401_500.json | python3 -c "
import json
import sys
import subprocess

data = json.load(sys.stdin)
if 'data' in data:
    for coin in data['data']:
        try:
            # Check if already exists
            check_cmd = f\"\"\"mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -N -e \"SELECT COUNT(*) FROM assets WHERE cmc_id = {coin['id']}\" 2>/dev/null\"\"\"
            exists = subprocess.check_output(check_cmd, shell=True).decode().strip()

            if exists == '0':
                # Prepare values
                symbol = coin['symbol'].replace(\"'\", \"''\")
                name = coin['name'].replace(\"'\", \"''\")
                slug = coin['slug'].replace(\"'\", \"''\")

                # Insert into database
                insert_cmd = f\"\"\"mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -e \"
                INSERT INTO assets (
                    cmc_id, name, symbol, slug, market_cap_rank, is_tradeable,
                    circulating_supply, total_supply, max_supply,
                    price_usd, market_cap, volume_24h,
                    percent_change_1h, percent_change_24h, percent_change_7d
                ) VALUES (
                    {coin['id']}, '{name}', '{symbol}', '{slug}', {coin['cmc_rank']}, 1,
                    {coin.get('circulating_supply', 'NULL')},
                    {coin.get('total_supply', 'NULL')},
                    {coin.get('max_supply', 'NULL')},
                    {coin['quote']['USD'].get('price', 0)},
                    {coin['quote']['USD'].get('market_cap', 0)},
                    {coin['quote']['USD'].get('volume_24h', 0)},
                    {coin['quote']['USD'].get('percent_change_1h', 0)},
                    {coin['quote']['USD'].get('percent_change_24h', 0)},
                    {coin['quote']['USD'].get('percent_change_7d', 0)}
                )\" 2>/dev/null\"\"\"
                subprocess.run(insert_cmd, shell=True)
                print(f'   ✅ {symbol} (#{coin[\"cmc_rank\"]}) adicionado')
        except Exception as e:
            print(f'   ❌ Erro: {e}')
"
fi

echo ""
echo "═══════════════════════════════════════════════"
echo "✅ Sincronização concluída!"
echo ""

# Show final stats
mysql -u folyo_user -p'Folyo@2025Secure' folyoaggregator -e "
SELECT
    'Total de ativos' as Info,
    COUNT(*) as Valor
FROM assets
WHERE is_tradeable = 1
UNION ALL
SELECT
    'Maior rank' as Info,
    MAX(market_cap_rank) as Valor
FROM assets
WHERE is_tradeable = 1;"