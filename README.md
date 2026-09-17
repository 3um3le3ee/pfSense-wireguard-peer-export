# WG Suite — WireGuard Provisioning Package for pfSense

> **Version 1.2.0** · pfSense CE 2.9.x / Plus · FreeBSD 15/16 · Apache 2.0

WG Suite is a full-featured WireGuard management and provisioning package for pfSense. It extends the built-in WireGuard interface with peer lifecycle management, live telemetry, site-to-site VPN automation, high-availability sync, a self-service peer portal, and a real-time NOC dashboard — all integrated into the pfSense GUI.

One click to add a peer, get the `.conf` file, and generate a QR code. No more configuring both sides manually.

Adding a WireGuard peer on pfSense normally means: create the peer in the GUI, manually generate keys, copy the public key back, hand-craft the client config, and figure out the endpoint/subnet yourself. This plugin turns all of that into a single step — click **Add New Peer**, fill in a name, and you get a ready-to-use config file and QR code while the peer is automatically registered on the firewall.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [REST API](#rest-api)
- [Metrics Endpoint](#metrics-endpoint)
- [Self-Test & Diagnostics](#self-test--diagnostics)
- [Uninstallation](#uninstallation)
- [License](#license)

---

## Features

### Peer Management
- Provision peers with auto-assigned IPs, pre-shared keys, DNS, keepalive, and access tier
- Per-peer bandwidth charts with live polling
- Peer groups, tags, expiry scheduling, and data quotas with throttling
- Bulk enable/disable/delete and bulk key rotation
- Peer Doctor: 12-point connectivity diagnostic tool
- Config export as `.conf` file, QR code (with logo), or WebSocket bundle
- One-time secure config download links — consumed on first use

### Self-Service Peer Portal
- Bookmarkable per-peer portal page — single link for status, self-test, and config re-download
- Config re-arm mints a fresh single-use download token on demand
- Opt-in: disabled by default; enabled per-tunnel in Settings

### NOC Dashboard
- Live peer status table: online/offline, last handshake, Rx/Tx, endpoint, tags
- Async table build — page shell loads immediately, rows populate in the background
- Filter by tunnel, group, tag, and status
- Tunnel create/edit modal integrated into the dashboard
- pfSense dashboard widget showing live peer count and status summary

### Network Map
- World map of connected peers using IP geolocation (ip-api.com, opt-in)
- GPS check-in support: peers submit their own device location via a token-protected link
- Three location tiers: GPS (highest accuracy) > IP geolocation > site fallback
- Per-peer consent tracking; consent source (peer vs admin) recorded and displayed

### Site-to-Site VPN Wizard
- Paired-blob round-trip design — no cross-box login required
- Three phases: `pair_generate` → `pair_apply` → `pair_finalize`
- Automatic OPT interface assignment, gateway, static routes, and firewall rules on each node

### High-Availability Sync
- XMLRPC sync over HTTPS to a backup pfSense node
- Syncs: WireGuard package config, WGX settings, firewall rules
- Auto-installs XMLRPC allow-rules on the backup node
- Manual and automatic sync modes; last sync status displayed in the HA tab
- Background sync fired via `register_shutdown_function` after `fastcgi_finish_request()`

### Audit Log
- Append-only log (5 MB with `.1` rotation)
- Date range, keyword, and action-type filters (peer changes, key rotation, HA sync, GPS, auth, backup/restore, etc.)
- All provisioning, deletion, settings changes, and CSRF events recorded with timestamp and pfSense username

### Settings
- Global peer defaults: DNS, keepalive, access tier, fallback subnets, PSK enforcement
- Email notification templates (peer provisioned, expiry warning)
- Chat (Slack/Teams webhook) and generic webhook notifications
- Settings backup and restore (JSON export, with optional secrets redaction)
- Named profiles for reusable peer configuration sets
- Peer limit enforcement with slot counter

### Security
- Server-side CSRF validation on all POST actions (`X-WGX-CSRF` header)
- Client-side X25519 key generation via WebCrypto API — private keys never transit the server
- Per-session rate limiting on provisioning and check-in endpoints
- Firewall rules tagged `WGX:` for auditability and clean deinstall
- All pfSense config access via `config_get_path`/`config_set_path` — no direct `$config[]` access
- External API calls (geolocation, Tor exit list) behind explicit opt-in toggles

### WebSocket Transport
- PHP WebSocket server with rc.d service management
- Token-authenticated; TLS-capable
- Enables real-time peer status streaming and WebSocket bundle export for clients

---

## Requirements

| Requirement | Version |
|---|---|
| pfSense CE | 2.8.x (FreeBSD 15) or later |
| pfSense Plus | 24.x or later |
| WireGuard package | Required on CE; built-in on Plus |
| PHP | 8.x (bundled with pfSense) |

The package targets Netgate's official pfSense package repository format. It carries no external PHP dependencies.

---

## Installation

```sh
# SSH into pfSense (option 8 for shell) and run:
curl -LO https://github.com/3um3le3ee/pfSense-wireguard-peer-export/releases/latest/download/pfSense-pkg-wg-export-1.2.0.pkg

# Install on the firewall
pkg add -fM pfSense-pkg-wg-export-1.2.0.pkg
```
## Alternative

```sh
# Copy the package to the firewall
scp dist/pfSense-pkg-wg-export-1.2.0.pkg root@<pfsense-ip>:/tmp/

# Install on the firewall
ssh root@<pfsense-ip> 'pkg add /tmp/pfSense-pkg-wg-export-1.2.0.pkg'
```

After installation, WG Suite appears under **VPN → WG Suite** in the pfSense menu.

---

## Uninstallation

```sh
# SSH into pfSense (option 8 for shell) and run:
pkg delete pfSense-pkg-wg-export

ssh root@<pfsense-ip> 'pkg delete pfSense-pkg-wg-export'
```

WireGuard tunnels and peers configured through the standard pfSense WireGuard interface are **not** removed.

---

## REST API

The REST API accepts API key authentication and exposes peer and tunnel management endpoints. API keys are provisioned in **Settings → API Keys**.

All API requests require an `X-WGX-API-Key` header.

---

## Metrics Endpoint

Exposes a Prometheus-compatible scrape endpoint. Enable it in **Settings → Metrics Endpoint** and configure an optional bearer token for authentication.

---

## Self-Test & Diagnostics

**Peer Doctor** (accessible from the Dashboard peer row) runs 12 checks against a peer:
- Tunnel state, kernel peer registration, allowed IPs, handshake recency
- DNS resolution, endpoint reachability, firewall rule presence
- Key validity, quota and expiry status, and more

**Self-Test** is the peer-facing equivalent: a token-protected page the peer opens themselves to confirm their tunnel is working.

**Package Self-Test** checks the package installation health: cron entry, deployed files, library load guards, data store writability, and more.

---

## License

Licensed under the [Apache License, Version 2.0](LICENSE).

Copyright © 2026 3um3le3ee
