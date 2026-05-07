#!/usr/local/bin/php -q
<?php
require_once("config.inc");
require_once("util.inc");
require_once("pkg-utils.inc");

define('WGX_VERSION', '1.0.8');

function wgx_log($mod, $msg, $data = []) {
    $log_entry = "[" . date('Y-m-d H:i:s') . "] [{$mod}] {$msg} ";
    if (!empty($data)) { $log_entry .= json_encode($data); }
    $log_entry .= "\n";
    @file_put_contents('/var/log/wgx_debug.log', $log_entry, FILE_APPEND);
}

$now = time();
$changed = false;

// A. Peer Expiration Logic
if (isset($config['installedpackages']['wireguard']['peers']['item']) && is_array($config['installedpackages']['wireguard']['peers']['item'])) {
    foreach ($config['installedpackages']['wireguard']['peers']['item'] as &$peer) {
        if (isset($peer['enabled']) && $peer['enabled'] === 'yes' && !empty($peer['expire_time'])) {
            if ($now > (int)$peer['expire_time']) {
                $peer['enabled'] = 'no';
                $changed = true;
                syslog(LOG_NOTICE, "WG Suite: Auto-disabled expired peer '{$peer['descr']}' on {$peer['tun']}");
            }
        }
    }
}

// B. Identity Sync
$system_users = [];
if (isset($config['system']['user']) && is_array($config['system']['user'])) {
    $system_users = $config['system']['user'];
}
$active_usernames = [];
foreach ($system_users as $su) {
    if (!isset($su['disabled'])) { $active_usernames[] = strtolower($su['name']); }
}

if (isset($config['installedpackages']['wireguard']['peers']['item']) && is_array($config['installedpackages']['wireguard']['peers']['item'])) {
    foreach ($config['installedpackages']['wireguard']['peers']['item'] as &$peer) {
        if (isset($peer['enabled']) && $peer['enabled'] === 'yes' && strpos(strtolower($peer['descr']), 'ad_sync:') === 0) {
            $mapped_user = trim(substr(strtolower($peer['descr']), 8));
            if (!in_array($mapped_user, $active_usernames)) {
                $peer['enabled'] = 'no';
                $changed = true;
            }
        }
    }
}

// C. Telemetry Archiving
$wg_bin = is_executable('/usr/local/bin/wg') ? '/usr/local/bin/wg' : '/usr/bin/wg';
if (!empty($wg_bin)) {
    $rawTx = trim(@shell_exec("{$wg_bin} show all transfer"));
    if ($rawTx) {
        $archive_file = '/var/db/wgx_telemetry_archive.json';
        $archive = file_exists($archive_file) ? json_decode(file_get_contents($archive_file), true) : [];
        $current_hour = strtotime(date('Y-m-d H:00:00'));

        foreach (explode("\n", $rawTx) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 4) {
                $pub = $parts[1];
                $rx = (int)$parts[2];
                $tx = (int)$parts[3];

                if (!isset($archive[$pub])) $archive[$pub] = ['rx' => 0, 'tx' => 0, 'last_seen' => 0, 'history' => []];

                if ($rx > 0 || $tx > 0) {
                    $archive[$pub]['rx'] = $rx;
                    $archive[$pub]['tx'] = $tx;
                    $archive[$pub]['last_seen'] = $now;

                    if (!isset($archive[$pub]['history'])) $archive[$pub]['history'] = [];
                    $archive[$pub]['history'][$current_hour] = $rx + $tx;

                    foreach ($archive[$pub]['history'] as $ts => $val) {
                        if ((int)$ts < ($now - 86400)) unset($archive[$pub]['history'][$ts]);
                    }
                }
            }
        }
        @file_put_contents($archive_file, json_encode($archive));
    }
}

// D. Auto-Update Checker Logic
$update_freq = $config['installedpackages']['wgexport']['config'][0]['update_freq'] ?? 'never';
$last_check_file = '/var/db/wgx_last_update_check.txt';
$update_flag_file = '/var/db/wgx_update_available.json';
$last_check = file_exists($last_check_file) ? (int)file_get_contents($last_check_file) : 0;
if (($update_freq === 'daily' && ($now - $last_check) > 86400) || ($update_freq === 'weekly' && ($now - $last_check) > 604800)) {
    file_put_contents($last_check_file, $now);
    $url = "https://raw.githubusercontent.com/3um3le3ee/pfSense-wireguard-peer-export/main/version.json";
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_USERAGENT=>'WG-Suite']);
    $resp = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($http_code === 200 && !empty($resp)) {
        $v_data = json_decode($resp, true);
        if (is_array($v_data) && isset($v_data['version']) && version_compare($v_data['version'], WGX_VERSION, '>')) {
            file_put_contents($update_flag_file, json_encode($v_data));
        } else { @unlink($update_flag_file); }
    }
}

// E. Auto-Heal Native UI Tabs (Forces strict order)
$wg_core_files = ['/usr/local/www/wg/vpn_wg_tunnels.php','/usr/local/www/wg/vpn_wg_peers.php','/usr/local/www/wg/vpn_wg_settings.php','/usr/local/www/wg/status_wireguard.php','/usr/local/www/status_wireguard.php'];
$tabs_healed = false;
foreach ($wg_core_files as $file) {
    if (file_exists($file)) {
        $orig_content = file_get_contents($file);
        $content = $orig_content;

        // 1. Strip out any existing custom tabs to prevent duplicates and bad ordering
        $content = preg_replace('/\$tab_array\[\]\s*=\s*array\(gettext\("Dashboard"\),\s*false,\s*"\/wg\/vpn_wg_dashboard\.php"\);\s*/s', '', $content);
        $content = preg_replace('/\$tab_array\[\]\s*=\s*array\(gettext\("Export"\),\s*false,\s*"\/wg\/vpn_wg_export\.php"\);\s*/s', '', $content);
        $content = preg_replace('/\$tab_array\[\]\s*=\s*array\(gettext\("Setup"\),\s*false,\s*"\/wg\/vpn_wg_setup\.php"\);\s*/s', '', $content);

        // 2. Inject all three in the exact correct order
        $injection = '$tab_array[] = array(gettext("Dashboard"), false, "/wg/vpn_wg_dashboard.php");' . "\n" .
                     '$tab_array[] = array(gettext("Export"), false, "/wg/vpn_wg_export.php");' . "\n" .
                     '$tab_array[] = array(gettext("Setup"), false, "/wg/vpn_wg_setup.php");' . "\n" .
                     'display_top_tabs($tab_array);';

        $content = preg_replace('/display_top_tabs\s*\(\s*\$tab_array\s*\)\s*;/s', $injection, $content);

        if ($content !== $orig_content) {
            file_put_contents($file, $content);
            $tabs_healed = true;
        }
    }
}
if ($tabs_healed) syslog(LOG_NOTICE, "WG Suite: Auto-healed custom UI tabs after native package overwrite.");

if ($changed) {
    write_config("WG Suite: CRON disabled expired/orphaned peers.");
    sync_package("wireguard");
    @include_once('/usr/local/pkg/wireguard/includes/wg_globals.inc'); @include_once('/usr/local/pkg/wireguard/includes/wg.inc'); @include_once('/usr/local/pkg/wireguard/includes/wg_service.inc');
    if (function_exists('setup_wg')) setup_wg();
    if (function_exists('clear_subsystem_dirty')) clear_subsystem_dirty('wireguard');
    @unlink('/tmp/wireguard.dirty');
}
?>
