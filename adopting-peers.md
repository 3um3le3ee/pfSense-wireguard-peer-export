# Adopting and importing peers

*Dashboard > More > Adopt existing peers* (`/wgx/vpn_wg_adopt.php`).
Only the Admin privilege can open it.

Every change on this page is a preview first. Before anything is written, the
page rebuilds the plan from the current configuration and compares it with the
preview. If anything changed in between, it writes nothing and asks you to look
again. Changes include another admin editing peers, or a different group or
tags than the preview was made with.

## Peers already on this firewall

These are peers made under VPN > WireGuard, or before WG Suite was installed.
They work and the Dashboard lists them, but they have none of WG Suite's own
fields.

**What adopting writes:**

| Field            | Value                                                    |
|------------------|----------------------------------------------------------|
| `wgx_tier`       | `admin`, which is the access the peer has now            |
| `wgx_group`, `wgx_tags` | if you enter them                                 |
| `wgx_s2s`        | `yes`, if the peer routes whole networks and has a fixed endpoint |
| `keepalive`      | copied from `persistentkeepalive`, so WG Suite shows it  |
| `descr`          | only if empty: "Peer" and the first 8 characters of the key |
| `wgx_created_by`, `wgx_adopted` | record where it came from                 |

The `wgx_s2s` flag matters for HA. Without it, HA sync copies the peer to the
backup router as a client peer.

**What it does not touch:** keys, addresses, endpoints, the preshared key, or
anything else the tunnel uses. No device reconnects, and the tunnel is not
reloaded.

**What stays the same afterwards:**

- WG Suite cannot show or email the peer's configuration, because the private
  key is only on the device. *Reissue config* gives the device a new key and
  configuration when you need one.
- The key age shows as unknown until the keys are rotated.

**What the preview flags:**

- **Blocks adopting:**
  - a missing or invalid public key
  - a tunnel that does not exist
- **Warnings:**
  - no AllowedIPs
  - an address shared with another peer on the same tunnel
  - an address outside the tunnel's subnet

## Peers from another WireGuard server

Paste the server configuration (`/etc/wireguard/wg0.conf` or similar), or
choose the file, then pick a tunnel.

- **What each `[Peer]` becomes:** a peer on that tunnel with its public key,
  preshared key, AllowedIPs, endpoint and keepalive. Devices keep their own
  keys.
- **Names:** taken from the comments these tools write:
  - wg-easy: `# Client: name`
  - PiVPN: `### begin name ###`
  - wireguard-ui: `# friendly_name = name`
  - otherwise, a `# name` line just above `[Peer]`
- **Ignored settings:** `[Interface]` settings other than the private key,
  address and port, such as `DNS`, `PostUp` and `MTU`. The preview lists the
  ones it ignored.

**Repointing the devices:** each device's configuration needs:

- **Endpoint:** this firewall's address and the tunnel's port.
- **PublicKey:** the tunnel's public key, unless the tunnel takes over the old
  server's key.

**Using the old server's key:** tick *Use the old server's key for this tunnel*
and the tunnel gets the `[Interface] PrivateKey` from the file. The devices
then only need their Endpoint changed.

- It is allowed only on a tunnel with no peers, because changing a tunnel's
  key cuts off every device already using it.
- Retire the old server once the devices have moved.

**What the preview flags:**

- **Blocks a peer:**
  - a key that is already on this firewall
  - an address already in use on the tunnel
  - an invalid key or address
- **Warnings:**
  - addresses outside the tunnel's subnet
  - a different listen port from the old server
  - an old server subnet that does not match the tunnel. Devices keep their
    old addresses, so traffic back to them is not routed until one side is
    changed.

The pasted configuration can contain the old server's private key. Between
preview and import it is kept on the firewall in `/tmp` (mode 600), not
written back into the page. It is deleted after the import, or after an hour.
