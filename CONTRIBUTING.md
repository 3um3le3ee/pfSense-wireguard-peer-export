# Contributing to WG Suite

Thanks for helping. Bug reports, testing on different hardware and pfSense
versions, translations and code are all welcome.

## Reporting bugs

Use the [bug report form](https://github.com/3um3le3ee/pfSense-wireguard-peer-export/issues/new?template=bug_report.yml).
The most useful things to include are:

- the WG Suite version and build fingerprint (*VPN > WG Suite*, panel footer)
- the pfSense edition and version
- the **Connectivity doctor** output for the peer, if it is a connection problem
- the relevant lines from *VPN > WG Suite > Audit* and *Status > System Logs*

Redact public IP addresses, keys and hostnames before posting. Never post a
private key or a full `config.xml`.

Security problems go through [SECURITY.md](SECURITY.md), not issues.

## Translations

See [docs/translations.md](docs/translations.md). Generate the template with
`php tools/make_pot.php`, copy it to `locale/<lang>.po`, translate it in Poedit
and open a pull request with just that file.

### Before you open a pull request

```sh
php tests/prebuild_check.php .
```

It must finish with `0 failure(s)`. CI runs the same checks on every push and
pull request. If you add a mutation path (anything that changes peers, tunnels
or rules), add a case for it to `tests/verify.php`.

Test on a real pfSense box (or VM) before submitting, and say in the pull
request what you tested on.

### Pull requests

- One feature or fix per pull request.
- Keep changes focused; do not reformat files you are not otherwise changing.
- Add a line under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md).
- By submitting a pull request you agree that your contribution is licensed
  under the [Apache License 2.0](LICENSE).

## Releases (maintainer)

1. Move the `Unreleased` entries in `CHANGELOG.md` under the new version and
   add the version's row to `vpn_wg_credits.php`.
2. Build on FreeBSD 15 / pfSense: `sh build.sh` and enter the version.
3. Commit the version changes and tag: `git tag v1.3.0 && git push origin v1.3.0`.
4. In `dist/`, write the checksum: `sha256sum pfSense-pkg-wg-export-1.3.0.pkg > SHA256SUMS`.
5. Create the GitHub release from the tag, paste `.github/release-notes/v1.3.0.md`
   with the checksum filled in, and attach the `.pkg` and `SHA256SUMS`.
