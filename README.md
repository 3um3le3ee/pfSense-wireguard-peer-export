<p align="center">
  <img src=".github/assets/logo.png" alt="WG Suite" width="140">
</p>

<h1 align="center">WG Suite</h1>

<p align="center">
  WireGuard provisioning and management for pfSense
</p>

<p align="center">
  <a href="https://github.com/3um3le3ee/pfSense-wireguard-peer-export/releases/latest"><img alt="Latest release" src="https://img.shields.io/github/v/release/3um3le3ee/pfSense-wireguard-peer-export?label=release"></a>
  <a href="LICENSE"><img alt="Licence: Apache 2.0" src="https://img.shields.io/badge/licence-Apache%202.0-blue"></a>
  <img alt="pfSense CE 2.8 / Plus" src="https://img.shields.io/badge/pfSense-CE%202.8%20%7C%20Plus-212121">
</p>

---

Adding a WireGuard peer on pfSense normally means creating the peer, generating keys somewhere else, copying the public key across, hand-writing the client config and working out the endpoint and subnet yourself.

WG Suite turns that into one step. Click **Add peer**, give it a name, and you get a ready-to-import `.conf` file and QR code while the peer is registered on the firewall and loaded into the kernel.

Around that it adds what running WireGuard for real people needs: access tiers enforced by firewall rules, expiry, quotas and schedules, key rotation, a connectivity doctor, live telemetry, a self-service portal for peers, HA sync, site-to-site linking and a WebSocket transport for networks that block WireGuard.

Everything WG Suite creates is ordinary pfSense configuration (tunnels under *VPN > WireGuard*, rules under *Firewall > Rules*, aliases and NAT entries), so it can always be inspected or changed from the normal pfSense pages.

> **Version 1.3.0** · [What's new](CHANGELOG.md#130--2026-10-21) · [Install](#installation) · [Documentation](#documentation)

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Upgrading](#upgrading)
- [Uninstalling](#uninstalling)
- [Quick start](#quick-start)
- [Automation: REST API, metrics and health](#automation-rest-api-metrics-and-health)
- [Privacy](#privacy)
- [Security](#security)
- [Documentation](#documentation)
- [Contributing](#contributing)
- [Licence](#licence)

## Features

### Peers
- **One-click provisioning**: keys, next free address, DNS, keepalive and endpoint filled in; `.conf`, QR code or a one-time download link out.
- **One-time private keys**: a new private key is deleted from the firewall once the config has been viewed or downloaded, so a later compromise of the firewall or its backups cannot reveal it.
- **Access tiers**: *Admin* (full access), *BYOD / Guest* (internet only) and *Vendor / Contractor* (default deny except listed targets, with optional protocol and port), enforced by firewall rules tagged with the peer's key.
- **Lifecycle**: expiry with a 24-hour warning, access schedules (business hours, weekdays, date ranges, custom weekly windows), monthly data quotas that block or throttle, inactive-peer disabling, and directory sync that disables a peer when its LDAP / Active Directory account is disabled or leaves a required group.
- **Key management**: rotate, reissue (new keys and a fresh link, same address), scheduled auto-rotation with a seven-day warning, and pre-shared keys rotated with the keys.
- **Bulk actions**: CSV import, enable/disable, rotate, extend or set expiry, move to another tunnel, reissue, download and delete.
- **Profiles and group policies**: named provisioning presets, and policies that keep every member of a group in line with a profile.
- **Adopt existing peers**: bring peers made under *VPN > WireGuard* under WG Suite without reconnecting them, or import a `wg0.conf` from wg-easy, PiVPN or wireguard-ui.
- **Configuration preflight and drift detection**: warns before handing over a config that will not work, and when a peer's issued config no longer matches its tunnel or the kernel no longer matches `config.xml`.

### Diagnostics and monitoring
- **Connectivity doctor**: walks the whole chain for one peer (config, kernel, port, WAN rule, tunnel rule including the TCP-flags trap, NAT, handshake, traffic in both directions, addressing, path MTU) and gives a concrete fix for each failure.
- **Peer self-test**: a link the peer opens on the problem device to check routing, transfer size and speed; results land in the doctor report.
- **Network page**: live bandwidth, subnet utilisation per tunnel, top talkers, countries, quotas and a per-peer drawer.
- **Peer map**: consenting peers placed by IP or by an opt-in GPS check-in.
- **Audit trail and configuration history**: every WG Suite action with user and time, CSV export, and restore of tunnels, peers and rules from any earlier pfSense revision.
- **Notifications**: email, Telegram, WhatsApp and signed webhooks for expiry, rotation, quota, offline peers, drift, endpoint country changes, cloned configs and site-to-site endpoints outside their pinned range.
- **Status > Monitoring graphs** for tunnel and peer traffic.

### Self-service
- **Peer portal**: a bookmarkable page where a peer sees their status, runs the self-test, manages location consent, replaces their own keys and requests access for another device.
- **Sign-in**: portal link, plus optional TOTP, passkeys and OpenID Connect single sign-on (Entra ID, Google Workspace, Okta, Authentik, Keycloak).
- **Device requests**: peers ask for another device; an admin approves or declines.
- **Public listener**: serves only the peer-facing pages on a separate port, so it can be opened on WAN while the web interface stays on the LAN.

### Sites and resilience
- **Tunnel setup**: one click creates the key pair, tunnel, interface, WAN and tunnel rules and outbound NAT. IPv4, IPv6 and dual-stack; optional FRR / OSPF mesh routing.
- **Site-to-site wizard**: joins two pfSense routers, detects and offers to renumber overlapping LANs, and supports endpoint pinning.
- **HA sync**: copies peers, settings and rules to a backup node over XML-RPC with TLS key pinning; optional shared tunnel identity for CARP; quiet alerts on the backup.
- **WebSocket transport**: carries WireGuard over TCP 443 for hotel, guest and corporate networks that block UDP.
- **AmneziaWG obfuscation** when the AmneziaWG package is installed.

### Administration
- Three privilege tiers: **View**, **Peer operator** and **Admin**.
- Searchable in-GUI help covering every page, option and action.
- Translation-ready: follows the pfSense GUI language; see [docs/translations.md](docs/translations.md).
- Settings backup and restore, and an uninstall option that keeps or wipes your tunnels.
- A pfSense dashboard widget.

## Requirements

| | |
|---|---|
| **pfSense CE** | 2.8.x (FreeBSD 15). Developed and tested on CE 2.8.1. |
| **pfSense Plus** | Supported. WireGuard is built in. |
| **WireGuard package** | Required on CE (*System > Package Manager*). |
| **Optional** | FRR (mesh routing), AmneziaWG (obfuscation), pfrest REST API package (installed by WG Suite on request, for the mobile app). |

WG Suite has no external PHP dependencies, and every JavaScript library it uses is installed locally; the web GUI makes no CDN calls.

## Installation

From a shell on the firewall (SSH, option 8), or *Diagnostics > Command Prompt*:

```sh
cd /tmp
fetch https://github.com/3um3le3ee/pfSense-wireguard-peer-export/releases/download/v1.3.0/pfSense-pkg-wg-export-1.3.0.pkg
fetch https://github.com/3um3le3ee/pfSense-wireguard-peer-export/releases/download/v1.3.0/SHA256SUMS
sha256sum -c SHA256SUMS
pkg add -fM pfSense-pkg-wg-export-1.3.0.pkg
```

`sha256sum -c` must print `OK` before you install. (If your system has no `sha256sum`, run `sha256 pfSense-pkg-wg-export-1.3.0.pkg` and compare the result with `SHA256SUMS` by eye.) WG Suite then appears under **VPN > WG Suite**.

WG Suite is installed from the shell. It is not in Netgate's package repository, so it does not appear in *System > Package Manager*.

## Upgrading

Install the new release over the old one with the same commands. Peers, tunnels and settings live in `config.xml` and are kept.

After upgrading pfSense itself, check that **VPN > WG Suite** is still there. If it is not, run the install again; nothing in your configuration is lost.

## Uninstalling

```sh
pkg delete pfSense-pkg-wg-export
```

By default your WireGuard tunnels and peers are **kept** and keep working under *VPN > WireGuard*. WG Suite's own settings, rules, runtime files and scheduled jobs are always removed. To remove the tunnels and peers too, tick *Settings > Uninstall > Wipe WireGuard peers and tunnels on uninstall* first.

## Quick start

1. **Setup**: check the suggested description, port and address, then **Deploy Tunnel**.
2. **Dashboard > Add peer**: name it, pick an access policy, then **Provision & Save**.
3. Scan the QR code with the WireGuard app, download the `.conf`, or email a one-time link. The private key is shown once.
4. If the peer does not connect, run the **Connectivity doctor** (stethoscope) from its row.

Every option is explained in **VPN > WG Suite > Help**, which is searchable.

## Automation: REST API, metrics and health

**REST API** (`/wgx/vpn_wg_api.php/v1/...`) for scripts and onboarding. Keys have `read` or `write` scope, are stored hashed and are shown once.

```sh
curl -s -H "Authorization: Bearer $KEY" https://fw/wgx/vpn_wg_api.php/v1/peers
```

| Method | Path | |
|---|---|---|
| GET | `/v1/status` | service and tunnel summary |
| GET | `/v1/tunnels` | tunnels with peer counts |
| GET | `/v1/peers` | peers, optionally `?tunnel=NAME` |
| GET | `/v1/peers/{pubkey}` | one peer with live status |
| POST | `/v1/peers` | create: `{"descr", "tun", "profile"?}` |
| DELETE | `/v1/peers/{pubkey}` | delete |
| POST | `/v1/peers/{pubkey}/config-link` | mint a one-time config link |

Creating a peer returns a one-time configuration link, never key material.

**Prometheus metrics** (`/wgx/vpn_wg_metrics.php`) and the **node health endpoint** (`/wgx/vpn_wg_health.php`, for Uptime Kuma, Nagios and HA monitoring) share a bearer token set in *Settings > Metrics Endpoint*. Both return `404` until a token is set. See [docs/health-endpoint.md](docs/health-endpoint.md).

## Privacy

WG Suite was built to make WireGuard and privacy simple, so everything that leaves the firewall or records a person is **off by default**:

- **IP geolocation and reputation** use an HTTPS provider you choose, only once enabled. Private addresses are never sent.
- **Location on the map** needs each peer's recorded consent, whether by IP or GPS. GPS check-in happens only when the peer opens their link and approves the prompt; positions stay on the firewall and are deleted after the retention period you set.
- **Per-peer metrics** are off until enabled, and public keys are never exported.

## Security

- Server-side CSRF validation on every action, per-session rate limiting and an audit entry for every change and refusal.
- One-time private key view, single-use download links and hashed API, metrics and portal tokens.
- Secrets (HA password, chat and webhook tokens, geolocation key) sealed with a per-node key kept outside config backups.
- HA XML-RPC pinned to the backup's TLS key after first use.
- The WebSocket daemon runs as an unprivileged `wgx` user with per-peer tokens.
- Optional components fetched from the internet (pfrest) are pinned to a release and verified by SHA-256 before install.

Found a vulnerability? Please report it privately. See [SECURITY.md](SECURITY.md).

## Documentation

- **In the GUI**: *VPN > WG Suite > Help*, searchable, with an entry for every page, option and action.
- [Adopting and importing peers](docs/adopting-peers.md)
- [Group policies](docs/group-policies.md)
- [Node health endpoint](docs/health-endpoint.md)
- [Translations](docs/translations.md)
- [Changelog](CHANGELOG.md)

## Contributing

Bug reports, testing on different hardware and translations are all welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) first, and use the [issue forms](https://github.com/3um3le3ee/pfSense-wireguard-peer-export/issues/new/choose).

WG Suite is free and does not take donations.

## Licence

Copyright © 2026 3um3le3ee. Licensed under the [Apache License, Version 2.0](LICENSE). Bundled third-party libraries keep their own licences; see [NOTICE](NOTICE).

WG Suite is an independent community project. It is not affiliated with, endorsed by or supported by Netgate or the pfSense project. pfSense is a trademark of Electric Sheep Fencing LLC (Netgate); WireGuard is a registered trademark of Jason A. Donenfeld. Review the code before running it on production systems.
