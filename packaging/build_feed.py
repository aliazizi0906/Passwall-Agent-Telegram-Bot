#!/usr/bin/env python3
"""Build reproducible OpenWrt IPK packages and a flat opkg feed."""

from __future__ import annotations

import argparse
import gzip
import hashlib
import io
import json
import re
import tarfile
from pathlib import Path, PurePosixPath


ARCHITECTURES = {
    "armv7": {
        "opkg": "pw_agent_armv7",
        "elf_class": 1,
        "elf_data": 1,
        "elf_machine": 40,
        "machine_case": "armv7l|armv7*",
    },
    "arm64": {
        "opkg": "pw_agent_arm64",
        "elf_class": 2,
        "elf_data": 1,
        "elf_machine": 183,
        "machine_case": "aarch64|arm64",
    },
    "amd64": {
        "opkg": "pw_agent_amd64",
        "elf_class": 2,
        "elf_data": 1,
        "elf_machine": 62,
        "machine_case": "x86_64|amd64",
    },
    "386": {
        "opkg": "pw_agent_386",
        "elf_class": 1,
        "elf_data": 1,
        "elf_machine": 3,
        "machine_case": "i386|i486|i586|i686",
    },
    "mips": {
        "opkg": "pw_agent_mips",
        "elf_class": 1,
        "elf_data": 2,
        "elf_machine": 8,
        # Some little-endian MIPS OpenWrt kernels also report `mips`; the
        # opkg target architecture check below distinguishes endianness.
        "machine_case": "mips|mipsel|mipsle",
    },
    "mipsle": {
        "opkg": "pw_agent_mipsle",
        "elf_class": 1,
        "elf_data": 1,
        "elf_machine": 8,
        "machine_case": "mips|mipsel|mipsle",
    },
}

VERSION_RE = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+(?:[.-][A-Za-z0-9.-]+)?$")
REQUIRED_BUNDLE_FILES = ("pw-agent", "pw-agent.init", "config.example.json")


def _gzip_bytes(data: bytes) -> bytes:
    buffer = io.BytesIO()
    with gzip.GzipFile(fileobj=buffer, mode="wb", filename="", mtime=0, compresslevel=9) as stream:
        stream.write(data)
    return buffer.getvalue()


def _tar_bytes(files: dict[str, tuple[bytes, int]], source_date_epoch: int) -> bytes:
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w", format=tarfile.USTAR_FORMAT) as archive:
        for path in sorted(files):
            contents, mode = files[path]
            info = tarfile.TarInfo("./" + path)
            info.size = len(contents)
            info.mode = mode
            info.mtime = source_date_epoch
            info.uid = 0
            info.gid = 0
            info.uname = ""
            info.gname = ""
            archive.addfile(info, io.BytesIO(contents))
    return _gzip_bytes(buffer.getvalue())


def _ar_bytes(members: list[tuple[str, bytes, int]], source_date_epoch: int) -> bytes:
    output = bytearray(b"!<arch>\n")
    for name, payload, mode in members:
        archive_name = name if name.endswith("/") else name + "/"
        header = (
            f"{archive_name:<16}"
            f"{source_date_epoch:<12}"
            f"{0:<6}"
            f"{0:<6}"
            f"{mode:o}"
        )
        header = header.ljust(48) + f"{len(payload):<10}`\n"
        if len(header.encode("ascii")) != 60:
            raise ValueError(f"invalid ar header for {name}")
        output.extend(header.encode("ascii"))
        output.extend(payload)
        if len(payload) % 2:
            output.extend(b"\n")
    return bytes(output)


def _read_bundle(bundle_path: Path) -> dict[str, bytes]:
    wanted = set(REQUIRED_BUNDLE_FILES)
    found: dict[str, bytes] = {}
    with tarfile.open(bundle_path, mode="r:gz") as archive:
        for member in archive.getmembers():
            raw_name = member.name
            while raw_name.startswith("./"):
                raw_name = raw_name[2:]
            path = PurePosixPath(raw_name)
            if path.is_absolute() or ".." in path.parts:
                raise ValueError(f"unsafe path in {bundle_path.name}: {member.name}")
            if member.issym() or member.islnk() or member.isdev():
                raise ValueError(f"unsupported special file in {bundle_path.name}: {member.name}")
            if not member.isfile() or raw_name not in wanted:
                continue
            if raw_name in found:
                raise ValueError(f"duplicate {raw_name} in {bundle_path.name}")
            stream = archive.extractfile(member)
            if stream is None:
                raise ValueError(f"could not read {raw_name} from {bundle_path.name}")
            found[raw_name] = stream.read()

    missing = wanted.difference(found)
    if missing:
        raise ValueError(f"{bundle_path.name} is missing: {', '.join(sorted(missing))}")
    return found


def _validate_elf(binary: bytes, arch: str) -> None:
    expected = ARCHITECTURES[arch]
    if len(binary) < 20 or binary[:4] != b"\x7fELF":
        raise ValueError(f"{arch} binary is not an ELF executable")
    elf_class, elf_data = binary[4], binary[5]
    if elf_data not in (1, 2):
        raise ValueError(f"{arch} binary has an unsupported ELF byte order")
    byte_order = "little" if elf_data == 1 else "big"
    machine = int.from_bytes(binary[18:20], byte_order)
    actual = (elf_class, elf_data, machine)
    wanted = (expected["elf_class"], expected["elf_data"], expected["elf_machine"])
    if actual != wanted:
        raise ValueError(
            f"{arch} bundle has ELF class/data/machine {actual}; expected {wanted}"
        )


def _preinst_script(arch: str) -> bytes:
    pattern = ARCHITECTURES[arch]["machine_case"]
    extra = ""
    if arch in ("mips", "mipsle"):
        wanted_prefix = "mipsel_" if arch == "mipsle" else "mips_"
        other_prefix = "mips_" if arch == "mipsle" else "mipsel_"
        extra = f'''\narchitectures="$(opkg print-architecture 2>/dev/null || true)"
printf '%s\\n' "$architectures" | grep -Eq '[[:space:]]{wanted_prefix}[A-Za-z0-9_]*([[:space:]]|$)' || {{
    echo "ERROR: cannot confirm {arch} endianness from opkg print-architecture." >&2
    exit 1
}}
if printf '%s\\n' "$architectures" | grep -Eq '[[:space:]]{other_prefix}[A-Za-z0-9_]*([[:space:]]|$)'; then
    echo "ERROR: this router advertises the opposite MIPS endianness." >&2
    exit 1
fi
'''
    script = f'''#!/bin/sh
set -eu
[ -n "${{IPKG_INSTROOT:-}}" ] && exit 0
machine="$(uname -m)"
case "$machine" in
    {pattern}) ;;
    *)
        echo "ERROR: pw-agent package targets {arch}, but this router reports $machine." >&2
        exit 1
        ;;
esac
{extra}exit 0
'''
    return script.encode("utf-8")


def _postinst_script() -> bytes:
    return b'''#!/bin/sh
set -e
[ -n "${IPKG_INSTROOT:-}" ] && exit 0
mkdir -p /etc/pw-agent /etc/pw-agent/backups /etc/pw-agent/safety
chmod 700 /etc/pw-agent /etc/pw-agent/backups /etc/pw-agent/safety
if [ ! -f /etc/pw-agent/config.json ]; then
    cp /usr/share/pw-agent/config.example.json /etc/pw-agent/config.json
    chmod 600 /etc/pw-agent/config.json
    echo "PW Agent installed. Edit /etc/pw-agent/config.json before enabling the service."
    exit 0
fi
if grep -q 'CHANGE_ME' /etc/pw-agent/config.json; then
    echo "PW Agent config still contains CHANGE_ME; service was left stopped."
    exit 0
fi
if [ ! -f /etc/config/passwall2 ]; then
    echo "Passwall2 is missing; install it before enabling PW Agent."
    exit 0
fi
/etc/init.d/pw-agent enable
/etc/init.d/pw-agent restart || true
exit 0
'''


def _prerm_script() -> bytes:
    return b'''#!/bin/sh
set -e
if [ "${1:-}" = "remove" ]; then
    /etc/init.d/pw-agent stop >/dev/null 2>&1 || true
    /etc/init.d/pw-agent disable >/dev/null 2>&1 || true
fi
exit 0
'''


def _build_ipk(arch: str, version: str, files: dict[str, bytes], epoch: int) -> bytes:
    opkg_arch = ARCHITECTURES[arch]["opkg"]
    data_files = {
        "etc/init.d/pw-agent": (files["pw-agent.init"], 0o755),
        "usr/bin/pw-agent": (files["pw-agent"], 0o755),
        "usr/share/pw-agent/config.example.json": (files["config.example.json"], 0o644),
    }
    installed_size = (sum(len(content) for content, _mode in data_files.values()) + 1023) // 1024
    control_text = (
        "Package: pw-agent\n"
        f"Version: {version}\n"
        f"Architecture: {opkg_arch}\n"
        "Section: net\n"
        "Priority: optional\n"
        "Maintainer: PW Agent maintainers\n"
        f"Installed-Size: {installed_size}\n"
        "Description: PW Agent OpenWrt service agent for Passwall2\n"
    ).encode("utf-8")
    control_files = {
        "control": (control_text, 0o644),
        "preinst": (_preinst_script(arch), 0o755),
        "postinst": (_postinst_script(), 0o755),
        "prerm": (_prerm_script(), 0o755),
    }
    control_tar = _tar_bytes(control_files, epoch)
    data_tar = _tar_bytes(data_files, epoch)
    return _ar_bytes(
        [
            ("debian-binary", b"2.0\n", 0o100644),
            ("control.tar.gz", control_tar, 0o100644),
            ("data.tar.gz", data_tar, 0o100644),
        ],
        epoch,
    )


def build_feed(version: str, source_dir: Path, output_dir: Path, epoch: int) -> list[Path]:
    if not VERSION_RE.fullmatch(version):
        raise ValueError(f"invalid package version: {version}")
    if epoch < 0:
        raise ValueError("source-date-epoch must be zero or greater")
    output_dir.mkdir(parents=True, exist_ok=True)

    package_paths: list[Path] = []
    package_entries: list[tuple[str, bytes]] = []
    for arch in sorted(ARCHITECTURES):
        bundle_path = source_dir / f"pw-agent-openwrt-{arch}.tar.gz"
        if not bundle_path.is_file():
            raise FileNotFoundError(f"missing signed release bundle: {bundle_path}")
        files = _read_bundle(bundle_path)
        _validate_elf(files["pw-agent"], arch)
        try:
            config = json.loads(files["config.example.json"])
        except (UnicodeDecodeError, json.JSONDecodeError) as error:
            raise ValueError(f"invalid config.example.json in {bundle_path.name}") from error
        if not isinstance(config, dict) or not all(
            key in config for key in ("server_url", "device_id", "device_secret")
        ):
            raise ValueError(f"config.example.json in {bundle_path.name} is missing required fields")
        if not files["pw-agent.init"].startswith(b"#!/bin/sh"):
            raise ValueError(f"invalid init script in {bundle_path.name}")

        filename = f"pw-agent_{version}_{ARCHITECTURES[arch]['opkg']}.ipk"
        package_bytes = _build_ipk(arch, version, files, epoch)
        package_path = output_dir / filename
        package_path.write_bytes(package_bytes)
        package_paths.append(package_path)

        sha256 = hashlib.sha256(package_bytes).hexdigest()
        md5 = hashlib.md5(package_bytes, usedforsecurity=False).hexdigest()
        package_entries.append(
            (
                filename,
                (
                    "Package: pw-agent\n"
                    f"Version: {version}\n"
                    f"Architecture: {ARCHITECTURES[arch]['opkg']}\n"
                    "Section: net\n"
                    "Priority: optional\n"
                    "Maintainer: PW Agent maintainers\n"
                    f"Installed-Size: {(sum(len(files[name]) for name in REQUIRED_BUNDLE_FILES) + 1023) // 1024}\n"
                    f"Filename: {filename}\n"
                    f"Size: {len(package_bytes)}\n"
                    f"MD5Sum: {md5}\n"
                    f"SHA256sum: {sha256}\n"
                    "Description: PW Agent OpenWrt service agent for Passwall2\n"
                ).encode("utf-8"),
            )
        )

    packages_bytes = b"\n".join(entry for _filename, entry in package_entries) + b"\n"
    (output_dir / "Packages").write_bytes(packages_bytes)
    (output_dir / "Packages.gz").write_bytes(_gzip_bytes(packages_bytes))
    return package_paths


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version", required=True, help="release version without the leading v")
    parser.add_argument("--source-dir", required=True, type=Path, help="directory containing signed release bundle assets")
    parser.add_argument("--output-dir", required=True, type=Path, help="directory for IPKs and Packages metadata")
    parser.add_argument("--source-date-epoch", required=True, type=int, help="fixed timestamp from the release tag commit")
    args = parser.parse_args()
    packages = build_feed(args.version, args.source_dir, args.output_dir, args.source_date_epoch)
    print(f"Built {len(packages)} IPK packages and Packages/Packages.gz in {args.output_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
