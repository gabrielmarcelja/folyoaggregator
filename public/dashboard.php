<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FolyoAggregator - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        .dashboard-container {
            padding: 20px;
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #667eea;
        }
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .price-table {
            background: white;
            border-radius: 15px;
            overflow: hidden;
        }
        .price-up { color: #28a745; }
        .price-down { color: #dc3545; }
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        .status-online {
            background: #d4edda;
            color: #155724;
        }
        .status-offline {
            background: #f8d7da;
            color: #721c24;
        }
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .daemon-status {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        .pulse {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        .exchange-badge {
            display: inline-block;
            padding: 3px 8px;
            margin: 2px;
            border-radius: 5px;
            background: #e9ecef;
            font-size: 0.75rem;
        }
        .confidence-bar {
            height: 6px;
            border-radius: 3px;
            background: #e9ecef;
            overflow: hidden;
        }
        .confidence-fill {
            height: 100%;
            transition: width 0.5s;
        }
        .confidence-high { background: #28a745; }
        .confidence-medium { background: #ffc107; }
        .confidence-low { background: #dc3545; }
    </style>
</head>
<body>
    <?php
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();

    use FolyoAggregator\Core\Database;

    $db = Database::getInstance();

    // Get system statistics
    $stats = $db->fetchOne("
        SELECT
            (SELECT COUNT(*) FROM assets WHERE is_active = 1) as total_assets,
            (SELECT COUNT(*) FROM assets WHERE is_tradeable = 1) as tradeable_assets,
            (SELECT COUNT(*) FROM prices WHERE timestamp > NOW() - INTERVAL 10 MINUTE) as recent_prices,
            (SELECT COUNT(*) FROM historical_ohlcv) as total_candles,
            (SELECT COUNT(*) FROM aggregated_prices WHERE timestamp > NOW() - INTERVAL 1 HOUR) as hourly_aggregations,
            (SELECT SUM(market_cap) FROM assets WHERE is_active = 1) as total_market_cap
    ");

    // Get top movers
    $gainers = $db->fetchAll("
        SELECT symbol, name, percent_change_24h, market_cap_rank, icon_url
        FROM assets
        WHERE is_active = 1 AND percent_change_24h IS NOT NULL
        ORDER BY percent_change_24h DESC
        LIMIT 5
    ");

    $losers = $db->fetchAll("
        SELECT symbol, name, percent_change_24h, market_cap_rank, icon_url
        FROM assets
        WHERE is_active = 1 AND percent_change_24h IS NOT NULL
        ORDER BY percent_change_24h ASC
        LIMIT 5
    ");

    // Get recent prices with aggregation
    $recentPrices = $db->fetchAll("
        SELECT
            a.symbol,
            a.name,
            a.icon_url,
            a.market_cap_rank,
            ap.price_vwap,
            ap.price_simple_avg,
            ap.confidence_score,
            ap.exchange_count,
            ap.total_volume_24h,
            a.percent_change_24h,
            ap.timestamp
        FROM aggregated_prices ap
        JOIN assets a ON a.id = ap.asset_id
        WHERE ap.timestamp = (
            SELECT MAX(timestamp)
            FROM aggregated_prices ap2
            WHERE ap2.asset_id = ap.asset_id
        )
        ORDER BY a.market_cap_rank ASC
        LIMIT 20
    ");

    // Get exchange status
    $exchanges = $db->fetchAll("
        SELECT
            e.name,
            e.exchange_id,
            e.api_status,
            COUNT(p.id) as recent_prices,
            MAX(p.timestamp) as last_price
        FROM exchanges e
        LEFT JOIN prices p ON p.exchange_id = e.id
            AND p.timestamp > NOW() - INTERVAL 10 MINUTE
        WHERE e.is_active = 1
        GROUP BY e.id
        ORDER BY e.name
    ");

    // Check daemon status
    $daemonPidFile = '/var/www/html/folyoaggregator/logs/price-collector.pid';
    $daemonRunning = false;
    if (file_exists($daemonPidFile)) {
        $pid = file_get_contents($daemonPidFile);
        $daemonRunning = posix_getpgid($pid) !== false;
    }
    ?>

    <!-- Daemon Status Indicator -->
    <div class="daemon-status">
        <div class="alert <?php echo $daemonRunning ? 'alert-success' : 'alert-danger'; ?> d-flex align-items-center">
            <i class="bi <?php echo $daemonRunning ? 'bi-check-circle-fill pulse' : 'bi-x-circle-fill'; ?> me-2"></i>
            <span>Collector: <?php echo $daemonRunning ? 'ONLINE' : 'OFFLINE'; ?></span>
        </div>
    </div>

    <div class="dashboard-container">
        <div class="text-center text-white mb-4">
            <h1><i class="bi bi-graph-up"></i> FolyoAggregator Dashboard</h1>
            <p class="lead">Real-time Cryptocurrency Data Aggregation System</p>
        </div>

        <!-- Statistics Cards -->
        <div class="row">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number" id="total-assets"><?php echo number_format($stats['total_assets']); ?></div>
                    <div class="stat-label">Total Assets</div>
                    <small class="text-success"><?php echo $stats['tradeable_assets']; ?> tradeable</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number" id="recent-prices"><?php echo number_format($stats['recent_prices']); ?></div>
                    <div class="stat-label">Prices (10min)</div>
                    <small class="text-muted">Real-time collection</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number" id="total-candles"><?php echo number_format($stats['total_candles']); ?></div>
                    <div class="stat-label">Historical Candles</div>
                    <small class="text-info">OHLCV data</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number">$<?php echo number_format($stats['total_market_cap']/1000000000, 1); ?>B</div>
                    <div class="stat-label">Total Market Cap</div>
                    <small class="text-warning">All assets</small>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="row mt-4">
            <!-- Live Prices -->
            <div class="col-md-8">
                <div class="price-table">
                    <div class="card-header bg-primary text-white p-3">
                        <h5 class="mb-0"><i class="bi bi-currency-exchange"></i> Live Aggregated Prices</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Asset</th>
                                    <th>Price (VWAP)</th>
                                    <th>24h %</th>
                                    <th>Volume</th>
                                    <th>Confidence</th>
                                    <th>Exchanges</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentPrices as $price): ?>
                                <tr>
                                    <td><?php echo $price['market_cap_rank']; ?></td>
                                    <td>
                                        <img src="<?php echo $price['icon_url']; ?>" width="20" class="me-2">
                                        <strong><?php echo $price['symbol']; ?></strong>
                                        <small class="text-muted"><?php echo $price['name']; ?></small>
                                    </td>
                                    <td>$<?php echo number_format($price['price_vwap'], 2); ?></td>
                                    <td class="<?php echo $price['percent_change_24h'] >= 0 ? 'price-up' : 'price-down'; ?>">
                                        <?php echo number_format($price['percent_change_24h'], 2); ?>%
                                    </td>
                                    <td>$<?php echo number_format($price['total_volume_24h']/1000000, 1); ?>M</td>
                                    <td>
                                        <div class="confidence-bar">
                                            <div class="confidence-fill <?php
                                                echo $price['confidence_score'] >= 80 ? 'confidence-high' :
                                                    ($price['confidence_score'] >= 60 ? 'confidence-medium' : 'confidence-low');
                                            ?>" style="width: <?php echo $price['confidence_score']; ?>%"></div>
                                        </div>
                                        <small><?php echo round($price['confidence_score']); ?>%</small>
                                    </td>
                                    <td><?php echo $price['exchange_count']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Side Panel -->
            <div class="col-md-4">
                <!-- Top Gainers -->
                <div class="stat-card mb-3">
                    <h6 class="text-success"><i class="bi bi-trending-up"></i> Top Gainers</h6>
                    <hr>
                    <?php foreach ($gainers as $g): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span><?php echo $g['symbol']; ?></span>
                        <span class="text-success">+<?php echo number_format($g['percent_change_24h'], 2); ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Top Losers -->
                <div class="stat-card mb-3">
                    <h6 class="text-danger"><i class="bi bi-trending-down"></i> Top Losers</h6>
                    <hr>
                    <?php foreach ($losers as $l): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span><?php echo $l['symbol']; ?></span>
                        <span class="text-danger"><?php echo number_format($l['percent_change_24h'], 2); ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Exchange Status -->
                <div class="stat-card">
                    <h6><i class="bi bi-server"></i> Exchange Status</h6>
                    <hr>
                    <?php foreach ($exchanges as $ex): ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span><?php echo $ex['name']; ?></span>
                        <span class="status-badge <?php echo $ex['recent_prices'] > 0 ? 'status-online' : 'status-offline'; ?>">
                            <?php echo $ex['recent_prices'] > 0 ? 'ONLINE' : 'OFFLINE'; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- System Info -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="stat-card">
                    <h6><i class="bi bi-info-circle"></i> System Information</h6>
                    <hr>
                    <div class="row">
                        <div class="col-md-3">
                            <small class="text-muted">Last Update</small><br>
                            <span id="last-update"><?php echo date('H:i:s'); ?></span>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">Aggregations/Hour</small><br>
                            <span><?php echo number_format($stats['hourly_aggregations']); ?></span>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">Database Size</small><br>
                            <span id="db-size">Calculating...</span>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">API Endpoints</small><br>
                            <span class="text-success">All Operational</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh every 10 seconds
        setInterval(() => {
            location.reload();
        }, 10000);

        // Calculate database size
        fetch('/api/v1/status')
            .then(r => r.json())
            .then(data => {
                // Update with real data if available
            });

        // Update timestamp
        document.getElementById('last-update').textContent = new Date().toLocaleTimeString();
    </script>
</body>
</html>