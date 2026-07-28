<?php
/**
 * FlexiWAN Package Include File
 *
 * Helper functions for the FlexiWAN pfSense package.
 * Uses pfSense config_get_path() / config_set_path() APIs.
 */

define('FLEXIWAN_SRC_PATH', '/usr/local/pkg/flexiwan/src');
define('FLEXIWAN_LOG',       '/var/log/flexiwan.log');

/* ── Config helpers ──────────────────────────────────────────────────────── */

function flexiwan_get_config() {
    $cfg = config_get_path('installedpackages/flexiwan/config/0', []);
    return is_array($cfg) ? $cfg : [];
}

function flexiwan_is_enabled() {
    $cfg = flexiwan_get_config();
    return isset($cfg['enabled']) && $cfg['enabled'] === 'on';
}

function flexiwan_get_backend_url() {
    $cfg = flexiwan_get_config();
    return !empty($cfg['backend_url']) ? $cfg['backend_url'] : 'https://manage.flexiwan.com';
}

function flexiwan_get_org_token() {
    $cfg = flexiwan_get_config();
    return $cfg['org_token'] ?? '';
}

/* ── Device info ─────────────────────────────────────────────────────────── */

function flexiwan_get_device_info() {
    $defaults = [
        'device_id'           => '',
        'device_name'         => gethostname(),
        'organization_id'     => '',
        'registration_status' => 'unregistered',
        'backend_url'         => flexiwan_get_backend_url(),
    ];
    try {
        require_once(FLEXIWAN_SRC_PATH . '/FlexiWANDeviceRegistration.php');
        $reg = new FlexiWANDeviceRegistration();
        $info = $reg->getDeviceInfo();
        return is_array($info) ? array_merge($defaults, $info) : $defaults;
    } catch (Exception $e) {
        flexiwan_log('error', 'getDeviceInfo: ' . $e->getMessage());
        return $defaults;
    }
}

function flexiwan_get_device_status() {
    try {
        require_once(FLEXIWAN_SRC_PATH . '/FlexiWANDeviceRegistration.php');
        $reg = new FlexiWANDeviceRegistration();
        return $reg->checkRegistrationStatus();
    } catch (Exception $e) {
        flexiwan_log('error', 'getDeviceStatus: ' . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/* ── Health ──────────────────────────────────────────────────────────────── */

function flexiwan_get_health_status() {
    $defaults = [
        'system' => [
            'cpu_usage'    => 0,
            'uptime'       => 0,
            'load_average' => ['one_minute' => 0, 'five_minutes' => 0, 'fifteen_minutes' => 0],
            'memory_usage' => ['total' => 0, 'used' => 0, 'free' => 0, 'percent' => 0],
            'disk_usage'   => ['total' => 0, 'used' => 0, 'free' => 0, 'percent' => 0],
        ],
        'interfaces' => [],
        'tunnels'    => [],
    ];
    try {
        require_once(FLEXIWAN_SRC_PATH . '/FlexiWANHealthMonitor.php');
        $hm = new FlexiWANHealthMonitor();
        $result = $hm->getHealthStatus();
        if (!is_array($result)) return $defaults;
        // Deep merge to ensure all keys exist
        $result['system'] = array_merge($defaults['system'], $result['system'] ?? []);
        $result['system']['memory_usage'] = array_merge($defaults['system']['memory_usage'], $result['system']['memory_usage'] ?? []);
        $result['system']['disk_usage']   = array_merge($defaults['system']['disk_usage'],   $result['system']['disk_usage'] ?? []);
        $result['system']['load_average'] = array_merge($defaults['system']['load_average'], $result['system']['load_average'] ?? []);
        return $result;
    } catch (Exception $e) {
        flexiwan_log('error', 'getHealthStatus: ' . $e->getMessage());
        return $defaults;
    }
}

/* ── Tunnels ─────────────────────────────────────────────────────────────── */

function flexiwan_get_tunnels() {
    try {
        require_once(FLEXIWAN_SRC_PATH . '/FlexiWANConfigManager.php');
        $tunnels = FlexiWANConfigManager::getSynchronizedTunnels();
        return is_array($tunnels) ? $tunnels : [];
    } catch (Exception $e) {
        flexiwan_log('error', 'getTunnels: ' . $e->getMessage());
        return [];
    }
}

/* ── Sync status ─────────────────────────────────────────────────────────── */

function flexiwan_get_last_sync_status() {
    $defaults = ['formatted_time' => 'Never', 'last_sync_ago' => 0];
    try {
        require_once(FLEXIWAN_SRC_PATH . '/FlexiWANSyncEngine.php');
        $se = new FlexiWANSyncEngine();
        $result = $se->getLastSyncStatus();
        return is_array($result) ? array_merge($defaults, $result) : $defaults;
    } catch (Exception $e) {
        flexiwan_log('error', 'getLastSyncStatus: ' . $e->getMessage());
        return $defaults;
    }
}

/* ── Callbacks ───────────────────────────────────────────────────────────── */

function flexiwan_sync_config() {
    if (!flexiwan_is_enabled()) return;
    try {
        require_once(FLEXIWAN_SRC_PATH . '/FlexiWANSyncEngine.php');
        $se = new FlexiWANSyncEngine();
        $result = $se->syncFromFlexiWAN();
        if (!$result['success']) {
            log_error('FlexiWAN: Sync failed - ' . $result['message']);
        }
    } catch (Exception $e) {
        log_error('FlexiWAN: Sync error - ' . $e->getMessage());
    }
}

function flexiwan_report_health() {
    if (!flexiwan_is_enabled()) return;
    try {
        require_once(FLEXIWAN_SRC_PATH . '/FlexiWANHealthMonitor.php');
        $hm = new FlexiWANHealthMonitor();
        $result = $hm->reportHealth();
        if (!$result['success']) {
            log_error('FlexiWAN: Health report failed - ' . $result['message']);
        }
    } catch (Exception $e) {
        log_error('FlexiWAN: Health error - ' . $e->getMessage());
    }
}

/* ── Formatting ──────────────────────────────────────────────────────────── */

function flexiwan_format_bytes($bytes, $precision = 2) {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $precision) . ' ' . $units[$i];
}

function flexiwan_format_uptime($seconds) {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return '0m';
    $days    = floor($seconds / 86400);
    $hours   = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $parts = [];
    if ($days)    $parts[] = $days . 'd';
    if ($hours)   $parts[] = $hours . 'h';
    if ($minutes) $parts[] = $minutes . 'm';
    return implode(' ', $parts) ?: '0m';
}

function flexiwan_get_status_badge($status) {
    $map = [
        'approved'     => 'success',
        'up'           => 'success',
        'connected'    => 'success',
        'pending'      => 'warning',
        'unregistered' => 'secondary',
        'down'         => 'danger',
        'error'        => 'danger',
        'unknown'      => 'secondary',
    ];
    $class = $map[strtolower((string)$status)] ?? 'secondary';
    return '<span class="badge badge-' . $class . '">' . htmlspecialchars(ucfirst((string)$status)) . '</span>';
}

function flexiwan_log($level, $message) {
    $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message . "\n";
    @file_put_contents(FLEXIWAN_LOG, $line, FILE_APPEND | LOCK_EX);
}
