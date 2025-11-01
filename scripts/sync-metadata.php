#!/usr/bin/env php
<?php
/**
 * Sync Complete Metadata from CoinMarketCap
 * Fetches detailed info using crypto-info endpoint
 */

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use FolyoAggregator\Core\Database;

$db = Database::getInstance();

echo "\n╔══════════════════════════════════════════════════════╗\n";
echo "║     SINCRONIZAÇÃO DE METADADOS COMPLETOS - CMC      ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

// Get CMC API key (using Folyo's key)
$apiKey = $_ENV['CMC_API_KEY'] ?? 'dfd1ef151785484daf455a67e0523574';

// Get all assets that need metadata update
$assets = $db->fetchAll("
    SELECT id, cmc_id, symbol, name
    FROM assets
    WHERE cmc_id IS NOT NULL
    AND (description IS NULL OR description = '' OR logo_url IS NULL)
    ORDER BY market_cap_rank ASC
    LIMIT 200
");

echo "📊 Encontrados " . count($assets) . " ativos para atualizar metadados\n\n";

$updated = 0;
$failed = 0;

// Process in batches of 20 (CMC limit)
$batches = array_chunk($assets, 20);

foreach ($batches as $batchNum => $batch) {
    $ids = array_column($batch, 'cmc_id');
    $idString = implode(',', $ids);

    echo "📥 Batch " . ($batchNum + 1) . "/" . count($batches) . " (IDs: $idString)\n";

    // Call CMC crypto-info endpoint
    $url = "https://pro-api.coinmarketcap.com/v2/cryptocurrency/info?id=$idString";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-CMC_PRO_API_KEY: ' . $apiKey,
        'Accept: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "   ❌ Erro na API (HTTP $httpCode)\n";
        $failed += count($batch);
        sleep(2); // Wait before next batch
        continue;
    }

    $data = json_decode($response, true);

    if (!isset($data['data'])) {
        echo "   ❌ Resposta inválida\n";
        $failed += count($batch);
        continue;
    }

    // Process each coin's metadata
    foreach ($data['data'] as $cmcId => $info) {
        // Find the asset in our batch
        $asset = array_filter($batch, function($a) use ($cmcId) {
            return $a['cmc_id'] == $cmcId;
        });

        if (empty($asset)) continue;
        $asset = reset($asset);

        // Extract all metadata
        $updateData = [
            'description' => $info['description'] ?? null,
            'logo_url' => $info['logo'] ?? null,
            'category' => $info['category'] ?? null,
            'tags' => json_encode($info['tags'] ?? []),
            'platform' => $info['platform']['name'] ?? null,
            'platform_id' => $info['platform']['id'] ?? null,
            'token_address' => $info['platform']['token_address'] ?? null,
            'date_launched' => isset($info['date_launched']) ? date('Y-m-d', strtotime($info['date_launched'])) : null,
            'notice' => $info['notice'] ?? null,
            'self_reported_circulating_supply' => $info['self_reported_circulating_supply'] ?? null,
            'self_reported_market_cap' => $info['self_reported_market_cap'] ?? null
        ];

        // Handle URLs
        $urls = $info['urls'] ?? [];
        if (!empty($urls)) {
            $updateData['website_url'] = implode(',', $urls['website'] ?? []);
            $updateData['whitepaper_url'] = implode(',', $urls['technical_doc'] ?? []);
            $updateData['twitter_username'] = implode(',', $urls['twitter'] ?? []);
            $updateData['reddit_url'] = implode(',', $urls['reddit'] ?? []);
            $updateData['github_url'] = implode(',', $urls['source_code'] ?? []);
            $updateData['telegram_channel'] = implode(',', $urls['message_board'] ?? []);
            $updateData['explorer_urls'] = json_encode($urls['explorer'] ?? []);
            $updateData['announcement_url'] = json_encode($urls['announcement'] ?? []);
            $updateData['chat_urls'] = json_encode($urls['chat'] ?? []);
        }

        // Handle contract addresses
        if (isset($info['contract_address']) && is_array($info['contract_address'])) {
            $updateData['contract_addresses'] = json_encode($info['contract_address']);
        }

        // Build UPDATE query
        $setClauses = [];
        $params = [];
        foreach ($updateData as $field => $value) {
            if ($value !== null) {
                $setClauses[] = "$field = ?";
                $params[] = $value;
            }
        }

        if (!empty($setClauses)) {
            $params[] = $asset['id'];
            $sql = "UPDATE assets SET " . implode(', ', $setClauses) . " WHERE id = ?";

            try {
                $db->query($sql, $params);
                echo "   ✅ {$asset['symbol']}: Metadados atualizados\n";
                $updated++;
            } catch (Exception $e) {
                echo "   ❌ {$asset['symbol']}: Erro ao atualizar - " . $e->getMessage() . "\n";
                $failed++;
            }
        }

        // Also save description in multiple languages if available
        if (isset($info['description']) && !empty($info['description'])) {
            try {
                $db->query("
                    INSERT INTO asset_descriptions (asset_id, language_code, description)
                    VALUES (?, 'en', ?)
                    ON DUPLICATE KEY UPDATE description = VALUES(description)
                ", [$asset['id'], $info['description']]);
            } catch (Exception $e) {
                // Ignore if table doesn't exist
            }
        }

        // Save URLs to flexible table
        if (!empty($urls)) {
            foreach ($urls as $type => $urlList) {
                if (is_array($urlList)) {
                    foreach ($urlList as $index => $url) {
                        try {
                            $db->query("
                                INSERT INTO asset_urls (asset_id, url_type, url, is_primary)
                                VALUES (?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE url = VALUES(url)
                            ", [$asset['id'], $type, $url, $index === 0 ? 1 : 0]);
                        } catch (Exception $e) {
                            // Ignore if table doesn't exist
                        }
                    }
                }
            }
        }
    }

    echo "   📊 Batch completo\n\n";

    // Rate limit: wait 1 second between batches
    sleep(1);
}

echo "══════════════════════════════════════════════════════\n";
echo "✅ SINCRONIZAÇÃO COMPLETA!\n";
echo "══════════════════════════════════════════════════════\n\n";

echo "📊 Resultados:\n";
echo "  • Atualizados: $updated ativos\n";
echo "  • Falhas: $failed ativos\n";

// Show sample of updated data
$sample = $db->fetchOne("
    SELECT symbol, description, logo_url, website_url, twitter_username
    FROM assets
    WHERE description IS NOT NULL
    AND logo_url IS NOT NULL
    LIMIT 1
");

if ($sample) {
    echo "\n📋 Exemplo de dados sincronizados ({$sample['symbol']}):\n";
    echo "  • Descrição: " . substr($sample['description'], 0, 100) . "...\n";
    echo "  • Logo: {$sample['logo_url']}\n";
    echo "  • Website: {$sample['website_url']}\n";
    echo "  • Twitter: {$sample['twitter_username']}\n";
}

echo "\n✅ Agora temos TODOS os metadados que a Folyo precisa!\n\n";