#!/usr/bin/env python3
"""
build_custompf_repo.py
Rebuild the full custompf repository catalog including pfSense-pkg-ovp.

This script:
  1. Reads all existing .pkg files from custompf/docs/All/
  2. Builds pfSense-pkg-ovp-1.1.0.pkg from source
  3. Regenerates packagesite.yaml covering ALL packages
  4. Signs and packages into packagesite.pkg
  5. Updates meta.conf, fingerprint, and public key
  6. Writes everything into custompf/docs/

Key insight from existing custompf build_pkg.py:
  - Tar member paths are WITHOUT leading slash: usr/local/pkg/foo.xml
  - Manifest files: section uses WITH leading slash: /usr/local/pkg/foo.xml
"""

import hashlib, io, json, os, subprocess, tarfile, textwrap, time

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
OVP_LICENSE = "MIT"
OVP_MAINT   = "SomeoneCares"

# Files to include in the OVP package
# (source_path_relative_to_files_dir, absolute_dest_on_pfsense)
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

def ucl_list(items):
    return "[" + ", ".join(f'"{i}"' for i in items) + "]"

def sign_yaml(yaml_bytes):
    """Double-hash RSA-SHA256 signature as required by pfSense pkg."""
    inner = sha256hex(yaml_bytes).encode()
    return subprocess.check_output(
        ["openssl", "dgst", "-sha256", "-sign", PRIVATE_KEY, "-"],
        input=inner
    )

def compute_fingerprint():
    der = subprocess.check_output(
        ["openssl", "rsa", "-pubin", "-in", PUBLIC_KEY, "-outform", "DER"],
        stderr=subprocess.DEVNULL
    )
    return sha256hex(der)

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

    # Build files section
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
        licenses: ["{OVP_LICENSE}"]
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
        # Order: +MANIFEST, +COMPACT_MANIFEST, +INSTALL, +DEINSTALL, files
        tar_add(tf, "+MANIFEST",         manifest_str)
        tar_add(tf, "+COMPACT_MANIFEST", manifest_str)
        tar_add(tf, "+INSTALL",          install_script,   mode=0o755)
        tar_add(tf, "+DEINSTALL",        deinstall_script, mode=0o755)
        # Regular files — tar member WITHOUT leading slash (custompf convention)
        for dest_abs, data in file_entries:
            tar_name = dest_abs.lstrip("/")
            tar_add(tf, tar_name, data)

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
        checksum = pkg_sum(pkg_data)

        # Parse name/version from filename
        stem  = fname[:-4]
        parts = stem.rsplit("-", 1)
        if len(parts) == 2 and parts[1][0].isdigit():
            ename, eversion = parts
        else:
            ename, eversion = stem, "0.0.0"

        # Use OVP metadata for OVP package, generic for others
        if ename == OVP_NAME:
            comment = OVP_COMMENT
            desc    = OVP_DESC
            www     = OVP_WWW
        else:
            comment = ename
            desc    = ename
            www     = "https://github.com/SomeoneCares/custompf"

        entry = {
            "name":         ename,
            "version":      eversion,
            "origin":       f"net/{ename}",
            "comment":      comment,
            "arch":         "freebsd:*:*",
            "www":          www,
            "maintainer":   OVP_MAINT,
            "prefix":       "/usr/local",
            "licenselogic": "single",
            "licenses":     ["MIT"],
            "categories":   ["net"],
            "flatsize":     pkg_size,
            "desc":         desc,
            "sum":          checksum,
            "repopath":     f"All/{fname}",
            "pkgsize":      pkg_size,
        }
        entries.append(entry)
        print(f"[repo]   + {fname}")

    yaml_lines = "\n".join(json.dumps(e) for e in entries) + "\n"
    yaml_bytes = yaml_lines.encode("utf-8")

    yaml_path = os.path.join(DOCS_DIR, "packagesite.yaml")
    with open(yaml_path, "wb") as f:
        f.write(yaml_bytes)
    print(f"[repo] packagesite.yaml  ({len(yaml_bytes):,} bytes, {len(entries)} packages)")

    # Sign and package
    print("[repo] Signing catalog ...")
    sig_bytes = sign_yaml(yaml_bytes)
    with open(PUBLIC_KEY, "rb") as f:
        pub_bytes = f.read()

    site_buf = io.BytesIO()
    with tarfile.open(fileobj=site_buf, mode="w:xz") as tf:
        tar_add(tf, "packagesite.yaml",   yaml_bytes)
        tar_add(tf, f"{SIGN_NAME}.sig",   sig_bytes)
        tar_add(tf, f"{SIGN_NAME}.pub",   pub_bytes)

    site_bytes = site_buf.getvalue()
    with open(os.path.join(DOCS_DIR, "packagesite.pkg"), "wb") as f:
        f.write(site_bytes)
    print(f"[repo] packagesite.pkg   ({len(site_bytes):,} bytes)")

    # meta.conf
    fingerprint = compute_fingerprint()
    meta = textwrap.dedent(f"""\
        version: 2
        packing_format: "txz"
        manifests_archive: "packagesite"
        digests_archive: "digests"
        signature_type: "FINGERPRINTS"
        fingerprints: "/usr/local/etc/pkg/fingerprints/{SIGN_NAME}"
    """)
    for mname in ("meta.conf", "meta"):
        with open(os.path.join(DOCS_DIR, mname), "w") as f:
            f.write(meta)

    # digests.txz placeholder
    dig_buf = io.BytesIO()
    with tarfile.open(fileobj=dig_buf, mode="w:xz"):
        pass
    with open(os.path.join(DOCS_DIR, "digests.txz"), "wb") as f:
        f.write(dig_buf.getvalue())

    print(f"[repo] Fingerprint: {fingerprint}")
    return fingerprint

# ── Main ─────────────────────────────────────────────────────────────────────

def main():
    os.makedirs(ALL_DIR, exist_ok=True)

    # 1. Build OVP package
    build_ovp_pkg()

    # 2. Rebuild full catalog
    fingerprint = build_catalog()

    # 3. Copy updated public key into custompf/keys/
    keys_dest = os.path.join(REPO_ROOT, "keys")
    os.makedirs(keys_dest, exist_ok=True)

    with open(PUBLIC_KEY, "rb") as f:
        pub_bytes = f.read()
    with open(os.path.join(keys_dest, "custompf.pub"), "wb") as f:
        f.write(pub_bytes)

    fp_content = f"function: sha256\nfingerprint: {fingerprint}\n"
    with open(os.path.join(keys_dest, "custompf.fingerprint"), "w") as f:
        f.write(fp_content)

    print()
    print("=" * 60)
    print("BUILD COMPLETE")
    print("=" * 60)
    print(f"  Fingerprint : {fingerprint}")
    print(f"  Packages    : {len(os.listdir(ALL_DIR))} in docs/All/")
    print()

if __name__ == "__main__":
    main()
