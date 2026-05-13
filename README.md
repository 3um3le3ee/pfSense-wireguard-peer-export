pfSense WireGuard Peer Export
=============================

One click to add a peer, get the .conf file, and generate a QR code. No more configuring both sides manually.

Adding a WireGuard peer on pfSense normally means: create the peer in the GUI, manually generate keys, copy the public key back, hand-craft the client config, and figure out the endpoint/subnet yourself. This plugin turns all of that into a single step — click **Add New Peer**, fill in a name, and you get a ready-to-use config file and QR code while the peer is automatically registered on the firewall.

### ✨ Features

*   **Visual Telemetry & NOC Dashboard:** A dedicated Network Operations Center (NOC) dashboard featuring live Rx/Tx bandwidth charts, IP subnet exhaustion pie charts, a 24-hour aggregated usage trend chart, and a live top talkers data table.
    
*   **Auto-Tunnel Setup Wizard:** Deploy entirely new WireGuard tunnels from scratch in seconds. It automatically handles key generation, interface mapping, firewall rules, and Outbound NAT.
    
*   **One-Click Peer Provisioning:** Instantly creates the peer on the firewall, generates keys, and delivers a ready-to-import .conf + QR code.
    
*   **Dual-Stack IPv4/IPv6 Support:** The Auto-Setup Wizard now fully supports IPv6. You can create IPv6-only tunnels or dual-stack tunnels with both primary and secondary IP addresses.
    
*   **Smart IP Allocation & Conflict Prevention:** The auto-IP engine now uses a proper free-list allocator that scans the tunnel subnet to find the first genuinely free IP address, cleanly filling gaps left by previously deleted peers. It also proactively blocks provisioning if an IP conflict is detected.
    
*   **Import .conf Files:** Upload an existing WireGuard configuration file, and the UI will automatically parse the keys, IPs, and endpoints to pre-fill the provisioning modal.
    
*   **Expiration, Identity Sync & Telemetry Daemon:** A dedicated background cron job automatically disables peers when they reach a configured expiration date. It syncs with LDAP/Local User accounts (using the ad\_sync: prefix) to revoke VPN access if the system account is disabled, and safely archives bandwidth telemetry for the dashboard.
    
*   **Auto-Update Checker:** A background checker (configurable for Daily, Weekly, or Never) alerts you to new versions with a one-click Download & Install Now banner in the UI.
    
*   **Advanced Peer Management:** Administrators can easily perform a Key Rotation to revoke access and generate fresh keys, Kill Connection to instantly drop a peer from the kernel, or Delete Peer to permanently erase them.
    
*   **Email Configuration Delivery:** Directly email .conf configuration files to end-users utilizing the native pfSense SMTP engine.
    
*   **Bulk CSV Import:** Rapidly mass-provision peers by pasting a list of names and IP addresses into the Bulk CSV modal.
    
*   **Global Security Policies:** Enforce mandatory Pre-Shared Keys (PSK) for all new peers and configure fallback subnets for split tunneling.
    
*   **Resilient HA Sync Wizard:** Securely push peers to a backup node over XMLRPC with a Strict TLS toggle. Failed sync attempts are automatically saved to a background queue and retried by the daemon.
    
*   **Self-Healing & Persistence:** Auto-Bootstrap persistence survives pfSense firmware upgrades, pre-install backups protect your config during updates, and aggressive UI tab healing ensures native menus stay intact.
    
*   **100% Offline Assets:** Uses locally installed JavaScript libraries for QR codes and Charts with built-in dependency validation — no external CDN calls.

*   **Namespace Isolation (Bulletproof Uninstalls):** A massive under-the-hood architectural upgrade. All custom UI files and tools are now securely sandboxed in a dedicated /wgx/ directory rather than injecting directly into the native WireGuard folders. This ensures that uninstalling the tool is 100% safe and will never conflict with or break your native pfSense WireGuard GUI.

*   **Zero-Touch Site-to-Site (S2S) Deployment:** A powerful new wizard allows you to instantly deploy a mesh/bridge tunnel between two pfSense firewalls. Simply enter the remote firewall's credentials, and the suite handles key generation, interface mapping, firewall rules, and routing on both sides simultaneously via XMLRPC.

*   **Automated Bandwidth Throttling (QoS Alias):** The background telemetry daemon now actively monitors total data usage (Rx+Tx) per peer. If a peer exceeds your configured soft cap limit, they are automatically placed into a dynamic WGX_THROTTLED pfSense Alias, allowing you to easily apply pfSense traffic shapers or block rules.

*   **Time-Based Access Scheduling:** Restrict peer access based on dynamic time schedules during provisioning. You can now easily limit specific peers to "Business Hours" (Mon-Fri, 09:00-17:00) or "Weekends Only," which is actively enforced by the expiration daemon.

*   **FRR OSPF Dynamic Routing Injection:** Advanced users deploying new tunnels via the Setup Wizard can now check a box to automatically inject the new interface into the pfSense FRR OSPF package, broadcasting the new routes across your mesh network instantly.

*   **Dedicated System Audit Trail:** A brand-new Audit tab has been added to the top menu. This page filters your native pfSense system logs to provide a clean, searchable history of all WireGuard Suite actions, including peer creations, deletions, key rotations, and S2S deployments.

*   **Hall of Fame / Credits Page:** A dedicated, native-looking credits page accessed directly from the footer, recognizing the community testers and supporters who helped refine the suite.
    

### 🚀 Quick Start

#### Package Installation

To install the tool as a native pfSense package, SSH into your pfSense (option 8 for shell), and run the following command to install the local package:

**1\. Download the package**
```bash
curl -LO https://github.com/3um3le3ee/pfSense-wireguard-peer-export/releases/latest/download/pfSense-pkg-wg-export-1.0.9.pkg
```

**2\. Install the package**
```bash
pkg add -fM pfSense-pkg-wg-export-1.0.9.pkg
```

_Note: The installer will automatically download the offline QR code and Chart libraries to your firewall during setup. New tabs will appear under VPN > WireGuard._

#### Uninstall

To remove the package and clean up all integration files, run:
```bash
pkg delete pfSense-pkg-wg-export
```

### 📊 Dashboard Widget

Version 1.0.8 includes a streamlined native pfSense Dashboard widget for quick access.

**How to Enable:**

1.  Go to your pfSense **Dashboard** (Status > Dashboard).
    
2.  Click the **Add Widget** (+) icon at the top right.
    
3.  Select **Wg Peer Export** from the list.
    
4.  Click **Save Settings** at the top of the dashboard.
    

**Widget Features:**

*   **Overview Stats:** Visually displays the total number of configured tunnels and provisioned peers.
    
*   **Quick Actions:** Provides one-click shortcut buttons to instantly access the new Visual Telemetry Dashboard, Auto-Setup wizard, or Manage Peers screen.
    

### 📖 Usage

#### 1\. Deploy a New Tunnel (One-Click Setup)

If you are starting from scratch and do not have a WireGuard tunnel configured yet:

1.  Go to **VPN > WireGuard > Setup**.
    
2.  Enter a **Tunnel Description** (e.g., Employee\_VPN) and a **Listen Port** (default: 51820).
    
3.  Enter your **Tunnel IPv4 Address / CIDR** (e.g., 10.10.10.1/24).
    
4.  _(Optional)_ Enter an **IPv6 Address / Prefix** to create a dual-stack tunnel.
    
5.  Select your **Outbound NAT Interface** (usually WAN) from the dropdown list.
    
6.  Click **Deploy Tunnel**.
    

The suite will automatically generate the server keys, create the interface, assign the IP addresses, build the necessary firewall rules to allow traffic, and create the Outbound NAT routing rules. Your tunnel is immediately ready for peers.

#### 2\. Add a New Peer (The Provisioning Workflow)

1.  Go to **VPN > WireGuard > Peer Export**, click **Add New Peer** (or use **Import .conf**).
    
2.  Pick a **Target Tunnel** — Endpoint, Public Key, and AllowedIPs are filled in automatically.
    
3.  Enter a **Peer Description** (this will become the configuration filename).
    
4.  **Auto-IP Discovery:** The Assigned IP box will automatically calculate and suggest the next truly available IP address in the tunnel's subnet.
    
5.  Optionally set **DNS**, **Pre-Shared Key**, an **Expiration (Days)**, or switch to **Split Tunnel** mode.
    
6.  Download the .conf or scan the QR code on your phone.
    
7.  Click **Provision & Save** — the peer is securely saved, checked for IP conflicts, and the WireGuard service is instantly synchronized in the background.
    

_Warning: Download or scan before clicking Add — the private key is generated statelessly and wiped from memory once saved._

#### 3\. Export Existing Peers & Live Management

The page lists all configured peers with their tunnel, public key, allowed IPs, live online status, and active Rx/Tx data usage.

*   **Export:** Click the QR code icon on any row to generate its config.
    
*   **Email:** Click the envelope icon to send the configuration profile via email.
    
*   **Rotate Keys:** Click the refresh icon to instantly revoke access and generate a fresh keypair.
    
*   **Bulk Download:** Use **Download All** to grab an archive of every peer.
    

### 🔒 Security & Architecture

This tool was designed with strict enterprise firewall security in mind:

*   **100% Offline & Air-Gap Safe:** The required frontend libraries are installed locally on the firewall, meaning no external UI dependencies or CDN calls are ever made by the WebGUI.
    
*   **Hardened Admin Verification:** The authentication check strictly queries the pfSense native system configuration to verify membership in the admins group.
    
*   **Strict CSRF Protection:** All background interactions utilize pfSense's native tokens to prevent Cross-Site Request Forgery (CSRF) attacks.
    
*   **Server-Side Validation:** Form inputs are heavily sanitized, and IP conflicts are proactively blocked using pfSense's native networking functions before writing to the config.
    
*   **Stateless Key Management:** Private keys are generated via the firewall's native wg binary, sent directly to the browser, and are never stored in the pfSense config or system logs.
    
*   **Global Security Toggles:** Administrators can optionally enforce strict PSK requirements and secure HA syncs with strict TLS validation to prevent MITM attacks.

*   * * * * *

**⚠️ Disclaimer**

Unofficial community plugin. This project is not affiliated with or supported by Netgate or the pfSense project. Users should review the code before running it on production firewalls. Use at your own risk.

**License**

This project is licensed under the MIT License --- see the LICENSE file for details.
