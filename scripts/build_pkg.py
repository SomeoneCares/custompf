#!/usr/bin/env python3
"""
FreeBSD pkg package builder for pfSense custom repository.

This script creates a proper .pkg archive (which is a tar+zstd or tar+xz file)
with the required +MANIFEST (UCL format), +COMPACT_MANIFEST, and +FILES entries,
then generates a packagesite.yaml catalog and compresses it into packagesite.pkg
for use as a pfSense/FreeBSD custom pkg repository.

FreeBSD pkg format reference:
  - A .pkg file is a tar archive (xz-compressed) containing:
    +MANIFEST  - UCL-format package metadata
    +COMPACT_MANIFEST - same but without desc/files
    +FILES     - file checksums
    All package files under their full paths (e.g. /usr/local/pkg/...)

  - A repository consists of:
    All/<package>.pkg  - the package files
    packagesite.yaml   - newline-delimited JSON (one object per package)
    packagesite.pkg    - packagesite.yaml compressed with xz inside a tar
    meta.conf          - repository metadata
    meta               - same as meta.conf (some versions)
"""

import os
import sys
import json
import hashlib
import tarfile
import io
import time
import gzip
import struct

STAGING_DIR = "/home/ubuntu/pkg-build/staging"
OUTPUT_DIR  = "/home/ubuntu/pkg-build/packages"
ALL_DIR     = os.path.join(OUTPUT_DIR, "All")

# Package metadata
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
PKG_ARCH        = "freebsd:*:*"   # compatible with all FreeBSD versions
PKG_CATEGORIES  = ["net"]
PKG_LICENSE     = ["MIT"]
PKG_LICENSE_LOGIC = "single"

os.makedirs(ALL_DIR, exist_ok=True)

# ─────────────────────────────────────────────
# Step 1: Collect all files and compute checksums
# ─────────────────────────────────────────────
print("Collecting files from staging directory...")

files_meta = {}   # path -> {sum, size, perm}
file_entries = [] # list of (archive_path, real_path)

for root, dirs, files in os.walk(STAGING_DIR):
    for fname in sorted(files):
        real_path = os.path.join(root, fname)
        # Path relative to staging root (becomes the installed path)
        rel_path = real_path[len(STAGING_DIR):]  # e.g. /usr/local/pkg/flexiwan.xml

        with open(real_path, "rb") as f:
            data = f.read()

        sha256 = hashlib.sha256(data).hexdigest()
        size   = len(data)
        mode   = oct(os.stat(real_path).st_mode)[-4:]

        files_meta[rel_path] = {"sum": f"1${sha256}", "size": size, "perm": int(mode, 8)}
        file_entries.append((rel_path, real_path))
        print(f"  {rel_path}  ({size} bytes, sha256={sha256[:16]}...)")

# ─────────────────────────────────────────────
# Step 2: Build UCL manifest strings
# ─────────────────────────────────────────────
print("\nBuilding package manifest...")

def ucl_list(items):
    return "[" + ", ".join(f'"{i}"' for i in items) + "]"

def build_manifest(include_files=True, include_desc=True):
    lines = []
    lines.append(f'name: "{PKG_NAME}"')
    lines.append(f'version: "{PKG_VERSION}"')
    lines.append(f'origin: "{PKG_ORIGIN}"')
    lines.append(f'comment: "{PKG_COMMENT}"')
    lines.append(f'arch: "{PKG_ARCH}"')
    lines.append(f'www: "{PKG_WWW}"')
    lines.append(f'maintainer: "{PKG_MAINTAINER}"')
    lines.append(f'prefix: "{PKG_PREFIX}"')
    lines.append(f'licenselogic: "{PKG_LICENSE_LOGIC}"')
    lines.append(f'licenses: {ucl_list(PKG_LICENSE)}')
    lines.append(f'categories: {ucl_list(PKG_CATEGORIES)}')
    lines.append(f'flatsize: {sum(v["size"] for v in files_meta.values())}')
    if include_desc:
        lines.append(f'desc: "{PKG_DESC}"')
    if include_files:
        lines.append('files: {')
        for path, meta in sorted(files_meta.items()):
            lines.append(f'  "{path}": "{meta["sum"]}"')
        lines.append('}')
        lines.append('directories: {}')
    return "\n".join(lines) + "\n"

manifest_full    = build_manifest(include_files=True,  include_desc=True)
manifest_compact = build_manifest(include_files=False, include_desc=False)

# ─────────────────────────────────────────────
# Step 3: Build the +FILES entry
# ─────────────────────────────────────────────
files_content_lines = []
for path, meta in sorted(files_meta.items()):
    files_content_lines.append(f'"{path}": "{meta["sum"]}"')
files_content = "{\n" + "\n".join(files_content_lines) + "\n}\n"

# ─────────────────────────────────────────────
# Step 4: Assemble the .pkg tar archive (xz-compressed)
# ─────────────────────────────────────────────
pkg_filename = f"{PKG_NAME}-{PKG_VERSION}.pkg"
pkg_path     = os.path.join(ALL_DIR, pkg_filename)

print(f"\nBuilding package archive: {pkg_filename}")

with tarfile.open(pkg_path, "w:xz") as tar:

    def add_string(name, content):
        data = content.encode("utf-8")
        info = tarfile.TarInfo(name=name)
        info.size  = len(data)
        info.mtime = int(time.time())
        info.mode  = 0o644
        tar.addfile(info, io.BytesIO(data))

    # Add control files
    add_string("+MANIFEST",         manifest_full)
    add_string("+COMPACT_MANIFEST", manifest_compact)
    add_string("+FILES",            files_content)

    # Add all package files
    for rel_path, real_path in sorted(file_entries):
        # Strip leading slash for tar member name
        member_name = rel_path.lstrip("/")
        info = tarfile.TarInfo(name=member_name)
        info.size  = os.path.getsize(real_path)
        info.mtime = int(time.time())
        # Preserve executable bit for scripts
        if real_path.endswith(".sh") or real_path.endswith("flexiwand"):
            info.mode = 0o755
        else:
            info.mode = 0o644
        with open(real_path, "rb") as f:
            tar.addfile(info, f)
        print(f"  + {member_name}")

print(f"Package built: {pkg_path}")

# ─────────────────────────────────────────────
# Step 5: Compute package file checksum and size
# ─────────────────────────────────────────────
with open(pkg_path, "rb") as f:
    pkg_data = f.read()

pkg_sha256  = hashlib.sha256(pkg_data).hexdigest()
pkg_size    = len(pkg_data)
pkg_flatsize = sum(v["size"] for v in files_meta.values())

print(f"\nPackage SHA256: {pkg_sha256}")
print(f"Package size:   {pkg_size} bytes")

# ─────────────────────────────────────────────
# Step 6: Generate packagesite.yaml
# (newline-delimited JSON, one object per package)
# ─────────────────────────────────────────────
print("\nGenerating packagesite.yaml...")

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
    "flatsize":     pkg_flatsize,
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

packagesite_yaml_path = os.path.join(OUTPUT_DIR, "packagesite.yaml")
with open(packagesite_yaml_path, "w") as f:
    f.write(packagesite_yaml)

print(f"packagesite.yaml written: {packagesite_yaml_path}")

# ─────────────────────────────────────────────
# Step 7: Compress packagesite.yaml into packagesite.pkg
# (tar archive containing packagesite.yaml, then xz-compressed)
# ─────────────────────────────────────────────
print("\nBuilding packagesite.pkg...")

packagesite_pkg_path = os.path.join(OUTPUT_DIR, "packagesite.pkg")

with tarfile.open(packagesite_pkg_path, "w:xz") as tar:
    data = packagesite_yaml.encode("utf-8")
    info = tarfile.TarInfo(name="packagesite.yaml")
    info.size  = len(data)
    info.mtime = int(time.time())
    info.mode  = 0o644
    tar.addfile(info, io.BytesIO(data))

print(f"packagesite.pkg written: {packagesite_pkg_path}")

# ─────────────────────────────────────────────
# Step 8: Compute packagesite.pkg checksum
# ─────────────────────────────────────────────
with open(packagesite_pkg_path, "rb") as f:
    ps_data = f.read()

ps_sha256 = hashlib.sha256(ps_data).hexdigest()
ps_size   = len(ps_data)

print(f"packagesite.pkg SHA256: {ps_sha256}")
print(f"packagesite.pkg size:   {ps_size} bytes")

# ─────────────────────────────────────────────
# Step 9: Generate meta.conf
# ─────────────────────────────────────────────
print("\nGenerating meta.conf...")

meta_conf = {
    "version": 2,
    "packing_format": "txz",
    "manifests": "packagesite.yaml",
    "manifests_archive": "packagesite",
    "filesite": "filesite.yaml",
    "filesite_archive": "filesite",
    "digests": "digests.txz",
    "digests_archive": "digests",
    "signature_type": "NONE"
}

meta_conf_str = json.dumps(meta_conf, indent=2) + "\n"

meta_conf_path = os.path.join(OUTPUT_DIR, "meta.conf")
with open(meta_conf_path, "w") as f:
    f.write(meta_conf_str)

# Also write as 'meta' (some pkg versions look for this)
meta_path = os.path.join(OUTPUT_DIR, "meta")
with open(meta_path, "w") as f:
    f.write(meta_conf_str)

print(f"meta.conf written: {meta_conf_path}")

# ─────────────────────────────────────────────
# Step 10: Generate digests.txz
# (contains sha256 checksums for all packages)
# ─────────────────────────────────────────────
print("\nGenerating digests.txz...")

digests_content = f"{pkg_filename}:{pkg_sha256}\n"

digests_path = os.path.join(OUTPUT_DIR, "digests.txz")
with tarfile.open(digests_path, "w:xz") as tar:
    data = digests_content.encode("utf-8")
    info = tarfile.TarInfo(name="digests.yaml")
    info.size  = len(data)
    info.mtime = int(time.time())
    info.mode  = 0o644
    tar.addfile(info, io.BytesIO(data))

print(f"digests.txz written: {digests_path}")

# ─────────────────────────────────────────────
# Step 11: Summary
# ─────────────────────────────────────────────
print("\n" + "="*60)
print("Repository build complete!")
print("="*60)
print(f"\nRepository files in: {OUTPUT_DIR}")
print(f"\nDirectory structure:")
for root, dirs, files in os.walk(OUTPUT_DIR):
    level = root.replace(OUTPUT_DIR, '').count(os.sep)
    indent = ' ' * 2 * level
    print(f'{indent}{os.path.basename(root)}/')
    subindent = ' ' * 2 * (level + 1)
    for file in sorted(files):
        fpath = os.path.join(root, file)
        fsize = os.path.getsize(fpath)
        print(f'{subindent}{file}  ({fsize} bytes)')

print(f"""
To use this repository on pfSense, add this to /usr/local/etc/pkg/repos/custompf.conf:

custompf: {{
  url: "https://SomeoneCares.github.io/custompf/",
  enabled: yes,
  priority: 10
}}

Then run: pkg update && pkg install pfSense-pkg-flexiwan
""")
