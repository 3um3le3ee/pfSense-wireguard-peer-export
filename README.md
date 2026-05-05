pfSense WireGuard Peer Export
=============================

One click to add a peer, get the `.conf` file, and generate a QR code. No more configuring both sides manually.

Adding a WireGuard peer on pfSense normally means: create the peer in the GUI, manually generate keys, copy the public key back, hand-craft the client config, and figure out the endpoint/subnet yourself. This plugin turns all of that into a single step --- click **Add New Peer**, fill in a name, and you get a ready-to-use config file and QR code while the peer is automatically registered on the firewall.

### ✨ Features

-   **One-Click Peer Provisioning:** Instantly creates the peer on the firewall, generates keys, and delivers a ready-to-import `.conf` + QR code.

-   **Expiration & Identity Sync Daemon:** A dedicated background cron job automatically disables peers when they reach a configured expiration date. It also syncs with LDAP/Local User accounts (using the `ad_sync:` prefix) to revoke VPN access if the system account is missing or disabled.

-   **Live Telemetry & Monitoring:** The main dashboard table now displays live Receive (Rx) and Transmit (Tx) data usage in megabytes for each connected peer.

-   **Advanced Peer Management:** Administrators can easily perform a "Key Rotation" to revoke access and generate fresh keys, "Kill Connection" to instantly drop a peer from the kernel, or "Delete Peer" to permanently erase them.

-   **Email Configuration Delivery:** Directly email `.conf` configuration files to end-users utilizing the native pfSense SMTP engine.

-   **Bulk CSV Import:** Rapidly mass-provision peers by pasting a list of names and IP addresses (`Name, IPAddress`) into the new Bulk CSV modal.

-   **Global Security Policies:** Enforce mandatory Pre-Shared Keys (PSK) for all new peers and configure fallback subnets for split tunneling.

-   **Resilient HA Sync Wizard:** Securely push peers to a backup node over XMLRPC with a new Strict TLS toggle. Failed sync attempts are automatically saved to a background queue (`/var/db/wgx_ha_queue.json`) and retried by the daemon.

-   **Auto-Tunnel Setup Wizard:** Deploy entirely new WireGuard tunnels in seconds, now featuring a dropdown menu to explicitly map Outbound NAT rules to a specific interface.

-   **Auto-IP Discovery Engine:** Automatically calculates and suggests the next available IP address in the tunnel subnet.

-   **Smart Endpoint Auto-Discovery:** Automatically detects if your router is behind a Double NAT and fetches your true public IP to ensure 5G cellular clients can always connect.

-   **100% Offline QR Code:** Uses a locally installed `qrcode.min.js` library with built-in dependency validation --- no external CDN calls.

-   **Stateless Key Handling:** Private keys are generated on-the-fly and never stored in the pfSense config or system logs.

* * * * *

### 🚀 Quick Start

#### 📦 Package Installation

To install the tool as a native pfSense package (which allows for cleaner management and persistence), use the following commands. This will download the pre-compiled `.pkg` and install it using the system's package manager.

SSH into your pfSense (option 8 for shell), then download and run the installer:

**1\. Download the package**
```bash
curl -LO https://github.com/3um3le3ee/pfSense-wireguard-peer-export/releases/latest/download/pfSense-pkg-wg-export-1.0.7.pkg
```
**2\. Install the package**
```bash
pkg add -fM pfSense-pkg-wg-export-1.0.7.pkg
```
*Note: The installer will automatically download the offline QR code library to your firewall during setup. A new **Peer Export** tab will appear under **VPN > WireGuard**.*

#### 🗑️ Uninstall
```bash
pkg delete -y pfSense-pkg-wg-export-1.0.7.pkg
```
* * * * *

### 📊 Dashboard Widget

Version 1.0.7 includes a streamlined native pfSense Dashboard widget (`wg_peer_export.widget.php`) for quick access.

**How to Enable:**

1.  Go to your pfSense **Dashboard** (Status > Dashboard).

2.  Click the **Add Widget** (+) icon at the top right.

3.  Select **Wg Peer Export** from the list.

4.  Click **Save Settings** at the top of the dashboard.

**Widget Features:**

-   **Overview Stats:** Visually displays the total number of configured tunnels and provisioned peers.

-   **Quick Actions:** Provides one-click shortcut buttons to instantly access the Auto-Setup wizard or Manage Peers dashboard.

* * * * *

### 📖 Usage

#### Add a New Peer (The Provisioning Workflow)

1.  Go to **VPN > WireGuard > Peer Export**, click **Add New Peer**.

2.  Pick a **Target Tunnel** --- Endpoint, Public Key, and AllowedIPs are filled in automatically.

3.  Enter a **Peer Description** (this will become the configuration filename).

4.  **Auto-IP Discovery:** The **Assigned IP** box will automatically calculate and suggest the next available IP address in the tunnel's subnet!

5.  Optionally set **DNS**, **Pre-Shared Key**, an **Expiration (Days)**, or switch to **Split Tunnel** mode.

6.  Download the `.conf` or scan the QR code on your phone.

7.  Click **Provision & Save to pfSense** --- the peer is securely saved to the database and the WireGuard service is instantly synchronized in the background.

⚠️ *Download or scan before clicking Add --- the private key is generated statelessly and wiped from memory once saved.*

#### Export Existing Peers & Live Management

The page lists all configured peers with their tunnel, public key, allowed IPs, live online status, and active Rx/Tx data usage.

-   **Export:** Click the QR code icon on any row to generate its config.

-   **Email:** Click the envelope icon to send the configuration profile via email.

-   **Rotate Keys:** Click the refresh icon to instantly revoke access and generate a fresh keypair.

-   **Bulk Download:** Use **Download All** to grab a `.zip` or `.tar.gz` of every peer.

*Note: To prevent accidentally breaking existing tunnels, the "Generate Keys" button is safely hidden when viewing an already-provisioned peer.*

* * * * *

### 📁 Files

-   `pfSense-pkg-wg-export-1.0.7.pkg` Native pfSense Package.

-   `vpn_wg_setup.php` Setup page --- Setup WireGuard tunnel with one click, auto adds firewall/NAT rules.

-   `vpn_wg_export.php` Main page --- Contains the peer table, Live Telemetry, Email/CSV modules, and AJAX endpoints.

-   `wgx_expire.php` The automated background daemon handling peer expirations, LDAP identity sync, and HA queue processing.

-   `wg_peer_export.widget.php` Dashboard widget --- Provides high-level tunnel/peer statistics and quick links.

* * * * *

### 🔒 Security & Architecture

This tool was designed with strict enterprise firewall security in mind:

-   **100% Offline & Air-Gap Safe:** The `qrcode.min.js` library is installed locally on the firewall, meaning no external requests are ever made by the WebGUI.

-   **Strict CSRF Protection:** All background interactions utilize pfSense's native `__csrf_magic` tokens to prevent Cross-Site Request Forgery (CSRF) attacks.

-   **Server-Side Validation:** Form inputs are heavily sanitized and validated using pfSense's native `is_ipaddr()` function before writing to `config.xml`.

-   **Stateless Key Management:** Private keys are generated via the firewall's native `wg` binary, sent directly to the browser, and are **never** stored in the pfSense config or system logs.

-   **Global Security Toggles:** Administrators can optionally enforce strict PSK requirements and secure HA syncs with strict TLS validation to prevent MITM attacks.

* * * * *

**⚠️ Disclaimer**

Unofficial community plugin. This project is not affiliated with or supported by Netgate or the pfSense project. Users should review the code before running it on production firewalls. Use at your own risk.

**License**

This project is licensed under the MIT License --- see the LICENSE file for details.
