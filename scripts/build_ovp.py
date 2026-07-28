#!/usr/bin/env python3
"""
build_custompf_repo.py
Rebuild the full custompf repository using PUBKEY signature mode.

PUBKEY mode (matches original working custompf repo):
  - meta.conf embeds the full PEM public key inline
  - packagesite.pkg contains: packagesite.yaml + custompf.sig
  - Signature = RSA-SHA256 of sha256hex(yaml).encode()  via pkeyutl
  - NO fingerprint files needed on pfSense clients
  - Client .conf uses: signature_type: "PUBKEY", pubkey: "/path/to/repo.pub"

TAR MEMBER PATHS (custompf convention):
  - Regular files WITHOUT leading slash: usr/local/pkg/foo.xml
  - Manifest files: section WITH leading slash: /usr/local/pkg/foo.xml
"""

import hashlib, io, json, os, subprocess, sys, tarfile, textwrap, time

# ── Paths ────────────────────────────────────────────────────────────────────
REPO_ROOT   = "/home/ubuntu/custompf"
OVP_SOURCE  = "/home/ubuntu/pFSense-OVP/pfSense-pkg-ovp/files"
KEYS_DIR    = "/home/ubuntu/custompf-keys"
PRIVATE_KEY = os.path.join(KEYS_DIR, "repo.key")
PUBLIC_KEY  = os.path.join(KEYS_DIR, "repo.pub")
DOCS_DIR    = os.path.join(REPO_ROOT, "docs")
ALL_DIR     = os.path.join(DOCS_DIR, "All")
SIGN_NAME   = "custompf"

# ── OVP package metadata ─────────────────────────────────────────────────────
OVP_NAME    = "pfSense-pkg-ovp"
OVP_VERSION = "1.1.0"
OVP_ORIGIN  = "net/pfSense-pkg-ovp"
OVP_COMMENT = "OpenVPN Client Importer for pfSense 2.8"
OVP_DESC    = ("Upload a .ovpn file to automatically create a fully configured "
               "OpenVPN client instance in pfSense, including CA certificate, "
               "client certificate, client key, and TLS authentication key.")
OVP_WWW     = "https://github.com/SomeoneCares/pFSense-OVP"
OVP_ARCH    = "freebsd:*:*"
OVP_PREFIX  = "/usr/local"
OVP_MAINT   = "SomeoneCares"

OVP_FILES = [
    ("usr/local/pkg/ovp_import.xml",
     "/usr/local/pkg/ovp_import.xml"),
    ("usr/local/pkg/ovp_import.inc",
     "/usr/local/pkg/ovp_import.inc"),
    ("usr/local/share/pfSense-pkg-ovp/info.xml",
     "/usr/local/share/pfSense-pkg-ovp/info.xml"),
    ("usr/local/www/packages/ovp/ovp_upload.php",
     "/usr/local/www/packages/ovp/ovp_upload.php"),
    ("usr/local/www/packages/ovp/ovp_parser.php",
     "/usr/local/www/packages/ovp/ovp_parser.php"),
]

# ── Helpers ──────────────────────────────────────────────────────────────────

def sha256hex(data):
    return hashlib.sha256(data).hexdigest()

def pkg_sum(data):
    return "1$" + sha256hex(data)

def tar_add(tf, name, data, mode=0o644):
    ti = tarfile.TarInfo(name=name)
    ti.size  = len(data)
    ti.mode  = mode
    ti.mtime = int(time.time())
    tf.addfile(ti, io.BytesIO(data))

def sign_yaml(yaml_bytes):
    """
    Sign using double-hash pkeyutl (matches original custompf build):
      hex_hash    = sha256hex(yaml_bytes)
      double_hash = sha256(hex_hash.encode()).digest()
      signature   = pkeyutl -sign -inkey repo.key -pkeyopt digest:sha256
    """
    hex_hash    = sha256hex(yaml_bytes)
    double_hash = hashlib.sha256(hex_hash.encode()).digest()

    dh_file  = os.path.join(KEYS_DIR, "double_hash.bin")
    sig_file = os.path.join(KEYS_DIR, f"{SIGN_NAME}.sig")

    with open(dh_file, "wb") as f:
        f.write(double_hash)

    result = subprocess.run(
        ["openssl", "pkeyutl", "-sign", "-inkey", PRIVATE_KEY,
         "-in", dh_file, "-out", sig_file, "-pkeyopt", "digest:sha256"],
        capture_output=True
    )
    if result.returncode != 0:
        print("Sign failed:", result.stderr.decode(), file=sys.stderr)
        sys.exit(1)

    with open(sig_file, "rb") as f:
        return f.read()

# ── Build OVP .pkg ───────────────────────────────────────────────────────────

def build_ovp_pkg():
    print(f"[ovp] Building {OVP_NAME}-{OVP_VERSION}.pkg ...")

    file_entries = []
    flatsize = 0

    for src_rel, dest_abs in OVP_FILES:
        src = os.path.join(OVP_SOURCE, src_rel)
        with open(src, "rb") as f:
            data = f.read()
        file_entries.append((dest_abs, data))
        flatsize += len(data)
        print(f"[ovp]   {dest_abs}  ({len(data):,} B)")

    files_ucl = ""
    for dest_abs, data in file_entries:
        files_ucl += f'  "{dest_abs}": "{pkg_sum(data)}"\n'

    manifest_str = textwrap.dedent(f"""\
        name: "{OVP_NAME}"
        version: "{OVP_VERSION}"
        origin: "{OVP_ORIGIN}"
        comment: "{OVP_COMMENT}"
        arch: "{OVP_ARCH}"
        www: "{OVP_WWW}"
        maintainer: "{OVP_MAINT}"
        prefix: "{OVP_PREFIX}"
        licenselogic: "single"
        licenses: ["MIT"]
        categories: ["net"]
        flatsize: {flatsize}
        desc: "{OVP_DESC}"
        files: {{
        {files_ucl}}}
        directories: {{}}
    """).encode("utf-8")

    install_script = (
        "#!/bin/sh\n"
        'if [ "${2}" != "POST-INSTALL" ]; then\n'
        "\texit 0\nfi\n"
        "${PKG_ROOTDIR}/usr/local/bin/php -f "
        "${PKG_ROOTDIR}/etc/rc.packages pfSense-pkg-ovp ${2}\n"
    ).encode()

    deinstall_script = (
        "#!/bin/sh\n"
        'if [ "${2}" != "DEINSTALL" ]; then\n'
        "\texit 0\nfi\n"
        "${PKG_ROOTDIR}/usr/local/bin/php -f "
        "${PKG_ROOTDIR}/etc/rc.packages pfSense-pkg-ovp ${2}\n"
    ).encode()

    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:xz") as tf:
        tar_add(tf, "+MANIFEST",         manifest_str)
        tar_add(tf, "+COMPACT_MANIFEST", manifest_str)
        tar_add(tf, "+INSTALL",          install_script,   mode=0o755)
        tar_add(tf, "+DEINSTALL",        deinstall_script, mode=0o755)
        for dest_abs, data in file_entries:
            tar_add(tf, dest_abs.lstrip("/"), data)

    pkg_bytes = buf.getvalue()
    pkg_path  = os.path.join(ALL_DIR, f"{OVP_NAME}-{OVP_VERSION}.pkg")
    with open(pkg_path, "wb") as f:
        f.write(pkg_bytes)

    print(f"[ovp] Written: {pkg_path}  ({len(pkg_bytes):,} bytes)")
    return pkg_path, pkg_bytes, flatsize

# ── Build repo catalog ───────────────────────────────────────────────────────

def build_catalog():
    print("[repo] Building packagesite.yaml ...")

    entries = []
    for fname in sorted(os.listdir(ALL_DIR)):
        if not fname.endswith(".pkg"):
            continue
        fpath = os.path.join(ALL_DIR, fname)
        with open(fpath, "rb") as f:
            pkg_data = f.read()
        pkg_size = len(pkg_data)
        pkg_sha  = sha256hex(pkg_data)
        stem  = fname[:-4]
        parts = stem.rsplit("-", 1)
        ename, eversion = (parts[0], parts[1]) if len(parts) == 2 and parts[1][0].isdigit() else (stem, "0.0.0")
        comment = OVP_COMMENT if ename == OVP_NAME else ename
        desc    = OVP_DESC    if ename == OVP_NAME else ename
        www     = OVP_WWW     if ename == OVP_NAME else "https://github.com/SomeoneCares/custompf"
        entries.append({
            "name": ename, "version": eversion, "origin": f"net/{ename}",
            "comment": comment, "arch": "freebsd:*:*", "www": www,
            "maintainer": OVP_MAINT, "prefix": "/usr/local",
            "licenselogic": "single", "licenses": ["MIT"], "categories": ["net"],
            "flatsize": pkg_size, "desc": desc, "sum": pkg_sha,
            "repopath": f"All/{fname}", "pkgsize": pkg_size,
        })
        print(f"[repo]   + {fname}")

    yaml_lines = "\n".join(json.dumps(e, separators=(',', ':')) for e in entries) + "\n"
    yaml_bytes = yaml_lines.encode("utf-8")
    with open(os.path.join(DOCS_DIR, "packagesite.yaml"), "wb") as f:
        f.write(yaml_bytes)
    print(f"[repo] packagesite.yaml  ({len(yaml_bytes):,} bytes, {len(entries)} packages)")

    # Sign
    print("[repo] Signing catalog ...")
    sig_bytes = sign_yaml(yaml_bytes)

    # packagesite.pkg — PUBKEY mode: only yaml + sig (NO .pub embedded)
    site_buf = io.BytesIO()
    with tarfile.open(fileobj=site_buf, mode="w:xz") as tf:
        tar_add(tf, "packagesite.yaml",  yaml_bytes)
        tar_add(tf, f"{SIGN_NAME}.sig",  sig_bytes)
    site_bytes = site_buf.getvalue()
    with open(os.path.join(DOCS_DIR, "packagesite.pkg"), "wb") as f:
        f.write(site_bytes)
    print(f"[repo] packagesite.pkg   ({len(site_bytes):,} bytes)")

    # Read public key PEM for embedding in meta.conf
    with open(PUBLIC_KEY, "r") as f:
        pub_pem = f.read().strip()
    # Escape newlines for JSON string embedding
    pub_pem_escaped = pub_pem.replace("\n", "\\n")

    # meta.conf — PUBKEY mode: embed public key inline
    meta = (
        '{\n'
        '  "version": 2,\n'
        '  "packing_format": "txz",\n'
        '  "manifests": "packagesite.yaml",\n'
        '  "manifests_archive": "packagesite",\n'
        '  "filesite": "filesite.yaml",\n'
        '  "filesite_archive": "filesite",\n'
        '  "digests": "digests.txz",\n'
        '  "digests_archive": "digests",\n'
        '  "signature_type": "PUBKEY",\n'
        f'  "pubkey": "{pub_pem_escaped}"\n'
        '}\n'
    )
    for mname in ("meta.conf", "meta"):
        with open(os.path.join(DOCS_DIR, mname), "w") as f:
            f.write(meta)
    print(f"[repo] meta.conf written (PUBKEY mode, {len(meta)} bytes)")

    # digests.txz placeholder
    dig_buf = io.BytesIO()
    with tarfile.open(fileobj=dig_buf, mode="w:xz"):
        pass
    with open(os.path.join(DOCS_DIR, "digests.txz"), "wb") as f:
        f.write(dig_buf.getvalue())

    # Also save standalone pub key file for clients that want to download it
    with open(os.path.join(DOCS_DIR, "custompf.pub"), "w") as f:
        f.write(pub_pem + "\n")

    return pub_pem

# ── Main ─────────────────────────────────────────────────────────────────────

def main():
    os.makedirs(ALL_DIR, exist_ok=True)

    build_ovp_pkg()
    pub_pem = build_catalog()

    # Update keys/ directory
    keys_dest = os.path.join(REPO_ROOT, "keys")
    os.makedirs(keys_dest, exist_ok=True)
    with open(PUBLIC_KEY, "rb") as f:
        pub_bytes = f.read()
    with open(os.path.join(keys_dest, "custompf.pub"), "wb") as f:
        f.write(pub_bytes)
    # Keep fingerprint file for reference (not required for PUBKEY mode)
    fp = hashlib.sha256(pub_bytes).hexdigest()
    with open(os.path.join(keys_dest, "custompf.fingerprint"), "w") as f:
        f.write(f"function: sha256\nfingerprint: {fp}\n")

    print()
    print("=" * 60)
    print("BUILD COMPLETE — PUBKEY mode")
    print("=" * 60)
    print(f"  Packages: {len([f for f in os.listdir(ALL_DIR) if f.endswith('.pkg')])} in docs/All/")
    print()
    print("Client custompf.conf:")
    print('  custompf: {')
    print('    url: "https://SomeoneCares.github.io/custompf/",')
    print('    signature_type: "PUBKEY",')
    print('    pubkey: "/usr/local/etc/pkg/custompf.pub",')
    print('    enabled: yes,')
    print('    priority: 10')
    print('  }')
    print()

if __name__ == "__main__":
    main()
