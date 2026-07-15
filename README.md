# pfSense WireGuard Peer Export (WG Suite)

One click to add a peer, get the `.conf` file, and generate a QR code. No more configuring both sides manually.

Adding a WireGuard peer on pfSense normally means: create the peer in the GUI, manually generate keys, copy the public key back, hand-craft the client config, and figure out the endpoint/subnet yourself. This plugin turns all of that into a single step — click **Add New Peer**, fill in a name, and you get a ready-to-use config file and QR code while the peer is automatically registered on the firewall.

---

## What's New in v1.1.0

### 🩺 Peer Connectivity Doctor
A per-peer diagnostic engine that walks the full connectivity chain and reports each link as pass / warn / fail with a concrete fix. Click the stethoscope icon on any peer row to run it. Checks, in causal order:

1. **Peer enabled** — peer is active in the configuration
2. **Tunnel state** — tunnel exists, is enabled, and is running in the kernel
3. **Peer loaded in kernel** — detects the classic config-vs-kernel gap where only the `.conf` files were rewritten without a proper tunnel sync
4. **UDP port bound** — verifies the tunnel's listen port actually has a listener (via `sockstat`)
5. **WAN reachability rule** — WAN pass rule or NAT port forward covering the listen port
6. **Tunnel firewall rule** — pass rule on the tunnel interface is present *and* free of the protocol/TCP-flags trap: rules with `protocol = any` combined with TCP flags, or TCP-only rules, are flagged by name with an explanation of why they silently drop all non-TCP peer traffic
7. **Outbound NAT** — mode-aware; automatic/hybrid pass, manual mode scans rules for the tunnel subnet, disabled warns with a fix
8. **Handshake** — never / stale (with age) / fresh (< 3 minutes), with endpoint hint for never-connected peers
9. **Traffic flow** — rx-only or tx-only asymmetry diagnosed separately (one-way traffic is the signature of the rule trap or missing NAT)
10. **Addressing** — peer /32 inside the tunnel subnet; catches duplicate IPs and duplicate public keys across peers on the same tunnel
11. **Path-MTU probe** — DF-set ICMP ladder to the live endpoint (1500 → 1492 → 1472 → 1420); prescribes a concrete MTU and MSS clamp when the path is constrained; reports inconclusive rather than a false alarm when the client blocks ICMP
12. **WebSocket listener** — (WS tunnels only) verifies the TCP WS port has a local listener

The modal shows a colour-coded result for each check and a **Next Step** banner carrying the first failure's remediation so there is no ambiguity about where to start.

---

### 🗑️ Delete Tunnel
Tunnels can now be deleted directly from the WireGuard Status panel via a trash button in the new Actions column.

- Assigned tunnels (those mapped to a pfSense OPT interface) show a **disabled button** with a tooltip — the assignment must be removed at Interfaces → Assignments first, matching the native package's own validation
- Tunnels with peers require an explicit **cascade confirmation**: click OK to delete the tunnel and all its peers from the kernel and config simultaneously
- A **type-the-tunnel-name prompt** is required in the browser, and the name match is re-enforced server-side — no accidental one-click deletes
- Cascaded peers are removed from the kernel via `wg set … peer … remove` before the config is touched
- WGX-managed outbound NAT rules (`WGX: Auto-created outbound NAT for <tun>`) are cleaned up automatically; manually created rules are left untouched
- Follows all established WGX conventions: CSRF, rate limiting, `config_get_path` / `config_set_path`, audit log, HA sync

---

### 📊 NOC Dashboard — Subnet Usage Overhaul

**Per-tunnel subnet accounting (backend)**

Previous versions computed IP capacity and usage incorrectly: `used_ips` incremented once per Allowed IPs row across all peers regardless of tunnel, and split-tunnel LAN routes (`192.168.1.0/24`) in the same list were counted as addresses. The backend now:

- Builds a per-tunnel subnet map using a four-tier resolution chain: **kernel** (`pfSense_get_ifaddrs()` / `ifconfig` parse) → **tunnel config** `addresses/row` → **assigned OPT interface** static config → **peer inference** (infers a `/24` from the first peer `/32`, flagged as *inferred*)
- Uses kernel-first resolution because the native package leaves `addresses/row` empty for assigned tunnels (it seeds them with a blank row with mask 128)
- Counts a peer once, only if its IPv4 address falls inside its own tunnel's subnet via a net-long mask compare
- Handles case-insensitive tunnel name matching between config and peer records

**Precise percentage display**

`Math.round(2/506×100)` was producing `0%` used and `100%` available for a lightly-used pool. The display now:

- Uses `fmtPctPrecise()`: never rounds nonzero use down to `0%` or a non-full pool up to `100%`; clamps to `<0.1%` / `>99.9%` at the extremes; one decimal below 10%
- Shows a **doughnut centre readout** (`2 / 506` + `0.4% used`) via an inline Chart.js plugin so the count and percentage can never contradict each other
- **Filter-aware scoping**: selecting a tunnel in the Tunnel dropdown re-scopes the pie to that tunnel's `used / capacity` — the meaningful numbers for the subnet peers actually live in — with the tunnel name and CIDR labelled in the heading

**New panels in Charts Row 3**

- **Subnet Utilisation by Tunnel** — table with per-tunnel CIDR, progress bars (green / amber at 70% / red at 90%), precise % label, and a hover tooltip showing which resolution tier found the subnet (`kernel`, `tunnel-config`, `pfsense-interface`, `inferred`, or `unresolved`); inferred entries are marked `*`
- **Top Talkers (24h)** — top 5 peers ranked by 24-hour traffic, computed server-side from the telemetry archive with reset-aware delta calculation (counter reset → use the new value as the delta); medal emojis for top 3
- **Country Distribution** — peer country chips from the existing geo cache

---

## ✨ Full Feature List

- **Visual Telemetry & NOC Dashboard:** A dedicated Network Operations Center (NOC) dashboard featuring live Rx/Tx bandwidth charts, IP subnet exhaustion pie charts with per-tunnel breakdown, a 24-hour aggregated usage trend chart, a live Top Talkers table, and a country distribution panel.

- **Peer Connectivity Doctor:** Per-peer 12-check diagnostic modal covering the full chain from config through kernel, port binding, firewall rules (including the protocol/TCP-flags trap), NAT, handshake, traffic symmetry, addressing conflicts, path-MTU, and WebSocket listener. Each check provides a plain-English result and a concrete remediation step.

- **Delete Tunnel:** Remove tunnels (and optionally cascade-delete their peers) directly from the WireGuard Status panel, with assignment guard, typed-name confirmation, kernel teardown, NAT cleanup, and HA sync.

- **Auto-Tunnel Setup Wizard:** Deploy entirely new WireGuard tunnels from scratch in seconds. It automatically handles key generation, interface mapping, firewall rules, and Outbound NAT.

- **One-Click Peer Provisioning:** Instantly creates the peer on the firewall, generates keys, and delivers a ready-to-import `.conf` + QR code.

- **Dual-Stack IPv4/IPv6 Support:** The Auto-Setup Wizard fully supports IPv6 — create IPv6-only tunnels or dual-stack tunnels with both primary and secondary IP addresses.

- **Smart IP Allocation & Conflict Prevention:** The auto-IP engine uses a proper free-list allocator that scans the tunnel subnet to find the first genuinely free IP address, filling gaps left by deleted peers. Proactively blocks provisioning on IP conflict.

- **Import `.conf` Files:** Upload an existing WireGuard configuration file and the UI automatically parses keys, IPs, and endpoints to pre-fill the provisioning modal.

- **Expiration, Identity Sync & Telemetry Daemon:** A background cron job automatically disables peers at their expiration date, syncs with LDAP/Local User accounts (`ad_sync:` prefix) to revoke VPN access when accounts are disabled, and archives bandwidth telemetry for the dashboard.

- **Auto-Update Checker:** A background checker (Daily, Weekly, or Never) alerts you to new versions with a one-click Download & Install Now banner.

- **Advanced Peer Management:** Key Rotation (revoke + fresh keypair), Kill Connection (instant kernel drop), and Delete Peer — all from the peer row action buttons.

- **Email Configuration Delivery:** Email `.conf` files directly to end-users via the native pfSense SMTP engine.

- **Bulk CSV Import:** Mass-provision peers by pasting a list of names and IP addresses into the Bulk CSV modal.

- **Global Security Policies:** Enforce mandatory Pre-Shared Keys (PSK) for all new peers and configure fallback subnets for split tunnelling.

- **Resilient HA Sync Wizard:** Push peers to a backup node over XMLRPC with a Strict TLS toggle. Failed sync attempts are queued and retried automatically by the daemon.

- **Self-Healing & Persistence:** Auto-Bootstrap persistence survives pfSense firmware upgrades, pre-install backups protect config during updates, and aggressive UI tab healing keeps native menus intact.

- **100% Offline Assets:** Locally installed JavaScript libraries for QR codes and charts with built-in dependency validation — no external CDN calls.

- **Namespace Isolation (Bulletproof Uninstalls):** All custom UI files and tools are sandboxed in a dedicated `/wgx/` directory rather than injected into the native WireGuard folders — uninstalling is 100% safe and never breaks the native pfSense WireGuard GUI.

- **Automated Bandwidth Throttling (QoS Alias):** The telemetry daemon monitors total data usage per peer. Peers exceeding a configured soft cap are placed into a dynamic `WGX_THROTTLED` pfSense Alias for use with traffic shapers or block rules.

- **Time-Based Access Scheduling:** Restrict peer access to configured time windows (e.g. Business Hours, Weekends Only), enforced by the expiration daemon.

- **FRR OSPF Dynamic Routing Injection:** Automatically inject new tunnel interfaces into the pfSense FRR OSPF package during setup to broadcast routes across a mesh network.

- **Dedicated System Audit Trail:** The Audit tab filters native pfSense system logs to provide a searchable history of all WG Suite actions — peer creations, deletions, key rotations, S2S deployments.

---

## 🚀 Quick Start

### Package Installation

SSH into pfSense (option 8 for shell) and run:

**1. Download the package**
```bash
curl -LO https://github.com/3um3le3ee/pfSense-wireguard-peer-export/releases/latest/download/pfSense-pkg-wg-export-1.1.0.pkg
```

**2. Install the package**
```bash
pkg add -fM pfSense-pkg-wg-export-1.1.0.pkg
```

### Uninstall

```bash
pkg delete pfSense-pkg-wg-export
```

---

## 📊 Dashboard Widget

Includes a native pfSense Dashboard widget for quick access.

**How to Enable:**

1. Go to **Status > Dashboard**
2. Click the **Add Widget** (+) icon at the top right
3. Select **Wg Peer Export** from the list
4. Click **Save Settings**

**Widget Features:**
- Overview stats: total configured tunnels and provisioned peers
- Quick-action buttons to the Visual Telemetry Dashboard, Auto-Setup wizard, and Manage Peers screen

---

## 📖 Usage

### 1. Deploy a New Tunnel

1. Go to **VPN > WG Suite > Setup**
2. Enter a **Tunnel Description** (e.g. `Employee_VPN`) and a **Listen Port** (default: `51820`)
3. Enter your **Tunnel IPv4 Address / CIDR** (e.g. `10.10.10.1/24`)
4. _(Optional)_ Enter an **IPv6 Address / Prefix** for a dual-stack tunnel
5. Select your **Outbound NAT Interface** (usually WAN)
6. Click **Deploy Tunnel**

The suite automatically generates server keys, creates the interface, assigns IP addresses, builds firewall rules, and creates outbound NAT rules. The tunnel is immediately ready for peers.

### 2. Add a New Peer

1. Go to **VPN > WG Suite > Export**, click **Add Peer** (or **Import .conf**)
2. Pick a **Target Tunnel** — Endpoint, Public Key, and AllowedIPs fill in automatically
3. Enter a **Peer Description** (becomes the config filename)
4. **Auto-IP Discovery** calculates and suggests the next available IP in the tunnel subnet
5. Optionally set **DNS**, **Pre-Shared Key**, **Expiration (Days)**, or **Split Tunnel** mode
6. Download the `.conf` or scan the QR code
7. Click **Provision & Save**

> ⚠️ Download or scan before clicking Save — the private key is generated statelessly and wiped from memory once saved.

### 3. Diagnose a Peer

Click the **stethoscope icon** (🩺) on any peer row to open the Connectivity Doctor. The modal runs all 12 checks against the live system and returns within a few seconds (the path-MTU probe accounts for most of the time). Each failed or warned check shows a concrete fix. Click **Run again** to re-check after making a change.

### 4. Delete a Tunnel

Click the **trash icon** in the Actions column of the WireGuard Status panel. Assigned tunnels show a disabled button — unassign first at **Interfaces > Assignments**. Tunnels with peers prompt for cascade confirmation, then require you to type the tunnel name before the request is sent.

### 5. Export Existing Peers & Live Management

The peer list shows tunnel, public key, allowed IPs, live status, and Rx/Tx usage.

- **Export:** QR code icon → generate config
- **Email:** envelope icon → send via pfSense SMTP
- **Rotate Keys:** refresh icon → revoke and re-key
- **Bandwidth Graph:** bar-chart icon → per-peer 24h graph
- **Kill Connection:** bolt icon → instant kernel drop
- **Connectivity Doctor:** stethoscope icon → 12-check diagnostic
- **Delete:** trash icon → permanent removal
- **Bulk Download:** Download All → archive of every peer's config

---

## 🔒 Security & Architecture

- **100% Offline & Air-Gap Safe:** All frontend libraries are installed locally — no external CDN calls from the WebGUI
- **Hardened Admin Verification:** Authentication strictly queries the pfSense native system configuration for `admins` group membership
- **Strict CSRF Protection:** All background interactions use pfSense native tokens
- **Server-Side Validation:** Inputs sanitised server-side; IP conflicts blocked before writing to config
- **Stateless Key Management:** Private keys are generated by the firewall's `wg` binary, sent directly to the browser, and never stored in the pfSense config or system logs
- **Typed-Name Confirmation:** Destructive operations (tunnel deletion) require the name to be typed in the browser and re-verified server-side
- **Global Security Toggles:** Optional strict PSK enforcement and strict TLS validation on HA sync to prevent MITM attacks
- **Rate Limiting:** All AJAX endpoints are rate-limited; returning HTTP 429 on excess
- **Audit Logging:** All destructive and provisioning actions are written to the pfSense system log and the WG Suite audit trail

---

## Changelog

### v1.1.0

#### Peer & Tunnel Management
- **NEW** Peer Connectivity Doctor — 12-check per-peer diagnostic (config → kernel → port → WAN rule → tunnel firewall rule → NAT → handshake → traffic symmetry → addressing → path-MTU → WS listener) with a plain-English result and concrete fix for each link. The tunnel firewall check specifically detects the protocol/TCP-flags trap that silently drops all non-TCP peer traffic.
- **NEW** Delete Tunnel — trash button in the WireGuard Status Actions column. Assigned tunnels show a disabled button until unassigned at Interfaces → Assignments. Tunnels with peers require explicit cascade confirmation; kernel peer removal, interface teardown, and WGX outbound NAT cleanup all happen automatically. Typed-name confirmation enforced in both browser and server.

#### NOC Dashboard — Subnet Usage
- **FIXED** Subnet usage accounting — per-tunnel subnet map with four-tier kernel-first resolution (kernel `pfSense_get_ifaddrs()` → tunnel config `addresses/row` → assigned OPT interface → peer inference). Split-tunnel LAN routes in Allowed IPs no longer inflate the used count; each peer is counted once against its own tunnel's subnet only.
- **FIXED** Percentage display — `fmtPctPrecise()` replaces `Math.round()`, eliminating `0% used / 100% available` on lightly loaded pools. Doughnut centre readout shows exact `used / capacity` alongside the percentage so the two can never contradict. Filter-aware pie scoping: selecting a tunnel in the dropdown shows that tunnel's `used / capacity` rather than the aggregate.
- **NEW** NOC Dashboard: Subnet Utilisation by Tunnel table — per-tunnel CIDR, progress bars (green / amber at 70% / red at 90%), precise percentage label, and a hover tooltip showing which resolution tier resolved the subnet (`kernel`, `tunnel-config`, `pfsense-interface`, `inferred`, or `unresolved`).
- **NEW** NOC Dashboard: Top Talkers (24h) — top 5 peers by traffic, computed server-side from the telemetry archive with reset-aware delta calculation. Medal icons for the top three.
- **NEW** NOC Dashboard: Country Distribution — peer country chips from the geo cache.

#### Peer Location Map
- **NEW** Live world map (OpenStreetMap tiles via Leaflet.js, loaded locally) showing a marker for every peer that has a recorded endpoint IP. Clicking a marker opens a popup with the peer name, tunnel, last-seen time, and a GPS link so admins can quickly query connections from unexpected locations.
- Markers reflect where each peer's traffic *enters* the internet, not the peer's physical device location: peers on mobile data show their carrier's regional gateway IP; peers on home WiFi show the household WAN IP (because they share the home internet connection). The popup makes this distinction clear so admins interpret the map correctly.
- Admin GPS links open the coordinates in the system default mapping app for quick cross-reference against known peer locations.

#### WebSocket Transport (Advanced)
- **NEW** WireGuard UDP over WebSocket — tunnels WireGuard traffic inside a standard WebSocket connection on TCP port 443, making it indistinguishable from HTTPS to deep-packet inspection. Defeats networks and firewalls that block UDP, block non-HTTP ports, or actively identify and drop WireGuard handshakes (common on corporate networks, hotels, carrier-grade NAT, and some countries' national filtering infrastructure).
- Configured per-tunnel in the WG Suite UI; the `wg_ws_server` daemon handles the server-side WebSocket-to-UDP relay. Peers download a WebSocket-aware client bundle instead of a standard `.conf`.
- v1.1.0 hardens the WS bundle download (replaced `form.submit()` with `fetch()` + blob), fixes WireGuard routing loops caused by the tunnel daemon's own TCP connection being captured by `AllowedIPs = 0.0.0.0/0` (resolved via host-route pinning in `start.sh`), and adds WebSocket Ping/Pong frame handling for connection keepalive on restrictive middleboxes.

#### Dedicated System Audit Trail
- **NEW** Audit tab in the top navigation — filters the native pfSense system log to show only WG Suite actions: peer provisioning, key rotations, tunnel creation and deletion, S2S deployments, and HA sync events.
- Searchable by peer name, tunnel, or action type. Each entry links to the full syslog line for context. Provides a clean compliance-friendly history without requiring access to the raw system log.

---

## ⚠️ Disclaimer

Unofficial community plugin. This project is not affiliated with or supported by Netgate or the pfSense project. Users should review the code before running it on production systems.

License

This project is licensed under the MIT License --- see the LICENSE file for details.
