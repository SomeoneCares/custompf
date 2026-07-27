# FlexiWAN SD-WAN Integration - Installation Guide

Complete guide for installing the FlexiWAN SD-WAN integration plugin from the custom pfSense repository.

## Prerequisites

- pfSense version 2.6.0 or higher
- Administrator access to pfSense WebGUI
- Internet connectivity to https://manage.flexiwan.com
- FlexiWAN account with Organization Token

## Installation Methods

### Method 1: Via Custom Repository (Recommended)

This is the easiest method for ongoing updates.

#### Step 1: Add the Custom Repository

1. Log in to pfSense WebGUI
2. Navigate to **System > Package Manager > Settings**
3. Scroll to **Repository List**
4. Click **Add** to add a new repository
5. Enter the following details:
   - **Name**: `custompf`
   - **URL**: `https://github.com/SomeoneCares/custompf/releases/download/latest/`
6. Click **Save**

#### Step 2: Install the Package

1. Navigate to **System > Package Manager > Available Packages**
2. Search for "flexiwan" in the search box
3. You should see "FlexiWAN SD-WAN Integration" in the results
4. Click the **Install** button next to it
5. Confirm the installation when prompted
6. Wait for the installation to complete

#### Step 3: Verify Installation

1. Navigate to **Services** menu
2. You should now see **FlexiWAN** as a new submenu item
3. Click on it to access the plugin settings

### Method 2: Manual Installation from Release

Use this method if you prefer to download and install manually.

#### Step 1: Download the Package

1. Visit the [Releases](https://github.com/SomeoneCares/custompf/releases) page
2. Download the latest `pfSense-pkg-flexiwan-1.0.0.txz` file
3. Save it to your computer

#### Step 2: Upload to pfSense

1. Log in to pfSense WebGUI
2. Navigate to **System > Package Manager > Settings**
3. Scroll to **Upload Package**
4. Click **Choose File** and select the downloaded `.txz` file
5. Click **Upload**
6. Wait for the upload and installation to complete

#### Step 3: Verify Installation

1. Navigate to **Services** menu
2. You should now see **FlexiWAN** as a new submenu item

### Method 3: SSH Installation

For advanced users who prefer command-line installation.

#### Step 1: Download the Package via SSH

```bash
ssh admin@your-pfsense-ip
cd /tmp
fetch https://github.com/SomeoneCares/custompf/releases/download/latest/pfSense-pkg-flexiwan-1.0.0.txz
```

#### Step 2: Install the Package

```bash
pkg add pfSense-pkg-flexiwan-1.0.0.txz
```

#### Step 3: Restart Web Interface

```bash
service php-fpm restart
```

## Post-Installation Configuration

### Step 1: Enable the Plugin

1. Navigate to **Services > FlexiWAN > Settings**
2. Check the **Enable FlexiWAN Integration** checkbox
3. (Optional) Configure:
   - **Backend URL**: Leave as default or enter custom URL
   - **Synchronization Interval**: Default is 300 seconds (5 minutes)
   - **Health Report Interval**: Default is 60 seconds (1 minute)
4. Click **Save**

### Step 2: Register Your Device

1. Log in to [FlexiWAN Management Console](https://manage.flexiwan.com)
2. Navigate to **Inventory > Tokens**
3. Click **"New Token"** to create an organization token
4. Copy the generated token
5. In pfSense, navigate to **Services > FlexiWAN > Device Registration**
6. Paste the token into the **Organization Token** field
7. Enter a device name (e.g., "Office-Firewall-1")
8. Click **Register Device**

### Step 3: Approve Device in FlexiWAN

1. Return to the [FlexiWAN Management Console](https://manage.flexiwan.com)
2. Navigate to **Inventory > Devices**
3. Find the new device (status: "Unknown")
4. Click on the device name
5. Enter device name and description
6. Check the **Approved** checkbox
7. Click **Update Device**

### Step 4: Verify Integration

1. In pfSense, navigate to **Services > FlexiWAN > Status**
2. Check the **Device Registration** section
3. Status should show "Approved"
4. Device ID should be populated

## Troubleshooting Installation

### Package Not Found in Available Packages

**Problem**: The FlexiWAN package doesn't appear in the available packages list.

**Solution**:
1. Verify the repository URL is correct
2. Clear the package cache: Navigate to **System > Package Manager > Settings** and click **Reload Repositories**
3. Wait a few minutes and refresh the page
4. Check your internet connection

### Installation Fails

**Problem**: Installation fails with an error message.

**Solution**:
1. Check `/var/log/system.log` for error details
2. Ensure you have sufficient disk space: `df -h`
3. Try installing again
4. If the problem persists, try manual installation via SSH

### FlexiWAN Menu Doesn't Appear

**Problem**: After installation, the FlexiWAN menu doesn't appear under Services.

**Solution**:
1. Restart the web interface: `service php-fpm restart`
2. Clear your browser cache
3. Log out and log back in to pfSense
4. Check `/var/log/system.log` for PHP errors

### Daemon Not Running

**Problem**: The FlexiWAN daemon is not running.

**Solution**:
1. Check daemon status: `service flexiwan.sh status`
2. Start the daemon: `service flexiwan.sh start`
3. Check logs: `tail -f /var/log/flexiwan.log`
4. Verify the daemon file exists: `ls -la /usr/local/bin/flexiwand`

## Uninstallation

To remove the FlexiWAN plugin:

1. Navigate to **System > Package Manager > Installed Packages**
2. Find "FlexiWAN SD-WAN Integration"
3. Click the **Remove** button
4. Confirm the removal
5. The plugin will be uninstalled and all configuration will be removed

## Next Steps

After successful installation:

1. Configure the plugin settings
2. Register your device with FlexiWAN
3. Create SD-WAN tunnels in FlexiWAN
4. Monitor the status dashboard
5. Review the [Configuration Guide](FLEXIWAN_CONFIGURATION.md)

## Support

For issues or questions:

- Review the [Troubleshooting Guide](FLEXIWAN_TROUBLESHOOTING.md)
- Check the [FlexiWAN Documentation](https://docs.flexiwan.com)
- Visit the [pfSense Forum](https://forum.netgate.com)
