<?php
/**
 * vpn_wg_setup.php
 * Custom WireGuard Setup Wizard for pfSense
 * Unified Suite Edition v1.0.8
 */

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("pkg-utils.inc");
require_once("util.inc");

$local_interfaces = get_configured_interface_with_descr();

if (!isset($config['installedpackages']) || !is_array($config['installedpackages'])) {
    $config['installedpackages'] = [];
}
if (!isset($config['installedpackages']['wireguard']) || !is_array($config['installedpackages']['wireguard'])) {
    $config['installedpackages']['wireguard'] = [];
}
if (!isset($config['installedpackages']['wireguard']['tunnels']) || !is_array($config['installedpackages']['wireguard']['tunnels'])) {
    $config['installedpackages']['wireguard']['tunnels'] = [];
}

$lan_ip = $config['interfaces']['lan']['ipaddr'] ?? 'Unknown';
$lan_subnet = $config['interfaces']['lan']['subnet'] ?? '24';

if ($_POST && isset($_POST['deploy_all'])) {
    $wg_desc    = !empty($_POST['wg_desc'])    ? trim($_POST['wg_desc'])    : 'WG_Tunnel';
    $wg_port    = !empty($_POST['wg_port'])    ? (int)$_POST['wg_port']    : 51820;
    $wg_ip_full = !empty($_POST['wg_ip'])      ? trim($_POST['wg_ip'])      : '10.10.10.1/24';
    $wg_ip6_full= !empty($_POST['wg_ip6'])     ? trim($_POST['wg_ip6'])     : '';
    $nat_iface  = !empty($_POST['nat_iface'])  ? trim($_POST['nat_iface'])  : 'wan';

    // ── Parse primary tunnel address (IPv4 or IPv6) ──────────────────────────
    $ip_parts   = explode('/', $wg_ip_full, 2);
    $wg_ip      = $ip_parts[0];
    $wg_mask    = $ip_parts[1] ?? '24';
    $is_v6_primary = is_ipaddrv6($wg_ip);

    if ($is_v6_primary) {
        $network_cidr = gen_subnetv6($wg_ip, $wg_mask) . '/' . $wg_mask;
    } else {
        $network_cidr = gen_subnet($wg_ip, $wg_mask) . '/' . $wg_mask;
    }

    // ── Parse optional dual-stack IPv6 address ───────────────────────────────
    $wg_ip6     = '';
    $wg_mask6   = '';
    $network6_cidr = '';

    if (!empty($wg_ip6_full)) {
        $ip6_parts = explode('/', $wg_ip6_full, 2);
        $wg_ip6    = $ip6_parts[0];
        $wg_mask6  = $ip6_parts[1] ?? '64';

        if (is_ipaddrv6($wg_ip6)) {
            $network6_cidr = gen_subnetv6($wg_ip6, $wg_mask6) . '/' . $wg_mask6;
        } else {
            $wg_ip6 = ''; // invalid — ignore silently
        }
    }

    // ── Find a safe tun_wgN interface name ───────────────────────────────────
    $existing_tuns = [];
    if (isset($config['installedpackages']['wireguard']['tunnels']['item']) &&
        is_array($config['installedpackages']['wireguard']['tunnels']['item'])) {
        foreach ($config['installedpackages']['wireguard']['tunnels']['item'] as $t) {
            if (isset($t['name'])) $existing_tuns[] = $t['name'];
        }
    }

    $tun_idx = 0;
    while (in_array("tun_wg{$tun_idx}", $existing_tuns)) $tun_idx++;
    $tun_iface = "tun_wg{$tun_idx}";

    // ── Generate keypair ─────────────────────────────────────────────────────
    $wg_bin = is_executable('/usr/local/bin/wg') ? '/usr/local/bin/wg' : '/usr/bin/wg';
    $privkey = trim(shell_exec("{$wg_bin} genkey"));
    $pubkey  = trim(shell_exec("echo " . escapeshellarg($privkey) . " | {$wg_bin} pubkey"));

    // =========================================================================
    // STAGE 1: OS-level interface creation
    // =========================================================================
    mwexec("/sbin/ifconfig wg create name {$tun_iface}", true);
    mwexec("/sbin/ifconfig {$tun_iface} group wireguard", true);

    $tmp_key = tempnam(sys_get_temp_dir(), 'wg_');
    file_put_contents($tmp_key, $privkey);
    mwexec("{$wg_bin} set {$tun_iface} listen-port " . escapeshellarg((string)$wg_port) .
           " private-key " . escapeshellarg($tmp_key), true);
    @unlink($tmp_key);

    // =========================================================================
    // STAGE 2: Build tunnel address list (IPv4, or IPv6, or dual-stack)
    // =========================================================================
    $address_items = [];

    if ($is_v6_primary) {
        // Primary address is IPv6
        $address_items[] = ['address' => $wg_ip, 'mask' => $wg_mask, 'descr' => 'Tunnel_IPv6'];
        // Optional secondary IPv4
        if (!empty($_POST['wg_ip4_secondary'])) {
            $v4s = explode('/', trim($_POST['wg_ip4_secondary']), 2);
            if (is_ipaddrv4($v4s[0])) {
                $address_items[] = ['address' => $v4s[0], 'mask' => $v4s[1] ?? '24', 'descr' => 'Tunnel_IPv4'];
            }
        }
    } else {
        // Primary address is IPv4
        $address_items[] = ['address' => $wg_ip, 'mask' => $wg_mask, 'descr' => 'Tunnel_IPv4'];
        // Optional secondary IPv6
        if (!empty($wg_ip6)) {
            $address_items[] = ['address' => $wg_ip6, 'mask' => $wg_mask6, 'descr' => 'Tunnel_IPv6'];
        }
    }

    $config['installedpackages']['wireguard']['config'][0]['enable'] = 'on';
    $config['installedpackages']['wireguard']['tunnels']['item'][] = [
        'name'       => $tun_iface,
        'enable'     => 'on',
        'enabled'    => 'yes',
        'descr'      => $wg_desc,
        'listenport' => (string)$wg_port,
        'privatekey' => $privkey,
        'publickey'  => $pubkey,
        'addresses'  => ['item' => $address_items]
    ];

    write_config("WG Suite: Deployed Tunnel");

    @include_once('/usr/local/pkg/wireguard/includes/wg.inc');
    if (function_exists('wg_resync')) wg_resync($tun_iface, true);
    sync_package("wireguard");

    // =========================================================================
    // STAGE 3: Map pfSense interface
    // =========================================================================
    for ($i = 1; $i <= 99; $i++) {
        if (!isset($config['interfaces']["opt{$i}"])) { $new_opt = "opt{$i}"; break; }
    }

    $config['interfaces'][$new_opt] = [
        'enable' => '',
        'if'     => $tun_iface,
        'descr'  => 'WG_VPN',
        'type'   => 'none',
        'ipaddr' => 'none'
    ];

    // =========================================================================
    // STAGE 4: Firewall rules
    // =========================================================================
    if (!isset($config['filter']['rule']) || !is_array($config['filter']['rule'])) {
        $config['filter']['rule'] = [];
    } elseif (!empty($config['filter']['rule']) && !isset($config['filter']['rule'][0])) {
        $config['filter']['rule'] = [$config['filter']['rule']];
    }

    // WireGuard UDP inbound — works for both IPv4 and IPv6 endpoints
    $config['filter']['rule'][] = [
        'type'       => 'pass',
        'interface'  => 'wan',
        'ipprotocol' => 'inet46',   // accepts both v4 and v6 clients
        'statetype'  => 'keep state',
        'protocol'   => 'udp',
        'source'     => ['any' => true],
        'destination'=> ['any' => true, 'port' => (string)$wg_port],
        'descr'      => "Allow WireGuard Inbound ({$wg_desc})",
        'created'    => make_config_revision_entry()
    ];

    // Tunnel traffic pass — cover both address families on the WG interface
    $config['filter']['rule'][] = [
        'type'       => 'pass',
        'interface'  => $new_opt,
        'ipprotocol' => 'inet46',
        'protocol'   => 'any',
        'source'     => ['any' => true],
        'destination'=> ['any' => true],
        'descr'      => "Allow WireGuard Traffic ({$wg_desc})",
        'created'    => make_config_revision_entry()
    ];

    // =========================================================================
    // STAGE 5: Outbound NAT — one rule per address family configured
    // =========================================================================
    if (!isset($config['nat']['outbound'])) $config['nat']['outbound'] = [];
    if (empty($config['nat']['outbound']['mode']) || $config['nat']['outbound']['mode'] === 'automatic') {
        $config['nat']['outbound']['mode'] = 'hybrid';
    }

    if (!isset($config['nat']['outbound']['rule']) || !is_array($config['nat']['outbound']['rule'])) {
        $config['nat']['outbound']['rule'] = [];
    } elseif (!empty($config['nat']['outbound']['rule']) && !isset($config['nat']['outbound']['rule'][0])) {
        $config['nat']['outbound']['rule'] = [$config['nat']['outbound']['rule']];
    }

    $nat_cidrs_to_add = [];
    if (!empty($network_cidr))  $nat_cidrs_to_add[] = $network_cidr;   // IPv4 or primary IPv6
    if (!empty($network6_cidr)) $nat_cidrs_to_add[] = $network6_cidr;  // secondary IPv6

    foreach ($nat_cidrs_to_add as $nat_cidr) {
        $already_exists = false;
        foreach ($config['nat']['outbound']['rule'] as $r) {
            if (($r['source']['network'] ?? '') === $nat_cidr) { $already_exists = true; break; }
        }

        if (!$already_exists) {
            $config['nat']['outbound']['rule'][] = [
                'source'      => ['network' => $nat_cidr],
                'sourceport'  => '',
                'descr'       => "WG Auto Setup: Outbound NAT for {$wg_desc}",
                'target'      => '',
                'interface'   => $nat_iface,
                'destination' => ['any' => true],
                'natport'     => '',
                'created'     => make_config_revision_entry()
            ];
        }
    }

    write_config("WG Suite: Finalized Interface, Firewall, and NAT");

    // =========================================================================
    // STAGE 6: Apply everything
    // =========================================================================
    interface_configure($new_opt);
    filter_configure_sync();

    // Force IP onto the OS interface — apply all configured addresses
    foreach ($address_items as $addr) {
        if (is_ipaddrv4($addr['address'])) {
            mwexec("/sbin/ifconfig {$tun_iface} inet {$addr['address']}/{$addr['mask']} alias", true);
        } elseif (is_ipaddrv6($addr['address'])) {
            mwexec("/sbin/ifconfig {$tun_iface} inet6 {$addr['address']} prefixlen {$addr['mask']}", true);
        }
    }

    mwexec("/sbin/ifconfig {$tun_iface} up", true);

    $v6_info = !empty($wg_ip6) ? " | IPv6: {$wg_ip6}/{$wg_mask6}" : '';
    $savemsg = "Deployment Complete! Interface {$tun_iface} created. IPv4: {$wg_ip}/{$wg_mask}{$v6_info}. Routing and NAT applied.";
}

$pgtitle = [gettext("VPN"), gettext("WireGuard"), gettext("Setup")];
include("head.inc");

$tab_array = [];
$tab_array[] = [gettext("Tunnels"), false, "/wg/vpn_wg_tunnels.php"];
$tab_array[] = [gettext("Peers"), false, "/wg/vpn_wg_peers.php"];
$tab_array[] = [gettext("Settings"), false, "/wg/vpn_wg_settings.php"];
$tab_array[] = [gettext("Status"), false, "/wg/status_wireguard.php"];
$tab_array[] = [gettext("Dashboard"), false, "/wg/vpn_wg_dashboard.php"];
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
            <table class="table table-striped table-hover">
                <tbody>
                    <tr>
                        <td class="col-sm-3"><strong>Tunnel Description</strong></td>
                        <td class="col-sm-9">
                            <input type="text" class="form-control" name="wg_desc" value="WG_Tunnel">
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Listen Port</strong></td>
                        <td>
                            <input type="text" class="form-control" name="wg_port" value="51820">
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Tunnel IPv4 Address / CIDR</strong>
                        </td>
                        <td>
                            <input type="text" class="form-control" name="wg_ip"
                                   value="10.10.10.1/24"
                                   placeholder="e.g. 10.10.10.1/24">
                            <span class="help-block">
                                Primary tunnel address. Can be IPv4 <em>or</em> IPv6 — enter whichever
                                address family you want to use as the primary.
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Tunnel IPv6 Address / Prefix</strong>
                            <span class="text-muted"> (optional)</span>
                        </td>
                        <td>
                            <input type="text" class="form-control" name="wg_ip6"
                                   value=""
                                   placeholder="e.g. 2001:db8:85a3::8a2e:370:7334/64">
                            <span class="help-block">
                                Leave blank for IPv4-only. Fill in to create a dual-stack tunnel.
                                A <code>fd00::/8</code> ULA prefix is recommended for private tunnels.
                                A NAT rule will be created for this prefix automatically.
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Outbound NAT Interface</strong></td>
                        <td>
                            <select name="nat_iface" class="form-control">
                                <?php foreach($local_interfaces as $if => $desc): ?>
                                    <option value="<?= htmlspecialchars($if) ?>"
                                        <?= ($if === 'wan') ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($desc) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="help-block">
                                Select the interface to apply outbound NAT rules to.
                                NAT rules are created for all configured address families.
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="col-sm-offset-3 col-sm-9" style="margin-top: 15px;">
                <button class="btn btn-sm btn-primary" type="submit" name="deploy_all" value="Deploy">
                    <i class="fa fa-save icon-embed-btn"></i> Deploy Tunnel
                </button>
            </div>
        </form>
    </div>
<?php include("foot.inc"); ?>
