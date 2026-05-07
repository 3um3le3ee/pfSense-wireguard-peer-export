<?php
/**
 * vpn_wg_dashboard.php
 * Visual NOC Dashboard (Live Speed, Time-Series, IP Display)
 * Unified Suite Edition v1.0.8
 */
require_once("guiconfig.inc");
require_once("util.inc");
require_once("pkg-utils.inc");

function wgx_get_config_array_dash($type) {
    global $config; $data = []; $type_plural = $type . 's';
    if (function_exists('config_get_path') && config_get_path("installedpackages/wireguard/{$type_plural}/item") !== null) { $data = config_get_path("installedpackages/wireguard/{$type_plural}/item", []); }
    elseif (isset($config['installedpackages']['wireguard'][$type_plural]['item'])) { $data = $config['installedpackages']['wireguard'][$type_plural]['item']; }
    elseif (function_exists('config_get_path') && config_get_path("wireguard/{$type}/item") !== null) { $data = config_get_path("wireguard/{$type}/item", []); }
    elseif (isset($config['wireguard'][$type]['item'])) { $data = $config['wireguard'][$type]['item']; }
    if (!is_array($data)) return [];
    if (!empty($data) && !isset($data[0])) $data = [$data];
    return $data;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_telemetry') {
    ob_start();
    try {
        $wg_bin = is_executable('/usr/local/bin/wg') ? '/usr/local/bin/wg' : '/usr/bin/wg';
        $telemetry = []; $endpoints = [];

        if (!empty($wg_bin)) {
            $rawTx = trim(shell_exec("{$wg_bin} show all transfer"));
            if ($rawTx) {
                foreach (explode("\n", $rawTx) as $line) {
                    $parts = preg_split('/\s+/', trim($line));
                    if (count($parts) >= 4) { $telemetry[$parts[1]] = ['rx' => (int)$parts[2], 'tx' => (int)$parts[3], 'hs' => 0]; }
                }
            }
            $rawHs = trim(shell_exec("{$wg_bin} show all latest-handshakes"));
            if ($rawHs) {
                foreach (explode("\n", $rawHs) as $line) {
                    $parts = preg_split('/\s+/', trim($line));
                    if (count($parts) >= 3 && isset($telemetry[$parts[1]])) { $telemetry[$parts[1]]['hs'] = (int)$parts[2]; }
                }
            }
            $rawEp = trim(shell_exec("{$wg_bin} show all endpoints"));
            if ($rawEp) {
                foreach (explode("\n", $rawEp) as $line) {
                    $parts = preg_split('/\s+/', trim($line));
                    if (count($parts) >= 3) {
                        $pub = $parts[1];
                        $endpoint = $parts[2];
                        if ($endpoint !== '(none)') {
                            // Safely extract IP, handling IPv6 brackets and dynamic ports
                            $last_colon = strrpos($endpoint, ':');
                            if ($last_colon !== false) {
                                $ip = substr($endpoint, 0, $last_colon);
                                $endpoints[$pub] = trim($ip, '[]');
                            } else {
                                $endpoints[$pub] = trim($endpoint, '[]');
                            }
                        }
                    }
                }
            }
        }

        $archive_file = '/var/db/wgx_telemetry_archive.json';
        $archive = file_exists($archive_file) ? json_decode(file_get_contents($archive_file), true) : [];

        $a_peers = wgx_get_config_array_dash('peer');
        $payload_peers = [];
        $used_ips = 0; $tunnels = [];

        foreach (wgx_get_config_array_dash('tunnel') as $t) { if(isset($t['name'])) $tunnels[] = $t['name']; }
        $total_tunnels = count($tunnels);

        foreach ($a_peers as $p) {
            if (!is_array($p)) continue;
            $pub = $p['publickey'] ?? '';
            $desc = $p['descr'] ?? 'Unknown';
            $tun = $p['tun'] ?? 'Unknown';

            $rx = $telemetry[$pub]['rx'] ?? 0;
            $tx = $telemetry[$pub]['tx'] ?? 0;
            $hs = $telemetry[$pub]['hs'] ?? 0;
            $ep_ip = $endpoints[$pub] ?? null;

            $history = [];
            if (isset($archive[$pub]['history'])) {
                foreach ($archive[$pub]['history'] as $ts => $val) { $history[$ts] = $val; }
            }

            $payload_peers[] = [
                'pub' => $pub, 'name' => $desc, 'tun' => $tun,
                'rx' => $rx, 'tx' => $tx, 'total' => $rx + $tx,
                'handshake' => $hs, 'ip' => $ep_ip,
                'history' => $history
            ];

            $allowedips = isset($p['allowedips']) && is_array($p['allowedips']) ? $p['allowedips'] : [];
            $raw_allowedips = $allowedips['row'] ?? ($allowedips['item'] ?? []);
            if (is_array($raw_allowedips) && !empty($raw_allowedips)) {
                $rows = isset($raw_allowedips['address']) ? [$raw_allowedips] : $raw_allowedips;
                foreach ($rows as $row) { if (is_array($row) && !empty($row['address'])) $used_ips++; }
            }
        }

        $available_ips = ($total_tunnels * 253) - $used_ips;
        if ($available_ips < 0) $available_ips = 0;

        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 'peers' => $payload_peers, 'tunnels' => $tunnels,
            'ip_used' => $used_ips, 'ip_free' => $available_ips,
            'server_time' => microtime(true)
        ]);
        exit;

    } catch (\Throwable $e) {
        ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
    }
}

$pgtitle = [gettext("VPN"), gettext("WireGuard"), gettext("NOC Dashboard")];
include("head.inc");

$tab_array = array();
$tab_array[] = array(gettext("Tunnels"), false, "/wg/vpn_wg_tunnels.php");
$tab_array[] = array(gettext("Peers"), false, "/wg/vpn_wg_peers.php");
$tab_array[] = array(gettext("Settings"), false, "/wg/vpn_wg_settings.php");
$tab_array[] = array(gettext("Status"), false, "/wg/status_wireguard.php");
$tab_array[] = array(gettext("Dashboard"), true, "/wg/vpn_wg_dashboard.php");
$tab_array[] = array(gettext("Export"), false, "/wg/vpn_wg_export.php");
$tab_array[] = array(gettext("Setup"), false, "/wg/vpn_wg_setup.php");
display_top_tabs($tab_array);
?>

<script src="/wg_chart.js"></script>
<style>
.dash-controls { background: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #ddd; }
.health-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 5px; }
.speed-badge { font-family: monospace; font-size: 14px; font-weight: bold; padding: 4px 8px; border-radius: 4px; transition: all 0.3s ease; }
.speed-high { background-color: #d9534f; color: white; }
.speed-med { background-color: #f0ad4e; color: white; }
.speed-low { background-color: #5cb85c; color: white; }
.speed-idle { background-color: #eee; color: #888; }
.quota-exceeded { background-color: rgba(217, 83, 79, 0.15) !important; }
</style>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title">Network Operations Center (NOC) Dashboard</h2>
    </div>
    <div class="panel-body">

        <div class="dash-controls row">
            <div class="col-sm-2">
                <label><i class="fa fa-server"></i> Tunnel</label>
                <select id="filterTun" class="form-control" onchange="processData()"><option value="ALL">All Tunnels</option></select>
            </div>
            <div class="col-sm-3">
                <label><i class="fa fa-filter"></i> Top Talkers</label>
                <select id="filterTop" class="form-control" onchange="processData()">
                    <option value="10">Show Top 10</option>
                    <option value="25">Show Top 25</option>
                    <option value="9999">Show All</option>
                </select>
            </div>
            <div class="col-sm-3">
                <label><i class="fa fa-search"></i> Search Peer</label>
                <input type="text" id="filterSearch" class="form-control" placeholder="Name or IP..." oninput="processData()">
            </div>
            <div class="col-sm-2">
                <label><i class="fa fa-tachometer"></i> Quota (GB)</label>
                <input type="number" id="quotaLimit" class="form-control" value="100" oninput="processData()">
            </div>
            <div class="col-sm-2 text-right">
                <label style="margin-top:25px;">
                    <input type="checkbox" id="liveToggle" checked onchange="togglePolling()"> Live Poll (7s)
                </label>
            </div>
        </div>

        <div class="row">
            <div class="col-sm-8">
                <h4><i class="fa fa-bar-chart"></i> Live Bandwidth Snapshot</h4>
                <canvas id="bwChart" height="100"></canvas>
            </div>
            <div class="col-sm-4">
                <h4><i class="fa fa-pie-chart"></i> Subnet Exhaustion</h4>
                <canvas id="ipPieChart" height="200"></canvas>
            </div>
        </div>

        <hr>
        <div class="row">
            <div class="col-sm-12">
                <h4><i class="fa fa-line-chart"></i> 24-Hour Usage Trend (Aggregated)</h4>
                <canvas id="trendChart" height="60"></canvas>
            </div>
        </div>

        <hr>
        <div class="row">
            <div class="col-sm-12">
                <h4><i class="fa fa-users"></i> Live Peer Details</h4>
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-condensed" id="peerTable">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Peer Name</th>
                                <th>Tunnel</th>
                                <th>IP Address</th>
                                <th>Live Speed (Rx+Tx)</th>
                                <th>Total Data</th>
                                <th>Quota Status</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
let bwChartInst = null;
let pieChartInst = null;
let trendChartInst = null;
let pollInterval = null;
let globalPeerData = [];
let previousPeerState = {};
let lastProcessedServerTime = 0;
let serverTime = 0;
const POLL_RATE_SEC = 7;

function getCsrf() {
    if (typeof csrfMagicToken !== 'undefined') return csrfMagicToken;
    const el = document.querySelector("input[name='__csrf_magic']");
    return el ? el.value : '';
}

function formatBytes(bytes, decimals = 2) {
    if (!+bytes) return '0 Bytes';
    const k = 1024, dm = decimals < 0 ? 0 : decimals, sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
}

function fetchTelemetry() {
    const body = new URLSearchParams({ action: 'get_telemetry', __csrf_magic: getCsrf() });
    fetch('/wg/vpn_wg_dashboard.php', { method: 'POST', body: body })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const currentServerTime = data.server_time;

                if (currentServerTime <= lastProcessedServerTime) return;
                lastProcessedServerTime = currentServerTime;

                const sel = document.getElementById('filterTun');
                if (sel.options.length === 1 && data.tunnels.length > 0) {
                    data.tunnels.forEach(t => sel.add(new Option(t, t)));
                }

                data.peers.forEach(p => {
                    p.liveSpeedBps = 0;
                    if (previousPeerState[p.pub] !== undefined) {
                        const prev = previousPeerState[p.pub];
                        const deltaBytes = p.total - prev.bytes;
                        const deltaTime = currentServerTime - prev.time;

                        if (deltaTime > 2.0) {
                            if (deltaBytes > 1024) {
                                const rawBps = deltaBytes / deltaTime;
                                p.liveSpeedBps = (rawBps * 0.7) + (prev.ema * 0.3);
                            } else {
                                p.liveSpeedBps = 0;
                            }
                        } else {
                            p.liveSpeedBps = prev.ema;
                        }
                    }
                    previousPeerState[p.pub] = { bytes: p.total, time: currentServerTime, ema: p.liveSpeedBps || 0 };
                });

                globalPeerData = data.peers;
                serverTime = Math.floor(currentServerTime);
                processData();
                renderPieChart(data.ip_used, data.ip_free);
            }
        });
}

function processData() {
    const searchTxt = document.getElementById('filterSearch').value.toLowerCase();
    const topN = parseInt(document.getElementById('filterTop').value, 10);
    const selTun = document.getElementById('filterTun').value;
    const quotaBytes = parseFloat(document.getElementById('quotaLimit').value || 100) * 1024 * 1024 * 1024;

    let filtered = globalPeerData.filter(p => {
        const matchName = p.name.toLowerCase().includes(searchTxt) || (p.ip && p.ip.toLowerCase().includes(searchTxt));
        const matchTun = (selTun === 'ALL') || (p.tun === selTun);
        return matchName && matchTun;
    });

    filtered.sort((a, b) => b.total - a.total);
    const chartPeers = filtered.slice(0, topN);

    const labels = []; const rxData = []; const txData = []; const rxCols = []; const txCols = [];
    chartPeers.forEach(p => {
        labels.push(p.name); rxData.push(p.rx); txData.push(p.tx);
        const diff = serverTime - p.handshake;
        if (p.handshake > 0 && diff < 180) { rxCols.push('rgba(92,184,92,0.8)'); txCols.push('rgba(92,184,92,0.4)'); }
        else if (p.handshake > 0 && diff < 86400) { rxCols.push('rgba(240,173,78,0.8)'); txCols.push('rgba(240,173,78,0.4)'); }
        else { rxCols.push('rgba(217,83,79,0.8)'); txCols.push('rgba(217,83,79,0.4)'); }
    });
    updateBarChart(labels, rxData, txData, rxCols, txCols);
    updateTable(filtered, quotaBytes);
    updateTrendChart(chartPeers);
}

function updateTable(peers, quotaBytes) {
    const tbody = document.querySelector('#peerTable tbody');
    tbody.innerHTML = '';

    peers.forEach(p => {
        const tr = document.createElement('tr');
        const pct = (p.total / quotaBytes) * 100;
        if (pct > 90) tr.classList.add('quota-exceeded');

        let statHtml = '<span class="health-dot" style="background:#d9534f;"></span> Offline';
        const diff = serverTime - p.handshake;
        if (p.handshake > 0 && diff < 180) statHtml = '<span class="health-dot" style="background:#5cb85c;"></span> Online';
        else if (p.handshake > 0 && diff < 86400) statHtml = '<span class="health-dot" style="background:#f0ad4e;"></span> Idle';

        let ipHtml = '<span class="text-muted">No Active Endpoint</span>';
        if (p.ip) {
            ipHtml = `<span class="text-info">${p.ip}</span>`;
        }

        const mbps = (p.liveSpeedBps * 8) / 1000000;
        let speedClass = 'speed-idle';
        if (mbps > 20) speedClass = 'speed-high';
        else if (mbps > 3) speedClass = 'speed-med';
        else if (mbps > 0.01) speedClass = 'speed-low';

        const displayMbps = mbps === 0 ? "0.00" : (mbps < 0.01 ? "< 0.01" : mbps.toFixed(2));
        const speedHtml = `<span class="speed-badge ${speedClass}">${displayMbps} Mbps</span>`;

        const qColor = pct > 90 ? 'progress-bar-danger' : (pct > 75 ? 'progress-bar-warning' : 'progress-bar-success');
        const qHtml = `<div class="progress" style="margin:0;height:15px;"><div class="progress-bar ${qColor}" style="width:${Math.min(pct,100)}%;line-height:15px;font-size:10px;">${pct.toFixed(1)}%</div></div>`;

        tr.innerHTML = `
            <td>${statHtml}</td>
            <td><strong>${p.name}</strong></td>
            <td>${p.tun}</td>
            <td>${ipHtml}</td>
            <td>${speedHtml}</td>
            <td>${formatBytes(p.total)}</td>
            <td>${qHtml}</td>
        `;
        tbody.appendChild(tr);
    });
}

function updateTrendChart(peers) {
    if (!trendChartInst && document.getElementById('trendChart')) {
        const ctx = document.getElementById('trendChart').getContext('2d');
        trendChartInst = new Chart(ctx, {
            type: 'line',
            data: { labels: [], datasets: [{ label: 'Total Aggregated Bandwidth (MB)', data: [], borderColor: '#337ab7', backgroundColor: 'rgba(51, 122, 183, 0.2)', fill: true, tension: 0.3 }] },
            options: { responsive: true, scales: { y: { beginAtZero: true } } }
        });
    }

    const aggregated = {};
    peers.forEach(p => {
        if (p.history) {
            Object.keys(p.history).forEach(ts => {
                if (!aggregated[ts]) aggregated[ts] = 0;
                aggregated[ts] += p.history[ts];
            });
        }
    });

    const sortedTimes = Object.keys(aggregated).sort();
    const labels = []; const data = [];
    sortedTimes.forEach(ts => {
        const d = new Date(ts * 1000);
        labels.push(d.getHours() + ':00');
        data.push(aggregated[ts] / 1024 / 1024);
    });

    if (trendChartInst) {
        trendChartInst.data.labels = labels;
        trendChartInst.data.datasets[0].data = data;
        trendChartInst.update();
    }
}

function updateBarChart(labels, rx, tx, rxCols, txCols) {
    if (!bwChartInst) {
        const ctxBw = document.getElementById('bwChart').getContext('2d');
        bwChartInst = new Chart(ctxBw, {
            type: 'bar',
            data: { labels: labels, datasets: [ { label: 'Rx', data: rx, backgroundColor: rxCols }, { label: 'Tx', data: tx, backgroundColor: txCols } ] },
            options: { responsive: true, animation: { duration: 400 }, scales: { y: { ticks: { callback: function(value) { return formatBytes(value, 0); } } } }, plugins: { tooltip: { callbacks: { label: function(context) { return context.dataset.label + ': ' + formatBytes(context.raw); } } } } }
        });
    } else {
        bwChartInst.data.labels = labels;
        bwChartInst.data.datasets[0].data = rx; bwChartInst.data.datasets[0].backgroundColor = rxCols;
        bwChartInst.data.datasets[1].data = tx; bwChartInst.data.datasets[1].backgroundColor = txCols;
        bwChartInst.update();
    }
}

function renderPieChart(used, free) {
    if (!pieChartInst) {
        const ctxPie = document.getElementById('ipPieChart').getContext('2d');
        pieChartInst = new Chart(ctxPie, {
            type: 'doughnut', data: { labels: ['Used IPs', 'Available IPs'], datasets: [{ data: [used, free], backgroundColor: ['rgba(217,83,79,0.8)', 'rgba(92,184,92,0.8)'] }] }, options: { responsive: true, cutout: '65%' }
        });
    } else {
        pieChartInst.data.datasets[0].data = [used, free];
        pieChartInst.update();
    }
}

function togglePolling() {
    const isChecked = document.getElementById('liveToggle').checked;
    if (isChecked) { pollInterval = setInterval(fetchTelemetry, POLL_RATE_SEC * 1000); }
    else { clearInterval(pollInterval); }
}

document.addEventListener('DOMContentLoaded', () => { fetchTelemetry(); togglePolling(); });
</script>
<?php include("foot.inc"); ?>
