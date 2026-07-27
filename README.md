# Custom pfSense Packages Repository

A **FreeBSD `pkg`-compatible** custom repository for pfSense, hosted on GitHub Pages. Add the repository URL to pfSense and install packages directly from the Package Manager — exactly like official packages.

**Repository URL:** `https://SomeoneCares.github.io/custompf/`

---

## Available Packages

| Package | Version | Category | Description |
|---------|---------|----------|-------------|
| `pfSense-pkg-flexiwan` | 1.0.0 | net | FlexiWAN SD-WAN Integration — registers pfSense with FlexiWAN backend, syncs tunnels and policies, reports health metrics |

---

## Adding This Repository to pfSense

### Step 1 — Download the Repository Public Key

SSH into your pfSense device and run:

```bash
fetch -o /usr/local/etc/pkg/custompf.pub \
  https://raw.githubusercontent.com/SomeoneCares/custompf/master/keys/custompf.pub
```

### Step 2 — Create the Repository Configuration File

```bash
cat > /usr/local/etc/pkg/repos/custompf.conf << 'EOF'
custompf: {
  url: "https://SomeoneCares.github.io/custompf/",
  signature_type: "PUBKEY",
  pubkey: "/usr/local/etc/pkg/custompf.pub",
  enabled: yes,
  priority: 10
}
EOF
```

### Step 3 — Update the Package Catalog

```bash
pkg update
```

You should see output like:
```
Updating custompf repository catalogue...
custompf repository is up to date.
```

### Step 4 — Install a Package

```bash
pkg install pfSense-pkg-flexiwan
```

Or via the pfSense WebGUI: **System > Package Manager > Available Packages**, search for `flexiwan`, click **Install**.

---

## Repository Structure

```
custompf/
├── docs/                          ← GitHub Pages root (the actual pkg repository)
│   ├── index.html                 ← Repository landing page
│   ├── packagesite.pkg            ← Compressed package catalog (used by pkg update)
│   ├── packagesite.yaml           ← Human-readable package catalog
│   ├── meta.conf                  ← Repository metadata and signing info
│   ├── meta                       ← Same as meta.conf (legacy compatibility)
│   ├── digests.txz                ← Package SHA256 checksums
│   ├── signature                  ← RSA signature of packagesite.pkg
│   └── All/
│       └── pfSense-pkg-flexiwan-1.0.0.pkg   ← Package archive
│
├── sources/                       ← Port source code (for building packages)
│   └── net/
│       └── pfSense-pkg-flexiwan/  ← FlexiWAN package source
│
├── keys/
│   └── custompf.pub               ← Repository public key (for pkg signature verification)
│
├── scripts/
│   └── build_pkg.py               ← Package build automation script
│
└── README.md
```

---

## Adding a New Package to This Repository

To add another package to the repository:

1. **Add source files** under `sources/net/pfSense-pkg-<name>/` following the FreeBSD port structure.

2. **Update `scripts/build_pkg.py`** — add a new package entry to the `PACKAGES` list (the script supports building multiple packages in one run).

3. **Run the build script** on a FreeBSD system (or Linux with Python 3):
   ```bash
   python3 scripts/build_pkg.py
   ```

4. **Copy the output** from `packages/` to `docs/` and commit:
   ```bash
   cp packages/All/<new-package>.pkg docs/All/
   # Re-run the build to regenerate packagesite.pkg with all packages
   cp packages/packagesite.pkg docs/
   cp packages/packagesite.yaml docs/
   git add docs/ && git commit -m "Add <new-package> v1.0.0" && git push
   ```

5. **GitHub Pages** automatically serves the updated `docs/` folder — no server management needed.

---

## Security

All packages are signed with an RSA-4096 key pair. The **public key** is committed to this repository at `keys/custompf.pub`. The **private key** is never committed and must be kept secure by the repository maintainer.

When pfSense is configured with `signature_type: "PUBKEY"`, the `pkg` tool verifies the RSA signature of `packagesite.pkg` before trusting the catalog, and verifies SHA256 checksums of each package before installation.

---

## License

MIT License — see [LICENSE](LICENSE) for details.
