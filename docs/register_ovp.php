#!/usr/local/bin/php -f
<?php
require_once('config.inc');
require_once('functions.inc');
require_once('pkg-utils.inc');
$pkg_interface = 'console';
echo "Registering pfSense-pkg-ovp...\n";
$result = install_package_xml('ovp');
if ($result) {
    echo "SUCCESS: Package registered. Menu item added to VPN menu.\n";
    echo "Run: /etc/rc.restart_webgui\n";
} else {
    echo "FAILED: install_package_xml returned false.\n";
    echo "Checking info.xml path...\n";
    $path = '/usr/local/share/pfSense-pkg-ovp/info.xml';
    echo file_exists($path) ? "info.xml EXISTS at $path\n" : "info.xml MISSING at $path\n";
    $path2 = '/usr/local/pkg/ovp_import.xml';
    echo file_exists($path2) ? "ovp_import.xml EXISTS at $path2\n" : "ovp_import.xml MISSING at $path2\n";
}
?>
