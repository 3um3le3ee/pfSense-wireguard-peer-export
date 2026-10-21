# Group policies

*Dashboard > More > Group policies* (`/wgx/vpn_wg_groups.php`). Only the
Admin privilege can open it.

A profile is normally applied once, when a peer is created. After that the
peer and the profile are independent. A group policy binds a profile to a
group instead, so the group's members keep following it:

- Edit the profile and every member changes with it.
- A peer that is given the group picks up its settings.

## What is enforced

| Profile setting                | Effect on a member                                  |
|--------------------------------|-----------------------------------------------------|
| Access tier, zero-trust target | The member's firewall rules are rewritten. Connections the new tier does not allow are cut. |
| Schedule                       | WG Suite's scheduled job acts on it at its next run, and may disable the peer outside its hours. |
| Auto-rotate keys               | Taken up by the scheduled job.                      |
| Quota / quota exempt           | Taken up by the scheduled job.                      |
| Persistent keepalive           | The tunnel is reloaded. The device does not notice. |
| DNS, MTU                       | Stored on the peer. They are part of the device's own configuration, so the device keeps its current settings until it is sent a new one. The Dashboard's "config outdated" banner lists those devices. |

## What is not enforced

These profile settings are never applied to existing members:

- **Tunnel:** moving tunnels disconnects the device.
- **AllowedIPs:** they live in the device's configuration.
- **Expiry:** the profile stores a number of days, so applying it again on
  every run would keep pushing the expiry forward.

The preview lists which of these the profile has.

## Applying

| Mode | What happens |
|------|--------------|
| **By hand** (default) | Nothing changes until you open the group's preview and apply it. The preview lists, per member, each field as it is now and as it will be, and what that will do. Untick members to leave them out. The write is refused if anything changed after the preview was made. |
| **Automatic** | `wgx_expire.php`, the scheduled job that runs every few minutes, brings members in line on each run. It logs the change to the audit log and the history of each peer. A member it cannot change is left alone, and the system log reports it once. An example is a vendor tier with no target. Exceptions are not possible: turn Automatic off to keep one. |

The Dashboard's More menu shows how many members differ from their group's
profile.

## Notes

- A group's bandwidth cap is still set in *Settings* (Group bandwidth caps).
  The Group policies page shows it next to the group.
- Deleting a profile that a policy uses stops the policy. Members keep their
  current settings, and the page says the profile is gone.
- Removing a policy leaves the members as they are.
- Policies are stored in `installedpackages/wgexport/group_policies`. They are
  removed on uninstall with the rest of WG Suite's settings.
