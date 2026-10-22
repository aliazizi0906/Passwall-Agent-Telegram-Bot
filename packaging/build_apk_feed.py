#!/usr/bin/env python3
"""Create signed APK v3 packages and direct-URL per-architecture indexes.

The caller supplies OpenWrt's SDK host apk executable. No home-grown APK
serialization is used; apk mkpkg and mkndx produce and sign the binary files.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

from build_feed import ARCHITECTURES, _read_bundle, _validate_elf


REPOSITORY = "aliazizi0906/Passwall-Agent-Telegram-Bot"
APK_KEY_NAME = "pw-agent-apkv3-p256.pem"
KEY_INFO = b"pw-agent-apkv3-ecdsa-p256-signing-v1"
KEY_SALT = b"pw-agent-release-ed25519-to-apkv3-v1"
P256_ORDER = int("ffffffff00000000ffffffffffffffffbce6faada7179e84f3b9cac2fc632551", 16)
VERSION_RE = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+$")


def apk_version(version: str) -> str:
    """Map a stable project release to an APK package build version."""
    if not VERSION_RE.fullmatch(version):
        raise ValueError("only stable X.Y.Z releases can be distributed via APK v3")
    return f"{version}-r0"


def asset_name(version: str, architecture: str) -> str:
    if architecture not in ARCHITECTURES:
        raise ValueError(f"unsupported package architecture: {architecture}")
    return f"pw-agent-{apk_version(version)}-{ARCHITECTURES[architecture]['opkg']}.apk"


def index_name(architecture: str) -> str:
    if architecture not in ARCHITECTURES:
        raise ValueError(f"unsupported package architecture: {architecture}")
    return f"pw-agent-packages-{architecture}.adb"


def key_from_ed25519_pem(pem: bytes):
    """Derive a separate deterministic ECDSA P-256 signing key (domain separated).

    This does not repurpose an Ed25519 private key as an EC scalar directly;
    HKDF binds the derived key to this particular APK v3 use and version.
    """
    try:
        from cryptography.hazmat.primitives import hashes, serialization
        from cryptography.hazmat.primitives.asymmetric import ec, ed25519
        from cryptography.hazmat.primitives.kdf.hkdf import HKDF
    except ImportError as error:
        raise RuntimeError("key derivation requires Python cryptography") from error

    source = serialization.load_pem_private_key(pem, password=None)
    if not isinstance(source, ed25519.Ed25519PrivateKey):
        raise ValueError("release signing key must be Ed25519 PKCS8 PEM")
    seed = source.private_bytes(
        encoding=serialization.Encoding.Raw,
        format=serialization.PrivateFormat.Raw,
        encryption_algorithm=serialization.NoEncryption(),
    )
    material = HKDF(algorithm=hashes.SHA256(), length=48, salt=KEY_SALT, info=KEY_INFO).derive(seed)
    scalar = (int.from_bytes(material, "big") % (P256_ORDER - 1)) + 1
    return ec.derive_private_key(scalar, ec.SECP256R1())


def derive_signing_files(release_pem_b64: str, private_destination: Path, public_destination: Path) -> None:
    """Write a private key to runner-local temporary storage and public PEM to dist.

    The caller must remove the private file after use; CI also installs a trap.
    """
    from cryptography.hazmat.primitives import serialization

    key = key_from_ed25519_pem(base64.b64decode(release_pem_b64.strip(), validate=True))
    private = key.private_bytes(
        encoding=serialization.Encoding.PEM,
        format=serialization.PrivateFormat.TraditionalOpenSSL,
        encryption_algorithm=serialization.NoEncryption(),
    )
    public = key.public_key().public_bytes(
        encoding=serialization.Encoding.PEM,
        format=serialization.PublicFormat.SubjectPublicKeyInfo,
    )
    private_destination.parent.mkdir(parents=True, exist_ok=True)
    public_destination.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(private_destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "wb") as output:
        output.write(private)
    os.chmod(private_destination, 0o600)
    public_destination.write_bytes(public)


def stage_payload(source: Path, destination: Path, arch: str) -> None:
    bundle = _read_bundle(source)
    _validate_elf(bundle["pw-agent"], arch)
    for item, content, mode in (
        ("usr/bin/pw-agent", bundle["pw-agent"], 0o755),
        ("etc/init.d/pw-agent", bundle["pw-agent.init"], 0o755),
        ("usr/share/pw-agent/config.example.json", bundle["config.example.json"], 0o644),
    ):
        path = destination / item
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)
        path.chmod(mode)


def pre_install(arch: str) -> str:
    pattern = ARCHITECTURES[arch]["machine_case"]
    return f"""#!/bin/sh
set -eu
case "$(uname -m)" in
  {pattern}) exit 0 ;;
  *) echo 'PW Agent: package targets {arch}, but router CPU is incompatible' >&2; exit 1 ;;
esac
"""


def post_install() -> str:
    return """#!/bin/sh
set -eu
mkdir -p /etc/pw-agent /etc/pw-agent/backups /etc/pw-agent/safety
chmod 700 /etc/pw-agent /etc/pw-agent/backups /etc/pw-agent/safety
if [ ! -e /etc/pw-agent/config.json ]; then
  cp /usr/share/pw-agent/config.example.json /etc/pw-agent/config.json
  chmod 600 /etc/pw-agent/config.json
  echo 'PW Agent: configure /etc/pw-agent/config.json before enabling the service'
  exit 0
fi
if grep -q CHANGE_ME /etc/pw-agent/config.json; then
  echo 'PW Agent: sample secret still present; service remains disabled'
  exit 0
fi
if [ ! -f /etc/config/passwall2 ]; then
  echo 'PW Agent: Passwall2 must be installed before starting the service'
  exit 0
fi
/etc/init.d/pw-agent enable
/etc/init.d/pw-agent restart || true
"""


def pre_deinstall() -> str:
    return """#!/bin/sh
set -eu
/etc/init.d/pw-agent stop >/dev/null 2>&1 || true
/etc/init.d/pw-agent disable >/dev/null 2>&1 || true
"""


def _run(args: list[str], *, epoch: int, cwd: Path | None = None) -> subprocess.CompletedProcess[str]:
    env = os.environ.copy()
    env["SOURCE_DATE_EPOCH"] = str(epoch)
    result = subprocess.run(args, cwd=cwd, env=env, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.returncode != 0:
        raise RuntimeError(f"apk command failed ({result.returncode}): {' '.join(args[:3])}: {result.stderr.strip() or result.stdout.strip()}")
    return result


def _create_apk(apk: Path, signing_key: Path, version: str, arch: str, source: Path, output: Path, epoch: int) -> Path:
    spec = ARCHITECTURES[arch]
    output_file = output / asset_name(version, arch)
    with tempfile.TemporaryDirectory(prefix=f"pw-agent-apkv3-{arch}-") as tmp:
        work = Path(tmp)
        files_root = work / "files"
        stage_payload(source, files_root, arch)
        scripts = {
            "pre-install": pre_install(arch),
            "post-install": post_install(),
            "pre-deinstall": pre_deinstall(),
        }
        opts = []
        for hook, body in scripts.items():
            path = work / f"{hook}.sh"
            path.write_text(body, encoding="ascii")
            path.chmod(0o755)
            opts.extend(("--script", f"{hook}:{path}"))
        _run(
            [
                str(apk), "mkpkg", "--info", "name:pw-agent",
                "--info", f"version:{apk_version(version)}",
                "--info", f"arch:{spec['opkg']}",
                "--info", "description:PW Agent OpenWrt service agent for Passwall2",
                "--info", "license:Unspecified",
                "--info", "origin:pw-agent",
                "--info", f"url:https://github.com/{REPOSITORY}",
                "--info", "maintainer:PW Agent maintainers",
                "--info", f"build-time:{epoch}",
                "--files", str(files_root),
                *opts,
                "--sign", str(signing_key),
                "--output", str(output_file),
            ],
            epoch=epoch,
        )
    if not output_file.is_file() or output_file.stat().st_size < 64:
        raise RuntimeError(f"apk mkpkg did not create a valid file for {arch}")
    return output_file


def build_apk_feed(apk: Path, private_key: Path, trusted_keys: Path, source_dir: Path, output_dir: Path, version: str, epoch: int) -> list[Path]:
    """Build six signed native APK packages with one signed, direct index each."""
    apk_version(version)
    if epoch < 0:
        raise ValueError("SOURCE_DATE_EPOCH must be non-negative")
    if not apk.is_file() or not os.access(apk, os.X_OK):
        raise ValueError("apk must be the executable supplied by the pinned OpenWrt 25.12 SDK")
    if not private_key.is_file() or not trusted_keys.is_dir():
        raise ValueError("missing signing key or trusted public-key directory")
    output_dir.mkdir(parents=True, exist_ok=True)
    output_files = []
    for arch in sorted(ARCHITECTURES):
        source = source_dir / f"pw-agent-openwrt-{arch}.tar.gz"
        if not source.is_file():
            raise FileNotFoundError(f"missing authenticated release asset: {source.name}")
        package = _create_apk(apk, private_key, version, arch, source, output_dir, epoch)
        index = output_dir / index_name(arch)
        # A direct index repository must reference a relative package asset
        # name. APK substitutes the package's version and architecture.
        _run(
            [
                str(apk), "mkndx", "--root", str(output_dir), "--keys-dir", str(trusted_keys),
                "--allow-untrusted", "--sign", str(private_key),
                "--pkgname-spec", "pw-agent-${version}-${arch}.apk",
                "--output", str(index), str(package),
            ],
            epoch=epoch,
        )
        if not index.is_file() or index.stat().st_size < 64:
            raise RuntimeError(f"apk mkndx did not create a valid index for {arch}")
        # Use the *real* SDK validator. Never accept unsigned or mismatched files.
        _run([str(apk), "verify", "--keys-dir", str(trusted_keys), str(package), str(index)], epoch=epoch)
        dumped = _run([str(apk), "adbdump", "--format", "json", str(index)], epoch=epoch)
        if "pw-agent" not in dumped.stdout or ARCHITECTURES[arch]["opkg"] not in dumped.stdout:
            raise RuntimeError(f"APK v3 index for {arch} does not describe the expected package")
        output_files.extend((package, index))
    return output_files


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="task", required=True)
    derive = commands.add_parser("derive-key", help="derive ephemeral APK P256 key from existing Ed25519 release secret")
    derive.add_argument("--private-key-out", type=Path, required=True)
    derive.add_argument("--public-key-out", type=Path, required=True)
    build = commands.add_parser("build-feed", help="build and validate all six signed APK packages and indexes")
    for name in ("apk", "private-key", "trusted-keys", "source-dir", "output-dir"):
        build.add_argument("--" + name, type=Path, required=True)
    build.add_argument("--version", required=True)
    build.add_argument("--source-date-epoch", type=int, required=True)
    args = parser.parse_args()
    if args.task == "derive-key":
        material = os.environ.get("UPDATE_SIGNING_PRIVATE_KEY_PEM_B64", "")
        if not material:
            raise ValueError("UPDATE_SIGNING_PRIVATE_KEY_PEM_B64 is required; refusing unsigned APK feed")
        derive_signing_files(material, args.private_key_out, args.public_key_out)
        print(f"Created derived APK P256 public key: {args.public_key_out}")
        return 0
    files = build_apk_feed(args.apk, args.private_key, args.trusted_keys, args.source_dir, args.output_dir, args.version, args.source_date_epoch)
    for file in files:
        print(f"Signed and verified {file.name}: sha256={hashlib.sha256(file.read_bytes()).hexdigest()}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (ValueError, RuntimeError, FileNotFoundError) as error:
        print(f"APK feed: {error}", file=sys.stderr)
        raise SystemExit(1) from error
