#!/usr/local/bin/php -f
<?php
require_once('config.inc');
require_once('functions.inc');
require_once('pkg-utils.inc');

echo "=== Installed packages in config.xml ===\n";
$pkgs = config_get_path('installedpackages/package', []);
foreach ($pkgs as $p) {
    echo "  name=" . $p['name'] . "  configfile=" . $p['configurationfile'] . "\n";
}

echo "\n=== Menus in config.xml ===\n";
$menus = config_get_path('installedpackages/menu', []);
if (empty($menus)) {
    echo "  (no menus registered)\n";
} else {
    foreach ($menus as $m) {
        echo "  name=" . $m['name'] . "  section=" . $m['section'] . "  url=" . $m['url'] . "\n";
    }
}

echo "\n=== Files on disk ===\n";
$files = [
    '/usr/local/share/pfSense-pkg-ovp/info.xml',
    '/usr/local/pkg/ovp_import.xml',
    '/usr/local/pkg/ovp_import.inc',
    '/usr/local/www/packages/ovp/ovp_upload.php',
];
foreach ($files as $f) {
    echo "  " . (file_exists($f) ? "EXISTS" : "MISSING") . "  $f\n";
}

echo "\n=== pkg info ===\n";
passthru('pkg info pfSense-pkg-ovp 2>&1 | head -5');
?>
