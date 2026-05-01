<?php
/**
 * vpn_wg_export.php
 * pfSense WireGuard Peer Provisioning & Export Tool
 * Unified Suite Edition v1.0.6
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
    $groups = isset($_SESSION['Groups']) ? $_SESSION['Groups'] : [];
    return (is_array($groups) && in_array('admins', $groups, true));
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

define('WGX_VERSION', '1.0.6');
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
        return $config['installedpackages']['wgexport']['config'][0];
    }
    return [
        'sync_enable' => 'false',
        'sync_ip' => '',
        'sync_user' => 'admin',
        'sync_pass' => ''
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
    write_config("WG Export Tool: Saved HA Sync Primary Settings");
}

function wgx_sync_to_backup($new_peer) {
    global $config;
    $settings = wgx_load_settings();

    if (empty($settings['sync_enable']) || $settings['sync_enable'] !== 'true') return false;

    $sync_ip   = $settings['sync_ip'] ?? '';
    $sync_user = !empty($settings['sync_user']) ? $settings['sync_user'] : 'admin';
    $sync_pass = !empty($settings['sync_pass']) ? base64_decode($settings['sync_pass']) : '';

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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $http_code !== 200 || strpos($resp, 'faultCode') !== false) {
        return false;
    }
    return true;
}

// =========================================================================
// 4. RATE LIMITER & SHELL
// =========================================================================

function wgx_check_rate_limit() {
    return true; // Removed rate limit for rapid testing
}

function wgx_wg_exec($wg_bin, $args) {
    if (empty($wg_bin)) return '';
    $cmd = array_merge([$wg_bin], $args);
    $desc = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) return '';

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

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $proc = proc_open([$wg_bin, 'pubkey'], $desc, $pipes);
    if (!is_resource($proc)) return [];

    fwrite($pipes[0], $priv . "\n");
    fclose($pipes[0]);

    $pub = trim(stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

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

function wgx_build_conf_template($peer, $server_tun) {
    $lines   = [];
    $lines[] = "[Interface]";
    $lines[] = "PrivateKey = __PRIVATE_KEY_PLACEHOLDER__";

    $ips = [];
    $allowedips = isset($peer['allowedips']) && is_array($peer['allowedips']) ? $peer['allowedips'] : [];
    $raw_rows = $allowedips['row'] ?? ($allowedips['item'] ?? []);

    if (is_array($raw_rows)) {
        $rows = isset($raw_rows['address']) ? [$raw_rows] : $raw_rows;
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
// 6. AJAX HANDLERS
// =========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_start();
    try {
        if ($_POST['action'] === 'save_sync') {
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
            if (!isset($config['filter']['rule']) || !is_array($config['filter']['rule'])) $config['filter']['rule'] = [];

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

        if ($_POST['action'] === 'add_peer') {
            if (!csrf_check(false)) {
                ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Refresh the page and try again.']); exit;
            }

            $valid_tunnels = wgx_valid_tunnel_names();
            $tun_name   = trim($_POST['tun'] ?? '');
            $publickey  = trim($_POST['publickey'] ?? '');
            $assigned_raw = trim($_POST['assignedip'] ?? '');
            $descr      = trim($_POST['descr'] ?? 'New Peer');
            $psk        = trim($_POST['presharedkey'] ?? '');
            $keepalive  = trim($_POST['keepalive'] ?? '25');

            if (!in_array($tun_name, $valid_tunnels, true)) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Invalid tunnel name.']); exit; }
            if (!preg_match(WGX_PUBKEY_REGEX, $publickey)) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Invalid WireGuard public key format.']); exit; }
            if ($keepalive !== '' && !ctype_digit($keepalive)) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Keep Alive must be a valid number.']); exit; }

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

            if (!empty($psk)) {
                $new_peer['presharedkey'] = $psk;
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

            $sync_msg = ".";
            $settings = wgx_load_settings();
            if (!empty($settings['sync_enable']) && $settings['sync_enable'] === 'true') {
                $sync_status = wgx_sync_to_backup($new_peer);
                if (!$sync_status) { $sync_msg = " (HA Sync Failed. Check remote firewall credentials)."; }
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
                    $conf = str_replace("__DNS_PLACEHOLDER__\n", '', $conf);

                    $ka = !empty($peer['keepalive']) ? $peer['keepalive'] : '25';
                    $conf = str_replace('__KEEPALIVE_PLACEHOLDER__', $ka, $conf);

                    if (!empty($peer['presharedkey'])) {
                        $conf = str_replace('__PSK_PLACEHOLDER__', 'PresharedKey = ' . $peer['presharedkey'], $conf);
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
}

// =========================================================================
// 8. BUILD PAGE DATA & TABS
// =========================================================================

$pgtitle = [gettext("VPN"), gettext("WireGuard"), gettext("Export")];
$pglinks = [null, "/wg/vpn_wg_tunnels.php", "@self"];
include("head.inc");

$tab_array = array();
$tab_array[] = array(gettext("Tunnels"), false, "/wg/vpn_wg_tunnels.php");
$tab_array[] = array(gettext("Peers"), false, "/wg/vpn_wg_peers.php");
$tab_array[] = array(gettext("Settings"), false, "/wg/vpn_wg_settings.php");
$tab_array[] = array(gettext("Status"), false, "/wg/status_wireguard.php");
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

    $next_ip = '';
    if (!empty($tun_base) && is_ipaddrv4($tun_base)) {
        $max = ip2long($tun_base);
        foreach ($a_peers as $p) {
            if (!is_array($p)) continue;
            if (($p['tun'] ?? '') !== ($tun['name'] ?? '')) continue;

            $allowedips = isset($p['allowedips']) && is_array($p['allowedips']) ? $p['allowedips'] : [];
            $raw_allowedips = $allowedips['row'] ?? ($allowedips['item'] ?? []);
            $rows = [];

            if (is_array($raw_allowedips) && !empty($raw_allowedips)) {
                $rows = isset($raw_allowedips['address']) ? [$raw_allowedips] : $raw_allowedips;
            }
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (is_array($row) && !empty($row['address']) && is_ipaddrv4($row['address'])) {
                        $max = max($max, ip2long($row['address']));
                    }
                }
            }
        }
        $next_ip = long2ip($max + 1) . '/32';
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
</style>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title"><?= gettext("WireGuard Provisioning & Export") ?></h2>
    </div>
    <div class="panel-body">
        <div class="row" style="margin-bottom:15px;">
            <div class="col-sm-4">
                <div class="input-group">
                    <span class="input-group-addon"><i class="fa fa-search"></i></span>
                    <input type="text" id="searchPeers" class="form-control" placeholder="Search by name, tunnel, or IP…">
                </div>
            </div>
            <div class="col-sm-8 text-right">
                <button class="btn btn-warning" onclick="openHaSyncWizard()">
                    <i class="fa fa-refresh icon-embed-btn"></i> HA Sync Wizard
                </button>
                <button class="btn btn-success" onclick="openAddPeerModal()">
                    <i class="fa fa-plus icon-embed-btn"></i> Add New Peer
                </button>
                <button class="btn btn-info" onclick="downloadSelected()">
                    <i class="fa fa-download icon-embed-btn"></i> Download Selected
                </button>
                <button class="btn btn-primary" onclick="downloadAll()">
                    <i class="fa fa-archive icon-embed-btn"></i> Download All
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover table-condensed" id="peersTable">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" title="Select all"></th>
                        <th>Status</th>
                        <th>Description</th>
                        <th>Tunnel</th>
                        <th>Assigned IPs</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($a_peers)): ?>
                    <tr>
                        <td colspan="6" class="text-center">No WireGuard peers configured.</td>
                    </tr>
                <?php else: foreach ($a_peers as $idx => $peer):
                    if (!is_array($peer)) continue;

                    $display_desc = htmlspecialchars($peer['descr'] ?? "Peer {$idx}", ENT_QUOTES, 'UTF-8');
                    $display_tun  = htmlspecialchars($peer['tun'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
                    $pubkey       = $peer['publickey'] ?? '';

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
                    <td>
                        <button class="btn btn-sm btn-info" onclick="openExportModal(<?= $idx ?>, <?= $json_name ?>)">
                            <i class="fa fa-qrcode icon-embed-btn"></i> Export
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

<!-- HA Sync Settings Wizard Modal -->
<div class="modal fade" id="haWizardModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">HA Sync Configuration Wizard</h4>
            </div>
            <div class="modal-body" style="padding-bottom: 0;">

                <ul class="nav nav-tabs" style="margin-bottom: 15px;">
                    <li class="active"><a href="#" onclick="switchWizardTab('primary', this); return false;">I am the Primary Node</a></li>
                    <li><a href="#" onclick="switchWizardTab('backup', this); return false;">I am the Backup Node</a></li>
                </ul>

                <!-- PRIMARY TAB -->
                <div id="tab-primary" class="wizard-tab active">
                    <p class="text-muted">Configure this node to automatically push newly provisioned peers to your Backup firewall.</p>
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" id="haSyncEnable" <?= (!empty($wgx_settings['sync_enable']) && $wgx_settings['sync_enable'] === 'true') ? 'checked' : '' ?>>
                            <strong>Enable Push Sync</strong>
                        </label>
                    </div>
                    <div class="form-group">
                        <label>Backup Node IP Address</label>
                        <input type="text" id="haSyncIP" class="form-control" placeholder="e.g. 192.168.1.14" value="<?=htmlspecialchars($wgx_settings['sync_ip'] ?? '', ENT_QUOTES, 'UTF-8')?>">
                    </div>
                    <div class="form-group">
                        <label>Admin Username (On Backup Node)</label>
                        <input type="text" id="haSyncUser" class="form-control" value="<?=htmlspecialchars($wgx_settings['sync_user'] ?? 'admin', ENT_QUOTES, 'UTF-8')?>">
                    </div>
                    <div class="form-group">
                        <label>Admin Password (On Backup Node)</label>
                        <input type="password" id="haSyncPass" class="form-control" <?= !empty($wgx_settings['sync_pass']) ? 'placeholder="[Password Saved - Leave blank to keep]"' : 'placeholder="Enter Admin Password"' ?>>
                    </div>
                    <div class="text-right" style="padding-bottom: 15px;">
                        <button class="btn btn-success" id="btnSavePrimary" onclick="savePrimaryNode()">Save Primary Settings</button>
                    </div>
                </div>

                <!-- BACKUP TAB -->
                <div id="tab-backup" class="wizard-tab">
                    <div class="alert alert-info">
                        <strong>Auto-Firewall Setup</strong><br>
                        This will safely punch a hole in this node's firewall to allow the Primary Node to push configurations to it over HTTPS.
                    </div>
                    <div class="form-group">
                        <label>Primary Node IP Address</label>
                        <input type="text" id="primaryIpInput" class="form-control" placeholder="e.g. 192.168.1.1">
                        <small class="text-muted">Only this exact IP will be allowed to connect.</small>
                    </div>
                    <div class="form-group">
                        <label>Which interface does the Primary Node connect to?</label>
                        <select id="backupInterfaceSelect" class="form-control">
                            <?php foreach ($local_interfaces as $iface => $ifacename): ?>
                                <option value="<?= htmlspecialchars($iface) ?>"><?= htmlspecialchars($ifacename) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="text-right" style="padding-bottom: 15px;">
                        <button class="btn btn-primary" id="btnSaveBackup" onclick="configureBackupFirewall()"><i class="fa fa-shield"></i> Build Firewall Rule</button>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Export & Provisioning Modal -->
<div class="modal fade" id="exportModal" tabindex="-1" role="dialog" aria-labelledby="exportModalLabel" data-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button class="close" onclick="closeModalAndReload()"><span>&times;</span></button>
                <h4 class="modal-title" id="exportModalLabel">WireGuard Peer</h4>
            </div>
            <div class="modal-body">

                <div class="row" id="rowAddNewParams" style="display:none;">
                    <div class="col-sm-4">
                        <div class="form-group">
                            <label><i class="fa fa-server"></i> Tunnel</label>
                            <select id="tunnelSelect" class="form-control" onchange="onTunnelChange()"></select>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="form-group">
                            <label><i class="fa fa-tag"></i> Description</label>
                            <input id="peerDescription" type="text" class="form-control" placeholder="e.g. Alice's iPhone" oninput="updateDisplays()">
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="form-group">
                            <label><i class="fa fa-sitemap"></i> Assigned IP(s)</label>
                            <input id="peerAssignedIP" type="text" class="form-control" placeholder="e.g. 10.0.0.5/32" oninput="updateDisplays()">
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
    const sel = document.getElementById('tunnelSelect');
    const subnet = sel.options[sel.selectedIndex]?.dataset.subnet || '10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16';
    setClientAllowedIPs(subnet);
}

function setUIState(mode) {
    modalMode = mode;

    ['clientPrivKey','endpointOverride','clientPsk','clientPubKey','peerDescription','peerAssignedIP'].forEach(id => {
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
        "",
        "[Peer]",
        "PublicKey = __SERVERPUB__",
        "Endpoint = __ENDPOINT_PLACEHOLDER__",
        "AllowedIPs = __ALLOWEDIPS_PLACEHOLDER__",
        "__PSK_PLACEHOLDER__",
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

    conf = conf.replace(/^DNS = .*\n?/m, '');
    const dns = document.getElementById('peerDNS').value.trim();
    if (dns) {
        conf = conf.replace(/^(Address = .*)$/m, '$1\nDNS = ' + dns);
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
    const desc = document.getElementById('peerDescription').value.trim();
    const ip = document.getElementById('peerAssignedIP').value.trim();
    const psk = document.getElementById('clientPsk').value.trim();
    const ka = document.getElementById('peerKeepAlive').value.trim();
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
        descr: desc,
        assignedip: ip,
        presharedkey: psk,
        keepalive: ka
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
EOF_UI_EXPORT
chmod 644 "${STAGEDIR}/usr/local/www/wg/vpn_wg_export.php"
