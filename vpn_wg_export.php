<?php
/**
 * vpn_wg_export.php
 * pfSense WireGuard Peer Provisioning & Export Tool
 * Unified Suite Edition v1.0.8
 */

require_once("guiconfig.inc");
require_once("util.inc");
require_once("filter.inc");
require_once("pkg-utils.inc");

// =========================================================================
// 1. SESSION & AUTH
// =========================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function wgx_is_admin() {
    if (function_exists('isAdminUID') && isAdminUID(isset($_SESSION['Username']) ? $_SESSION['Username'] : '')) {
        return true;
    }
    if (isset($_SESSION['Username']) && $_SESSION['Username'] === 'admin') {
        return true;
    }

    global $config;
    $current_user = $_SESSION['Username'] ?? '';
    $user_uid = null;

    if (isset($config['system']['user']) && is_array($config['system']['user'])) {
        foreach ($config['system']['user'] as $u) {
            if (isset($u['name']) && $u['name'] === $current_user) {
                $user_uid = $u['uid'];
                break;
            }
        }
    }

    if ($user_uid !== null && isset($config['system']['group']) && is_array($config['system']['group'])) {
        foreach ($config['system']['group'] as $g) {
            if (isset($g['name']) && $g['name'] === 'admins') {
                if (isset($g['member']) && is_array($g['member']) && in_array($user_uid, $g['member'])) {
                    return true;
                }
            }
        }
    }

    return false;
}

if (!wgx_is_admin()) {
    require_once("head.inc");
    print_info_box(gettext("Access Denied: Administrator privileges required."), "danger");
    include("foot.inc");
    exit;
}

// =========================================================================
// 2. CONSTANTS & BINARY DISCOVERY
// =========================================================================

define('WGX_VERSION', '1.0.8');
define('WGX_RATE_LIMIT_KEY', 'wgx_keygen_hits');
define('WGX_RATE_LIMIT_MAX', 30);
define('WGX_PUBKEY_REGEX', '/^[A-Za-z0-9+\/]{43}=$/');

$wg_bin = '';
foreach (['/usr/local/bin/wg', '/usr/bin/wg', '/sbin/wg'] as $candidate) {
    if (is_executable($candidate)) {
        $wg_bin = $candidate;
        break;
    }
}

// =========================================================================
// 3. SETTINGS & HA SYNC ENGINE
// =========================================================================

function wgx_load_settings() {
    global $config;
    if (isset($config['installedpackages']['wgexport']['config']) && is_array($config['installedpackages']['wgexport']['config']) && isset($config['installedpackages']['wgexport']['config'][0])) {
        $settings = $config['installedpackages']['wgexport']['config'][0];
        if (!isset($settings['fallback_subnets'])) {
            $settings['fallback_subnets'] = '192.168.101.0/24';
        }
        if (!isset($settings['update_freq'])) {
            $settings['update_freq'] = 'never';
        }
        return $settings;
    }
    return [
        'sync_enable' => 'false',
        'sync_ip' => '',
        'sync_user' => 'admin',
        'sync_pass' => '',
        'strict_tls' => 'false',
        'enforce_psk' => 'false',
        'fallback_subnets' => '192.168.101.0/24',
        'update_freq' => 'never'
    ];
}

function wgx_save_settings($settings) {
    global $config;

    if (!isset($config['installedpackages']) || !is_array($config['installedpackages'])) {
        $config['installedpackages'] = [];
    }
    if (!isset($config['installedpackages']['wgexport']) || !is_array($config['installedpackages']['wgexport'])) {
        $config['installedpackages']['wgexport'] = [];
    }

    $config['installedpackages']['wgexport']['config'] = [$settings];
    write_config("WG Export Tool: Saved Global Settings");
}

function wgx_sync_to_backup($new_peer) {
    global $config;
    $settings = wgx_load_settings();

    if (empty($settings['sync_enable']) || $settings['sync_enable'] !== 'true') return false;

    $sync_ip   = $settings['sync_ip'] ?? '';
    $sync_user = !empty($settings['sync_user']) ? $settings['sync_user'] : 'admin';
    $sync_pass = !empty($settings['sync_pass']) ? base64_decode($settings['sync_pass']) : '';
    $strict    = (isset($settings['strict_tls']) && $settings['strict_tls'] === 'true');

    if (empty($sync_ip) || empty($sync_pass)) return false;

    $peer_b64 = base64_encode(json_encode($new_peer));

    // Execute on remote with strict is_array checks and exact package paths + AUTO APPLY
    $remote_code = <<<PHP
    require_once("config.inc");
    require_once("pkg-utils.inc");
    \$peer = json_decode(base64_decode('{$peer_b64}'), true);

    \$a_peers = [];
    if (function_exists('config_get_path') && config_get_path('installedpackages/wireguard/peers/item') !== null) {
        \$a_peers = config_get_path('installedpackages/wireguard/peers/item', []);
    } elseif (isset(\$config['installedpackages']['wireguard']['peers']['item'])) {
        \$a_peers = \$config['installedpackages']['wireguard']['peers']['item'];
    } elseif (function_exists('config_get_path') && config_get_path('wireguard/peer/item') !== null) {
        \$a_peers = config_get_path('wireguard/peer/item', []);
    } elseif (isset(\$config['wireguard']['peer']['item'])) {
        \$a_peers = \$config['wireguard']['peer']['item'];
    }

    if (!is_array(\$a_peers)) \$a_peers = [];
    if (!empty(\$a_peers) && !isset(\$a_peers[0])) {
        \$a_peers = [\$a_peers];
    }

    \$a_peers[] = \$peer;

    if (function_exists('config_set_path')) {
        config_set_path('installedpackages/wireguard/peers/item', \$a_peers);
    } else {
        if (!isset(\$config['installedpackages']['wireguard']['peers']['item']) || !is_array(\$config['installedpackages']['wireguard']['peers']['item'])) {
            if (!isset(\$config['installedpackages'])) \$config['installedpackages'] = [];
            if (!isset(\$config['installedpackages']['wireguard'])) \$config['installedpackages']['wireguard'] = [];
            if (!isset(\$config['installedpackages']['wireguard']['peers'])) \$config['installedpackages']['wireguard']['peers'] = [];
            \$config['installedpackages']['wireguard']['peers']['item'] = [];
        }
        \$config['installedpackages']['wireguard']['peers']['item'] = \$a_peers;
    }

    write_config("WGX: HA Sync - Provisioned peer from Primary Node");
    sync_package("wireguard");

    @include_once('/usr/local/pkg/wireguard/includes/wg_globals.inc');
    @include_once('/usr/local/pkg/wireguard/includes/wg.inc');
    @include_once('/usr/local/pkg/wireguard/includes/wg_service.inc');

    if (function_exists('wg_resync')) {
        wg_resync(\$peer['tun'], true);
    } elseif (function_exists('setup_wg')) {
        setup_wg();
    }
    if (function_exists('clear_subsystem_dirty')) {
        clear_subsystem_dirty('wireguard');
    }
    @unlink('/tmp/wireguard.dirty');

    echo "SUCCESS: Peer injected into config successfully.";
PHP;

    $safe_code = htmlspecialchars($remote_code, ENT_QUOTES | ENT_XML1, 'UTF-8');

    $xml_payload = <<<XML
<?xml version="1.0"?>
<methodCall>
  <methodName>pfsense.exec_php</methodName>
  <params>
    <param><value><string>{$safe_code}</string></value></param>
  </params>
</methodCall>
XML;

    $url = "https://{$sync_ip}/xmlrpc.php";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_payload);
    curl_setopt($ch, CURLOPT_USERPWD, "{$sync_user}:{$sync_pass}");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: text/xml"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $strict);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $strict ? 2 : 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $http_code !== 200 || strpos($resp, 'faultCode') !== false) {
        $queue_file = '/var/db/wgx_ha_queue.json';
        if (file_exists($queue_file)) {
            $queue = json_decode(file_get_contents($queue_file), true);
        } else {
            $queue = [];
        }
        $queue[] = $new_peer;
        file_put_contents($queue_file, json_encode($queue));
        return false;
    }
    return true;
}

// =========================================================================
// 4. RATE LIMITER & SHELL
// =========================================================================

function wgx_check_rate_limit() {
    if (!isset($_SESSION[WGX_RATE_LIMIT_KEY])) {
        $_SESSION[WGX_RATE_LIMIT_KEY] = 0;
    }
    if ($_SESSION[WGX_RATE_LIMIT_KEY] >= WGX_RATE_LIMIT_MAX) {
        return false;
    }
    $_SESSION[WGX_RATE_LIMIT_KEY]++;
    return true;
}

function wgx_wg_exec($wg_bin, $args, $in = null) {
    if (empty($wg_bin)) return '';
    $cmd = array_merge([$wg_bin], $args);
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) return '';

    if ($in !== null) {
        fwrite($pipes[0], $in);
    }
    fclose($pipes[0]);

    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return trim((string)$out);
}

function wgx_gen_keypair($wg_bin) {
    if (empty($wg_bin)) return [];

    $priv = wgx_wg_exec($wg_bin, ['genkey']);
    if (empty($priv)) return [];

    $pub = wgx_wg_exec($wg_bin, ['pubkey'], $priv . "\n");

    if (empty($pub)) return [];

    return [
        'priv' => $priv,
        'pub' => $pub
    ];
}

// =========================================================================
// 5. CONFIG HELPERS
// =========================================================================

function wgx_get_config_array($type) {
    global $config;
    $data = [];
    $type_plural = $type . 's';

    if (function_exists('config_get_path') && config_get_path("installedpackages/wireguard/{$type_plural}/item") !== null) {
        $data = config_get_path("installedpackages/wireguard/{$type_plural}/item", []);
    } elseif (isset($config['installedpackages']['wireguard'][$type_plural]) && is_array($config['installedpackages']['wireguard'][$type_plural])) {
        if (isset($config['installedpackages']['wireguard'][$type_plural]['item'])) {
            $data = $config['installedpackages']['wireguard'][$type_plural]['item'];
        } else {
            $data = $config['installedpackages']['wireguard'][$type_plural];
        }
    } elseif (function_exists('config_get_path') && config_get_path("wireguard/{$type}/item") !== null) {
        $data = config_get_path("wireguard/{$type}/item", []);
    } elseif (isset($config['wireguard'][$type]) && is_array($config['wireguard'][$type]) && isset($config['wireguard'][$type]['item'])) {
        $data = $config['wireguard'][$type]['item'];
    }

    if (!is_array($data)) return [];

    if (!empty($data) && !isset($data[0])) {
        $data = [$data];
    }
    return $data;
}

function wgx_valid_tunnel_names() {
    $tunnels = wgx_get_config_array('tunnel');
    $names = [];
    foreach ($tunnels as $t) {
        if (is_array($t) && isset($t['name'])) {
            $names[] = $t['name'];
        }
    }
    return array_filter($names);
}

function wgx_best_endpoint($server_tun = null) {
    global $config;

    // 1. Check for native DDNS configurations first
    if (isset($config['dyndnses']['dyndns']) && is_array($config['dyndnses']['dyndns'])) {
        foreach ($config['dyndnses']['dyndns'] as $ddns) {
            if (is_array($ddns) && !empty($ddns['host'])) {
                return trim($ddns['host']);
            }
        }
    }

    // 2. Fetch the true WAN IP. Never query the WireGuard Interface IP.
    $wan_ip = (string)get_interface_ip('wan');

    // 3. Fallback: If WAN IP is private (Double NAT), fetch external Public IP
    if (is_private_ip($wan_ip)) {
        $public_ip = @trim(shell_exec("curl -s4 -m 2 https://api.ipify.org"));
        if (!empty($public_ip) && is_ipaddrv4($public_ip)) {
            return $public_ip;
        }
    }

    return $wan_ip;
}

function wgx_get_local_subnets() {
    global $config;
    $settings = wgx_load_settings();
    $subnets = [];

    if (isset($config['interfaces']) && is_array($config['interfaces'])) {
        foreach ($config['interfaces'] as $iface) {
            if (isset($iface['ipaddr']) && is_ipaddrv4($iface['ipaddr']) && isset($iface['subnet'])) {
                $sub = gen_subnet($iface['ipaddr'], $iface['subnet']);
                $subnets[] = "{$sub}/{$iface['subnet']}";
            }
        }
    }

    if (empty($subnets)) {
        return $settings['fallback_subnets'];
    } else {
        return implode(', ', $subnets);
    }
}

function wgx_build_conf_template($peer, $server_tun) {
    $lines   = [];
    $lines[] = "[Interface]";
    $lines[] = "PrivateKey = __PRIVATE_KEY_PLACEHOLDER__";

    $ips = [];
    $allowedips = isset($peer['allowedips']) && is_array($peer['allowedips']) ? $peer['allowedips'] : [];
    $raw_rows = $allowedips['row'] ?? ($allowedips['item'] ?? []);

    if (is_array($raw_rows)) {
        if (isset($raw_rows['address'])) {
            $rows = [$raw_rows];
        } else {
            $rows = $raw_rows;
        }
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row['address'])) {
                $mask   = !empty($row['mask']) ? '/' . (int)$row['mask'] : '/32';
                $ips[]  = $row['address'] . $mask;
            }
        }
    }

    $lines[] = "Address = " . (!empty($ips) ? implode(', ', $ips) : "10.x.x.x/32 # Assign IP in pfSense");
    $lines[] = "__DNS_PLACEHOLDER__";

    $lines[] = "";
    $lines[] = "[Peer]";
    $lines[] = "PublicKey = " . htmlspecialchars_decode((is_array($server_tun) && isset($server_tun['publickey'])) ? $server_tun['publickey'] : '', ENT_QUOTES);
    $lines[] = "__PSK_PLACEHOLDER__";
    $lines[] = "Endpoint = __ENDPOINT_PLACEHOLDER__";
    $lines[] = "AllowedIPs = __ALLOWEDIPS_PLACEHOLDER__";
    $lines[] = "PersistentKeepalive = __KEEPALIVE_PLACEHOLDER__";

    return implode("\n", $lines) . "\n";
}

// =========================================================================
// 5b. IP ALLOCATION HELPERS (Free-List within Subnet Bounds)
// =========================================================================
/**
 * Scans all peers on a tunnel and returns the first free IPv4 host address
 * within the tunnel subnet. Fills gaps left by deleted peers. Avoids static routes.
 */
function wgx_allocate_ipv4($tun_name, $tun_base_ip, $tun_mask) {
    global $config;
    if (!is_ipaddrv4($tun_base_ip)) {
        return null;
    }
    $mask      = (int)$tun_mask;
    $net_long  = ip2long(gen_subnet($tun_base_ip, $mask));
    $host_bits = 32 - $mask;
    $bcast_long = $net_long + (1 << $host_bits) - 1;

    $used = [];
    $used[ip2long($tun_base_ip)] = true;

    // 1. Existing Peers
    foreach (wgx_get_config_array('peer') as $p) {
        if (!is_array($p) || ($p['tun'] ?? '') !== $tun_name) continue;
        $allowedips = isset($p['allowedips']) && is_array($p['allowedips']) ? $p['allowedips'] : [];
        $raw = $allowedips['row'] ?? ($allowedips['item'] ?? []);
        if (!is_array($raw)) continue;
        $rows = isset($raw['address']) ? [$raw] : $raw;
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row['address']) && is_ipaddrv4($row['address'])) {
                $used[ip2long($row['address'])] = true;
            }
        }
    }

    // 2. Interfaces
    if (isset($config['interfaces']) && is_array($config['interfaces'])) {
        foreach ($config['interfaces'] as $iface) {
            if (isset($iface['ipaddr']) && is_ipaddrv4($iface['ipaddr'])) {
                $used[ip2long($iface['ipaddr'])] = true;
            }
        }
    }

    // 3. Virtual IPs
    if (isset($config['virtualip']['vip']) && is_array($config['virtualip']['vip'])) {
        foreach ($config['virtualip']['vip'] as $vip) {
            if (isset($vip['subnet']) && is_ipaddrv4($vip['subnet'])) {
                $used[ip2long($vip['subnet'])] = true;
            }
        }
    }

    // 4. Static Routes (Host Routes /32)
    if (isset($config['staticroutes']['route']) && is_array($config['staticroutes']['route'])) {
        foreach ($config['staticroutes']['route'] as $route) {
            if (isset($route['network']) && strpos($route['network'], '/32') !== false) {
                $route_ip = explode('/', $route['network'])[0];
                if (is_ipaddrv4($route_ip)) {
                    $used[ip2long($route_ip)] = true;
                }
            }
        }
    }

    // Walk subnet from .2 (skip .0 = network, .1 = server convention)
    for ($candidate = $net_long + 2; $candidate < $bcast_long; $candidate++) {
        if (!isset($used[$candidate])) {
            return long2ip($candidate) . '/32';
        }
    }
    return null; // subnet exhausted
}

/**
 * Allocates the next free IPv6 address within a /64–/120 prefix.
 * Scans existing peer addresses on the tunnel and walks sequentially
 * from ::2 upward until a free slot is found.
 */
function wgx_allocate_ipv6($tun_name, $tun_base_ip, $prefix_len) {
    global $config;
    if (!is_ipaddrv6($tun_base_ip)) {
        return null;
    }
    $used = [$tun_base_ip => true];

    // 1. Existing Peers
    foreach (wgx_get_config_array('peer') as $p) {
        if (!is_array($p) || ($p['tun'] ?? '') !== $tun_name) continue;
        $allowedips = isset($p['allowedips']) && is_array($p['allowedips']) ? $p['allowedips'] : [];
        $raw = $allowedips['row'] ?? ($allowedips['item'] ?? []);
        if (!is_array($raw)) continue;
        $rows = isset($raw['address']) ? [$raw] : $raw;
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row['address']) && is_ipaddrv6($row['address'])) {
                $used[$row['address']] = true;
            }
        }
    }

    // 2. Interfaces
    if (isset($config['interfaces']) && is_array($config['interfaces'])) {
        foreach ($config['interfaces'] as $iface) {
            if (isset($iface['ipaddr']) && is_ipaddrv6($iface['ipaddr'])) {
                $used[$iface['ipaddr']] = true;
            }
        }
    }

    // 3. Virtual IPs
    if (isset($config['virtualip']['vip']) && is_array($config['virtualip']['vip'])) {
        foreach ($config['virtualip']['vip'] as $vip) {
            if (isset($vip['subnet']) && is_ipaddrv6($vip['subnet'])) {
                $used[$vip['subnet']] = true;
            }
        }
    }

    // 4. Static Routes (Host Routes /128)
    if (isset($config['staticroutes']['route']) && is_array($config['staticroutes']['route'])) {
        foreach ($config['staticroutes']['route'] as $route) {
            if (isset($route['network']) && strpos($route['network'], '/128') !== false) {
                $route_ip = explode('/', $route['network'])[0];
                if (is_ipaddrv6($route_ip)) {
                    $used[$route_ip] = true;
                }
            }
        }
    }

    // Derive the network prefix bytes and zero the host portion
    $base_bin = inet_pton($tun_base_ip);
    if ($base_bin === false) return null;
    $net_bin = $base_bin;
    for ($bit = (int)$prefix_len; $bit < 128; $bit++) {
        $b = (int)($bit / 8);
        $net_bin[$b] = chr(ord($net_bin[$b]) & ~(1 << (7 - ($bit % 8))));
    }

    // Scan ::2 through ::fffe (65534 candidates for a /64)
    for ($i = 2; $i <= 65534; $i++) {
        $candidate_bin = $net_bin;
        // Write the low 16 bits into the last two bytes
        $candidate_bin[14] = chr(($i >> 8) & 0xff);
        $candidate_bin[15] = chr($i & 0xff);
        $candidate = inet_ntop($candidate_bin);
        if ($candidate !== false && !isset($used[$candidate])) {
            return $candidate . '/' . $prefix_len;
        }
    }
    return null;
}
/**
 * Checks whether any of the proposed IPs for a new peer conflict with
 * an address already assigned to an existing peer on the same tunnel.
 * Returns an array of conflict descriptions, or an empty array if clean.
 */
function wgx_check_ip_conflicts($tun_name, array $proposed_ips) {
    $conflicts = [];
    foreach (wgx_get_config_array('peer') as $p) {
        if (!is_array($p) || ($p['tun'] ?? '') !== $tun_name) continue;
        $allowedips = isset($p['allowedips']) && is_array($p['allowedips'])
            ? $p['allowedips'] : [];
        $raw = $allowedips['row'] ?? ($allowedips['item'] ?? []);
        if (!is_array($raw)) continue;
        $rows = isset($raw['address']) ? [$raw] : $raw;
        foreach ($rows as $existing) {
            if (!is_array($existing) || empty($existing['address'])) continue;
            foreach ($proposed_ips as $prop) {
                if (($prop['address'] ?? '') === $existing['address']) {
                    $conflicts[] = sprintf(
                        '%s/%s is already assigned to peer "%s"',
                        $existing['address'],
                        $existing['mask'] ?? '32',
                        htmlspecialchars($p['descr'] ?? 'unknown', ENT_QUOTES, 'UTF-8')
                    );
                }
            }
        }
    }
    return $conflicts;
}

// =========================================================================
// 6. AJAX HANDLERS
// =========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_start();
    try {
        if ($_POST['action'] === 'do_update') {
            if (!csrf_check(false)) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'CSRF failed.']); exit;
            }
            $pkg_url = trim($_POST['pkg_url'] ?? '');
            if (!filter_var($pkg_url, FILTER_VALIDATE_URL) || substr($pkg_url, -4) !== '.pkg') {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Invalid package URL. Ensure the URL ends with .pkg']); exit;
            }

            $tmp_file = '/tmp/wgx_update.pkg';
            $ch = curl_init($pkg_url);
            $fp = fopen($tmp_file, 'w+');
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'pfSense-WG-Suite');
            curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if ($err) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Download failed: ' . $err]); exit;
            }

            mwexec("pkg add -fM " . escapeshellarg($tmp_file), true);
            @unlink('/var/db/wgx_update_available.json');
            @unlink($tmp_file);

            ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => true, 'message' => 'Update installed successfully. Reloading...']); exit;
        }

        if ($_POST['action'] === 'dismiss_update') {
            @unlink('/var/db/wgx_update_available.json');
            ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => true]); exit;
        }

        if ($_POST['action'] === 'derive_pub') {
            if (!csrf_check(false)) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']); exit;
            }
            $priv = trim($_POST['privkey'] ?? '');
            if (empty($priv) || empty($wg_bin)) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Missing private key.']); exit;
            }
            $pub = wgx_wg_exec($wg_bin, ['pubkey'], $priv . "\n");
            ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => true, 'pub' => $pub]); exit;
        }

        if ($_POST['action'] === 'save_global') {
            if (!csrf_check(false)) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']); exit;
            }

            $old_settings = wgx_load_settings();
            $settings = [];
            $settings['sync_enable'] = (isset($_POST['sync_enable']) && $_POST['sync_enable'] === 'true') ? 'true' : 'false';
            $settings['sync_ip']     = trim($_POST['sync_ip'] ?? '');
            $settings['sync_user']   = trim($_POST['sync_user'] ?? 'admin');

            if (!empty($_POST['sync_pass'])) {
                $settings['sync_pass'] = base64_encode($_POST['sync_pass']);
            } else {
                $settings['sync_pass'] = $old_settings['sync_pass'] ?? '';
            }

            $settings['strict_tls'] = (isset($_POST['strict_tls']) && $_POST['strict_tls'] === 'true') ? 'true' : 'false';
            $settings['enforce_psk'] = (isset($_POST['enforce_psk']) && $_POST['enforce_psk'] === 'true') ? 'true' : 'false';
            $settings['fallback_subnets'] = trim($_POST['fallback_subnets'] ?? '192.168.101.0/24');
            $settings['update_freq'] = trim($_POST['update_freq'] ?? 'never');

            wgx_save_settings($settings);
            syslog(LOG_NOTICE, "WGX Export Tool: Global Settings saved.");

            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Global settings saved successfully.']);
            exit;
        }

        if ($_POST['action'] === 'save_sync') {
            if (!csrf_check(false)) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']); exit;
            }

            $old_settings = wgx_load_settings();
            $settings = $old_settings; // preserve all existing keys
            $settings['sync_enable'] = (isset($_POST['sync_enable']) && $_POST['sync_enable'] === 'true') ? 'true' : 'false';
            $settings['sync_ip']     = trim($_POST['sync_ip'] ?? '');
            $settings['sync_user']   = trim($_POST['sync_user'] ?? 'admin');

            if (!empty($_POST['sync_pass'])) {
                $settings['sync_pass'] = base64_encode($_POST['sync_pass']);
            } else {
                $settings['sync_pass'] = $old_settings['sync_pass'] ?? '';
            }

            wgx_save_settings($settings);
            syslog(LOG_NOTICE, "WGX HA Sync: User saved Primary Node settings.");

            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Primary Node Sync settings saved.']);
            exit;
        }

        if ($_POST['action'] === 'setup_backup_firewall') {
            if (!csrf_check(false)) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']); exit;
            }

            $primary_ip = trim($_POST['primary_ip'] ?? '');
            $interface  = trim($_POST['interface'] ?? 'wan');

            if (!is_ipaddrv4($primary_ip) && !is_ipaddrv6($primary_ip)) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Invalid Primary IP Address.']); exit;
            }

            global $config;
            if (!isset($config['filter']) || !is_array($config['filter'])) $config['filter'] = [];
            if (!isset($config['filter']['rule']) || !is_array($config['filter']['rule'])) {
                $config['filter']['rule'] = [];
            } elseif (!empty($config['filter']['rule']) && !isset($config['filter']['rule'][0])) {
                $config['filter']['rule'] = [$config['filter']['rule']]; // Normalization
            }

            $rule_exists = false;
            foreach ($config['filter']['rule'] as $rule) {
                if (isset($rule['source']['address']) && $rule['source']['address'] === $primary_ip &&
                    isset($rule['destination']['network']) && $rule['destination']['network'] === '(self)' &&
                    isset($rule['destination']['port']) && $rule['destination']['port'] === '443') {
                    $rule_exists = true;
                    break;
                }
            }

            if (!$rule_exists) {
                $new_rule = [
                    'type' => 'pass',
                    'ipprotocol' => 'inet',
                    'descr' => 'WGX HA Sync: Allow XMLRPC from Primary Node',
                    'interface' => $interface,
                    'tcpflags_any' => false,
                    'protocol' => 'tcp',
                    'source' => ['address' => $primary_ip],
                    'destination' => ['network' => '(self)', 'port' => '443'],
                    'created' => ['time' => time(), 'username' => $_SESSION['Username'] ?? 'admin']
                ];

                $config['filter']['rule'][] = $new_rule;
                write_config("WG Export Tool: Auto-created HA Sync firewall rule for {$primary_ip}");

                filter_configure();
                syslog(LOG_NOTICE, "WGX HA Sync: Auto-configured firewall to allow {$primary_ip} on {$interface}");

                ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Success! Firewall rule created and applied automatically. The Primary Node can now reach this Backup Node.']);
                exit;
            } else {
                ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'A firewall rule allowing that IP already exists on this node.']);
                exit;
            }
        }

        if ($_POST['action'] === 'delete_peer') {
            if (!csrf_check(false)) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']); exit;
            }

            $idx = (int)($_POST['idx'] ?? -1);
            $a_peers = wgx_get_config_array('peer');

            if (!isset($a_peers[$idx])) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Peer not found.']); exit;
            }

            $tun = $a_peers[$idx]['tun'] ?? '';
            $pubkey = $a_peers[$idx]['publickey'] ?? '';

            // Drop the peer from the active kernel interface first
            if (!empty($tun) && !empty($pubkey)) {
                wgx_wg_exec($wg_bin, ['set', $tun, 'peer', $pubkey, 'remove']);
            }

            // Remove the peer from the configuration array and re-index
            unset($a_peers[$idx]);
            $a_peers = array_values($a_peers);

            global $config;
            if (function_exists('config_set_path')) {
                config_set_path('installedpackages/wireguard/peers/item', $a_peers);
            } else {
                $config['installedpackages']['wireguard']['peers']['item'] = $a_peers;
            }

            write_config("WG Suite: Permanently deleted peer");
            sync_package("wireguard");

            @include_once('/usr/local/pkg/wireguard/includes/wg_globals.inc');
            @include_once('/usr/local/pkg/wireguard/includes/wg.inc');
            @include_once('/usr/local/pkg/wireguard/includes/wg_service.inc');

            if (function_exists('wg_resync')) {
                wg_resync($tun, true);
            } elseif (function_exists('setup_wg')) {
                setup_wg();
            }

            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Peer deleted successfully.']);
            exit;
        }

        if ($_POST['action'] === 'kill_peer') {
            if (!csrf_check(false)) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']); exit;
            }

            $pubkey = trim($_POST['pubkey'] ?? '');
            $tun = trim($_POST['tun'] ?? '');

            wgx_wg_exec($wg_bin, ['set', $tun, 'peer', $pubkey, 'remove']);

            global $config;
            $a_peers = wgx_get_config_array('peer');

            foreach ($a_peers as &$p) {
                if (($p['publickey'] ?? '') === $pubkey && ($p['tun'] ?? '') === $tun) {
                    $p['enabled'] = 'no';
                }
            }

            if (function_exists('config_set_path')) {
                config_set_path('installedpackages/wireguard/peers/item', $a_peers);
            } else {
                $config['installedpackages']['wireguard']['peers']['item'] = $a_peers;
            }

            write_config("WG Suite: Killed peer connection {$pubkey}");

            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Peer connection dropped and disabled.']);
            exit;
        }

        if ($_POST['action'] === 'rotate_keys') {
            if (!csrf_check(false)) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']); exit;
            }

            $idx = (int)($_POST['idx'] ?? -1);
            $a_peers = wgx_get_config_array('peer');

            if (!isset($a_peers[$idx])) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Peer not found.']); exit;
            }

            $pair = wgx_gen_keypair($wg_bin);
            if (empty($pair['pub'])) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Failed to generate keys.']); exit;
            }

            $tun_name = $a_peers[$idx]['tun'];
            $a_peers[$idx]['publickey'] = $pair['pub'];
            $a_peers[$idx]['privatekey'] = $pair['priv'];

            global $config;
            if (function_exists('config_set_path')) {
                config_set_path('installedpackages/wireguard/peers/item', $a_peers);
            } else {
                $config['installedpackages']['wireguard']['peers']['item'] = $a_peers;
            }

            write_config("WG Suite: Rotated keys for peer {$a_peers[$idx]['descr']}");
            sync_package("wireguard");

            @include_once('/usr/local/pkg/wireguard/includes/wg_globals.inc');
            @include_once('/usr/local/pkg/wireguard/includes/wg.inc');
            @include_once('/usr/local/pkg/wireguard/includes/wg_service.inc');

            if (function_exists('wg_resync')) {
                wg_resync($tun_name, true);
            } elseif (function_exists('setup_wg')) {
                setup_wg();
            }

            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Keys rotated successfully.', 'new_priv' => $pair['priv'], 'new_pub' => $pair['pub']]);
            exit;
        }

        if ($_POST['action'] === 'bulk_csv') {
            if (!csrf_check(false)) {
                ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
                exit;
            }

            $csvData = trim($_POST['csv_data'] ?? '');
            $tunName = trim($_POST['tun'] ?? '');

            if (empty($csvData) || empty($tunName)) {
                ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Missing data.']);
                exit;
            }

            $lines = explode("\n", $csvData);
            $processed = 0;
            $a_peers = wgx_get_config_array('peer');

            foreach ($lines as $line) {
                $cols = str_getcsv(trim($line));
                if (count($cols) >= 2) {
                    $descr = trim($cols[0]);
                    $ip = trim($cols[1]);

                    $ip_parts = explode('/', $ip);
                    if (is_ipaddr($ip_parts[0])) {
                        $pair = wgx_gen_keypair($wg_bin);
                        if (!empty($pair['pub'])) {
                            $new_peer = [
                                'enabled' => 'yes',
                                'tun' => $tunName,
                                'descr' => "AutoCSV: {$descr}",
                                'dynamic' => 'yes',
                                'endpoint' => '',
                                'port' => '',
                                'keepalive' => '25',
                                'publickey' => $pair['pub'],
                                'privatekey' => $pair['priv'],
                                'allowedips' => [
                                    'row' => [
                                        [
                                            'address' => $ip_parts[0],
                                            'mask' => '32',
                                            'descr' => ''
                                        ]
                                    ]
                                ]
                            ];
                            $a_peers[] = $new_peer;
                            $processed++;
                        }
                    }
                }
            }

            if ($processed > 0) {
                global $config;
                if (function_exists('config_set_path')) {
                    config_set_path('installedpackages/wireguard/peers/item', $a_peers);
                } else {
                    if (!isset($config['installedpackages']['wireguard']['peers']['item'])) {
                        $config['installedpackages']['wireguard']['peers']['item'] = [];
                    }
                    $config['installedpackages']['wireguard']['peers']['item'] = $a_peers;
                }
                write_config("WG Suite: Processed {$processed} peers from CSV");
                sync_package("wireguard");

                @include_once('/usr/local/pkg/wireguard/includes/wg_globals.inc');
                @include_once('/usr/local/pkg/wireguard/includes/wg.inc');
                @include_once('/usr/local/pkg/wireguard/includes/wg_service.inc');

                if (function_exists('wg_resync')) { wg_resync($tunName, true); } elseif (function_exists('setup_wg')) { setup_wg(); }
                if (function_exists('clear_subsystem_dirty')) { clear_subsystem_dirty('wireguard'); }
                @unlink('/tmp/wireguard.dirty');
            }

            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => "Provisioned {$processed} peers successfully."]);
            exit;
        }

        if ($_POST['action'] === 'email_peer') {
            if (!csrf_check(false)) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']); exit;
            }
            global $config;
            if (empty($config['notifications']['smtp']['ipaddress'])) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'SMTP not configured in pfSense System Settings.']); exit;
            }
            $to = trim($_POST['email'] ?? '');
            $conf_text = trim($_POST['conf'] ?? '');
            $peer_name = trim($_POST['name'] ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Invalid email address.']); exit;
            }
            $subject = "WireGuard VPN Configuration: {$peer_name}";
            $message = "Hello,\n\nHere is your WireGuard VPN configuration profile for: {$peer_name}\n\n";
            $message .= "=================================\n";
            $message .= $conf_text . "\n";
            $message .= "=================================\n\n";
            $message .= "Save this text as a .conf file or import it directly into your WireGuard client app.\n";

            if (function_exists('send_smtp_message')) {
                @send_smtp_message($message, $subject, $to);
                syslog(LOG_NOTICE, "WG Suite: Emailed configuration for {$peer_name} to {$to}");
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => true, 'message' => 'Configuration emailed successfully via pfSense SMTP.']); exit;
            } else {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Failed to trigger pfSense native mailer.']); exit;
            }
        }

        if ($_POST['action'] === 'add_peer') {
            if (!csrf_check(false)) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Refresh the page and try again.']); exit;
            }

            $valid_tunnels = wgx_valid_tunnel_names();
            $tun_name   = trim($_POST['tun'] ?? '');
            $publickey  = trim($_POST['publickey'] ?? '');
            $privatekey = trim($_POST['privatekey'] ?? '');
            $assigned_raw = trim($_POST['assignedip'] ?? '');
            $descr      = trim($_POST['descr'] ?? 'New Peer');
            $psk        = trim($_POST['presharedkey'] ?? '');
            $keepalive  = trim($_POST['keepalive'] ?? '25');
            $expiry_days = (int)($_POST['expiry'] ?? 0);

            if (!in_array($tun_name, $valid_tunnels, true)) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Invalid tunnel name.']); exit; }
            if (!preg_match(WGX_PUBKEY_REGEX, $publickey)) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Invalid WireGuard public key format.']); exit; }
            if ($keepalive !== '' && !ctype_digit($keepalive)) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Keep Alive must be a valid number.']); exit; }

            $settings = wgx_load_settings();
            if (($settings['enforce_psk'] === 'true') && empty($psk)) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Global Policy Violation: Pre-Shared Key is strictly required.']); exit;
            }

            $assigned_ips = array_filter(array_map('trim', explode(',', $assigned_raw)));
            $allowedips_array = [];
            foreach ($assigned_ips as $assigned) {
                if (strpos($assigned, '/') !== false) {
                    $parts = explode('/', $assigned, 2); $addr = trim($parts[0]); $mask = (string)(int)trim($parts[1]);
                } else { $addr = $assigned; $mask = '32'; }
                if (!is_ipaddr($addr) || (int)$mask < 0 || (int)$mask > 128) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => "Invalid IP address or mask: {$assigned}"]); exit; }
                $allowedips_array[] = [ 'address' => $addr, 'mask' => $mask, 'descr' => '' ];
            }
            if (empty($allowedips_array)) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'At least one Assigned IP is required.']); exit; }

            $descr = preg_replace('/[\x00-\x1F\x7F]/', '', $descr); $descr = substr($descr, 0, 128);

            $existing_peers = wgx_get_config_array('peer');
            foreach ($existing_peers as $ep) {
                if (is_array($ep) && ($ep['tun'] ?? '') === $tun_name && ($ep['publickey'] ?? '') === $publickey) {
                    ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'A peer with this public key already exists on that tunnel.']); exit;
                }
            }

            // IP conflict detection — reject before writing anything
            $ip_conflicts = wgx_check_ip_conflicts($tun_name, $allowedips_array);
            if (!empty($ip_conflicts)) {
                ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'IP Conflict Detected: ' . implode('; ', $ip_conflicts)
                ]);
                exit;
            }

            // Using strictly 'row' for PfSense Core Native XML parsing
            $new_peer = [
                'enabled'             => 'yes',
                'tun'                 => $tun_name,
                'descr'               => $descr,
                'dynamic'             => 'yes',
                'endpoint'            => '',
                'port'                => '',
                'keepalive'           => $keepalive,
                'publickey'           => $publickey,
                'allowedips'          => [
                    'row' => $allowedips_array
                ],
            ];

            if (!empty($privatekey)) {
                $new_peer['privatekey'] = $privatekey;
            }
            if (!empty($psk)) {
                $new_peer['presharedkey'] = $psk;
            }
            if ($expiry_days > 0) {
                $new_peer['expire_time'] = time() + ($expiry_days * 86400);
            }

            $a_peers = wgx_get_config_array('peer');
            $a_peers[] = $new_peer;

            global $config;
            if (function_exists('config_set_path')) {
                config_set_path('installedpackages/wireguard/peers/item', $a_peers);
            } else {
                if (!isset($config['installedpackages']['wireguard']['peers']['item']) || !is_array($config['installedpackages']['wireguard']['peers']['item'])) {
                    if (!isset($config['installedpackages'])) $config['installedpackages'] = [];
                    if (!isset($config['installedpackages']['wireguard'])) $config['installedpackages']['wireguard'] = [];
                    if (!isset($config['installedpackages']['wireguard']['peers'])) $config['installedpackages']['wireguard']['peers'] = [];
                    $config['installedpackages']['wireguard']['peers']['item'] = [];
                }
                $config['installedpackages']['wireguard']['peers']['item'] = $a_peers;
            }

            write_config("WG Export Tool: Provisioned peer '{$descr}' on tunnel '{$tun_name}'");
            $operator = $_SESSION['Username'] ?? 'unknown';
            syslog(LOG_NOTICE, "WireGuard Export Tool: Peer '{$descr}' provisioned on '{$tun_name}' by {$operator}");

            // DIRECT PEER KERNEL INJECTION
            $wg_bin_path = is_executable('/usr/local/bin/wg') ? '/usr/local/bin/wg' : '/usr/bin/wg';
            $wg_cmd = "{$wg_bin_path} set " . escapeshellarg($tun_name) . " peer " . escapeshellarg($publickey);
            if (!empty($psk)) {
                $tmp_psk = tempnam(sys_get_temp_dir(), 'wg_psk_');
                file_put_contents($tmp_psk, $psk);
                $wg_cmd .= " preshared-key " . escapeshellarg($tmp_psk);
            }
            if (!empty($keepalive)) { $wg_cmd .= " persistent-keepalive " . escapeshellarg($keepalive); }
            $allowed_ips_str = implode(',', array_map(function($ip) { return $ip['address'] . '/' . $ip['mask']; }, $allowedips_array));
            $wg_cmd .= " allowed-ips " . escapeshellarg($allowed_ips_str);
            mwexec($wg_cmd, true);
            if (isset($tmp_psk)) { @unlink($tmp_psk); }

            sync_package("wireguard");
            @include_once('/usr/local/pkg/wireguard/includes/wg_globals.inc');
            @include_once('/usr/local/pkg/wireguard/includes/wg.inc');
            @include_once('/usr/local/pkg/wireguard/includes/wg_service.inc');

            if (function_exists('wg_resync')) { wg_resync($tun_name, true); } elseif (function_exists('setup_wg')) { setup_wg(); }
            if (function_exists('clear_subsystem_dirty')) { clear_subsystem_dirty('wireguard'); }
            @unlink('/tmp/wireguard.dirty');

            // NAT AUTO-CREATION CHECK
            $a_tunnels_for_nat = wgx_get_config_array('tunnel');
            $tun_subnet = null;
            foreach ($a_tunnels_for_nat as $t) {
                if (($t['name'] ?? '') === $tun_name) {
                    $tun_addrs = isset($t['addresses']) && is_array($t['addresses']) ? $t['addresses'] : [];
                    $raw_row = $tun_addrs['item'] ?? ($tun_addrs['row'] ?? []);
                    if (is_array($raw_row) && !empty($raw_row)) {
                        if (isset($raw_row['address'])) {
                            $addr = $raw_row['address'];
                            $mask = (int)($raw_row['mask'] ?? 24);
                        } elseif (isset($raw_row[0]) && is_array($raw_row[0]) && isset($raw_row[0]['address'])) {
                            $addr = $raw_row[0]['address'];
                            $mask = (int)($raw_row[0]['mask'] ?? 24);
                        }
                        if (isset($addr) && is_ipaddr($addr)) {
                            $tun_subnet = gen_subnet($addr, $mask) . '/' . $mask;
                        }
                    }
                    break;
                }
            }

            if ($tun_subnet) {
                $nat_exists = false;
                if (!isset($config['nat']['outbound'])) { $config['nat']['outbound'] = []; }
                if (!isset($config['nat']['outbound']['rule']) || !is_array($config['nat']['outbound']['rule'])) {
                    $config['nat']['outbound']['rule'] = [];
                } elseif (!empty($config['nat']['outbound']['rule']) && !isset($config['nat']['outbound']['rule'][0])) {
                    $config['nat']['outbound']['rule'] = [$config['nat']['outbound']['rule']];
                }

                foreach ($config['nat']['outbound']['rule'] as $r) {
                    if (($r['source']['network'] ?? '') === $tun_subnet) {
                        $nat_exists = true;
                        break;
                    }
                }
                if (!$nat_exists) {
                    if (empty($config['nat']['outbound']['mode']) || $config['nat']['outbound']['mode'] === 'automatic') {
                        $config['nat']['outbound']['mode'] = 'hybrid';
                    }
                    $config['nat']['outbound']['rule'][] = [
                        'source'      => ['network' => $tun_subnet],
                        'sourceport'  => '',
                        'descr'       => "WGX Auto-NAT for {$tun_name}",
                        'target'      => '',
                        'interface'   => 'wan',
                        'destination' => ['any' => true],
                        'natport'     => '',
                        'created'     => make_config_revision_entry()
                    ];
                    write_config("WGX: Auto-created outbound NAT for {$tun_name}");
                    filter_configure();
                }
            }

            $sync_msg = ".";
            if (!empty($settings['sync_enable']) && $settings['sync_enable'] === 'true') {
                $sync_status = wgx_sync_to_backup($new_peer);
                if (!$sync_status) { $sync_msg = " (HA Sync Failed. Queued for background processing)."; }
            }

            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => "Peer provisioned and injected into kernel" . $sync_msg
            ]);
            exit;
        }
    } catch (\Throwable $e) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'PHP Error: ' . $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    ob_start();
    try {
        if ($_GET['action'] === 'gen_keys') {
            if (!wgx_check_rate_limit()) { ob_end_clean(); header('Content-Type: application/json'); http_response_code(429); echo json_encode(['error' => 'Rate limit exceeded.']); exit; }
            if (empty($wg_bin)) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['error' => 'wg binary not found.']); exit; }
            $pair = wgx_gen_keypair($wg_bin); $psk  = wgx_wg_exec($wg_bin, ['genpsk']);
            ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['priv' => $pair['priv'], 'pub' => $pair['pub'], 'psk' => $psk]); exit;
        }

        if ($_GET['action'] === 'gen_psk') {
            if (!wgx_check_rate_limit()) { ob_end_clean(); header('Content-Type: application/json'); http_response_code(429); echo json_encode(['error' => 'Rate limit exceeded.']); exit; }
            if (empty($wg_bin)) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['error' => 'wg binary not found.']); exit; }
            $psk = wgx_wg_exec($wg_bin, ['genpsk']);
            ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['psk' => $psk]); exit;
        }

        if ($_GET['action'] === 'get_conf_data' && isset($_GET['peer_idx'])) {
            $idx       = (int)$_GET['peer_idx'];
            $a_tunnels = wgx_get_config_array('tunnel');
            $a_peers   = wgx_get_config_array('peer');

            if (!isset($a_peers[$idx]) || !is_array($a_peers[$idx])) { ob_end_clean(); header('Content-Type: application/json'); http_response_code(404); echo json_encode(['error' => 'Peer not found.']); exit; }

            $peer       = $a_peers[$idx];
            $server_tun = null;

            foreach ($a_tunnels as $t) { if (is_array($t) && ($t['name'] ?? '') === ($peer['tun'] ?? '')) { $server_tun = $t; break; } }

            if (!$server_tun) { ob_end_clean(); header('Content-Type: application/json'); http_response_code(404); echo json_encode(['error' => 'Tunnel not found.']); exit; }

            $resp = [
                'template'           => wgx_build_conf_template($peer, $server_tun),
                'default_endpoint'   => wgx_best_endpoint($server_tun) . ':' . ($server_tun['listenport'] ?? '51820'),
                'existing_psk'       => $peer['presharedkey'] ?? '',
                'existing_keepalive' => $peer['keepalive'] ?? '25',
                'existing_privkey'   => $peer['privatekey'] ?? ''
            ];

            ob_end_clean(); header('Content-Type: application/json'); echo json_encode($resp); exit;
        }

        if ($_GET['action'] === 'bulk_export') {
            if (!isset($_POST['__csrf_magic']) && isset($_GET['__csrf_magic'])) { $_POST['__csrf_magic'] = $_GET['__csrf_magic']; }

            $a_tunnels = wgx_get_config_array('tunnel');
            $a_peers   = wgx_get_config_array('peer');

            if (isset($_GET['selected_peers']) && $_GET['selected_peers'] !== '') {
                $raw_indices  = explode(',', $_GET['selected_peers']);
                $peer_indices = array_filter(array_map('intval', $raw_indices), function($i) use ($a_peers) { return isset($a_peers[$i]); });
            } else {
                $peer_indices = array_keys($a_peers);
            }

            $tmp_dir = sys_get_temp_dir() . '/wgx_' . bin2hex(random_bytes(8));
            mkdir($tmp_dir, 0700);

            try {
                foreach ($peer_indices as $idx) {
                    if (!isset($a_peers[$idx]) || !is_array($a_peers[$idx])) continue;

                    $peer = $a_peers[$idx];
                    $tun_name = $peer['tun'] ?? '';
                    $server_tun = null;

                    foreach ($a_tunnels as $t) { if (is_array($t) && ($t['name'] ?? '') === $tun_name) { $server_tun = $t; break; } }
                    if (!$server_tun) continue;

                    $conf = wgx_build_conf_template($peer, $server_tun);

                    $priv = !empty($peer['privatekey']) ? $peer['privatekey'] : '<INSERT_PRIVATE_KEY_HERE>';
                    $ep_ip   = wgx_best_endpoint($server_tun);
                    $ep_port = !empty($server_tun['listenport']) ? (int)$server_tun['listenport'] : 51820;

                    $conf = str_replace('__PRIVATE_KEY_PLACEHOLDER__', $priv, $conf);
                    $conf = str_replace('__ENDPOINT_PLACEHOLDER__', "{$ep_ip}:{$ep_port}", $conf);
                    $conf = str_replace('__ALLOWEDIPS_PLACEHOLDER__', '0.0.0.0/0, ::/0', $conf);
                    $conf = str_replace('__DNS_PLACEHOLDER__', 'DNS = 8.8.8.8, 8.8.4.4', $conf);

                    $ka = !empty($peer['keepalive']) ? $peer['keepalive'] : '25';
                    $conf = str_replace('__KEEPALIVE_PLACEHOLDER__', $ka, $conf);

                    if (!empty($peer['presharedkey'])) {
                        $conf = str_replace('__PSK_PLACEHOLDER__', 'PresharedKey = ' + $peer['presharedkey'], $conf);
                    } else {
                        $conf = str_replace("__PSK_PLACEHOLDER__\n", '', $conf);
                    }

                    $safe_desc = preg_replace('/[^a-zA-Z0-9_-]/', '_', $peer['descr'] ?? "peer_{$idx}");
                    $safe_desc = ltrim($safe_desc, '._');
                    $filename  = "{$tmp_dir}/{$safe_desc}_{$idx}.conf";

                    file_put_contents($filename, $conf);
                }

                if (class_exists('ZipArchive')) {
                    $tmp_zip = tempnam(sys_get_temp_dir(), 'wgx_') . '.zip';
                    $zip     = new ZipArchive();
                    if ($zip->open($tmp_zip, ZipArchive::CREATE) !== true) throw new RuntimeException('Could not create ZIP archive.');
                    foreach (glob("{$tmp_dir}/*.conf") as $f) $zip->addFile($f, basename($f));
                    $zip->close();

                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="wireguard_peers.zip"');
                    header('Content-Length: ' . filesize($tmp_zip));
                    readfile($tmp_zip); unlink($tmp_zip);
                } else {
                    $tmp_tgz = tempnam(sys_get_temp_dir(), 'wgx_') . '.tar.gz';
                    $ret = null; passthru('tar -czf ' . escapeshellarg($tmp_tgz) . ' -C ' . escapeshellarg($tmp_dir) . ' .', $ret);
                    if ($ret !== 0) throw new RuntimeException('tar failed.');
                    header('Content-Type: application/gzip');
                    header('Content-Disposition: attachment; filename="wireguard_peers.tar.gz"');
                    header('Content-Length: ' . filesize($tmp_tgz));
                    readfile($tmp_tgz); unlink($tmp_tgz);
                }
            } finally {
                foreach (glob("{$tmp_dir}/*.conf") ?: [] as $f) unlink($f);
                if (is_dir($tmp_dir)) rmdir($tmp_dir);
            }
            exit;
        }
    } catch (\Throwable $e) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['error' => 'PHP Error: ' . $e->getMessage()]);
        exit;
    }
}

// =========================================================================
// 7. FETCH LIVE TELEMETRY
// =========================================================================

$wg_handshakes = [];
$wg_telemetry = [];

if (!empty($wg_bin)) {
    $raw = wgx_wg_exec($wg_bin, ['show', 'all', 'latest-handshakes']);
    if ($raw) {
        foreach (explode("\n", $raw) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 3 && preg_match(WGX_PUBKEY_REGEX, $parts[1])) {
                $wg_handshakes[$parts[1]] = (int)$parts[2];
            }
        }
    }

    $rawTx = wgx_wg_exec($wg_bin, ['show', 'all', 'transfer']);
    if ($rawTx) {
        $rawTx_lines = explode("\n", $rawTx);
        foreach ($rawTx_lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 4) {
                $wg_telemetry[$parts[1]] = [
                    'rx' => round($parts[2] / 1024 / 1024, 2),
                    'tx' => round($parts[3] / 1024 / 1024, 2)
                ];
            }
        }
    }
}

// =========================================================================
// 8. BUILD PAGE DATA & TABS
// =========================================================================

$dynamic_split_tunnel = wgx_get_local_subnets();
$pgtitle = [gettext("VPN"), gettext("WireGuard"), gettext("Export")];
$pglinks = [null, "/wg/vpn_wg_tunnels.php", "@self"];
include("head.inc");

$tab_array = array();
$tab_array[] = array(gettext("Tunnels"), false, "/wg/vpn_wg_tunnels.php");
$tab_array[] = array(gettext("Peers"), false, "/wg/vpn_wg_peers.php");
$tab_array[] = array(gettext("Settings"), false, "/wg/vpn_wg_settings.php");
$tab_array[] = array(gettext("Status"), false, "/wg/status_wireguard.php");
$tab_array[] = array(gettext("Dashboard"), false, "/wg/vpn_wg_dashboard.php");
$tab_array[] = array(gettext("Export"), true, "/wg/vpn_wg_export.php");
$tab_array[] = array(gettext("Setup"), false, "/wg/vpn_wg_setup.php");
display_top_tabs($tab_array);

$a_peers   = wgx_get_config_array('peer');
$a_tunnels = wgx_get_config_array('tunnel');

$tunnels_json = [];
foreach ($a_tunnels as $tun) {
    if (!is_array($tun)) continue;

    $ep_ip    = wgx_best_endpoint($tun);
    $ep_port  = !empty($tun['listenport']) ? (int)$tun['listenport'] : 51820;
    $tun_sub  = '';
    $tun_base = '';

    $ifaces = $config['interfaces'] ?? [];
    if (is_array($ifaces)) {
        foreach ($ifaces as $iface) {
            if (is_array($iface) && isset($iface['if'], $iface['ipaddr'], $iface['subnet']) && $iface['if'] === ($tun['name'] ?? '') && is_ipaddrv4($iface['ipaddr'])) {
                $tun_sub  = gen_subnet($iface['ipaddr'], $iface['subnet']) . '/' . $iface['subnet'];
                $tun_base = $iface['ipaddr'];
                break;
            }
        }
    }

    $tun_addrs = isset($tun['addresses']) && is_array($tun['addresses']) ? $tun['addresses'] : [];
    $raw_row = $tun_addrs['item'] ?? ($tun_addrs['row'] ?? []);
    if (empty($tun_sub) && is_array($raw_row) && !empty($raw_row)) {
        if (isset($raw_row['address'])) {
            $addr = $raw_row['address'];
            $mask = (int)($raw_row['mask'] ?? 24);
        } elseif (isset($raw_row[0]) && is_array($raw_row[0]) && isset($raw_row[0]['address'])) {
            $addr = $raw_row[0]['address'];
            $mask = (int)($raw_row[0]['mask'] ?? 24);
        }
        if (isset($addr) && is_ipaddr($addr)) {
            $tun_sub  = gen_subnet($addr, $mask) . '/' . $mask;
            $tun_base = $addr;
        }
    }

    // Use the proper free-list allocator instead of max()+1
    $next_ip = '';
    if (!empty($tun_base)) {
        if (is_ipaddrv4($tun_base)) {
            $allocated = wgx_allocate_ipv4($tun['name'] ?? '', $tun_base, $mask ?? 24);
            $next_ip = $allocated ?? 'Subnet full';
        } elseif (is_ipaddrv6($tun_base)) {
            $allocated = wgx_allocate_ipv6($tun['name'] ?? '', $tun_base, $mask ?? 64);
            $next_ip = $allocated ?? 'Subnet full';
        }
    }

    $tunnels_json[] = [
        'name'    => $tun['name'] ?? '',
        'pubkey'  => $tun['publickey'] ?? '',
        'endpoint'=> "{$ep_ip}:{$ep_port}",
        'subnet'  => $tun_sub,
        'next_ip' => $next_ip,
    ];
}

$auto_open_idx  = null;
$auto_open_name = null;
if (isset($_GET['provision_idx']) && ctype_digit((string)$_GET['provision_idx'])) {
    $idx = (int)$_GET['provision_idx'];
    if (isset($a_peers[$idx]) && is_array($a_peers[$idx])) {
        $auto_open_idx  = $idx;
        $auto_open_name = $a_peers[$idx]['descr'] ?? "Peer {$idx}";
    }
}

$wgx_settings = wgx_load_settings();

// Retrieve all local interfaces for the Firewall Setup dropdown
$local_interfaces = get_configured_interface_with_descr();

// Auto-Update Check Banner Logic
$update_data = false;
$update_file = '/var/db/wgx_update_available.json';
if (file_exists($update_file)) {
    $update_data = json_decode(file_get_contents($update_file), true);
}
?>

<style>
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
}
.status-pulse {
    animation: pulse 1.5s infinite;
    color: #5cb85c;
}
#qrcode_canvas img, #qrcode_canvas canvas {
    border-radius: 4px;
}
.wizard-tab {
    display: none;
}
.wizard-tab.active {
    display: block;
}
/* Fix for pfSense Bootstrap input-group buttons sticking out */
.input-group-btn .btn {
    margin-bottom: 0 !important;
    border-radius: 0 4px 4px 0 !important;
}
.input-group-btn .dropdown-toggle {
    border-radius: 0 4px 4px 0 !important;
}
.btn-rot {
    background-color: #8e44ad;
    color: white;
    border-color: #732d91;
}
.btn-rot:hover {
    background-color: #732d91;
    color: white;
}
</style>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title"><?= gettext("WireGuard Provisioning & Export") ?></h2>
    </div>
    <div class="panel-body">

        <?php if ($update_data && version_compare($update_data['version'], WGX_VERSION, '>')): ?>
        <div class="alert alert-info" id="updateBanner">
            <strong><i class="fa fa-arrow-circle-up"></i> Update Available!</strong>
            Version <?= htmlspecialchars($update_data['version']) ?> of the WireGuard Suite is available.
            <?php if(!empty($update_data['notes'])): ?><br><em>Release Notes: <?= htmlspecialchars($update_data['notes']) ?></em><?php endif; ?>
            <div style="margin-top: 10px;">
                <button class="btn btn-sm btn-success" id="btnInstallUpdate" onclick="installUpdate('<?= htmlspecialchars($update_data['url']) ?>')">
                    <i class="fa fa-download"></i> Download & Install Now
                </button>
                <button class="btn btn-sm btn-default" onclick="dismissUpdate()">Dismiss</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="row" style="margin-bottom:15px;">
            <div class="col-sm-3">
                <div class="input-group">
                    <span class="input-group-addon"><i class="fa fa-search"></i></span>
                    <input type="text" id="searchPeers" class="form-control" placeholder="Search by name, tunnel, or IP…">
                </div>
            </div>
            <div class="col-sm-9 text-right">
                <button class="btn btn-rot" onclick="openGlobalSettings()" title="Global Policies" style="color: #ffffff !important;">
                    <i class="fa fa-cog icon-embed-btn" style="color: #ffffff !important;"></i> Global Settings
                </button>
                <button class="btn btn-warning" onclick="openHaSyncWizard()" title="Setup High Availability" style="color: #ffffff !important;">
                    <i class="fa fa-refresh icon-embed-btn" style="color: #ffffff !important;"></i> HA Sync Wizard
                </button>
                <button class="btn btn-info" onclick="openCsvModal()" style="color: #ffffff !important;">
                    <i class="fa fa-table icon-embed-btn" style="color: #ffffff !important;"></i> Bulk CSV
                </button>
                <button class="btn btn-info" onclick="document.getElementById('importConfFileMain').click()" style="color: #ffffff !important;" title="Upload an existing .conf file">
                    <i class="fa fa-upload icon-embed-btn" style="color: #ffffff !important;"></i> Import .conf
                </button>
                <input type="file" id="importConfFileMain" style="display:none" accept=".conf,.txt" onchange="handleConfUpload(event)">
                <button class="btn btn-success" onclick="openAddPeerModal()" style="color: #ffffff !important;">
                    <i class="fa fa-plus icon-embed-btn" style="color: #ffffff !important;"></i> Add New Peer
                </button>
                <button class="btn btn-primary" onclick="downloadAll()" style="color: #ffffff !important;">
                    <i class="fa fa-archive icon-embed-btn" style="color: #ffffff !important;"></i> Download All
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover table-condensed" id="peersTable">
                <thead>
                    <tr>
                        <th style="width: 30px; padding-left: 15px;"><input type="checkbox" id="selectAll" title="Select all"></th>
                      <th>Status</th>
                      <th>Description</th>
                      <th>Tunnel</th>
                      <th>Assigned IPs</th>
                      <th class="text-center">Data (Rx/Tx)</th>
                      <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($a_peers)): ?>
                    <tr>
                        <td colspan="7" class="text-center">No WireGuard peers configured.</td>
                    </tr>
                <?php else: foreach ($a_peers as $idx => $peer):
                    if (!is_array($peer)) continue;

                    $display_desc = htmlspecialchars($peer['descr'] ?? "Peer {$idx}", ENT_QUOTES, 'UTF-8');
                    $display_tun  = htmlspecialchars($peer['tun'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
                    $pubkey       = $peer['publickey'] ?? '';

                    if (isset($wg_telemetry[$pubkey])) {
                        $rx = $wg_telemetry[$pubkey]['rx'];
                        $tx = $wg_telemetry[$pubkey]['tx'];
                    } else {
                        $rx = 0;
                        $tx = 0;
                    }

                    if (!empty($pubkey) && isset($wg_handshakes[$pubkey]) && $wg_handshakes[$pubkey] > 0) {
                        $diff = time() - $wg_handshakes[$pubkey];
                        if ($diff < 180) {
                            $status_html = '<strong><i class="fa fa-circle status-pulse"></i> Online</strong>';
                        } elseif ($diff < 86400) {
                            $status_html = '<span class="text-warning"><i class="fa fa-clock-o"></i> ' . round($diff / 60) . ' min ago</span>';
                        } else {
                            $status_html = '<span class="text-warning"><i class="fa fa-clock-o"></i> ' . round($diff / 86400) . ' day(s) ago</span>';
                        }
                    } else {
                        $status_html = '<span class="text-muted"><i class="fa fa-circle-o"></i> Offline</span>';
                    }

                    if (isset($peer['enabled']) && $peer['enabled'] === 'no') {
                        $status_html = '<span class="text-danger"><i class="fa fa-ban"></i> Disabled</span>';
                    }

                    $ip_parts = [];
                    $allowedips = isset($peer['allowedips']) && is_array($peer['allowedips']) ? $peer['allowedips'] : [];
                    $raw_allowedips = $allowedips['row'] ?? ($allowedips['item'] ?? []);
                    if (is_array($raw_allowedips) && !empty($raw_allowedips)) {
                        $rows = isset($raw_allowedips['address']) ? [$raw_allowedips] : $raw_allowedips;
                        foreach ($rows as $row) {
                            if (is_array($row) && !empty($row['address'])) {
                                $ip_parts[] = htmlspecialchars($row['address'] . (!empty($row['mask']) ? '/' . $row['mask'] : ''), ENT_QUOTES, 'UTF-8');
                            }
                        }
                    }

                    $json_name = htmlspecialchars(json_encode($peer['descr'] ?? "Peer {$idx}"), ENT_QUOTES, 'UTF-8');
                ?>
                <tr>
                    <td><input type="checkbox" class="peer-checkbox" value="<?= $idx ?>"></td>
                    <td><?= $status_html ?></td>
                    <td><strong><?= $display_desc ?></strong></td>
                    <td><?= $display_tun ?></td>
                    <td><?= implode(', ', $ip_parts) ?></td>
                    <td style="white-space: nowrap;" class="text-center">
                    <i class="fa fa-arrow-down text-success"></i> <?= $rx ?>MB /
                    <i class="fa fa-arrow-up text-info"></i> <?= $tx ?>MB
                    </td>
                    <td class="text-center">
                        <button class="btn btn-xs btn-info" onclick="openExportModal(<?= $idx ?>, <?= $json_name ?>)" title="Export Config">
                            <i class="fa fa-qrcode"></i>
                        </button>
                        <button class="btn btn-xs btn-rot" onclick="rotateKeys(<?= $idx ?>, <?= $json_name ?>)" title="Rotate Keys">
                            <i class="fa fa-refresh"></i>
                        </button>
                        <button class="btn btn-xs btn-primary" onclick="openEmailModal(<?= $idx ?>, <?= $json_name ?>)" title="Email Config">
                            <i class="fa fa-envelope"></i>
                        </button>
                        <button class="btn btn-xs btn-warning" onclick="killPeer('<?= htmlspecialchars($display_tun) ?>', '<?= htmlspecialchars($pubkey) ?>')" title="Kill Connection">
                            <i class="fa fa-bolt"></i>
                        </button>
                        <button class="btn btn-xs btn-danger" onclick="deletePeer(<?= $idx ?>, <?= $json_name ?>)" title="Delete Peer">
                            <i class="fa fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="panel-footer text-right">
        <small class="text-muted">WG Suite v<?= WGX_VERSION ?></small>
    </div>
</div>

<div class="modal fade" id="globalSettingsModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">Global Security Policies</h4>
            </div>
            <div class="modal-body form-horizontal">
                <div class="form-group">
                    <label class="col-sm-4 control-label">Fallback Split Subnets</label>
                    <div class="col-sm-8">
                        <input type="text" id="fallbackSubnets" class="form-control" value="<?= htmlspecialchars($wgx_settings['fallback_subnets']) ?>">
                        <span class="help-block">Default fallback subnets used for split tunneling if dynamic local subnets aren't detected.</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-4 control-label">Enforce PSK</label>
                    <div class="col-sm-8 checkbox">
                        <label>
                            <input type="checkbox" id="setEnforcePsk" <?= ($wgx_settings['enforce_psk'] === 'true') ? 'checked' : '' ?>>
                            <small>Force generation of Pre-Shared Key.</small>
                        </label>
                    </div>
                </div>
                <hr>
                <div class="form-group">
                    <label class="col-sm-4 control-label">Auto-Update Checks</label>
                    <div class="col-sm-8">
                        <select id="updateFreqSelect" class="form-control">
                            <option value="never" <?= ($wgx_settings['update_freq'] === 'never') ? 'selected' : '' ?>>Never (Manual Only)</option>
                            <option value="daily" <?= ($wgx_settings['update_freq'] === 'daily') ? 'selected' : '' ?>>Daily Check</option>
                            <option value="weekly" <?= ($wgx_settings['update_freq'] === 'weekly') ? 'selected' : '' ?>>Weekly Check</option>
                        </select>
                        <span class="help-block">Check GitHub for new versions automatically in the background.</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-success" onclick="saveGlobalSettings()">Save Policies</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="csvModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">Bulk CSV Import</h4>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    Format required: <code>Name, IPAddress</code> (e.g. <code>John Doe, 10.0.0.50/32</code>). Keys will be securely generated for you automatically.
                </div>
                <div class="form-group">
                    <label>Select Target Tunnel</label>
                    <select id="csvTunnelSelect" class="form-control">
                        <?php foreach($tunnels_json as $t): ?>
                            <option value="<?=htmlspecialchars($t['name'])?>"><?=htmlspecialchars($t['name'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>CSV Data</label>
                    <textarea id="csvDataInput" class="form-control" rows="8" placeholder="Alice, 10.0.0.51/32&#10;Bob, 10.0.0.52/32"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="processCsv()">Process Import</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="haWizardModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">HA Sync Configuration Wizard</h4>
            </div>
            <div class="modal-body form-horizontal">

                <ul class="nav nav-tabs" style="margin-bottom: 15px;">
                    <li class="active"><a href="#" onclick="switchWizardTab('primary', this); return false;">I am the Primary Node</a></li>
                    <li><a href="#" onclick="switchWizardTab('backup', this); return false;">I am the Backup Node</a></li>
                </ul>

                <div id="tab-primary" class="wizard-tab active">
                    <p class="text-muted text-center" style="margin-bottom: 20px;">Configure this node to automatically push newly provisioned peers to your Backup firewall.</p>
                    <div class="form-group">
                        <label class="col-sm-4 control-label">Enable Push Sync</label>
                        <div class="col-sm-8 checkbox">
                            <label><input type="checkbox" id="haSyncEnable" <?= (!empty($wgx_settings['sync_enable']) && $wgx_settings['sync_enable'] === 'true') ? 'checked' : '' ?>></label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-4 control-label">Backup Node IP</label>
                        <div class="col-sm-8">
                            <input type="text" id="haSyncIP" class="form-control" placeholder="e.g. 192.168.1.14" value="<?=htmlspecialchars($wgx_settings['sync_ip'] ?? '', ENT_QUOTES, 'UTF-8')?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-4 control-label">Admin Username</label>
                        <div class="col-sm-8">
                            <input type="text" id="haSyncUser" class="form-control" value="<?=htmlspecialchars($wgx_settings['sync_user'] ?? 'admin', ENT_QUOTES, 'UTF-8')?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-4 control-label">Admin Password</label>
                        <div class="col-sm-8">
                            <input type="password" id="haSyncPass" class="form-control" <?= !empty($wgx_settings['sync_pass']) ? 'placeholder="[Password Saved - Leave blank to keep]"' : 'placeholder="Enter Admin Password"' ?>>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-4 control-label">Strict TLS Verify</label>
                        <div class="col-sm-8 checkbox">
                            <label><input type="checkbox" id="haSyncTls" <?= (!empty($wgx_settings['strict_tls']) && $wgx_settings['strict_tls'] === 'true') ? 'checked' : '' ?>> <small>(Prevents MITM. Uncheck if backup node uses self-signed certs)</small></label>
                        </div>
                    </div>
                    <hr>
                    <div class="text-right">
                        <button class="btn btn-success" id="btnSavePrimary" onclick="savePrimaryNode()">Save Settings</button>
                    </div>
                </div>

                <div id="tab-backup" class="wizard-tab">
                    <div class="alert alert-info">
                        <strong>Auto-Firewall Setup</strong><br>
                        This will safely punch a hole in this node's firewall to allow the Primary Node to push configurations to it over HTTPS.
                    </div>
                    <div class="form-group">
                        <label class="col-sm-4 control-label">Primary Node IP</label>
                        <div class="col-sm-8">
                            <input type="text" id="primaryIpInput" class="form-control" placeholder="e.g. 192.168.1.1">
                            <span class="help-block">Only this exact IP will be allowed to connect.</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-4 control-label">Target Interface</label>
                        <div class="col-sm-8">
                            <select id="backupInterfaceSelect" class="form-control">
                                <?php foreach ($local_interfaces as $iface => $ifacename): ?>
                                    <option value="<?= htmlspecialchars($iface) ?>"><?= htmlspecialchars($ifacename) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <hr>
                    <div class="text-right">
                        <button class="btn btn-primary" id="btnSaveBackup" onclick="configureBackupFirewall()"><i class="fa fa-shield"></i> Build Firewall Rule</button>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="emailModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title" id="emailModalLabel">Email Configuration</h4>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">Ensure SMTP is configured in System > Advanced > Notifications for this feature to work.</div>
                <div class="form-group">
                    <label>Recipient Email Address</label>
                    <input type="email" id="emailTarget" class="form-control" placeholder="user@domain.com">
                </div>
                <input type="hidden" id="emailConfData">
                <input type="hidden" id="emailPeerName">
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="sendEmailReq()" id="btnSendMail"><i class="fa fa-paper-plane"></i> Send Configuration</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="exportModal" tabindex="-1" role="dialog" aria-labelledby="exportModalLabel" data-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button class="close" onclick="closeModalAndReload()"><span>&times;</span></button>
                <h4 class="modal-title" id="exportModalLabel">WireGuard Peer</h4>
            </div>
            <div class="modal-body">

                <div class="row" id="rowAddNewParams" style="display:none;">
                    <div class="col-sm-3">
                        <div class="form-group">
                            <label><i class="fa fa-server"></i> Tunnel</label>
                            <select id="tunnelSelect" class="form-control" onchange="onTunnelChange()"></select>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="form-group">
                            <label><i class="fa fa-tag"></i> Description</label>
                            <input id="peerDescription" type="text" class="form-control" placeholder="e.g. Alice's iPhone" oninput="updateDisplays()">
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="form-group">
                            <label><i class="fa fa-sitemap"></i> Assigned IP(s)</label>
                            <input id="peerAssignedIP" type="text" class="form-control" placeholder="e.g. 10.0.0.5/32" oninput="updateDisplays()">
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="form-group">
                            <label><i class="fa fa-clock-o"></i> Expiry (Days)</label>
                            <input id="peerExpiry" type="number" class="form-control" placeholder="0 (Never)">
                        </div>
                    </div>
                </div>

                <div class="row" id="rowKeyParams">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label><i class="fa fa-key"></i> Client Public Key</label>
                            <input id="clientPubKey" type="text" class="form-control" readonly>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label><i class="fa fa-lock"></i> Client Private Key</label>
                            <div class="input-group">
                                <input id="clientPrivKey" type="text" class="form-control" placeholder="Paste private key to unlock QR…" oninput="updateDisplays()">
                                <span class="input-group-btn" id="btnWrapGenKeys" style="display:none;">
                                    <button class="btn btn-warning" onclick="refreshKeys()" title="Generate new keypair">
                                        <i class="fa fa-refresh"></i>
                                    </button>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row" id="rowRouteParams">
                    <div class="col-sm-4">
                        <div class="form-group">
                            <label><i class="fa fa-exchange"></i> Client Allowed IPs</label>
                            <div class="input-group">
                                <input id="clientAllowedIPs" type="text" class="form-control" value="0.0.0.0/0, ::/0" oninput="updateDisplays()">
                                <div class="input-group-btn">
                                    <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span class="caret"></span></button>
                                    <ul class="dropdown-menu dropdown-menu-right">
                                        <li><a href="#" onclick="setClientAllowedIPs('0.0.0.0/0, ::/0'); return false;">Full Tunnel (All Traffic)</a></li>
                                        <li><a href="#" onclick="setSplitTunnel(); return false;">Split Tunnel (LAN Only)</a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="form-group">
                            <label><i class="fa fa-globe"></i> Endpoint Override</label>
                            <input id="endpointOverride" type="text" class="form-control" placeholder="host:port" oninput="updateDisplays()">
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="form-group">
                            <label>
                                <span id="pskCheckboxWrapper" style="display:none;">
                                    <input type="checkbox" id="pskEnabled" onchange="togglePsk(this)">
                                </span>
                                <i class="fa fa-shield"></i> Pre-Shared Key
                            </label>
                            <div class="input-group">
                                <input id="clientPsk" type="text" class="form-control" oninput="updateDisplays()">
                                <span class="input-group-btn" id="btnWrapGenPsk" style="display:none;">
                                    <button class="btn btn-warning" id="refreshPskBtn" onclick="refreshPsk()" disabled title="Generate PSK">
                                        <i class="fa fa-refresh"></i>
                                    </button>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row" id="rowDnsParams">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label><i class="fa fa-wifi"></i> DNS (optional)</label>
                            <input id="peerDNS" type="text" class="form-control" placeholder="e.g. 1.1.1.1, 8.8.8.8" oninput="updateDisplays()">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label><i class="fa fa-heartbeat"></i> Persistent Keep Alive</label>
                            <input id="peerKeepAlive" type="number" class="form-control" placeholder="25" value="25" oninput="updateDisplays()">
                        </div>
                    </div>
                </div>

                <hr>

                <div class="row">
                    <div class="col-sm-4 text-center">
                        <p><strong>Mobile QR Code</strong></p>
                        <div id="qrcode_canvas" style="display:inline-block;padding:15px;border-radius:5px;background:#fff;min-height:120px;"></div>
                    </div>
                    <div class="col-sm-8">
                        <p><strong>Raw Configuration</strong></p>
                        <textarea id="confText" class="form-control" rows="9" readonly style="font-family:monospace;font-size:12px;"></textarea>
                        <br>
                        <div class="row">
                            <div class="col-sm-6" id="btnWrapDownload">
                                <button class="btn btn-primary btn-block" onclick="downloadConfFile()">
                                    <i class="fa fa-download icon-embed-btn"></i> Download .conf
                                </button>
                            </div>
                            <div class="col-sm-6" id="btnWrapAddPeer" style="display:none;">
                                <button class="btn btn-success btn-block" id="btnAddPeer" onclick="addPeerToTunnel()">
                                    <i class="fa fa-save icon-embed-btn"></i> Provision & Save
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button class="btn btn-default" onclick="closeModalAndReload()">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="/wg_qrcode.js"></script>

<script>
const tunnelsData  = <?= json_encode($tunnels_json, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const dynamicSplit = "<?= htmlspecialchars($dynamic_split_tunnel, ENT_QUOTES, 'UTF-8') ?>";
let rawTemplateText = "";
let defaultEndpoint = "";
let currentPeerName = "";
let modalMode = "export";

function getCsrf() {
    if (typeof csrfMagicToken !== 'undefined') {
        return csrfMagicToken;
    }
    const el = document.querySelector("input[name='__csrf_magic']");
    return el ? el.value : '';
}

function installUpdate(url) {
    const btn = document.getElementById('btnInstallUpdate');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Downloading & Installing...';

    const body = new URLSearchParams({
        action: 'do_update',
        __csrf_magic: getCsrf(),
        pkg_url: url
    });

    fetch('/wg/vpn_wg_export.php', { method: 'POST', body: body })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if(data.success) {
                location.reload();
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-download"></i> Download & Install Now';
            }
        }).catch(e => {
            alert("Update Failed: " + e.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-download"></i> Download & Install Now';
        });
}

function dismissUpdate() {
    document.getElementById('updateBanner').style.display = 'none';
    const body = new URLSearchParams({
        action: 'dismiss_update',
        __csrf_magic: getCsrf()
    });
    fetch('/wg/vpn_wg_export.php', { method: 'POST', body: body });
}

document.getElementById('selectAll').addEventListener('change', function () {
    document.querySelectorAll('.peer-checkbox').forEach(c => c.checked = this.checked);
});

document.getElementById('searchPeers').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#peersTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

function downloadSelected() {
    const sel = [...document.querySelectorAll('.peer-checkbox:checked')].map(c => c.value);
    if (!sel.length) {
        alert("Select at least one peer.");
        return;
    }
    window.location.href = `/wg/vpn_wg_export.php?action=bulk_export&selected_peers=${encodeURIComponent(sel.join(','))}&__csrf_magic=${encodeURIComponent(getCsrf())}`;
}

function downloadAll() {
    window.location.href = `/wg/vpn_wg_export.php?action=bulk_export&__csrf_magic=${encodeURIComponent(getCsrf())}`;
}

function openGlobalSettings() {
    $('#globalSettingsModal').modal('show');
}

function saveGlobalSettings() {
    const body = new URLSearchParams();
    body.append('action', 'save_global');
    body.append('__csrf_magic', getCsrf());

    if (document.getElementById('haSyncEnable') && document.getElementById('haSyncEnable').checked) {
        body.append('sync_enable', 'true');
    } else {
        body.append('sync_enable', 'false');
    }
    if (document.getElementById('haSyncIP')) body.append('sync_ip', document.getElementById('haSyncIP').value);
    if (document.getElementById('haSyncUser')) body.append('sync_user', document.getElementById('haSyncUser').value);
    if (document.getElementById('haSyncPass')) body.append('sync_pass', document.getElementById('haSyncPass').value);

    if (document.getElementById('haSyncTls') && document.getElementById('haSyncTls').checked) {
        body.append('strict_tls', 'true');
    } else {
        body.append('strict_tls', 'false');
    }
    if (document.getElementById('setEnforcePsk') && document.getElementById('setEnforcePsk').checked) {
        body.append('enforce_psk', 'true');
    } else {
        body.append('enforce_psk', 'false');
    }
    if (document.getElementById('fallbackSubnets')) {
        body.append('fallback_subnets', document.getElementById('fallbackSubnets').value);
    }
    if (document.getElementById('updateFreqSelect')) {
        body.append('update_freq', document.getElementById('updateFreqSelect').value);
    }

    fetch('/wg/vpn_wg_export.php', { method: 'POST', body: body })
        .then(async r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

function openCsvModal() {
    $('#csvModal').modal('show');
}

function processCsv() {
    const data = document.getElementById('csvDataInput').value.trim();
    const tun = document.getElementById('csvTunnelSelect').value;

    if (!data) {
        alert("Please enter CSV data.");
        return;
    }

    const body = new URLSearchParams({
        action: 'bulk_csv',
        __csrf_magic: getCsrf(),
        csv_data: data,
        tun: tun
    });

    fetch('/wg/vpn_wg_export.php', { method: 'POST', body: body })
        .then(async r => r.json())
        .then(resp => {
            alert(resp.message);
            if (resp.success) {
                location.reload();
            }
        })
        .catch(e => alert("Error processing CSV."));
}

// === WIZARD FUNCTIONS ===
function openHaSyncWizard() {
    $('#haWizardModal').modal('show');
}

function switchWizardTab(tabId, element) {
    document.querySelectorAll('.wizard-tab').forEach(tab => tab.classList.remove('active'));
    document.getElementById('tab-' + tabId).classList.add('active');

    document.querySelectorAll('#haWizardModal .nav-tabs li').forEach(li => li.classList.remove('active'));
    element.parentElement.classList.add('active');
}

function savePrimaryNode() {
    const btn = document.getElementById('btnSavePrimary');
    const oldText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

    const body = new URLSearchParams({
        action: 'save_sync',
        __csrf_magic: getCsrf(),
        sync_enable: document.getElementById('haSyncEnable').checked,
        sync_ip: document.getElementById('haSyncIP').value,
        sync_user: document.getElementById('haSyncUser').value,
        sync_pass: document.getElementById('haSyncPass').value
    });

    fetch('/wg/vpn_wg_export.php', { method: 'POST', body })
        .then(async r => {
            if (!r.ok) throw new Error("Server Error: " + r.status);
            return r.json();
        })
        .then(data => {
            alert(data.message);
            if (data.success) {
                document.getElementById('haSyncPass').placeholder = '[Password Saved - Leave blank to keep]';
                document.getElementById('haSyncPass').value = '';
                $('#haWizardModal').modal('hide');
            }
        })
        .catch(e => {
            alert(e.message);
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = oldText;
        });
}

function configureBackupFirewall() {
    const primaryIp = document.getElementById('primaryIpInput').value.trim();
    if (!primaryIp) {
        alert("Please enter the Primary Node IP Address.");
        return;
    }

    const btn = document.getElementById('btnSaveBackup');
    const oldText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Building...';

    const body = new URLSearchParams({
        action: 'setup_backup_firewall',
        __csrf_magic: getCsrf(),
        primary_ip: primaryIp,
        interface: document.getElementById('backupInterfaceSelect').value
    });

    fetch('/wg/vpn_wg_export.php', { method: 'POST', body })
        .then(async r => {
            if (!r.ok) throw new Error("Server Error: " + r.status);
            return r.json();
        })
        .then(data => {
            alert(data.message);
            if (data.success) {
                $('#haWizardModal').modal('hide');
            }
        })
        .catch(e => {
            alert(e.message);
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = oldText;
        });
}

function setClientAllowedIPs(val) {
    document.getElementById('clientAllowedIPs').value = val;
    updateDisplays();
}

function setSplitTunnel() {
    setClientAllowedIPs(dynamicSplit);
}

function setUIState(mode) {
    modalMode = mode;

    ['clientPrivKey','endpointOverride','clientPsk','clientPubKey','peerDescription','peerAssignedIP','peerExpiry'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });

    document.getElementById('clientAllowedIPs').value = '0.0.0.0/0, ::/0';
    document.getElementById('peerDNS').value = '8.8.8.8, 8.8.4.4';
    document.getElementById('peerKeepAlive').value = mode === 'add' ? '25' : '';
    document.getElementById('confText').value = 'Loading…';
    document.getElementById('qrcode_canvas').innerHTML = '';

    ['rowAddNewParams', 'rowKeyParams', 'rowRouteParams', 'rowDnsParams'].forEach(id => {
        document.getElementById(id).style.display = '';
    });

    if (mode === 'export') {
        ['rowAddNewParams', 'btnWrapAddPeer', 'btnWrapGenKeys', 'btnWrapGenPsk', 'pskCheckboxWrapper'].forEach(id => {
            document.getElementById(id).style.display = 'none';
        });
        ['clientPrivKey','clientPsk'].forEach(id => {
            document.getElementById(id).readOnly = false;
        });
        document.getElementById('btnWrapDownload').className = 'col-sm-12';
    } else {
        ['rowAddNewParams', 'btnWrapAddPeer', 'btnWrapGenKeys', 'btnWrapGenPsk', 'pskCheckboxWrapper'].forEach(id => {
            document.getElementById(id).style.display = '';
        });
        ['clientPrivKey','clientPsk'].forEach(id => {
            document.getElementById(id).readOnly = true;
        });
        document.getElementById('pskEnabled').checked = false;
        document.getElementById('refreshPskBtn').disabled = true;
        document.getElementById('btnWrapDownload').className = 'col-sm-6';
    }
}

// === IMPORT .CONF FEATURE LOGIC ===
function parseImportedConf(text) {
    if (!text) return;

    let privMatch = text.match(/PrivateKey\s*=\s*([A-Za-z0-9+\/]{43}=)/i);
    let pubMatch = text.match(/PublicKey\s*=\s*([A-Za-z0-9+\/]{43}=)/i);
    let ipMatch = text.match(/Address\s*=\s*([0-9a-fA-F\.\:\/, ]+)/i) || text.match(/AllowedIPs\s*=\s*([0-9a-fA-F\.\:\/, ]+)/i);
    let descMatch = text.match(/#\s*(.+)/);

    if (ipMatch) {
        document.getElementById('peerAssignedIP').value = ipMatch[1].split(',')[0].trim();
    }
    if (descMatch && descMatch[1].trim() !== '') {
        document.getElementById('peerDescription').value = descMatch[1].trim();
    }

    if (privMatch) {
        document.getElementById('clientPrivKey').value = privMatch[1];
        fetch('/wg/vpn_wg_export.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=derive_pub&__csrf_magic=${encodeURIComponent(getCsrf())}&privkey=${encodeURIComponent(privMatch[1])}`
        }).then(r => r.json()).then(d => {
            if (d.success && d.pub) {
                document.getElementById('clientPubKey').value = d.pub;
                updateDisplays();
            }
        });
    } else if (pubMatch) {
        document.getElementById('clientPubKey').value = pubMatch[1];
        updateDisplays();
    } else {
        updateDisplays();
    }
}

function handleConfUpload(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(evt) {
        openAddPeerModal();
        document.getElementById('peerDescription').value = file.name.replace(/\.[^/.]+$/, "").replace(/[^a-zA-Z0-9 -]/g, '');
        parseImportedConf(evt.target.result);
    };
    reader.readAsText(file);
    e.target.value = '';
}
// ===================================

function deletePeer(idx, name) {
    if(!confirm(`Are you sure you want to PERMANENTLY delete the peer "${name}"? This action cannot be undone.`)) {
        return;
    }
    const body = new URLSearchParams({
        action: 'delete_peer',
        __csrf_magic: getCsrf(),
        idx: idx
    });
    fetch('/wg/vpn_wg_export.php', { method: 'POST', body: body })
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                location.reload();
            } else {
                alert('Failed to delete peer: ' + data.message);
            }
        });
}

function killPeer(tun, pubkey) {
    if(!confirm("Are you sure you want to kill this connection? This will drop the peer from the kernel and disable it immediately.")) {
        return;
    }
    const body = new URLSearchParams({
        action: 'kill_peer',
        __csrf_magic: getCsrf(),
        tun: tun,
        pubkey: pubkey
    });
    fetch('/wg/vpn_wg_export.php', { method: 'POST', body: body })
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                location.reload();
            } else {
                alert('Failed to kill peer: ' + data.message);
            }
        });
}

function rotateKeys(idx, name) {
    if(!confirm(`WARNING: This will immediately revoke current access for "${name}" and generate new keys in the kernel. Proceed?`)) {
        return;
    }
    const body = new URLSearchParams({
        action: 'rotate_keys',
        __csrf_magic: getCsrf(),
        idx: idx
    });
    fetch('/wg/vpn_wg_export.php', { method: 'POST', body: body })
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                alert(`Success! New Private Key for ${name}:\n\n${data.new_priv}\n\nYou MUST re-export and provide this to the user.`);
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        });
}

function openEmailModal(idx, name) {
    fetch(`/wg/vpn_wg_export.php?action=get_conf_data&peer_idx=${encodeURIComponent(idx)}&__csrf_magic=${encodeURIComponent(getCsrf())}`)
        .then(async r => r.json())
        .then(data => {
            if (data.error) {
                alert('Error: ' + data.error);
                return;
            }

            document.getElementById('emailPeerName').value = name;
            document.getElementById('emailModalLabel').textContent = 'Email Config: ' + name;

            let conf = data.template;
            let priv = data.existing_privkey ? data.existing_privkey : '<ENTER_PRIVATE_KEY_HERE_IF_KNOWN>';

            conf = conf.replace('__PRIVATE_KEY_PLACEHOLDER__', priv);
            conf = conf.replace(/__ENDPOINT_PLACEHOLDER__|^Endpoint = .*/m, 'Endpoint = ' + data.default_endpoint);
            conf = conf.replace(/__ALLOWEDIPS_PLACEHOLDER__|^AllowedIPs = .*/m, 'AllowedIPs = 0.0.0.0/0, ::/0');
            conf = conf.replace('__DNS_PLACEHOLDER__', 'DNS = 8.8.8.8, 8.8.4.4');

            if (data.existing_keepalive) {
                conf = conf.replace(/__KEEPALIVE_PLACEHOLDER__|^PersistentKeepalive = .*/m, 'PersistentKeepalive = ' + data.existing_keepalive);
            } else {
                conf = conf.replace(/__KEEPALIVE_PLACEHOLDER__|^PersistentKeepalive = .*/m, 'PersistentKeepalive = 25');
            }

            if (data.existing_psk) {
                conf = conf.replace('__PSK_PLACEHOLDER__', 'PresharedKey = ' + data.existing_psk);
            } else {
                conf = conf.replace(/^__PSK_PLACEHOLDER__\n?/m, '');
            }

            document.getElementById('emailConfData').value = conf;
            $('#emailModal').modal('show');
        })
        .catch(e => { alert("Failed to prepare email configuration."); });
}

function sendEmailReq() {
    const to = document.getElementById('emailTarget').value.trim();
    if (!to) {
        alert('Enter an email address.');
        return;
    }

    const btn = document.getElementById('btnSendMail');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Sending...';

    const body = new URLSearchParams();
    body.append('action', 'email_peer');
    body.append('__csrf_magic', getCsrf());
    body.append('email', to);
    body.append('conf', document.getElementById('emailConfData').value);
    body.append('name', document.getElementById('emailPeerName').value);

    fetch('/wg/vpn_wg_export.php', { method: 'POST', body: body })
        .then(async r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) {
                $('#emailModal').modal('hide');
            }
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-paper-plane"></i> Send Configuration';
        });
}

function openExportModal(peerIdx, peerName) {
    currentPeerName = peerName;
    setUIState('export');
    document.getElementById('exportModalLabel').textContent = 'Export: ' + peerName;

    fetch(`/wg/vpn_wg_export.php?action=get_conf_data&peer_idx=${encodeURIComponent(peerIdx)}&__csrf_magic=${encodeURIComponent(getCsrf())}`)
        .then(async r => {
            if (!r.ok) throw new Error(r.status);
            return r.json();
        })
        .then(data => {
            if (data.error) {
                alert('Error: ' + data.error);
                return;
            }
            rawTemplateText = data.template;
            defaultEndpoint = data.default_endpoint;
            document.getElementById('endpointOverride').placeholder = 'Default: ' + defaultEndpoint;
            if (data.existing_psk) {
                document.getElementById('clientPsk').value = data.existing_psk;
            }
            document.getElementById('peerKeepAlive').value = data.existing_keepalive || '25';
            updateDisplays();
            $('#exportModal').modal('show');
        })
        .catch(e => {
            alert("Failed to fetch configuration from pfSense. " + e.message);
        });
}

function openAddPeerModal() {
    currentPeerName = 'NewPeer';
    setUIState('add');
    document.getElementById('exportModalLabel').textContent = 'Provision New Peer';

    rawTemplateText = [
        "[Interface]",
        "PrivateKey = __PRIVATE_KEY_PLACEHOLDER__",
        "Address = 10.x.x.x/32",
        "__DNS_PLACEHOLDER__",
        "",
        "[Peer]",
        "PublicKey = __SERVERPUB__",
        "__PSK_PLACEHOLDER__",
        "Endpoint = __ENDPOINT_PLACEHOLDER__",
        "AllowedIPs = __ALLOWEDIPS_PLACEHOLDER__",
        "PersistentKeepalive = __KEEPALIVE_PLACEHOLDER__",
        ""
    ].join("\n");

    defaultEndpoint = "";
    populateTunnelSelect();
    generateNewKeys();
    $('#exportModal').modal('show');
}

function populateTunnelSelect() {
    const sel = document.getElementById('tunnelSelect');
    sel.innerHTML = '';

    tunnelsData.forEach(t => {
        const opt = new Option(t.name, t.endpoint);
        opt.dataset.pubkey = t.pubkey;
        opt.dataset.subnet = t.subnet;
        opt.dataset.nextip = t.next_ip;
        sel.appendChild(opt);
    });

    if (tunnelsData.length > 0) {
        document.getElementById('endpointOverride').placeholder = 'Default: ' + tunnelsData[0].endpoint;
        updateTunnelPubKey(tunnelsData[0].pubkey || '');
        document.getElementById('peerAssignedIP').value = tunnelsData[0].next_ip || '';
    }
}

function onTunnelChange() {
    const sel = document.getElementById('tunnelSelect');
    const opt = sel.options[sel.selectedIndex];

    document.getElementById('endpointOverride').placeholder = 'Default: ' + sel.value;
    document.getElementById('endpointOverride').value = '';
    updateTunnelPubKey(opt.dataset.pubkey || '');

    if (modalMode === 'add') {
        document.getElementById('peerAssignedIP').value = opt.dataset.nextip || '';
    }
    updateDisplays();
}

function updateTunnelPubKey(pubkey) {
    rawTemplateText = rawTemplateText.replace(/PublicKey = .*/, 'PublicKey = ' + pubkey);
}

function updateDisplays() {
    const privKey = document.getElementById('clientPrivKey').value.trim();
    let conf = rawTemplateText;

    conf = conf.replace('__PRIVATE_KEY_PLACEHOLDER__', privKey || '<PASTE_PRIVATE_KEY_HERE>');

    const psk = document.getElementById('clientPsk').value.trim();
    if (psk) {
        conf = conf.replace('__PSK_PLACEHOLDER__', 'PresharedKey = ' + psk);
    } else {
        conf = conf.replace(/^__PSK_PLACEHOLDER__\n?/m, '');
    }

    let ep = document.getElementById('endpointOverride').value.trim();
    if (!ep && modalMode === 'add') {
        ep = document.getElementById('tunnelSelect').value;
    }
    if (!ep) {
        ep = defaultEndpoint;
    }
    conf = conf.replace(/__ENDPOINT_PLACEHOLDER__|^Endpoint = .*/m, 'Endpoint = ' + ep);

    let allowedIPs = document.getElementById('clientAllowedIPs') ? document.getElementById('clientAllowedIPs').value.trim() : '0.0.0.0/0, ::/0';
    if (!allowedIPs) {
        allowedIPs = '0.0.0.0/0, ::/0';
    }
    conf = conf.replace(/__ALLOWEDIPS_PLACEHOLDER__|^AllowedIPs = .*/m, 'AllowedIPs = ' + allowedIPs);

    if (modalMode === 'add') {
        const raw_ip = document.getElementById('peerAssignedIP').value.trim() || '10.x.x.x/32';
        const ip = raw_ip.split(',')[0].trim();
        conf = conf.replace(/^Address = .*/m, 'Address = ' + ip);
    }

    const dns = document.getElementById('peerDNS').value.trim();
    if (dns) {
        conf = conf.replace('__DNS_PLACEHOLDER__', 'DNS = ' + dns);
    } else {
        conf = conf.replace(/__DNS_PLACEHOLDER__\n?/g, '');
    }

    let ka = document.getElementById('peerKeepAlive') ? document.getElementById('peerKeepAlive').value.trim() : '25';
    if (!ka) {
        ka = '25';
    }
    conf = conf.replace(/__KEEPALIVE_PLACEHOLDER__|^PersistentKeepalive = .*/m, 'PersistentKeepalive = ' + ka);

    if (modalMode === 'add') {
        conf = conf.replace(/^#.*\n/, '');
        const desc = document.getElementById('peerDescription').value.trim();
        if (desc) {
            conf = '# ' + desc + '\n' + conf;
        }
    }

    document.getElementById('confText').value = conf;

    const canvas = document.getElementById('qrcode_canvas');
    canvas.innerHTML = '';

    if (privKey) {
        if (typeof QRCode !== 'undefined') {
            try {
                new QRCode(canvas, {
                    text: conf,
                    width: 220,
                    height: 220,
                    colorDark: '#000',
                    colorLight: '#fff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            } catch(e) {
                console.error("QR Error", e);
            }
        } else {
            canvas.innerHTML = '<span class="text-danger">wg_qrcode.js not loaded</span>';
        }
    } else {
        canvas.innerHTML = '<small class="text-muted">Private key required<br>for QR generation</small>';
    }
}

function generateNewKeys() {
    fetch(`/wg/vpn_wg_export.php?action=gen_keys&__csrf_magic=${encodeURIComponent(getCsrf())}`)
        .then(async r => {
            if (!r.ok) throw new Error(r.status);
            return r.json();
        })
        .then(data => {
            if (data.error) {
                alert('Key generation error: ' + data.error);
                return;
            }
            document.getElementById('clientPubKey').value = data.pub;
            document.getElementById('clientPrivKey').value = data.priv;
            updateDisplays();
        })
        .catch(e => {
            alert('Server communication failed. ' + e.message);
        });
}

function refreshKeys() {
    generateNewKeys();
}

function refreshPsk() {
    fetch(`/wg/vpn_wg_export.php?action=gen_psk&__csrf_magic=${encodeURIComponent(getCsrf())}`)
        .then(async r => {
            if (!r.ok) throw new Error(r.status);
            return r.json();
        })
        .then(data => {
            if (data.error) {
                alert('PSK error: ' + data.error);
                return;
            }
            document.getElementById('clientPsk').value = data.psk;
            updateDisplays();
        })
        .catch(e => {
            alert('Server communication failed. ' + e.message);
        });
}

function togglePsk(el) {
    document.getElementById('refreshPskBtn').disabled = !el.checked;
    if (el.checked) {
        refreshPsk();
    } else {
        document.getElementById('clientPsk').value = '';
        updateDisplays();
    }
}

function validatePeerForm() {
    const pub = document.getElementById('clientPubKey').value.trim();
    const desc = document.getElementById('peerDescription').value.trim();
    const ip = document.getElementById('peerAssignedIP').value.trim();

    if (!pub || !/^[A-Za-z0-9+\/]{43}=$/.test(pub)) {
        alert('Invalid public key.');
        return false;
    }
    if (!desc) {
        alert('Enter description.');
        return false;
    }
    if (!ip) {
        alert('Enter at least one IP/mask.');
        return false;
    }
    return true;
}

function addPeerToTunnel() {
    if (!validatePeerForm()) return;

    const pub = document.getElementById('clientPubKey').value.trim();
    const priv = document.getElementById('clientPrivKey').value.trim();
    const desc = document.getElementById('peerDescription').value.trim();
    const ip = document.getElementById('peerAssignedIP').value.trim();
    const psk = document.getElementById('clientPsk').value.trim();
    const ka = document.getElementById('peerKeepAlive').value.trim();
    const exp = document.getElementById('peerExpiry') ? document.getElementById('peerExpiry').value.trim() : '0';
    const sel = document.getElementById('tunnelSelect');
    const tunName= sel.options[sel.selectedIndex].text;

    if (!confirm(`Provision peer to "${tunName}"?`)) return;

    const btn = document.getElementById('btnAddPeer');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

    const body = new URLSearchParams({
        action: 'add_peer',
        __csrf_magic: getCsrf(),
        tun: tunName,
        publickey: pub,
        privatekey: priv,
        descr: desc,
        assignedip: ip,
        presharedkey: psk,
        keepalive: ka,
        expiry: exp
    });

    fetch('/wg/vpn_wg_export.php', { method: 'POST', body })
    .then(async r => {
        if (!r.ok) {
            const txt = await r.text();
            if (txt.includes('<!DOCTYPE html>')) {
                throw new Error("CSRF Token Expired or Session Timed Out. Please refresh the page.");
            }
            throw new Error("Server Error: " + r.status);
        }
        return r.json();
    })
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = 'Provision & Save';
        }
    })
    .catch(e => {
        alert(e.message);
        btn.disabled = false;
        btn.innerHTML = 'Provision & Save';
    });
}

function closeModalAndReload() {
    $('#exportModal').modal('hide');
    location.reload();
}

function downloadConfFile() {
    if (modalMode === 'add' && !validatePeerForm()) return;

    const desc = modalMode === 'add' ? document.getElementById('peerDescription').value.trim() : currentPeerName;
    const fileName = desc.replace(/[^a-zA-Z0-9_-]/g, '_') + '.conf';

    const blob = new Blob([document.getElementById('confText').value], { type: 'text/plain' });
    const a = Object.assign(document.createElement('a'), {
        href: URL.createObjectURL(blob),
        download: fileName
    });

    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
}

<?php if ($auto_open_idx !== null): ?>
document.addEventListener('DOMContentLoaded', () => {
    openExportModal(<?= (int)$auto_open_idx ?>, <?= json_encode($auto_open_name, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
});
<?php endif; ?>
</script>
<?php include("foot.inc"); ?>
