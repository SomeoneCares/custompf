#!/usr/bin/env python3
"""
Build a correctly signed FreeBSD pkg repository for pfSense.

KEY INSIGHT from pkg source code (pkg_add.c, pkg.c):
  - ctx.rootfd = open("/", O_DIRECTORY)  -- root of filesystem
  - tar member "usr/local/bin/flexiwand"
  - pkg_absolutepath("usr/local/bin/flexiwand") -> "/usr/local/bin/flexiwand"
  - openat(rootfd="/", "usr/local/bin/flexiwand") -> installs to /usr/local/bin/flexiwand

  So tar members MUST be stored WITHOUT leading slash but WITH full path:
    usr/local/bin/flexiwand
    usr/local/pkg/flexiwan.xml
    usr/local/share/pfSense-pkg-flexiwan/info.xml

  And the manifest files section uses the same paths with leading slash:
    /usr/local/bin/flexiwand
    /usr/local/pkg/flexiwan.xml
    /usr/local/share/pfSense-pkg-flexiwan/info.xml

  The prefix field in the manifest is informational only for pkg.

REQUIRED for pfSense menu registration:
  - +INSTALL script that calls /etc/rc.packages pfSense-pkg-flexiwan POST-INSTALL
  - info.xml with ONLY: name, descr, pkginfolink, version, configurationfile
"""

import os, sys, json, hashlib, tarfile, io, time, subprocess

STAGING_DIR = "/home/ubuntu/pkg-build/staging"
OUTPUT_DIR  = "/home/ubuntu/pkg-build/packages"
ALL_DIR     = os.path.join(OUTPUT_DIR, "All")
KEYS_DIR    = "/home/ubuntu/pkg-build/keys"
PRIVATE_KEY = os.path.join(KEYS_DIR, "repo.key")
PUBLIC_KEY  = os.path.join(KEYS_DIR, "repo.pub")
SIGN_NAME   = "custompf"

PKG_NAME     = "pfSense-pkg-flexiwan"
PKG_VERSION  = "1.0.7"
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

PKG_INSTALL_SCRIPT = "#!/bin/sh\nif [ \"${2}\" != \"POST-INSTALL\" ]; then\n\texit 0\nfi\n${PKG_ROOTDIR}/usr/local/bin/php -f ${PKG_ROOTDIR}/etc/rc.packages pfSense-pkg-flexiwan ${2}\n"
PKG_DEINSTALL_SCRIPT = "#!/bin/sh\n/usr/local/bin/php -f /etc/rc.packages pfSense-pkg-flexiwan ${2}\n"

os.makedirs(ALL_DIR, exist_ok=True)

# ── Step 1: Collect files ──────────────────────────────────────────────────────
print("Collecting files...")
files_meta, file_entries = {}, []
for root, dirs, files in os.walk(STAGING_DIR):
    for fname in sorted(files):
        if fname in ("+INSTALL", "+DEINSTALL"): continue
        rp = os.path.join(root, fname)
        rel = rp[len(STAGING_DIR):]          # e.g. /usr/local/pkg/flexiwan.xml
        data = open(rp, "rb").read()
        sha  = hashlib.sha256(data).hexdigest()
        files_meta[rel] = {"sum": f"1${sha}", "size": len(data)}
        # tar member name: strip leading slash -> usr/local/pkg/flexiwan.xml
        tar_name = rel.lstrip("/")
        file_entries.append((rel, rp, tar_name))
        print(f"  {tar_name}  ({len(data)} B)")

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
        for p, m in sorted(files_meta.items()):
            # Manifest uses absolute paths (same as what pkg_absolutepath produces)
            L.append(f'  "{p}": "{m["sum"]}"')
        L += ['}', 'directories: {}']
    return "\n".join(L) + "\n"

pkg_file = f"{PKG_NAME}-{PKG_VERSION}.pkg"
pkg_path = os.path.join(ALL_DIR, pkg_file)
print(f"\nBuilding {pkg_file}...")

with tarfile.open(pkg_path, "w:xz") as tar:
    def add_str(name, content, mode=0o644):
        b = content.encode()
        i = tarfile.TarInfo(name=name); i.size=len(b); i.mtime=int(time.time()); i.mode=mode
        tar.addfile(i, io.BytesIO(b))

    add_str("+MANIFEST",         manifest(True,  True))
    add_str("+COMPACT_MANIFEST", manifest(False, False))
    add_str("+INSTALL",          PKG_INSTALL_SCRIPT,   mode=0o755)
    add_str("+DEINSTALL",        PKG_DEINSTALL_SCRIPT, mode=0o755)

    for rel, rp, tar_name in sorted(file_entries, key=lambda x: x[2]):
        # CRITICAL: tar member names must be ABSOLUTE paths (with leading slash)
        # pkg_create.c line 218: snprintf(fpath, ..., root, relocation, file->path)
        # file->path is absolute: /usr/local/bin/flexiwand
        # pkg_add.c line 955: pkg_absolutepath(archive_entry_pathname(ae), ..., fromroot=true)
        # Since path already starts with /, pkg_absolutepath returns it unchanged
        # So tar member /usr/local/bin/flexiwand -> lookup /usr/local/bin/flexiwand -> MATCH
        abs_tar_name = rel  # rel is already absolute: /usr/local/bin/flexiwand
        i = tarfile.TarInfo(name=abs_tar_name)
        i.size=os.path.getsize(rp); i.mtime=int(time.time())
        i.mode = 0o755 if (rp.endswith(".sh") or rp.endswith("flexiwand")) else 0o644
        with open(rp,"rb") as f: tar.addfile(i, f)
        print(f"  + {abs_tar_name}")

pkg_data   = open(pkg_path,"rb").read()
pkg_sha256 = hashlib.sha256(pkg_data).hexdigest()
pkg_size   = len(pkg_data)
print(f"\nPackage sha256={pkg_sha256}  size={pkg_size}")

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

# ── Step 4: Sign ──────────────────────────────────────────────────────────────
hex_hash    = hashlib.sha256(yaml_bytes).hexdigest()
double_hash = hashlib.sha256(hex_hash.encode()).digest()
dh_file  = os.path.join(KEYS_DIR, "double_hash.bin")
sig_file = os.path.join(KEYS_DIR, f"{SIGN_NAME}.sig")
with open(dh_file, "wb") as f: f.write(double_hash)
result = subprocess.run(["openssl","pkeyutl","-sign","-inkey",PRIVATE_KEY,
    "-in",dh_file,"-out",sig_file,"-pkeyopt","digest:sha256"], capture_output=True)
if result.returncode != 0: print("Sign failed:", result.stderr.decode()); sys.exit(1)
sig_bytes = open(sig_file,"rb").read()
pub_pem   = open(PUBLIC_KEY,"rb").read()

# ── Step 5: Build packagesite.pkg ─────────────────────────────────────────────
ps_pkg_path = os.path.join(OUTPUT_DIR, "packagesite.pkg")
with tarfile.open(ps_pkg_path, "w:xz") as tar:
    def add_bytes(name, data):
        i = tarfile.TarInfo(name=name); i.size=len(data); i.mtime=int(time.time()); i.mode=0o644
        tar.addfile(i, io.BytesIO(data))
    add_bytes("packagesite.yaml", yaml_bytes)
    add_bytes(f"{SIGN_NAME}.sig", sig_bytes)
    add_bytes(f"{SIGN_NAME}.pub", pub_pem)

# ── Step 6: meta.conf, digests ────────────────────────────────────────────────
meta = '{\n  "version": 2,\n  "packing_format": "txz",\n  "manifests": "packagesite.yaml",\n  "manifests_archive": "packagesite",\n  "filesite": "filesite.yaml",\n  "filesite_archive": "filesite",\n  "digests": "digests.txz",\n  "digests_archive": "digests",\n  "signature_type": "FINGERPRINTS"\n}\n'
for fn in ["meta.conf","meta"]:
    with open(os.path.join(OUTPUT_DIR,fn),"w") as f: f.write(meta)
dc = f"{pkg_file}:{pkg_sha256}\n".encode()
with tarfile.open(os.path.join(OUTPUT_DIR,"digests.txz"),"w:xz") as tar:
    i = tarfile.TarInfo(name="digests.yaml"); i.size=len(dc); i.mtime=int(time.time()); i.mode=0o644
    tar.addfile(i, io.BytesIO(dc))

fingerprint = hashlib.sha256(pub_pem).hexdigest()
print(f"\nFingerprint: {fingerprint}")
print(f"\nBuild complete! Version: {PKG_VERSION}")
print(f"\nOn pfSense:")
print(f"  rm -f /var/cache/pkg/pfSense-pkg-flexiwan*.pkg")
print(f"  pkg update -f && pkg install pfSense-pkg-flexiwan")
print(f"  pkg info -l pfSense-pkg-flexiwan")
print(f"  cat /usr/local/share/pfSense-pkg-flexiwan/info.xml")
