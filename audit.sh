#!/bin/sh
# pfSense Basic Security & Integrity Audit
# This script is read-only and will not modify your system.

echo "=========================================================="
echo " Starting pfSense Integrity Audit..."
echo "=========================================================="
echo ""

# 1. CORE SYSTEM INTEGRITY CHECK
echo ">>> 1. Checking core system files against Netgate signatures..."
echo "    (This compares hashes of installed files. It may take a minute or two.)"
# -s checks checksums, -q keeps it quiet unless there is a mismatch
pkg check -sq
if [ $? -eq 0 ]; then
    echo "    [OK] No core package checksum mismatches found."
else
    echo "    [WARNING] Some package files have been modified! Review the output above."
fi
echo ""

# 2. RECENTLY MODIFIED FILES IN CRITICAL DIRECTORIES
echo ">>> 2. Finding files modified in the last 7 days..."
echo "    (Looking in /etc, /usr/local/www, and /usr/local/etc)"
find /etc /usr/local/www /usr/local/etc -type f -mtime -7 | grep -v "/var/run" | grep -v "/var/db"
echo ""

# 3. HIDDEN FILES IN THE WEB DIRECTORY
echo ">>> 3. Checking for suspicious hidden files in the WebGUI directory..."
echo "    (Web files should rarely be hidden)"
find /usr/local/www -name ".*" -type f
echo ""

# 4. UNRECOGNIZED STARTUP SCRIPTS
echo ">>> 4. Listing custom startup scripts (/usr/local/etc/rc.d/)..."
echo "    (Review these to ensure you recognize them)"
ls -la /usr/local/etc/rc.d/ | grep -v "total"
echo ""

# 5. CHECK FOR ROGUE ADMIN ACCOUNTS
echo ">>> 5. Listing users with root/admin shell access..."
echo "    (Only accounts you created and 'root' should be here)"
grep -E 'sh$|csh$|bash$|tcsh$' /etc/passwd
echo ""

echo "=========================================================="
echo " Audit Complete."
echo " Note: Your custom 'vpn_wg_export.php' and widget will"
echo " likely show up in the 'Recently Modified' list!"
echo "=========================================================="