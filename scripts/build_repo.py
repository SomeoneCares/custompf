#!/usr/bin/env python3
"""
build_custompf_repo.py
Rebuild the full custompf repository — signature_type: NONE.

This matches the original working custompf build exactly:
  - packagesite.pkg contains only packagesite.yaml (no signature)
  - meta.conf uses signature_type: "NONE"
  - Client .conf uses: signature_type: "NONE"
  - No keys, no fingerprints, no signing required

TAR MEMBER PATHS (custompf convention):
  - Regular files WITHOUT leading slash: usr/local/pkg/foo.xml
  - Manifest files: section WITH leading slash: /usr/local/pkg/foo.xml
"""

import hashlib, io, json, os, tarfile, textwrap, time

# ── Paths ────────────────────────────────────────────────────────────────────
REPO_ROOT  = "/home/ubuntu/custompf"
OVP_SOURCE = "/home/ubuntu/pFSense-OVP/pfSense-pkg-ovp/files"
DOCS_DIR   = os.path.join(REPO_ROOT, "docs")
ALL_DIR    = os.path.join(DOCS_DIR, "All")

# ── OVP package metadata ─────────────────────────────────────────────────────
OVP_NAME    = "pfSense-pkg-ovp"
OVP_VERSION = "1.1.6"
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

    # Manifest files: section uses absolute paths WITH leading slash
    # This matches the working flexiwan 1.0.6 format exactly
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
        # Files stored WITH leading slash — matches working flexiwan 1.0.6 format
        # No directory entries needed (flexiwan 1.0.6 has none and works)
        for dest_abs, data in file_entries:
            tar_add(tf, dest_abs, data)

    pkg_bytes = buf.getvalue()
    pkg_path  = os.path.join(ALL_DIR, f"{OVP_NAME}-{OVP_VERSION}.pkg")
    with open(pkg_path, "wb") as f:
        f.write(pkg_bytes)

    print(f"[ovp] Written: {pkg_path}  ({len(pkg_bytes):,} bytes, flatsize={flatsize:,})")
    return pkg_bytes

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

    yaml_str   = "\n".join(json.dumps(e, separators=(',', ':')) for e in entries) + "\n"
    yaml_bytes = yaml_str.encode("utf-8")

    with open(os.path.join(DOCS_DIR, "packagesite.yaml"), "wb") as f:
        f.write(yaml_bytes)
    print(f"[repo] packagesite.yaml  ({len(yaml_bytes):,} bytes, {len(entries)} packages)")

    # packagesite.pkg — yaml only, no signature (signature_type: NONE)
    site_buf = io.BytesIO()
    with tarfile.open(fileobj=site_buf, mode="w:xz") as tf:
        tar_add(tf, "packagesite.yaml", yaml_bytes)
    site_bytes = site_buf.getvalue()
    with open(os.path.join(DOCS_DIR, "packagesite.pkg"), "wb") as f:
        f.write(site_bytes)
    print(f"[repo] packagesite.pkg   ({len(site_bytes):,} bytes)")

    # meta.conf — signature_type: NONE (matches original working build)
    meta = json.dumps({
        "version": 2,
        "packing_format": "txz",
        "manifests": "packagesite.yaml",
        "manifests_archive": "packagesite",
        "filesite": "filesite.yaml",
        "filesite_archive": "filesite",
        "digests": "digests.txz",
        "digests_archive": "digests",
        "signature_type": "NONE"
    }, indent=2) + "\n"

    for mname in ("meta.conf", "meta"):
        with open(os.path.join(DOCS_DIR, mname), "w") as f:
            f.write(meta)
    print(f"[repo] meta.conf written (signature_type: NONE)")

    # digests.txz placeholder
    dig_buf = io.BytesIO()
    with tarfile.open(fileobj=dig_buf, mode="w:xz"):
        pass
    with open(os.path.join(DOCS_DIR, "digests.txz"), "wb") as f:
        f.write(dig_buf.getvalue())

# ── Main ─────────────────────────────────────────────────────────────────────

def main():
    os.makedirs(ALL_DIR, exist_ok=True)
    build_ovp_pkg()
    build_catalog()

    print()
    print("=" * 60)
    print("BUILD COMPLETE")
    print("=" * 60)
    print(f"  Packages: {len([f for f in os.listdir(ALL_DIR) if f.endswith('.pkg')])} in docs/All/")
    print()
    print("Client custompf.conf (no keys needed):")
    print('  custompf: {')
    print('    url: "https://SomeoneCares.github.io/custompf/",')
    print('    signature_type: "NONE",')
    print('    enabled: yes,')
    print('    priority: 10')
    print('  }')
    print()

if __name__ == "__main__":
    main()
