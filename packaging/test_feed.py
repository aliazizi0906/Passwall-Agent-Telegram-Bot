#!/usr/bin/env python3
"""Offline tests for IPK packaging and opkg feed metadata; no router is required."""

from __future__ import annotations

import base64
import gzip
import hashlib
import io
import json
import tarfile
import tempfile
import unittest
from pathlib import Path

import build_feed
import sign_opkg_feed


ROOT = Path(__file__).resolve().parents[1]
PUBLIC_KEY_B64 = (ROOT / "UPDATE_PUBLIC_KEY.b64").read_text(encoding="ascii").strip()


def fake_elf(arch: str) -> bytes:
    spec = build_feed.ARCHITECTURES[arch]
    data = bytearray(64)
    data[:4] = b"\x7fELF"
    data[4] = spec["elf_class"]
    data[5] = spec["elf_data"]
    data[6] = 1
    order = "little" if spec["elf_data"] == 1 else "big"
    data[18:20] = spec["elf_machine"].to_bytes(2, order)
    return bytes(data)


def write_bundle(path: Path, arch: str, binary_arch: str | None = None) -> None:
    contents = {
        "pw-agent": fake_elf(binary_arch or arch),
        "pw-agent.init": b"#!/bin/sh /etc/rc.common\nUSE_PROCD=1\n",
        "config.example.json": json.dumps(
            {
                "server_url": "https://YOUR-DOMAIN.example/api",
                "device_id": "router-01",
                "device_secret": "CHANGE_ME_64_RANDOM_HEX",
                "poll_interval_seconds": 3,
                "request_timeout_seconds": 25,
            },
            sort_keys=True,
        ).encode(),
    }
    with tarfile.open(path, "w:gz") as archive:
        for name, data in contents.items():
            info = tarfile.TarInfo("./" + name)
            info.size = len(data)
            info.mode = 0o755 if name != "config.example.json" else 0o644
            archive.addfile(info, io.BytesIO(data))


def read_ar(path: Path) -> dict[str, bytes]:
    raw = path.read_bytes()
    if not raw.startswith(b"!<arch>\n"):
        raise AssertionError("IPK is not an ar archive")
    offset = 8
    members: dict[str, bytes] = {}
    while offset < len(raw):
        header = raw[offset : offset + 60]
        if len(header) != 60 or header[58:60] != b"`\n":
            raise AssertionError("invalid ar member header")
        name = header[:16].decode("ascii").strip().rstrip("/")
        size = int(header[48:58].decode("ascii").strip())
        offset += 60
        members[name] = raw[offset : offset + size]
        offset += size + (size % 2)
    return members


def read_tar_gz(raw: bytes) -> dict[str, bytes]:
    with tarfile.open(fileobj=io.BytesIO(raw), mode="r:gz") as archive:
        return {
            member.name.removeprefix("./"): archive.extractfile(member).read()
            for member in archive.getmembers()
            if member.isfile()
        }


class FeedBuildTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.sources = self.root / "source"
        self.sources.mkdir()
        for arch in build_feed.ARCHITECTURES:
            write_bundle(self.sources / f"pw-agent-openwrt-{arch}.tar.gz", arch)

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def test_builds_six_valid_ipks_and_metadata(self) -> None:
        output = self.root / "feed"
        packages = build_feed.build_feed("0.6.0", self.sources, output, 1_700_000_000)
        self.assertEqual(len(packages), 6)
        self.assertEqual((output / "Packages").read_bytes(), gzip.decompress((output / "Packages.gz").read_bytes()))

        index = (output / "Packages").read_text(encoding="utf-8")
        for arch, spec in build_feed.ARCHITECTURES.items():
            package = output / f"pw-agent_0.6.0_{spec['opkg']}.ipk"
            self.assertTrue(package.is_file())
            members = read_ar(package)
            self.assertEqual(set(members), {"debian-binary", "control.tar.gz", "data.tar.gz"})
            self.assertEqual(members["debian-binary"], b"2.0\n")
            control_files = read_tar_gz(members["control.tar.gz"])
            data_files = read_tar_gz(members["data.tar.gz"])
            self.assertIn(f"Architecture: {spec['opkg']}\n", control_files["control"].decode())
            self.assertIn("preinst", control_files)
            self.assertIn("postinst", control_files)
            self.assertIn("prerm", control_files)
            self.assertIn("usr/bin/pw-agent", data_files)
            self.assertIn("etc/init.d/pw-agent", data_files)
            self.assertIn("usr/share/pw-agent/config.example.json", data_files)
            self.assertIn(f"Filename: {package.name}\n", index)
            self.assertIn(f"SHA256sum: {hashlib.sha256(package.read_bytes()).hexdigest()}\n", index)

    def test_build_is_reproducible(self) -> None:
        first = self.root / "first"
        second = self.root / "second"
        build_feed.build_feed("0.6.0", self.sources, first, 1_700_000_000)
        build_feed.build_feed("0.6.0", self.sources, second, 1_700_000_000)
        first_files = {path.name: hashlib.sha256(path.read_bytes()).hexdigest() for path in first.iterdir()}
        second_files = {path.name: hashlib.sha256(path.read_bytes()).hexdigest() for path in second.iterdir()}
        self.assertEqual(first_files, second_files)

    def test_rejects_wrong_elf_architecture(self) -> None:
        write_bundle(self.sources / "pw-agent-openwrt-arm64.tar.gz", "arm64", binary_arch="amd64")
        with self.assertRaisesRegex(ValueError, "ELF class/data/machine"):
            build_feed.build_feed("0.6.0", self.sources, self.root / "bad", 1_700_000_000)

    def test_rejects_missing_bundle(self) -> None:
        (self.sources / "pw-agent-openwrt-mipsle.tar.gz").unlink()
        with self.assertRaisesRegex(FileNotFoundError, "mipsle"):
            build_feed.build_feed("0.6.0", self.sources, self.root / "missing", 1_700_000_000)

    def test_usign_public_key_format_matches_published_release_key(self) -> None:
        public_key = base64.b64decode(PUBLIC_KEY_B64)
        generated = sign_opkg_feed.format_usign_public_key(public_key)
        self.assertEqual(generated, (ROOT / "packaging" / "opkg-public-key").read_bytes())
        self.assertIn(sign_opkg_feed.key_id(public_key).hex().encode(), generated)

    def test_usign_signature_envelope_has_expected_wire_format(self) -> None:
        public_key = base64.b64decode(PUBLIC_KEY_B64)
        raw_signature = bytes(range(64))
        lines = sign_opkg_feed.format_usign_signature(public_key, raw_signature).splitlines()
        expected_id = sign_opkg_feed.key_id(public_key).hex().encode()
        self.assertEqual(lines[0], b"untrusted comment: signed by key " + expected_id)
        decoded = base64.b64decode(lines[1])
        self.assertEqual(decoded[:2], b"Ed")
        self.assertEqual(decoded[2:10], hashlib.sha256(public_key).digest()[:8])
        self.assertEqual(decoded[10:], raw_signature)


if __name__ == "__main__":
    unittest.main()
