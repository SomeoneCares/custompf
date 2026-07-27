#!/usr/bin/env python3
"""
Build a correctly signed FreeBSD pkg repository — based on actual pkg source code.

From pkg_repo.c analysis:

For SIG_FINGERPRINT mode, pkg_repo_meta_extract_signature_fingerprints() scans
the packagesite.pkg tar for entries ending in:
  - ".sig"  → raw RSA signature bytes (type=0)
  - ".pub"  → PEM public key (type=1)

The name used (without extension) must match between .sig and .pub files.
e.g.: "custompf.sig" and "custompf.pub"

The signature is computed as:
  RSA_sign( SHA256( SHA256_hex(packagesite.yaml) ) )
  i.e. sign the SHA256 of the hex-encoded SHA256 of the yaml data.

From pkgsign_ossl.c:
  sha256 = pkg_checksum_fd(fd, PKG_HASH_TYPE_SHA256_HEX)   # hex string of sha256
  hash   = pkg_checksum_data(sha256, len, PKG_HASH_TYPE_SHA256_RAW)  # sha256 of that hex
  EVP_PKEY_verify(ctx, sig, siglen, hash, 32)  # verify RSA with SHA256 padding

So we need: openssl pkeyutl -sign with -pkeyopt digest:sha256 on the double-hash bytes.

For SIG_PUBKEY mode, pkg looks for a tar entry named exactly "signature" containing
the raw RSA signature bytes (no SIGNATURE/CERT/END wrapping — that was wrong).
The public key is loaded from the repo config's "pubkey" file path on disk.
"""

import os, sys, json, hashlib, tarfile, io, time, subprocess

STAGING_DIR = "/home/ubuntu/pkg-build/staging"
OUTPUT_DIR  = "/home/ubuntu/pkg-build/packages"
ALL_DIR     = os.path.join(OUTPUT_DIR, "All")
KEYS_DIR    = "/home/ubuntu/pkg-build/keys"
PRIVATE_KEY = os.path.join(KEYS_DIR, "repo.key")
PUBLIC_KEY  = os.path.join(KEYS_DIR, "repo.pub")
SIGN_NAME   = "custompf"   # base name for .sig and .pub files in the tar

PKG_NAME     = "pfSense-pkg-flexiwan"
PKG_VERSION  = "1.0.0"
PKG_ORIGIN   = "net/pfSense-pkg-flexiwan"
PKG_COMMENT  = "pfSense FlexiWAN SD-WAN Integration"
PKG_DESC     = ("Integrates pfSense with the FlexiWAN SD-WAN central management platform "
                "(flexiManage). Provides device registration, configuration synchronization, "
                "health monitoring, and a native pfSense web UI for managing SD-WAN tunnels "
                "and policies without replacing any existing pfSense functionality.")
PKG_MAINTAINER = "SomeoneCares"
PKG_WWW      = "https://github.com/SomeoneCares/custompf"
PKG_PREFIX   = "/usr/local"
PKG_ARCH     = "freebsd:*:*"
PKG_CATEGORIES = ["net"]
PKG_LICENSE  = ["MIT"]

os.makedirs(ALL_DIR, exist_ok=True)

# ── Step 1: Collect files ──────────────────────────────────────────────────────
print("Collecting files...")
files_meta, file_entries = {}, []
for root, dirs, files in os.walk(STAGING_DIR):
    for fname in sorted(files):
        rp = os.path.join(root, fname)
        rel = rp[len(STAGING_DIR):]
        data = open(rp, "rb").read()
        sha  = hashlib.sha256(data).hexdigest()
        files_meta[rel] = {"sum": f"1${sha}", "size": len(data)}
        file_entries.append((rel, rp))
        print(f"  {rel}  ({len(data)} B)")

flatsize = sum(v["size"] for v in files_meta.values())

# ── Step 2: Build .pkg archive ────────────────────────────────────────────────
def ucl_list(items): return "[" + ", ".join(f'"{i}"' for i in items) + "]"

def manifest(with_files=True, with_desc=True):
    L = [
        f'name: "{PKG_NAME}"', f'version: "{PKG_VERSION}"',
        f'origin: "{PKG_ORIGIN}"', f'comment: "{PKG_COMMENT}"',
        f'arch: "{PKG_ARCH}"', f'www: "{PKG_WWW}"',
        f'maintainer: "{PKG_MAINTAINER}"', f'prefix: "{PKG_PREFIX}"',
        f'licenselogic: "single"', f'licenses: {ucl_list(PKG_LICENSE)}',
        f'categories: {ucl_list(PKG_CATEGORIES)}', f'flatsize: {flatsize}',
    ]
    if with_desc: L.append(f'desc: "{PKG_DESC}"')
    if with_files:
        L.append('files: {')
        for p, m in sorted(files_meta.items()): L.append(f'  "{p}": "{m["sum"]}"')
        L += ['}', 'directories: {}']
    return "\n".join(L) + "\n"

pkg_file = f"{PKG_NAME}-{PKG_VERSION}.pkg"
pkg_path = os.path.join(ALL_DIR, pkg_file)
print(f"\nBuilding {pkg_file}...")

with tarfile.open(pkg_path, "w:xz") as tar:
    def add_str(name, content):
        b = content.encode()
        i = tarfile.TarInfo(name=name); i.size=len(b); i.mtime=int(time.time()); i.mode=0o644
        tar.addfile(i, io.BytesIO(b))
    add_str("+MANIFEST",         manifest(True,  True))
    add_str("+COMPACT_MANIFEST", manifest(False, False))
    for rel, rp in sorted(file_entries):
        i = tarfile.TarInfo(name=rel.lstrip("/"))
        i.size=os.path.getsize(rp); i.mtime=int(time.time())
        i.mode = 0o755 if (rp.endswith(".sh") or rp.endswith("flexiwand")) else 0o644
        with open(rp,"rb") as f: tar.addfile(i, f)

pkg_data   = open(pkg_path,"rb").read()
pkg_sha256 = hashlib.sha256(pkg_data).hexdigest()
pkg_size   = len(pkg_data)
print(f"Package sha256={pkg_sha256}  size={pkg_size}")

# ── Step 3: Build packagesite.yaml ────────────────────────────────────────────
entry = {
    "name": PKG_NAME, "version": PKG_VERSION, "origin": PKG_ORIGIN,
    "comment": PKG_COMMENT, "desc": PKG_DESC, "maintainer": PKG_MAINTAINER,
    "www": PKG_WWW, "arch": PKG_ARCH, "prefix": PKG_PREFIX,
    "licenselogic": "single", "licenses": PKG_LICENSE, "categories": PKG_CATEGORIES,
    "flatsize": flatsize, "pkgsize": pkg_size, "sum": pkg_sha256,
    "path": f"All/{pkg_file}", "repopath": f"All/{pkg_file}",
    "files": {k: v["sum"] for k,v in files_meta.items()},
    "directories": {}, "deps": {}, "rdeps": {}
}
yaml_str   = json.dumps(entry, separators=(',',':')) + "\n"
yaml_bytes = yaml_str.encode()
with open(os.path.join(OUTPUT_DIR, "packagesite.yaml"), "wb") as f: f.write(yaml_bytes)
print(f"\npackagesite.yaml  {len(yaml_bytes)} bytes")

# ── Step 4: Compute the double-hash ───────────────────────────────────────────
# pkg_checksum_fd(fd, SHA256_HEX)  → hex string of sha256 of file
# pkg_checksum_data(hex, len, SHA256_RAW)  → sha256 of that hex string
hex_hash    = hashlib.sha256(yaml_bytes).hexdigest()   # 64-char hex
double_hash = hashlib.sha256(hex_hash.encode()).digest() # 32 bytes
print(f"hex_hash    = {hex_hash}")
print(f"double_hash = {double_hash.hex()}")

# ── Step 5: Sign the double-hash with RSA PKCS#1 v1.5 + SHA-256 ──────────────
# EVP_PKEY_CTX_set_signature_md(ctx, EVP_sha256()) + EVP_PKEY_verify
# means the signature is over the 32-byte double_hash with SHA-256 DigestInfo wrapper.
# openssl pkeyutl -sign -pkeyopt digest:sha256 signs a pre-hashed value with PKCS1 padding.

dh_file  = os.path.join(KEYS_DIR, "double_hash.bin")
sig_file = os.path.join(KEYS_DIR, f"{SIGN_NAME}.sig")

with open(dh_file, "wb") as f: f.write(double_hash)

result = subprocess.run([
    "openssl", "pkeyutl",
    "-sign",
    "-inkey", PRIVATE_KEY,
    "-in",    dh_file,
    "-out",   sig_file,
    "-pkeyopt", "digest:sha256",
], capture_output=True)

if result.returncode != 0:
    print("pkeyutl failed:", result.stderr.decode())
    sys.exit(1)

sig_bytes = open(sig_file, "rb").read()
print(f"\nRSA signature: {len(sig_bytes)} bytes  → {sig_file}")

# ── Step 6: Read public key PEM ───────────────────────────────────────────────
pub_pem = open(PUBLIC_KEY, "rb").read()
pub_dest = os.path.join(KEYS_DIR, f"{SIGN_NAME}.pub")
with open(pub_dest, "wb") as f: f.write(pub_pem)

# ── Step 7: Build packagesite.pkg ─────────────────────────────────────────────
# For FINGERPRINTS: tar must contain <name>.sig and <name>.pub
# For PUBKEY: tar must contain a file named exactly "signature" with raw sig bytes
#
# We build ONE packagesite.pkg that works for FINGERPRINTS mode:
# Contains: packagesite.yaml, custompf.sig, custompf.pub

ps_pkg_path = os.path.join(OUTPUT_DIR, "packagesite.pkg")
with tarfile.open(ps_pkg_path, "w:xz") as tar:
    def add_bytes(name, data):
        i = tarfile.TarInfo(name=name); i.size=len(data); i.mtime=int(time.time()); i.mode=0o644
        tar.addfile(i, io.BytesIO(data))
    add_bytes("packagesite.yaml",       yaml_bytes)
    add_bytes(f"{SIGN_NAME}.sig",       sig_bytes)   # raw RSA signature
    add_bytes(f"{SIGN_NAME}.pub",       pub_pem)     # PEM public key

ps_size = os.path.getsize(ps_pkg_path)
print(f"packagesite.pkg: {ps_size} bytes")

# Verify contents
print("\nVerifying packagesite.pkg contents:")
with tarfile.open(ps_pkg_path, "r:xz") as t:
    for m in t.getmembers():
        print(f"  {m.name}  ({m.size} bytes)")

# ── Step 8: Compute fingerprint ───────────────────────────────────────────────
# From pkg_repo.c: fingerprint = SHA256 of the PEM file as-is (not DER)
fingerprint = hashlib.sha256(pub_pem).hexdigest()
print(f"\nFingerprint (SHA256 of PEM file): {fingerprint}")

fp_file = os.path.join(KEYS_DIR, "repo.fingerprint")
with open(fp_file, "w") as f: f.write(fingerprint + "\n")

# ── Step 9: meta.conf ─────────────────────────────────────────────────────────
meta = """\
{
  "version": 2,
  "packing_format": "txz",
  "manifests": "packagesite.yaml",
  "manifests_archive": "packagesite",
  "filesite": "filesite.yaml",
  "filesite_archive": "filesite",
  "digests": "digests.txz",
  "digests_archive": "digests",
  "signature_type": "FINGERPRINTS"
}
"""
for fn in ["meta.conf", "meta"]:
    with open(os.path.join(OUTPUT_DIR, fn), "w") as f: f.write(meta)

# ── Step 10: digests.txz ──────────────────────────────────────────────────────
dc = f"{pkg_file}:{pkg_sha256}\n".encode()
with tarfile.open(os.path.join(OUTPUT_DIR,"digests.txz"),"w:xz") as tar:
    i = tarfile.TarInfo(name="digests.yaml"); i.size=len(dc); i.mtime=int(time.time()); i.mode=0o644
    tar.addfile(i, io.BytesIO(dc))

# ── Summary ───────────────────────────────────────────────────────────────────
print("\n" + "="*60)
print("Build complete!")
print("="*60)
print(f"\nFingerprint: {fingerprint}")
print(f"""
On pfSense, run these commands:

mkdir -p /usr/local/etc/pkg/fingerprints/custompf/trusted
mkdir -p /usr/local/etc/pkg/fingerprints/custompf/revoked

printf 'function: sha256\\nfingerprint: {fingerprint}\\n' > /usr/local/etc/pkg/fingerprints/custompf/trusted/custompf.fingerprint

printf 'custompf: {{\\n  url: "https://SomeoneCares.github.io/custompf/",\\n  signature_type: "fingerprints",\\n  fingerprints: "/usr/local/etc/pkg/fingerprints/custompf",\\n  enabled: yes,\\n  priority: 10\\n}}\\n' > /usr/local/etc/pkg/repos/custompf.conf

pkg update
pkg install pfSense-pkg-flexiwan
""")
