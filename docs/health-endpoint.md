# Node health endpoint

`/wgx/vpn_wg_health.php` answers one question per firewall: could this node
carry the WireGuard service right now? On an HA pair that matters most for the
backup, which nothing uses until the moment it has to work.

## Enabling it

It uses the metrics token: *Settings > Metrics Endpoint*. Without a token the
page returns `404`.

## Requests

```sh
# JSON; HTTP 200 when ok or warn, 503 when anything fails
curl -s -H "Authorization: Bearer $TOKEN" https://fw1/wgx/vpn_wg_health.php

# 503 on warnings too
curl -s -H "Authorization: Bearer $TOKEN" "https://fw1/wgx/vpn_wg_health.php?strict=1"

# Prometheus series (always HTTP 200; the state is in the values)
curl -s -H "Authorization: Bearer $TOKEN" "https://fw1/wgx/vpn_wg_health.php?format=prometheus"
```

A monitor that cannot send headers may use `?token=`, but the token then
appears in the web server log. Uptime Kuma, Nagios `check_http` and similar
tools only need the HTTP status.

## Checks

| id          | fail when                                         | warn when                         |
|-------------|---------------------------------------------------|-----------------------------------|
| `carp`      |                                                   | VIPs are split MASTER/BACKUP, or their state is unreadable |
| `tunnels`   | an enabled tunnel is not running, or `wg` is missing |                                |
| `drift`     |                                                   | running peers differ from config.xml |
| `websocket` | the WebSocket port forward exists but nothing listens behind it |                     |
| `cron`      |                                                   | WG Suite's scheduled job has not run for 20 minutes |
| `ha_sync`   | the last sync to the backup failed (primary only) | sync is configured but never ran  |
| `ha_match`  |                                                   | the backup's client peers differ from the primary's, or the backup cannot be read (primary only) |

The `websocket` check covers a gap in HA. The WAN forward is ordinary
configuration and can reach the backup. The WebSocket server behind it is
deployed per node. Without the server, every WebSocket peer drops on failover.

## Peer fingerprint

Each node reports `fingerprint: {peers, hash}`. It is a hash over the client
peers HA sync copies:

- public key and tunnel
- enabled state and addresses
- whether a preshared key is set (the key is hashed with a fixed prefix, never
  output)
- keepalive, tier and target

It leaves out site-to-site peers and the flags each node keeps for itself, so
a healthy pair reports the same hash. The output names no peers.

The primary also asks the backup for its fingerprint over the HA sync
connection, at most once every five minutes. It reports the comparison as
`ha_match`. A backup running an older WG Suite can only report a count; the
check says so.

## Prometheus

| Series | What it is |
|--------|------------|
| `wgx_health_status` | overall status: 0 ok, 1 warn, 2 fail |
| `wgx_health_check{check="…"}` | one check: 0 ok, 1 warn, 2 fail |
| `wgx_ha_peer_fingerprint_info{fingerprint="…",role="…"}` | always 1; the fingerprint is in the label |
| `wgx_ha_synced_peers` | client peers covered by the fingerprint |
| `wgx_ha_last_sync_timestamp_seconds` | primary only: time of the last sync |
| `wgx_ha_last_sync_success` | primary only: 1 when the last sync succeeded |

Example alert for nodes that disagree, with both nodes scraped:

```
count(count by (fingerprint) (wgx_ha_peer_fingerprint_info)) > 1
```

The endpoint is also served on the public listener, like the metrics
endpoint, so a monitor outside the network can reach it there.
