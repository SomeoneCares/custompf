# Custom pfSense Packages Repository

A custom FreeBSD ports repository containing additional pfSense packages for enhanced functionality.

## Packages Included

### FlexiWAN SD-WAN Integration (v1.0.0)

A production-ready pfSense plugin that integrates with the FlexiWAN SD-WAN central management platform (flexiManage).

**Features:**
- Device registration with FlexiWAN backend
- Automatic configuration synchronization
- Real-time health monitoring and metrics reporting
- Native pfSense web UI integration
- Background daemon for periodic operations

**Documentation:**
- [Installation Guide](docs/FLEXIWAN_INSTALLATION.md)
- [Configuration Guide](docs/FLEXIWAN_CONFIGURATION.md)
- [Troubleshooting Guide](docs/FLEXIWAN_TROUBLESHOOTING.md)

## Installation

### Method 1: Add Custom Repository to pfSense (Recommended)

1. Log in to pfSense WebGUI
2. Navigate to **System > Package Manager > Settings**
3. In the **Repository List**, add a new repository:
   - **Name**: `custompf`
   - **URL**: `https://github.com/YOUR_USERNAME/custompf/releases/download/latest/`
4. Click **Save**
5. Navigate to **System > Package Manager > Available Packages**
6. Search for "flexiwan"
7. Click **Install**

### Method 2: Manual Installation from GitHub

1. Download the latest release from the [Releases](https://github.com/YOUR_USERNAME/custompf/releases) page
2. Upload the `.txz` package file to your pfSense device
3. Install via SSH:
   ```bash
   pkg add /path/to/pfSense-pkg-flexiwan-1.0.0.txz
   ```

### Method 3: Build from Source

1. Clone this repository:
   ```bash
   git clone https://github.com/YOUR_USERNAME/custompf.git
   cd custompf
   ```

2. Build the package:
   ```bash
   cd net/pfSense-pkg-flexiwan
   make package
   ```

3. Install the built package:
   ```bash
   pkg add work/pkg/pfSense-pkg-flexiwan-1.0.0.txz
   ```

## Repository Structure

```
custompf/
├── net/
│   └── pfSense-pkg-flexiwan/          # FlexiWAN SD-WAN Integration
│       ├── Makefile                   # FreeBSD port Makefile
│       ├── pkg-plist                  # Package file list
│       ├── files/                     # Package files
│       │   └── usr/local/
│       │       ├── pkg/               # pfSense package files
│       │       ├── www/               # Web UI pages
│       │       ├── bin/               # Daemon scripts
│       │       └── etc/rc.d/          # Service scripts
│       └── pkg-install                # Installation script
├── docs/                              # Documentation
├── README.md                          # This file
└── LICENSE                            # MIT License
```

## Building Packages

To build all packages in this repository:

```bash
# Build a specific package
cd net/pfSense-pkg-flexiwan
make package

# The built package will be in work/pkg/
```

## Contributing

To add new packages to this repository:

1. Create a new directory under `net/` for your package
2. Follow the FreeBSD ports structure
3. Include a Makefile, pkg-plist, and files/
4. Submit a pull request

## Support

For issues with packages in this repository:

- **FlexiWAN Issues**: Check the [FlexiWAN Documentation](https://docs.flexiwan.com)
- **General pfSense Issues**: Visit the [pfSense Forum](https://forum.netgate.com)

## License

All packages in this repository are licensed under the MIT License. See [LICENSE](LICENSE) for details.

## Repository Maintainer

Maintained by: Your Name (your-email@example.com)

## Disclaimer

These packages are provided as-is. Use at your own risk. Always test in a non-production environment first.
