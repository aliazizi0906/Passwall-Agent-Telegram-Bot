#!/usr/bin/env python3
"""Sign an opkg Packages index in native usign format using the release Ed25519 key."""

from __future__ import annotations

import argparse
import base64
import hashlib
import subprocess
import tempfile
from pathlib import Path


def key_id(public_key: bytes) -> bytes:
    if len(public_key) != 32:
        raise ValueError("Ed25519 public key must contain exactly 32 bytes")
    return hashlib.sha256(public_key).digest()[:8]


def format_usign_public_key(public_key: bytes) -> bytes:
    fingerprint = key_id(public_key)
    payload = base64.b64encode(b"Ed" + fingerprint + public_key).decode("ascii")
    return f"untrusted comment: public key {fingerprint.hex()}\n{payload}\n".encode("ascii")


def format_usign_signature(public_key: bytes, signature: bytes) -> bytes:
    if len(signature) != 64:
        raise ValueError("Ed25519 signature must contain exactly 64 bytes")
    fingerprint = key_id(public_key)
    payload = base64.b64encode(b"Ed" + fingerprint + signature).decode("ascii")
    return f"untrusted comment: signed by key {fingerprint.hex()}\n{payload}\n".encode("ascii")


def sign_index(
    packages_path: Path,
    private_key_path: Path,
    public_key_b64_path: Path,
    expected_public_key_path: Path,
    signature_path: Path,
    output_public_key_path: Path,
) -> str:
    public_key = base64.b64decode(public_key_b64_path.read_text(encoding="ascii").strip(), validate=True)
    expected_public_key = format_usign_public_key(public_key)
    if expected_public_key_path.read_bytes() != expected_public_key:
        raise ValueError(
            f"{expected_public_key_path} does not match UPDATE_PUBLIC_KEY.b64; update the usign key file during key rotation"
        )

    with tempfile.TemporaryDirectory(prefix="pw-agent-opkg-sign-") as temporary:
        temporary_path = Path(temporary)
        public_der = temporary_path / "public.der"
        public_pem = temporary_path / "public.pem"
        raw_signature = temporary_path / "Packages.raw.sig"
        subprocess.run(
            ["openssl", "pkey", "-in", str(private_key_path), "-pubout", "-outform", "DER", "-out", str(public_der)],
            check=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        der_bytes = public_der.read_bytes()
        if len(der_bytes) < 32 or der_bytes[-32:] != public_key:
            raise ValueError("private signing key does not match UPDATE_PUBLIC_KEY.b64")
        subprocess.run(
            ["openssl", "pkey", "-pubin", "-inform", "DER", "-in", str(public_der), "-out", str(public_pem)],
            check=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        subprocess.run(
            [
                "openssl", "pkeyutl", "-sign", "-rawin", "-inkey", str(private_key_path),
                "-in", str(packages_path), "-out", str(raw_signature),
            ],
            check=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        signature_bytes = raw_signature.read_bytes()
        if len(signature_bytes) != 64:
            raise ValueError("OpenSSL did not produce a 64-byte Ed25519 signature")
        subprocess.run(
            [
                "openssl", "pkeyutl", "-verify", "-rawin", "-pubin", "-inkey", str(public_pem),
                "-in", str(packages_path), "-sigfile", str(raw_signature),
            ],
            check=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )

    signature_path.write_bytes(format_usign_signature(public_key, signature_bytes))
    output_public_key_path.write_bytes(expected_public_key)
    return key_id(public_key).hex()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--packages", required=True, type=Path)
    parser.add_argument("--private-key", required=True, type=Path)
    parser.add_argument("--public-key-b64", required=True, type=Path)
    parser.add_argument("--expected-public-key", required=True, type=Path)
    parser.add_argument("--output-signature", required=True, type=Path)
    parser.add_argument("--output-public-key", required=True, type=Path)
    args = parser.parse_args()
    fingerprint = sign_index(
        args.packages,
        args.private_key,
        args.public_key_b64,
        args.expected_public_key,
        args.output_signature,
        args.output_public_key,
    )
    print(f"Signed Packages index with usign key {fingerprint}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
