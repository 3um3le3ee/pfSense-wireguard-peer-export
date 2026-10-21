# Changelog

All notable changes to WG Suite are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.3.0] – 2026-10-21

The largest release so far: self-service sign-in, device requests, group
policies, adopting existing peers, configuration history, a public listener,
HA hardening, searchable help and translation support. The code base was also
split from one 13,000-line page into handler and library files, with a test
harness that runs before every build.

### Added

**Self-service portal**
- Passkey (WebAuthn) sign-in, up to ten per peer.
- OpenID Connect single sign-on (Entra ID, Google Workspace, Okta, Authentik,
  Keycloak or any OIDC provider). Peers are matched by verified email and then
  bound to the provider's subject.
- Self-service key replacement: a new key pair is staged and the old one is
  revoked only once the new one has handshaked, so the peer is never cut off.
- Device requests: a signed-in peer can ask for access on another device; an
  admin approves (a new peer and a one-time link are created) or declines.

**Peers and policy**
- Group policies bind a profile to a group, applied by hand from a preview or
  automatically by the scheduled job. See `docs/group-policies.md`.
- Group bandwidth caps, and a *throttle* option for over-quota peers instead of
  blocking them.
- Adopt peers already created under *VPN > WireGuard*, and import peers from
  another server's `wg0.conf` (names read from wg-easy, PiVPN and wireguard-ui
  comments). See `docs/adopting-peers.md`.
- Bulk *Move to Tunnel*.
- *Disable Inactive Peers* after a set number of days without a handshake.
- *Required Group* for directory sync, including nested Active Directory groups.
- Peer DNS names (`<peer>.<domain>`) kept up to date in the DNS Resolver.
- AmneziaWG obfuscation parameters when the AmneziaWG package is installed.
- Pre-shared keys are replaced along with the keys on rotation.

**Monitoring and alerts**
- Node health endpoint `/wgx/vpn_wg_health.php` (JSON and Prometheus) for
  CARP state, tunnels, drift, the WebSocket forward, the scheduled job and HA
  sync, including a peer fingerprint comparison between primary and backup.
  See `docs/health-endpoint.md`.
- Tunnel and peer traffic in *Status > Monitoring*.
- Alerts for a peer that stops handshaking, an endpoint country change, a
  config apparently running on two devices, and a site-to-site endpoint outside
  its pinned range.
- HMAC-SHA256 signed webhooks (`X-WGX-Timestamp`, `X-WGX-Signature`).
- Configuration history: see what a pfSense revision changed and restore its
  tunnels, peers and WG Suite rules, with the current state saved first.

**Administration**
- Three privilege tiers: *View*, *Peer operator* and *Admin*.
- Public listener: serves only the peer-facing pages on their own port, so it
  can be opened on WAN while the web interface stays on the LAN.
- CARP awareness: quiet alerts while a node is BACKUP, and an optional shared
  tunnel identity so one client config works on either node.
- Searchable help page under *VPN > WG Suite > Help*.
- Translation support: WG Suite follows the pfSense GUI language and reads
  `locale/<lang>.po`. See `docs/translations.md`.
- Uninstall option to keep (default) or wipe WireGuard tunnels and peers.

### Changed
- `vpn_wg_export.php` split into `wgx_handlers_*` and `wgx_lib_*` files.
- Pre-build checks: syntax, redeclared functions, unpackaged includes,
  licence headers, privilege coverage, public-page list, web-root guards,
  leftover files and translations. A mutation-path test harness runs every
  build.
- `build.sh` asks for the version and writes it into every file that states it,
  and refuses to build if any still disagree.

### Security
- HA XML-RPC pins the backup's TLS public key on first use and enforces it.
- HA password, chat tokens, webhook secret and geolocation key are sealed with
  a per-node key kept outside config backups. After restoring `config.xml` on
  new hardware, re-enter them.
- The WebSocket daemon runs as the unprivileged `wgx` user, with per-peer
  tokens; downloading a new bundle revokes the previous one.
- The pfrest REST API package is pinned to a release and verified by SHA-256
  before it is installed.
- Portal: MFA attempt cap and TOTP replay protection; OIDC requires
  `email_verified`; passkeys require user verification for MFA.
- Check-in and self-test sessions are bound to their token; self-test links
  expire and are byte-limited.
- Metrics and health tokens are stored hashed.
- Restored peers can no longer reuse an existing address or key, and archives
  are extracted without their owners and permissions.
- Bulk config export deletes the private keys it hands out, as the single view
  already did.

### Fixed
- Key rotation by the scheduled job and by bulk action now moves the peer's
  zero-trust and isolation rules to the new key.
- The WebSocket WAN forward is removed when no tunnel uses WebSocket any more.

### Upgrade notes
- OIDC: a token without `email_verified: true` is refused. Entra ID usually
  omits that claim; see the help entry *Portal single sign-on*.
- WebSocket peers keep working on the shared token until every enabled
  WebSocket peer has downloaded a new bundle; the shared token then stops.
- The OIDC client secret is kept in `/var/db`, not `config.xml`, so enter it on
  each HA node.

## [1.2.0] – 2026-09-17

### Added
- Mobile-responsive layout on every page.
- Configurable business hours, and expiry warning emails 24 hours before a
  peer expires.
- Audit log CSV export.
- WebSocket deployment service probe; WebSocket server periodic stats logging.
- Tunnel client auto-reconnect with exponential backoff.
- Credits tab on every page.

### Changed
- `wg show all dump` consolidated, three times fewer subprocesses per page.

### Fixed
- WebSocket server IPv6 handling and write timeout.
- GPS check-in rate limiting and file locking.

### Security
- The development reset is gated behind the `WGX_DEV_MODE` constant, and the
  build refuses a package that defines it.

## [1.1.0] – 2026-07-15

### Added
- Peer Connectivity Doctor: a 12-check diagnostic per peer.
- Delete Tunnel, with assignment guard, cascade confirmation and typed-name
  confirmation.
- NOC dashboard: stat cards, sparklines, anomaly badges, subnet utilisation by
  tunnel, top talkers (24 h) and country distribution.
- Peer map (Leaflet, installed locally) and GPS check-in.
- Site-to-site deployment wizard and HA sync.
- System audit trail.
- WebSocket transport.
- Opt-in IP reputation and geolocation.
- Bandwidth quota enforcement through a pfSense alias.

### Fixed
- Subnet usage accounting per tunnel, and percentages that rounded small use
  down to 0%.

## [1.0.0] – 2026-04

### Added
- Initial release: peer provisioning, QR code export, bulk CSV import, peer
  expiry and scheduling, key rotation and the dashboard widget, on pfSense CE
  and Plus.

[Unreleased]: https://github.com/3um3le3ee/pfSense-wireguard-peer-export/compare/v1.3.0...HEAD
[1.3.0]: https://github.com/3um3le3ee/pfSense-wireguard-peer-export/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/3um3le3ee/pfSense-wireguard-peer-export/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/3um3le3ee/pfSense-wireguard-peer-export/releases/tag/v1.1.0
[1.0.0]: https://github.com/3um3le3ee/pfSense-wireguard-peer-export/releases
