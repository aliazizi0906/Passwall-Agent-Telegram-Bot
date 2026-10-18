#!/usr/bin/env python3
"""Host-only validation of APK feed names, key derivation and CLI wiring."""

from __future__ import annotations

import base64
import hashlib
import json
import os
import subprocess
import tarfile
import tempfile
import unittest
from io import BytesIO
from pathlib import Path
from unittest.mock import patch

import build_apk_feed as module
from build_feed import ARCHITECTURES


def dummy_elf(arch: str) -> bytes:
    spec = ARCHITECTURES[arch]
    raw = bytearray(64)
    raw[:4] = b"\x7fELF"
    raw[4:6] = bytes((spec["elf_class"], spec["elf_data"]))
    byte_order = "little" if spec["elf_data"] == 1 else "big"
    raw[18:20] = spec["elf_machine"].to_bytes(2, byte_order)
    return bytes(raw)


def make_fake_bundle(path: Path, arch: str) -> None:
    contents = {
        "pw-agent": dummy_elf(arch),
        "pw-agent.init": b"#!/bin/sh /etc/rc.common\nUSE_PROCD=1\n",
        "config.example.json": json.dumps({"server_url": "https://host.example/api", "device_id": "router-1", "device_secret": "CHANGE_ME"}).encode(),
    }
    with tarfile.open(path, "w:gz") as handle:
        for name, data in contents.items():
            info = tarfile.TarInfo("./" + name)
            info.size = len(data)
            handle.addfile(info, BytesIO(data))


class ApkV3Tests(unittest.TestCase):
    def test_names_version_and_direct_index_compatibility(self) -> None:
        self.assertEqual(module.apk_version("0.7.0"), "0.7.0-r0")
        self.assertEqual(module.asset_name("0.7.0", "arm64"), "pw-agent-0.7.0-r0-pw_agent_arm64.apk")
        self.assertEqual(module.index_name("arm64"), "pw-agent-packages-arm64.adb")
        self.assertEqual(len({module.asset_name("0.7.0", a) for a in ARCHITECTURES}), 6)
        self.assertEqual(len({module.index_name(a) for a in ARCHITECTURES}), 6)
        for invalid in ("v0.7.0", "../0.7.0", "0.7.0-rc", "0.7"):
            with self.assertRaises(ValueError):
                module.apk_version(invalid)

    def test_scripts_preserve_config_and_enforce_architecture(self) -> None:
        self.assertIn("aarch64|arm64", module.pre_install("arm64"))
        self.assertIn("mipsel|mipsle", module.pre_install("mipsle"))
        self.assertIn("if [ ! -e /etc/pw-agent/config.json ]", module.post_install())
        self.assertIn("/etc/init.d/pw-agent restart", module.post_install())
        self.assertIn("/etc/init.d/pw-agent disable", module.pre_deinstall())

    def test_key_derivation_is_stable_domain_separated_and_matches_public(self) -> None:
        try:
            from cryptography.hazmat.primitives import serialization
            from cryptography.hazmat.primitives.asymmetric import ec, ed25519
        except ImportError:
            self.skipTest("cryptography is required for P256 key-derivation tests")
        upstream = ed25519.Ed25519PrivateKey.generate()
        pem = upstream.private_bytes(
            serialization.Encoding.PEM,
            serialization.PrivateFormat.PKCS8,
            serialization.NoEncryption(),
        )
        derived = module.key_from_ed25519_pem(pem)
        again = module.key_from_ed25519_pem(pem)
        self.assertIsInstance(derived, ec.EllipticCurvePrivateKey)
        self.assertEqual(derived.private_numbers().private_value, again.private_numbers().private_value)
        self.assertNotEqual(
            derived.private_numbers().private_value,
            int.from_bytes(
                upstream.private_bytes(serialization.Encoding.Raw, serialization.PrivateFormat.Raw, serialization.NoEncryption()),
                "big",
            ),
        )
        with tempfile.TemporaryDirectory() as temp:
            secret_path = Path(temp) / "secret.pem"
            pub_path = Path(temp) / "public.pem"
            module.derive_signing_files(base64.b64encode(pem).decode(), secret_path, pub_path)
            self.assertTrue(pub_path.read_bytes().startswith(b"-----BEGIN PUBLIC KEY-----"))
            key_loaded = serialization.load_pem_private_key(secret_path.read_bytes(), password=None)
            pub_loaded = serialization.load_pem_public_key(pub_path.read_bytes())
            self.assertEqual(key_loaded.public_key().public_numbers(), pub_loaded.public_numbers())
            self.assertEqual(key_loaded.private_numbers().private_value, derived.private_numbers().private_value)
            with self.assertRaises(FileExistsError):
                module.derive_signing_files(base64.b64encode(pem).decode(), secret_path, pub_path)

    def test_bundle_staging_enforces_binary_endianness_and_structure(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            src = base / "source.tar.gz"
            make_fake_bundle(src, "mipsle")
            module.stage_payload(src, base / "target", "mipsle")
            self.assertEqual((base / "target/usr/bin/pw-agent").read_bytes(), dummy_elf("mipsle"))
            with self.assertRaisesRegex(ValueError, "ELF class/data/machine"):
                module.stage_payload(src, base / "wrong", "mips")

    def test_invokes_mkpkg_mkndx_verify_and_adbdump_for_every_arch(self) -> None:
        # This test verifies command wiring; CI separately invokes the real
        # OpenWrt SDK apk executable to parse and verify the signed artifacts.
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            sources = root / "sources"
            sources.mkdir()
            keys = root / "keys"
            keys.mkdir()
            (keys / module.APK_KEY_NAME).write_text("public-key-fixture")
            private = root / "private.pem"
            private.write_text("private-key-fixture")
            apk = root / "apk"
            apk.write_text("fake executable")
            for arch in ARCHITECTURES:
                make_fake_bundle(sources / f"pw-agent-openwrt-{arch}.tar.gz", arch)
            commands = []

            def fake_run(argv, *, epoch, cwd=None):
                commands.append(argv)
                if argv[1] == "mkpkg":
                    Path(argv[argv.index("--output") + 1]).write_bytes(b"APK3" + bytes(128))
                if argv[1] == "mkndx":
                    Path(argv[argv.index("--output") + 1]).write_bytes(b"ADB3" + bytes(128))
                if argv[1] == "adbdump":
                    arch = Path(argv[-1]).stem.split("-")[-1]
                    return subprocess.CompletedProcess(argv, 0, '{"name":"pw-agent","arch":"' + ARCHITECTURES[arch]["opkg"] + '"}', "")
                return subprocess.CompletedProcess(argv, 0, "", "")

            with patch.object(module, "_run", side_effect=fake_run), patch.object(os, "access", return_value=True):
                emitted = module.build_apk_feed(apk, private, keys, sources, root / "out", "0.7.0", 1_700_000_000)
            self.assertEqual(len(emitted), 12)
            self.assertEqual([sum(argv[1] == op for argv in commands) for op in ("mkpkg", "mkndx", "verify", "adbdump")], [6, 6, 6, 6])
            for mkndx in (argv for argv in commands if argv[1] == "mkndx"):
                self.assertEqual(mkndx[mkndx.index("--pkgname-spec") + 1], "pw-agent-${version}-${arch}.apk")
                self.assertIn("--sign", mkndx)


if __name__ == "__main__":
    unittest.main()
