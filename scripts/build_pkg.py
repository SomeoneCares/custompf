#!/usr/bin/env python3
"""
Build a properly signed FreeBSD pkg repository.

The pkg tool expects packagesite.pkg to be a tar archive containing:
  - packagesite.yaml   (the catalog)
  - signature          (when signature_type = PUBKEY)

The signature file inside the tar must be in this exact binary format:
  MAGIC   (4 bytes): b'\x05\x01\x01\x01'  (pkg signature magic)
  TYPE    (4 bytes): b'\x00\x00\x00\x02'  (PUBKEY = 2)
  LENGTH  (4 bytes): big-endian uint32 = length of RSA signature bytes
  DATA    (N bytes): raw RSA signature bytes (SHA256 digest of packagesite.yaml)

References:
  https://github.com/freebsd/pkg/blob/master/libpkg/repo/binary/init.c
  https://github.com/freebsd/pkg/blob/master/libpkg/repo/binary/fetch.c
"""

import os
import sys
import json
import hashlib
import tarfile
import io
import time
import struct
import subprocess

STAGING_DIR  = "/home/ubuntu/pkg-build/staging"
OUTPUT_DIR   = "/home/ubuntu/pkg-build/packages"
ALL_DIR      = os.path.join(OUTPUT_DIR, "All")
KEYS_DIR     = "/home/ubuntu/pkg-build/keys"
PRIVATE_KEY  = os.path.join(KEYS_DIR, "repo.key")
PUBLIC_KEY   = os.path.join(KEYS_DIR, "repo.pub")

PKG_NAME        = "pfSense-pkg-flexiwan"
PKG_VERSION     = "1.0.0"
PKG_ORIGIN      = "net/pfSense-pkg-flexiwan"
PKG_COMMENT     = "pfSense FlexiWAN SD-WAN Integration"
PKG_DESC        = (
    "Integrates pfSense with the FlexiWAN SD-WAN central management platform "
    "(flexiManage). Provides device registration, configuration synchronization, "
    "health monitoring, and a native pfSense web UI for managing SD-WAN tunnels "
    "and policies without replacing any existing pfSense functionality."
)
PKG_MAINTAINER  = "SomeoneCares"
PKG_WWW         = "https://github.com/SomeoneCares/custompf"
PKG_PREFIX      = "/usr/local"
PKG_ARCH        = "freebsd:*:*"
PKG_CATEGORIES  = ["net"]
PKG_LICENSE     = ["MIT"]
PKG_LICENSE_LOGIC = "single"

os.makedirs(ALL_DIR, exist_ok=True)

# ─────────────────────────────────────────────
# Step 1: Collect files and compute checksums
# ─────────────────────────────────────────────
print("Collecting files from staging directory...")
files_meta  = {}
file_entries = []

for root, dirs, files in os.walk(STAGING_DIR):
    for fname in sorted(files):
        real_path = os.path.join(root, fname)
        rel_path  = real_path[len(STAGING_DIR):]
        with open(real_path, "rb") as f:
            data = f.read()
        sha256 = hashlib.sha256(data).hexdigest()
        size   = len(data)
        files_meta[rel_path]  = {"sum": f"1${sha256}", "size": size}
        file_entries.append((rel_path, real_path))
        print(f"  {rel_path}  ({size} bytes)")

flatsize = sum(v["size"] for v in files_meta.values())

# ─────────────────────────────────────────────
# Step 2: Build the .pkg archive
# ─────────────────────────────────────────────
def build_manifest(include_files=True, include_desc=True):
    def ucl_list(items):
        return "[" + ", ".join(f'"{i}"' for i in items) + "]"
    lines = [
        f'name: "{PKG_NAME}"',
        f'version: "{PKG_VERSION}"',
        f'origin: "{PKG_ORIGIN}"',
        f'comment: "{PKG_COMMENT}"',
        f'arch: "{PKG_ARCH}"',
        f'www: "{PKG_WWW}"',
        f'maintainer: "{PKG_MAINTAINER}"',
        f'prefix: "{PKG_PREFIX}"',
        f'licenselogic: "{PKG_LICENSE_LOGIC}"',
        f'licenses: {ucl_list(PKG_LICENSE)}',
        f'categories: {ucl_list(PKG_CATEGORIES)}',
        f'flatsize: {flatsize}',
    ]
    if include_desc:
        lines.append(f'desc: "{PKG_DESC}"')
    if include_files:
        lines.append('files: {')
        for path, meta in sorted(files_meta.items()):
            lines.append(f'  "{path}": "{meta["sum"]}"')
        lines.append('}')
        lines.append('directories: {}')
    return "\n".join(lines) + "\n"

pkg_filename = f"{PKG_NAME}-{PKG_VERSION}.pkg"
pkg_path     = os.path.join(ALL_DIR, pkg_filename)
print(f"\nBuilding {pkg_filename}...")

with tarfile.open(pkg_path, "w:xz") as tar:
    def add_str(name, content):
        data = content.encode("utf-8")
        info = tarfile.TarInfo(name=name)
        info.size  = len(data)
        info.mtime = int(time.time())
        info.mode  = 0o644
        tar.addfile(info, io.BytesIO(data))

    add_str("+MANIFEST",         build_manifest(True,  True))
    add_str("+COMPACT_MANIFEST", build_manifest(False, False))

    for rel_path, real_path in sorted(file_entries):
        member = rel_path.lstrip("/")
        info   = tarfile.TarInfo(name=member)
        info.size  = os.path.getsize(real_path)
        info.mtime = int(time.time())
        info.mode  = 0o755 if (real_path.endswith(".sh") or real_path.endswith("flexiwand")) else 0o644
        with open(real_path, "rb") as f:
            tar.addfile(info, f)

with open(pkg_path, "rb") as f:
    pkg_data   = f.read()
pkg_sha256 = hashlib.sha256(pkg_data).hexdigest()
pkg_size   = len(pkg_data)
print(f"Package: {pkg_path}  sha256={pkg_sha256}")

# ─────────────────────────────────────────────
# Step 3: Build packagesite.yaml
# ─────────────────────────────────────────────
pkg_entry = {
    "name":         PKG_NAME,
    "version":      PKG_VERSION,
    "origin":       PKG_ORIGIN,
    "comment":      PKG_COMMENT,
    "desc":         PKG_DESC,
    "maintainer":   PKG_MAINTAINER,
    "www":          PKG_WWW,
    "arch":         PKG_ARCH,
    "prefix":       PKG_PREFIX,
    "licenselogic": PKG_LICENSE_LOGIC,
    "licenses":     PKG_LICENSE,
    "categories":   PKG_CATEGORIES,
    "flatsize":     flatsize,
    "pkgsize":      pkg_size,
    "sum":          pkg_sha256,
    "path":         f"All/{pkg_filename}",
    "repopath":     f"All/{pkg_filename}",
    "files":        {k: v["sum"] for k, v in files_meta.items()},
    "directories":  {},
    "deps":         {},
    "rdeps":        {}
}

packagesite_yaml = json.dumps(pkg_entry, separators=(',', ':')) + "\n"
packagesite_yaml_bytes = packagesite_yaml.encode("utf-8")

yaml_path = os.path.join(OUTPUT_DIR, "packagesite.yaml")
with open(yaml_path, "w") as f:
    f.write(packagesite_yaml)
print(f"packagesite.yaml written ({len(packagesite_yaml_bytes)} bytes)")

# ─────────────────────────────────────────────
# Step 4: Sign packagesite.yaml with RSA private key
# The pkg tool signs/verifies the RAW yaml bytes (SHA256withRSA)
# ─────────────────────────────────────────────
print("\nSigning packagesite.yaml...")

sig_raw_path = os.path.join(OUTPUT_DIR, "signature.raw")
result = subprocess.run(
    ["openssl", "dgst", "-sha256", "-sign", PRIVATE_KEY,
     "-out", sig_raw_path, yaml_path],
    capture_output=True
)
if result.returncode != 0:
    print("ERROR signing:", result.stderr.decode())
    sys.exit(1)

with open(sig_raw_path, "rb") as f:
    sig_bytes = f.read()

print(f"RSA signature: {len(sig_bytes)} bytes")

# ─────────────────────────────────────────────
# Step 5: Build the pkg signature header
#
# Format (from pkg source libpkg/repo/binary/fetch.c):
#   4 bytes: magic  = 0x05 0x01 0x01 0x01
#   4 bytes: type   = 0x00 0x00 0x00 0x02  (PUBKEY)
#   4 bytes: length = big-endian uint32 (length of sig_bytes)
#   N bytes: sig_bytes
# ─────────────────────────────────────────────
MAGIC = b'\x05\x01\x01\x01'
TYPE  = b'\x00\x00\x00\x02'   # PUBKEY = 2
sig_header = MAGIC + TYPE + struct.pack(">I", len(sig_bytes)) + sig_bytes

sig_file_path = os.path.join(OUTPUT_DIR, "signature")
with open(sig_file_path, "wb") as f:
    f.write(sig_header)
print(f"Signature file (with header): {len(sig_header)} bytes")

# ─────────────────────────────────────────────
# Step 6: Build packagesite.pkg
# A tar archive containing packagesite.yaml AND the signature file
# ─────────────────────────────────────────────
print("\nBuilding packagesite.pkg (with embedded signature)...")

packagesite_pkg_path = os.path.join(OUTPUT_DIR, "packagesite.pkg")

with tarfile.open(packagesite_pkg_path, "w:xz") as tar:
    # Add packagesite.yaml
    info = tarfile.TarInfo(name="packagesite.yaml")
    info.size  = len(packagesite_yaml_bytes)
    info.mtime = int(time.time())
    info.mode  = 0o644
    tar.addfile(info, io.BytesIO(packagesite_yaml_bytes))

    # Add signature file
    info2 = tarfile.TarInfo(name="signature")
    info2.size  = len(sig_header)
    info2.mtime = int(time.time())
    info2.mode  = 0o644
    tar.addfile(info2, io.BytesIO(sig_header))

with open(packagesite_pkg_path, "rb") as f:
    ps_data = f.read()
print(f"packagesite.pkg: {len(ps_data)} bytes")

# ─────────────────────────────────────────────
# Step 7: meta.conf — PUBKEY signature type
# ─────────────────────────────────────────────
meta_conf = """\
{
  "version": 2,
  "packing_format": "txz",
  "manifests": "packagesite.yaml",
  "manifests_archive": "packagesite",
  "filesite": "filesite.yaml",
  "filesite_archive": "filesite",
  "digests": "digests.txz",
  "digests_archive": "digests",
  "signature_type": "PUBKEY"
}
"""

for fname in ["meta.conf", "meta"]:
    with open(os.path.join(OUTPUT_DIR, fname), "w") as f:
        f.write(meta_conf)

# ─────────────────────────────────────────────
# Step 8: digests.txz
# ─────────────────────────────────────────────
digests_content = f"{pkg_filename}:{pkg_sha256}\n".encode()
digests_path = os.path.join(OUTPUT_DIR, "digests.txz")
with tarfile.open(digests_path, "w:xz") as tar:
    info = tarfile.TarInfo(name="digests.yaml")
    info.size  = len(digests_content)
    info.mtime = int(time.time())
    info.mode  = 0o644
    tar.addfile(info, io.BytesIO(digests_content))

# ─────────────────────────────────────────────
# Summary
# ─────────────────────────────────────────────
print("\n" + "="*60)
print("Build complete!")
print("="*60)
for root, dirs, files in os.walk(OUTPUT_DIR):
    level = root.replace(OUTPUT_DIR, '').count(os.sep)
    indent = ' ' * 2 * level
    print(f'{indent}{os.path.basename(root)}/')
    for file in sorted(files):
        fpath = os.path.join(root, file)
        print(f'{"  "*(level+1)}{file}  ({os.path.getsize(fpath)} bytes)')
