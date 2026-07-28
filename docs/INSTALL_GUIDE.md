# Adding the Custom pfSense Repository and Installing FlexiWAN

This guide walks through adding the `custompf` custom package repository to a pfSense installation and installing the FlexiWAN SD-WAN Integration package.

---

## Prerequisites

- pfSense 2.6.0 or later (tested on 2.8.1)
- SSH access to the pfSense device
- Internet access from the pfSense device to GitHub

---

## Step 1 — SSH into pfSense

From your workstation, connect to pfSense via SSH:

```sh
ssh admin@<your-pfsense-ip>
```

Enter your admin password when prompted. You will land at the pfSense shell prompt:

```
[2.8.1-RELEASE][admin@pfsense.home.arpa]/root:
```

---

## Step 2 — Create the Fingerprint Directories

pfSense uses a fingerprint-based trust system to verify packages. Create the required directory structure:

```sh
mkdir -p /usr/local/etc/pkg/fingerprints/custompf/trusted
mkdir -p /usr/local/etc/pkg/fingerprints/custompf/revoked
```

---

## Step 3 — Install the Repository Public Key Fingerprint

This fingerprint allows pfSense to verify that packages from the repository are authentic and have not been tampered with:

```sh
printf 'function: sha256\nfingerprint: 80ea9a70a2d9551b31518f333523ff5bdf57e2531eb607d6a5047a4b2abf8921\n' > /usr/local/etc/pkg/fingerprints/custompf/trusted/custompf.fingerprint
```

Verify it was written correctly:

```sh
cat /usr/local/etc/pkg/fingerprints/custompf/trusted/custompf.fingerprint
```

Expected output:
```
function: sha256
fingerprint: 80ea9a70a2d9551b31518f333523ff5bdf57e2531eb607d6a5047a4b2abf8921
```

---

## Step 4 — Create the Repository Configuration

Add the `custompf` repository to pfSense's package manager:

```sh
printf 'custompf: {\n  url: "https://SomeoneCares.github.io/custompf/",\n  signature_type: "fingerprints",\n  fingerprints: "/usr/local/etc/pkg/fingerprints/custompf",\n  enabled: yes,\n  priority: 10\n}\n' > /usr/local/etc/pkg/repos/custompf.conf
```

Verify the file:

```sh
cat /usr/local/etc/pkg/repos/custompf.conf
```

Expected output:
```
custompf: {
  url: "https://SomeoneCares.github.io/custompf/",
  signature_type: "fingerprints",
  fingerprints: "/usr/local/etc/pkg/fingerprints/custompf",
  enabled: yes,
  priority: 10
}
```

---

## Step 5 — Update the Package Catalog

Fetch the latest package catalog from all repositories including the new `custompf` repo:

```sh
pkg update -f
```

You should see output similar to:

```
Updating custompf repository catalogue...
Fetching meta.conf: 100%
Fetching packagesite.pkg: 100%
Processing entries: 100%
custompf repository update completed. 1 packages processed.
Updating pfSense-core repository catalogue...
...
All repositories are up to date.
```

If you see `custompf repository update completed`, the repository is working correctly.

---

## Step 6 — Search for Available Packages

Confirm the FlexiWAN package is visible:

```sh
pkg search pfSense-pkg-flexiwan
```

Expected output:
```
pfSense-pkg-flexiwan-1.0.8     pfSense FlexiWAN SD-WAN Integration
```

---

## Step 7 — Install the Package

```sh
pkg install pfSense-pkg-flexiwan
```

When prompted `Proceed with this action? [y/N]`, type `y` and press Enter.

You should see:
```
[1/1] Installing pfSense-pkg-flexiwan-1.0.8...
[1/1] Extracting pfSense-pkg-flexiwan-1.0.8: 100%
```

Verify all files were installed:

```sh
pkg info -l pfSense-pkg-flexiwan
```

You should see 13 files listed under `/usr/local/`.

---

## Step 8 — Register the Package with pfSense

This step registers the package in pfSense's configuration and adds it to the web UI menu:

```sh
echo '<?php require_once("config.inc"); require_once("functions.inc"); require_once("pkg-utils.inc"); $pkg_interface = "console"; install_package_xml("flexiwan"); echo "done\n";' > /tmp/reg.php && /usr/local/bin/php /tmp/reg.php
```

Expected output:
```
Saving updated package information...
done.
Loading package configuration... done.
Configuring package components...
Writing configuration... done.
done
```

---

## Step 9 — Restart the Web Interface

```sh
/etc/rc.restart_webgui
```

Wait for the prompt to return (about 5 seconds).

---

## Step 10 — Access FlexiWAN in the pfSense WebGUI

1. Open your browser and navigate to `https://<your-pfsense-ip>`
2. Log in with your admin credentials
3. Click **Services** in the top navigation menu
4. Click **FlexiWAN** — you should see the FlexiWAN settings page

---

## Configuring FlexiWAN

Once the package is installed, configure it through the pfSense WebGUI:

### Settings Tab (`Services > FlexiWAN`)

| Field | Description |
|-------|-------------|
| Enable FlexiWAN Integration | Check to activate the plugin |
| FlexiWAN Backend URL | Leave as `https://manage.flexiwan.com` (default) |
| Organization Token | Paste your token from the FlexiWAN portal |
| Sync Interval | How often to sync with the backend (default: 60 seconds) |
| Enable Debug Logging | Check to write verbose logs to `/var/log/flexiwan.log` |

### Device Registration Tab

1. Enter a **Device Name** (defaults to hostname)
2. Paste your **Organization Token** from [manage.flexiwan.com](https://manage.flexiwan.com)
   - Log in → Inventory → Tokens → New Token → Copy
3. Click **Save**
4. The device will register automatically and appear in the FlexiWAN portal
5. Approve the device in the FlexiWAN portal to begin syncing configurations

### Status Tab

Shows real-time information:
- Device registration status
- Last synchronization time
- System health (CPU, memory, disk)
- Network interfaces
- Active SD-WAN tunnels

---

## Upgrading the Package

When a new version is available:

```sh
pkg update -f
pkg upgrade pfSense-pkg-flexiwan
```

---

## Removing the Package

To uninstall the FlexiWAN package:

```sh
pkg delete pfSense-pkg-flexiwan
```

To also remove the repository:

```sh
rm /usr/local/etc/pkg/repos/custompf.conf
rm -rf /usr/local/etc/pkg/fingerprints/custompf
```

---

## Troubleshooting

### `pkg update` fails with "No signature found"

The fingerprint file is missing or incorrect. Redo Step 3.

### Package installs but menu does not appear

Re-run the registration command from Step 8, then restart the web interface (Step 9).

### Status page shows a crash report

Check the PHP error log:

```sh
tail -20 /var/log/flexiwan.log
```

### `pkg update` still shows old version

Clear the package cache:

```sh
pkg clean -a -y
pkg update -f
```

### Cannot connect to FlexiWAN backend

1. Verify internet access: `ping manage.flexiwan.com`
2. Check the backend URL in Settings is `https://manage.flexiwan.com`
3. Verify the organization token is correct and not expired
4. Check logs: `tail -f /var/log/flexiwan.log`

---

## Repository Information

| Item | Value |
|------|-------|
| Repository URL | `https://SomeoneCares.github.io/custompf/` |
| GitHub Source | `https://github.com/SomeoneCares/custompf` |
| Fingerprint | `80ea9a70a2d9551b31518f333523ff5bdf57e2531eb607d6a5047a4b2abf8921` |
| Signature Type | `fingerprints` (RSA-4096) |
| Current Package | `pfSense-pkg-flexiwan-1.0.8` |
