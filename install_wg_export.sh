#!/bin/sh

echo "Deploying WG Peer Export (v0.4.3 - Secure Mode)..."

# Resolve the directory where this script lives
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# ---------------------------------------------------------
# 1. VERIFY NETGATE SYSTEM INTEGRITY
# ---------------------------------------------------------
echo "-> Verifying system integrity against official Netgate hashes..."
# This proves to the user that our tool hasn't modified core system files
if pkg check -s pfSense-core > /dev/null 2>&1; then
    echo "-> [OK] Core pfSense GUI files match official Netgate checksums."
else
    echo "-> [NOTICE] Non-standard core files detected (this is normal if you have other patches installed)."
fi

if pkg check -s pfSense-pkg-WireGuard > /dev/null 2>&1; then
    echo "-> [OK] WireGuard package files match official Netgate checksums."
else
    echo "-> [NOTICE] Non-standard WireGuard files detected."
fi

# ---------------------------------------------------------
# 2. FETCH THE OFFLINE QR CODE LIBRARY
# ---------------------------------------------------------
echo "-> Fetching qrcode.min.js for offline firewall use..."
curl -sS "https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" > /usr/local/www/wg_qrcode.js
if [ -s "/usr/local/www/wg_qrcode.js" ]; then
    chmod 644 /usr/local/www/wg_qrcode.js
    echo "-> [OK] Downloaded and saved QR library locally."
else
    echo "-> [WARNING] Failed to download qrcode.min.js. Ensure the firewall has temporary internet access, or QR codes will not generate."
fi

# ---------------------------------------------------------
# 3. DEPLOY THE MAIN EXPORTER PAGE
# ---------------------------------------------------------
echo "-> Installing /usr/local/www/vpn_wg_export.php..."
if [ -f "${SCRIPT_DIR}/vpn_wg_export.php" ]; then
    cp "${SCRIPT_DIR}/vpn_wg_export.php" /usr/local/www/vpn_wg_export.php
    chmod 644 /usr/local/www/vpn_wg_export.php
else
    echo "ERROR: vpn_wg_export.php not found in ${SCRIPT_DIR}."
    exit 1
fi

# ---------------------------------------------------------
# 4. DEPLOY THE SMART DASHBOARD WIDGET
# ---------------------------------------------------------
echo "-> Installing /usr/local/www/widgets/widgets/wg_client_export.widget.php..."
if [ -f "${SCRIPT_DIR}/wg_client_export.widget.php" ]; then
    mkdir -p /usr/local/www/widgets/widgets/
    cp "${SCRIPT_DIR}/wg_client_export.widget.php" /usr/local/www/widgets/widgets/wg_client_export.widget.php
    chmod 644 /usr/local/www/widgets/widgets/wg_client_export.widget.php
else
    echo "ERROR: wg_client_export.widget.php not found in ${SCRIPT_DIR}."
    exit 1
fi

# ---------------------------------------------------------
# 5. ADD TO PFSENSE DROPDOWN MENU
# ---------------------------------------------------------
echo "-> Registering menu item in pfSense config..."
/usr/local/bin/php -r '
require_once("config.inc");
global $config;
$exists = false;
if (!is_array($config["installedpackages"])) { $config["installedpackages"] = array(); }
if (!isset($config["installedpackages"]["menu"]) || !is_array($config["installedpackages"]["menu"])) { $config["installedpackages"]["menu"] = array(); }
foreach ($config["installedpackages"]["menu"] as $menu) {
    if (isset($menu["name"]) && $menu["name"] === "WG Peer export") { $exists = true; break; }
}
if (!$exists) {
    $config["installedpackages"]["menu"][] = array(
        "name" => "WG Peer export",
        "section" => "VPN",
        "url" => "/vpn_wg_export.php"
    );
    write_config("Installed WG Peer export menu item.");
}
'

echo ""
echo "=========================================================="
echo " Deployment complete! Your WG Peer Export tool is live."
echo "=========================================================="