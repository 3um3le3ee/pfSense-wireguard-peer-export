pfSense WireGuard Peer Export - FreeBSD 26 Compatible
--
**One click to add a peer, get the `.conf` file, and generate a QR code. No more configuring both sides manually.**

**✅ Now compatible with FreeBSD 26 (pkg 2.6.x) and pfSense 2.7.x+**

Adding a WireGuard peer on pfSense normally means: create the peer in the GUI, manually generate keys, copy the public key back, hand-craft the client config, and figure out the endpoint/subnet yourself. This plugin turns all of that into a single step — click **Add New Peer**, fill in a name, and you get a ready-to-use config file and QR code while the peer is automatically registered on the firewall.

## ✨ Features

- **One-Click Peer Provisioning:** Instantly creates the peer on the firewall, generates keys, and delivers a ready-to-import `.conf` + QR code.
- **Auto-IP Discovery Engine:** Automatically calculates and suggests the next available IP address in the tunnel subnet.
- **Real-Time Config Preview:** Full `.conf` file and offline QR code generated instantly in the browser.
- **Bulk Export Support:** Download all peers at once as `.zip` or `.tar.gz` with one click.
- **Live Status Dashboard:** See tunnel, public key, Allowed IPs, and online/offline status for every peer.
- **Split Tunnel & DNS Options:** Easy toggles for full-tunnel vs split-tunnel and custom DNS.
- **100% Offline QR Code:** Uses a locally installed `qrcode.min.js` library — no external CDN calls.
- **Stateless Key Handling:** Private keys are generated on-the-fly and never stored in pfSense config or logs.

## 🚀 Quick Start

## 📦 Package Installation

### FreeBSD 26 / pfSense 2.7.x+ (Latest)

Install the tool as a native package using the updated pkg 2.6.x format:

SSH into your system, then download and run the installer:

**1. Download the package**
```bash
curl -LO https://raw.githubusercontent.com/Rex-odus/pfSense-wireguard-peer-export/freebsd-26-support/pfSense-pkg-wg-export.pkg
```

**2. Install the package**
```bash
pkg add pfSense-pkg-wg-export.pkg
```

*Note: The installer will automatically download the offline QR code library during setup.*

### FreeBSD 24 / Legacy Systems

For older FreeBSD 24 systems, use the v1.0.5 release from the original repository.

A new **Peer Export** tab will appear under **VPN > WireGuard**.

## 🗑️ Uninstall

```bash
pkg delete -y pfSense-pkg-wg-export
```

## 📊 Dashboard Widget

The plugin includes a native Dashboard widget for real-time monitoring and quick management of your WireGuard peers.

### How to Enable:
1. Go to your **Dashboard** (Status > Dashboard).
2. Click the **Add Widget** (+) icon at the top right.
3. Select **Wg Peer Export** from the list.
4. Click **Save Settings** at the top of the dashboard.

### Widget Features:
- **Live Telemetry:** Shows "Handshake" status (Green/Red) to see who is currently connected.
- **Data Usage:** Displays total Transmit/Receive bytes for every active peer.
- **Quick Export:** A one-click dropdown to instantly download a `.conf` file for any peer without leaving the dashboard.
- **Peer Search:** Quickly filter through long lists of peers to find a specific endpoint.

## 📖 Usage

### Add a New Peer (The Provisioning Workflow)

1. Go to **VPN > WireGuard > Peer Export**, click **Add New Peer**.
2. Pick a **Target Tunnel** — Endpoint, Public Key, and AllowedIPs are filled in automatically.
3. Enter a **Peer Description** (this will become the configuration filename).
4. **Auto-IP Discovery:** The **Assigned IP** box will automatically calculate and suggest the next available free IP address in the tunnel's subnet!
5. Optionally set **DNS**, **Pre-Shared Key**, or switch to **Split Tunnel** mode.
6. **Download the .conf** or **scan the QR code** on your phone.
7. **Click Provision & Save** — the peer is securely saved and the WireGuard service is instantly synchronized in the background.

> ⚠️ **Download or scan before clicking Add** — the private key is generated statelessly and wiped from memory once saved.

### Export Existing Peers
The page also lists all configured peers with their tunnel, public key, allowed IPs, and live online status. Click **Export config** on any row to generate its config and QR code, or use **Download All** to grab a `.zip` or `.tar.gz` of every peer.

*Note: To prevent accidentally breaking existing tunnels, the "Generate Keys" button is safely hidden when exporting an already-provisioned peer.*

## ✨ What It Does For You

| Feature | How this plugin simplifies it |
| :--- | :--- |
| **Key Management** | Keys are auto-generated on page open; no manual `wg genkey` needed. |
| **Peer Registration** | Public keys are registered automatically upon adding. |
| **Tunnel Details** | Endpoint IP, Port, and Server Public Key are auto-populated from the tunnel. |
| **IP Assignment** | **Auto-IP Engine** calculates and suggests the next available free IP. |
| **Client Config** | Real-time preview and one-click download of the `.conf` file. |
| **Mobile Setup** | QR code rendered instantly and **100% offline** for mobile scanning. |
| **Workflow** | One single form configures both the firewall and the client securely. |

## 🔒 Security & Architecture (v1.0.6 Updates)

This tool was designed with strict enterprise firewall security in mind:

- **FreeBSD 26 Compatible:** Updated package manifest for pkg 2.6.x with proper shlibs tracking
- **100% Offline & Air-Gap Safe:** The Cloudflare CDN has been removed. The `qrcode.min.js` library is installed locally on the firewall, meaning no external requests are ever made by the WebGUI.
- **Strict CSRF Protection:** All background interactions utilize native `__csrf_magic` tokens to prevent Cross-Site Request Forgery (CSRF) attacks.
- **Server-Side Validation:** Form inputs are heavily sanitized and validated using native `is_ipaddr()` function before writing to config.
- **Stateless Key Management:** Private keys are generated via the native `wg` binary, sent directly to the browser, and are **never** stored in config or system logs.

## 📁 Files

- **`pfSense-pkg-wg-export.pkg`** Native Package — Updated for FreeBSD 26 / pkg 2.6.x compatibility
- **`vpn_wg_export.php`** Main page — contains the peer table, Auto-IP engine, and AJAX endpoints
- **`wg_client_export.widget.php`** Dashboard widget — provides live telemetry and quick-export dropdown

## ⚠️ Disclaimer

**Unofficial community plugin.** This project is not affiliated with or supported by Netgate or the pfSense project. Users should review the code before running it on production firewalls. Use at your own risk.

## License

This project is licensed under the **MIT License** — see the [LICENSE](LICENSE) file for details.