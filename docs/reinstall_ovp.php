#!/usr/local/bin/php -f
<?php
require_once('config.inc');
require_once('functions.inc');
require_once('pkg-utils.inc');

$pkg_interface = 'console';

echo "=== Cleaning up stale OVP package entries ===\n";

// Remove ALL entries named ovp or pfSense-pkg-ovp from installedpackages/package
$pkgs = config_get_path('installedpackages/package', []);
$cleaned = [];
$removed = 0;
foreach ($pkgs as $p) {
    if ($p['name'] === 'ovp' || $p['name'] === 'pfSense-pkg-ovp') {
        echo "  Removing stale package entry: " . $p['name'] . "\n";
        $removed++;
    } else {
        $cleaned[] = $p;
    }
}
config_set_path('installedpackages/package', $cleaned);
echo "  Removed $removed stale entries.\n";

// Remove any OVP menu entries
$menus = config_get_path('installedpackages/menu', []);
$clean_menus = [];
$removed_menus = 0;
foreach ($menus as $m) {
    if (isset($m['url']) && strpos($m['url'], 'ovp') !== false) {
        echo "  Removing stale menu entry: " . $m['name'] . "\n";
        $removed_menus++;
    } else {
        $clean_menus[] = $m;
    }
}
config_set_path('installedpackages/menu', $clean_menus);
echo "  Removed $removed_menus stale menu entries.\n";

write_config("Cleaned up stale OVP package entries", false, true);
echo "  Config saved.\n\n";

echo "=== Re-registering pfSense-pkg-ovp ===\n";
$result = install_package_xml('ovp');
if ($result) {
    echo "\nSUCCESS: Package registered.\n";
    // Verify menu was written
    $menus = config_get_path('installedpackages/menu', []);
    $found = false;
    foreach ($menus as $m) {
        if (isset($m['url']) && strpos($m['url'], 'ovp') !== false) {
            echo "Menu entry confirmed: name='" . $m['name'] . "' section='" . $m['section'] . "' url='" . $m['url'] . "'\n";
            $found = true;
        }
    }
    if (!$found) {
        echo "WARNING: Menu entry not found after registration.\n";
        echo "Checking ovp_import.xml menu section...\n";
        $cfg = parse_xml_config_pkg('/usr/local/pkg/ovp_import.xml', 'packagegui');
        echo "menu from XML: " . print_r($cfg['menu'], true) . "\n";
    }
} else {
    echo "\nFAILED: install_package_xml returned false.\n";
}
?>
