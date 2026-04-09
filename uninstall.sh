#!/bin/sh

echo "========================================================"
echo "=          WireGuard Peer Export uninstaller           ="
echo "========================================================"

# 1. Check for and remove the official FreeBSD package
echo "-> Checking for official pkg installation..."
if pkg info -e pfSense-pkg-wg-export; then
    echo "-> Package found. Uninstalling via pkg manager..."
    pkg delete -y pfSense-pkg-wg-export
else
    echo "-> Package not found. Proceeding to legacy cleanup..."
fi

# 2. Force remove the physical files (Catches legacy installs)
echo "-> Scrubbing physical application files..."
rm -f /usr/local/www/vpn_wg_export.php
rm -f /usr/local/www/widgets/widgets/wg_client_export.widget.php

# 3. Build the PHP cleanup script to scrub the XML database
echo "-> Cleaning up pfSense configuration database..."
cat << 'EOF' > /tmp/wg_cleanup.php
<?php
require_once("config.inc");
require_once("util.inc");
global $config;

$modified = false;

// Remove the GUI Menu Link
if (is_array($config["installedpackages"]["menu"])) {
    foreach ($config["installedpackages"]["menu"] as $k => $m) {
        if ($m["name"] === "WG Client Export") {
            unset($config["installedpackages"]["menu"][$k]);
            $modified = true;
            break;
        }
    }
}

// Remove the Package Manager Receipt (Just in case)
if (is_array($config["installedpackages"]["package"])) {
    foreach ($config["installedpackages"]["package"] as $k => $p) {
        if ($p["name"] === "wg-export" || $p["name"] === "pfSense-pkg-wg-export") {
            unset($config["installedpackages"]["package"][$k]);
            $modified = true;
            break;
        }
    }
}

if ($modified) {
    write_config("Universal Uninstall: Cleaned WG Client Export from database");
    echo "-> Database successfully scrubbed.\n";
} else {
    echo "-> Database is already clean.\n";
}
?>
EOF

# 4. Execute the PHP script and delete it
/usr/local/bin/php /tmp/wg_cleanup.php
rm -f /tmp/wg_cleanup.php

# 5. Restart the WebGUI
echo "-> Restarting pfSense WebGUI..."
/etc/rc.restart_webgui

echo "========================================================"
echo "                  Removal Successful!                  ="
echo "========================================================"
