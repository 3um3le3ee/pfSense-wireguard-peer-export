<?php
/**
 * vpn_wg_setup.php
 * Custom WireGuard Setup Wizard for pfSense
 * Unified Suite Edition v1.1.24
 */

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("pkg-utils.inc");
require_once("util.inc");

if (!is_array($config['installedpackages']['wireguard'])) {
    $config['installedpackages']['wireguard'] = [];
}
if (!is_array($config['installedpackages']['wireguard']['tunnels'])) {
    $config['installedpackages']['wireguard']['tunnels'] = [];
}

$lan_ip = $config['interfaces']['lan']['ipaddr'] ?? 'Unknown';
$lan_subnet = $config['interfaces']['lan']['subnet'] ?? '24';

if ($_POST && isset($_POST['deploy_all'])) {
    $wg_desc = !empty($_POST['wg_desc']) ? $_POST['wg_desc'] : 'WG_Tunnel';
    $wg_port = !empty($_POST['wg_port']) ? $_POST['wg_port'] : '51820';
    $wg_ip_full = !empty($_POST['wg_ip']) ? $_POST['wg_ip'] : '10.10.10.1/24';

    $ip_parts = explode('/', $wg_ip_full);
    $wg_ip = $ip_parts[0];
    $wg_mask = $ip_parts[1] ?? '24';

    $network_ip = gen_subnet($wg_ip, $wg_mask);

    // Find safe tunnel ID
    $existing_tuns = [];
    if (isset($config['installedpackages']['wireguard']['tunnels']['item']) && is_array($config['installedpackages']['wireguard']['tunnels']['item'])) {
        foreach ($config['installedpackages']['wireguard']['tunnels']['item'] as $t) {
            if (isset($t['name'])) {
                $existing_tuns[] = $t['name'];
            }
        }
    }
    $tun_idx = 0;
    while (in_array("tun_wg{$tun_idx}", $existing_tuns)) {
        $tun_idx++;
    }
    $tun_iface = "tun_wg{$tun_idx}";

    // Generate Keys
    $wg_bin = is_executable('/usr/local/bin/wg') ? '/usr/local/bin/wg' : '/usr/bin/wg';
    $privkey = trim(shell_exec("{$wg_bin} genkey"));
    $pubkey = trim(shell_exec("echo " . escapeshellarg($privkey) . " | {$wg_bin} pubkey"));

    // =========================================================================
    // STAGE 1: DIRECT OS INJECTION
    // =========================================================================
    mwexec("/sbin/ifconfig wg create name {$tun_iface}", true);
    mwexec("/sbin/ifconfig {$tun_iface} group wireguard", true);

    $tmp_key = tempnam(sys_get_temp_dir(), 'wg_');
    file_put_contents($tmp_key, $privkey);
    mwexec("{$wg_bin} set {$tun_iface} listen-port " . escapeshellarg($wg_port) . " private-key " . escapeshellarg($tmp_key), true);
    @unlink($tmp_key);

    // =========================================================================
    // STAGE 2: Write Config
    // =========================================================================
    $config['installedpackages']['wireguard']['config'][0]['enable'] = 'on';
    $config['installedpackages']['wireguard']['tunnels']['item'][] = [
        'name' => $tun_iface,
        'enable' => 'on',
        'enabled' => 'yes',
        'descr' => $wg_desc,
        'listenport' => $wg_port,
        'privatekey' => $privkey,
        'publickey' => $pubkey,
        'addresses' => [
            'item' => [
                [
                    'address' => $wg_ip,
                    'mask' => $wg_mask,
                    'descr' => 'Tunnel_IP'
                ]
            ]
        ]
    ];
    write_config("WG Suite: Deployed Tunnel");

    @include_once('/usr/local/pkg/wireguard/includes/wg.inc');
    if (function_exists('wg_resync')) {
        wg_resync($tun_iface, true);
    }
    sync_package("wireguard");

    // STAGE 3: Map pfSense Interface & Firewall
    for ($i = 1; $i <= 99; $i++) {
        if (!isset($config['interfaces']["opt{$i}"])) {
            $new_opt = "opt{$i}";
            break;
        }
    }
    $config['interfaces'][$new_opt] = [
        'enable' => '',
        'if' => $tun_iface,
        'descr' => 'WG_VPN',
        'type' => 'none',
        'ipaddr' => 'none'
    ];

    $config['filter']['rule'][] = [
        'type' => 'pass',
        'interface' => 'wan',
        'ipprotocol' => 'inet',
        'statetype' => 'keep state',
        'protocol' => 'udp',
        'source' => [
            'any' => true
        ],
        'destination' => [
            'any' => true,
            'port' => $wg_port
        ],
        'descr' => "Allow WireGuard Inbound",
        'created' => make_config_revision_entry()
    ];
    $config['filter']['rule'][] = [
        'type' => 'pass',
        'interface' => $new_opt,
        'ipprotocol' => 'inet',
        'protocol' => 'any',
        'source' => [
            'any' => true
        ],
        'destination' => [
            'any' => true
        ],
        'descr' => "Allow WireGuard Traffic",
        'created' => make_config_revision_entry()
    ];

    // STAGE 4: Outbound NAT Injection Fix
    if (!isset($config['nat']['outbound'])) { $config['nat']['outbound'] = []; }
    if (!isset($config['nat']['outbound']['mode']) || $config['nat']['outbound']['mode'] === 'automatic' || $config['nat']['outbound']['mode'] === '') {
        $config['nat']['outbound']['mode'] = 'hybrid';
    }
    if (!isset($config['nat']['outbound']['rule']) || !is_array($config['nat']['outbound']['rule'])) {
        $config['nat']['outbound']['rule'] = [];
    }

    $nat_exists = false;
    foreach ($config['nat']['outbound']['rule'] as $r) {
        if (($r['source']['network'] ?? '') === "{$network_ip}/{$wg_mask}") {
            $nat_exists = true;
            break;
        }
    }

    if (!$nat_exists) {
        $config['nat']['outbound']['rule'][] = [
            'source' => ['network' => "{$network_ip}/{$wg_mask}"],
            'sourceport' => '',
            'descr' => "WG Auto Setup: Outbound NAT for {$wg_desc}",
            'target' => '',
            'interface' => 'wan',
            'destination' => ['any' => true],
            'natport' => '',
            'created' => make_config_revision_entry()
        ];
    }

    write_config("WG Suite: Finalized Mapping & Outbound NAT");
    interface_configure($new_opt);
    filter_configure_sync();

    // FINAL SLEDGEHAMMER: Force IP onto interface
    mwexec("/sbin/ifconfig {$tun_iface} inet {$wg_ip}/{$wg_mask} alias", true);
    mwexec("/sbin/ifconfig {$tun_iface} up", true);

    $savemsg = "Deployment Complete! Interface {$tun_iface} created, forced onto port {$wg_port}, and routing/NAT applied.";
}

$pgtitle = [gettext("VPN"), gettext("WireGuard"), gettext("Setup")];
include("head.inc");

$tab_array = [];
$tab_array[] = [gettext("Tunnels"), false, "/wg/vpn_wg_tunnels.php"];
$tab_array[] = [gettext("Peers"), false, "/wg/vpn_wg_peers.php"];
$tab_array[] = [gettext("Settings"), false, "/wg/vpn_wg_settings.php"];
$tab_array[] = [gettext("Status"), false, "/wg/status_wireguard.php"];
$tab_array[] = [gettext("Export"), false, "/wg/vpn_wg_export.php"];
$tab_array[] = [gettext("Setup"), true, "/wg/vpn_wg_setup.php"];
display_top_tabs($tab_array);

if (isset($savemsg)) {
    print_info_box($savemsg, 'success');
}
?>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title">WireGuard Tunnel Setup</h2>
    </div>
    <div class="panel-body">
        <form method="post">
            <div class="form-group">
                <label>Tunnel Description</label>
                <input type="text" class="form-control" name="wg_desc" value="WG_Tunnel">
            </div>
            <div class="form-group">
                <label>Listen Port</label>
                <input type="text" class="form-control" name="wg_port" value="51820">
            </div>
            <div class="form-group">
                <label>Tunnel IP Address / CIDR</label>
                <input type="text" class="form-control" name="wg_ip" value="10.10.10.1/24">
            </div>
            <p><i>Local Route: <?php echo htmlspecialchars($lan_ip . '/' . $lan_subnet); ?>.</i></p>
            <button class="btn btn-primary" type="submit" name="deploy_all" value="Deploy">Deploy Tunnel</button>
        </form>
    </div>
</div>
<?php include("foot.inc"); ?>
