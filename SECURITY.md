# Security policy

WG Suite runs on firewalls and handles VPN keys, so security reports are taken
seriously and answered first.

## Supported versions

| Version | Security fixes |
|---------|----------------|
| 1.3.x   | Yes            |
| 1.2.x   | Until 1.4.0 is released |
| < 1.2   | No             |

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Report it privately through GitHub:
[Security > Report a vulnerability](https://github.com/3um3le3ee/pfSense-wireguard-peer-export/security/advisories/new).

If you cannot use GitHub, email **3um3le3ee@mail.com** with `WG Suite security`
in the subject.

Include, if you can:

- the WG Suite version (*VPN > WG Suite*, panel footer) and pfSense version
- the page, endpoint or file involved
- steps to reproduce, or a proof of concept
- what an attacker would need (a login, a peer link, network position) and what
  they gain

Do not send real private keys, `config.xml` files or peer configurations.
Redact them.

## What happens next

- You get an acknowledgement within **5 days**.
- The issue is confirmed or ruled out, and you are told which, within
  **14 days**.
- A fix is released as soon as it is ready, normally within **30 days** for
  anything that exposes keys, bypasses authentication or allows code execution.
- A GitHub security advisory is published with the release. You are credited
  unless you ask not to be.

Please give the fix time to ship before disclosing publicly.

## In scope

- Authentication or privilege bypass, including between the View, Peer
  operator and Admin tiers
- Exposure of private keys, pre-shared keys, tokens or sealed secrets
- The peer-facing pages: portal, configuration download, check-in, self-test,
  device requests, REST API, metrics and health endpoints, and the public
  listener
- HA sync, site-to-site pairing and the WebSocket daemon
- Command, SQL, XML or code injection, CSRF and XSS

## Out of scope

- Vulnerabilities in pfSense, FreeBSD, WireGuard or third-party packages
  themselves (report those upstream)
- Issues that need full Admin access to the firewall already
- Missing hardening headers without a demonstrated impact
