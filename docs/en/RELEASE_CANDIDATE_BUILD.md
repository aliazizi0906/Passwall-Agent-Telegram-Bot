# Release build marker — v1.3.0-rc.1

Release source: `feature/v1.3-control-center`. The matching official GitHub Release should be marked **Pre-release**, not Stable. Builds require the repository Ed25519 private signing key to match `UPDATE_PUBLIC_KEY.b64`.

Validated pre-publication by branch CI:
- Go fmt, test, vet and six target architectures
- PHP lint and setup/command validation
- Dashboard/LuCI JavaScript syntax
- OpenWrt shell/JSON checks
- cPanel ZIP packing and checksum (without runtime secrets)
- README / installation and migration guidance

Still requires real-hardware acceptance, especially WAN/Wi-Fi rollback, cellular modem drivers, leak safety, rate limits and LED RGB writes, before GA `v1.3.0`. Do not replace production v0.6 without staging.
