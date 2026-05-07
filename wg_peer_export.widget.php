<?php
/**
 * wg_peer_export.widget.php
 * WG Export Tool
 */
require_once("guiconfig.inc");
require_once("util.inc");

// Fetch configuration data safely
$a_tunnels = [];
if (isset($config['installedpackages']['wireguard']['tunnels']['item']) && is_array($config['installedpackages']['wireguard']['tunnels']['item'])) {
    $a_tunnels = $config['installedpackages']['wireguard']['tunnels']['item'];
}

$a_peers = [];
if (isset($config['installedpackages']['wireguard']['peers']['item']) && is_array($config['installedpackages']['wireguard']['peers']['item'])) {
    $a_peers = $config['installedpackages']['wireguard']['peers']['item'];
}

$total_tunnels = count($a_tunnels);
$total_peers = count($a_peers);
?>
<div class="content">
    <table class="table table-striped table-hover">
        <tbody>
            <tr>
                <td><strong><i class="fa fa-server text-muted"></i> Configured Tunnels</strong></td>
                <td class="text-right"><span class="badge bg-primary"><?= $total_tunnels ?></span></td>
            </tr>
            <tr>
                <td><strong><i class="fa fa-users text-muted"></i> Provisioned Peers</strong></td>
                <td class="text-right"><span class="badge bg-info"><?= $total_peers ?></span></td>
            </tr>
            <tr>
                <td colspan="2" class="text-center" style="padding-top: 15px; padding-bottom: 15px;">
                    <div class="btn-group">
                        <a href="/wg/vpn_wg_dashboard.php" class="btn btn-info btn-sm" title="Dashboard">
                            <i class="fa fa-bar-chart"></i> Telemetry
                        </a>
                        <a href="/wg/vpn_wg_setup.php" class="btn btn-success btn-sm" title="Setup Wizard">
                            <i class="fa fa-magic"></i> Auto-Setup
                        </a>
                        <a href="/wg/vpn_wg_export.php" class="btn btn-primary btn-sm" title="Export Tool">
                            <i class="fa fa-external-link"></i> Manage
                        </a>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>
</div>
