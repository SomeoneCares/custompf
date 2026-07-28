# custompf — Custom pfSense Package Repository

A **FreeBSD `pkg`-compatible** custom repository for pfSense, hosted on GitHub Pages.
Add the repository once and then install whichever packages you need — each plugin is
**independently installable**.

**Repository URL:** `https://SomeoneCares.github.io/custompf/`

---

## Available Packages

| Package | Version | Description |
| :--- | :--- | :--- |
| `pfSense-pkg-ovp` | 1.1.0 | **OpenVPN Client Importer** — upload a `.ovpn` file to automatically create a VPN client with certificates |
| `pfSense-pkg-flexiwan` | 1.0.8 | **FlexiWAN SD-WAN** — registers pfSense with FlexiWAN backend, syncs tunnels and policies |

---

## One-Time Repository Setup on pfSense

Run these commands **once** via SSH or **Diagnostics > Command Prompt**.

```sh
# 1. Create fingerprint directories
mkdir -p /usr/local/etc/pkg/fingerprints/custompf/trusted
mkdir -p /usr/local/etc/pkg/fingerprints/custompf/revoked

# 2. Install the repository signing fingerprint
printf 'function: sha256\nfingerprint: 38a64b88cba4cb49d36685dd2a51b2ed968f9806a42b37b30a4efb9b614416af\n' \
  > /usr/local/etc/pkg/fingerprints/custompf/trusted/custompf.fingerprint

# 3. Register the repository
printf 'custompf: {\n  url: "https://SomeoneCares.github.io/custompf/",\n  signature_type: "fingerprints",\n  fingerprints: "/usr/local/etc/pkg/fingerprints/custompf",\n  enabled: yes,\n  priority: 10\n}\n' \
  > /usr/local/etc/pkg/repos/custompf.conf

# 4. Refresh the catalog
pkg update -f
```

---

## Install: OpenVPN Client Importer (`pfSense-pkg-ovp`)

```sh
pkg install pfSense-pkg-ovp
```

Register it with the pfSense web UI:

```sh
php -r "require_once('config.inc'); require_once('functions.inc'); require_once('pkg-utils.inc'); \$pkg_interface='console'; install_package_xml('ovp_import'); echo \"done\n\";"
/etc/rc.restart_webgui
```

Then go to **VPN > OpenVPN > Import Client (.ovpn)** to use it.

---

## Install: FlexiWAN SD-WAN (`pfSense-pkg-flexiwan`)

```sh
pkg install pfSense-pkg-flexiwan
```

Register it with the pfSense web UI:

```sh
php -r "require_once('config.inc'); require_once('functions.inc'); require_once('pkg-utils.inc'); \$pkg_interface='console'; install_package_xml('flexiwan'); echo \"done\n\";"
/etc/rc.restart_webgui
```

---

## Updating a Package

```sh
pkg clean -a -y && pkg update -f
pkg upgrade pfSense-pkg-ovp        # update OVP only
pkg upgrade pfSense-pkg-flexiwan   # update FlexiWAN only
```

---

## Removing a Package

```sh
pkg delete pfSense-pkg-ovp         # remove OVP only
pkg delete pfSense-pkg-flexiwan    # remove FlexiWAN only
```

---

## Repository Structure

```
custompf/
├── docs/                        ← GitHub Pages root (the pkg repo URL)
│   ├── All/
│   │   ├── pfSense-pkg-ovp-1.1.0.pkg
│   │   └── pfSense-pkg-flexiwan-1.0.8.pkg
│   ├── packagesite.pkg          ← signed catalog (xz tar)
│   ├── packagesite.yaml         ← plain catalog (one JSON entry per line)
│   ├── meta.conf / meta
│   └── digests.txz
├── keys/
│   ├── custompf.pub             ← public signing key (committed)
│   └── custompf.fingerprint     ← SHA-256 fingerprint of the public key
├── net/
│   ├── pfSense-pkg-ovp/         ← OVP plugin source files
│   └── pfSense-pkg-flexiwan/    ← FlexiWAN plugin source files
└── scripts/
    └── build_repo.py            ← rebuilds .pkg archives and signed catalog
```

---

## Security

All packages are signed with an RSA-4096 key pair using the `fingerprints` method.
The **public key** is committed at `keys/custompf.pub`. The **private key** is never
committed. pfSense verifies the catalog signature and each package's SHA-256 checksum
before installation.

---

## License

MIT — see [LICENSE](LICENSE) for details.
