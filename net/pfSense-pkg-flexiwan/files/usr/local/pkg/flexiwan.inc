<?php
/**
 * FlexiWAN Package Include File
 * 
 * Contains helper functions and callbacks for the pfSense package system.
 * This file is included by the pfSense package manager during installation
 * and configuration updates.
 * 
 * @package FlexiWAN
 * @author Manus AI
 * @version 1.0.0
 */

// Define package paths
define('FLEXIWAN_PKG_PATH', '/usr/local/pkg/flexiwan');
define('FLEXIWAN_SRC_PATH', '/usr/local/pkg/flexiwan/src');
define('FLEXIWAN_WWW_PATH', '/usr/local/www/flexiwan');

/**
 * Synchronize FlexiWAN configuration
 * 
 * This function is called when the package configuration is saved
 * or when the system is resynced.
 */
function flexiwan_sync_config() {
    global $config;
    
    // Check if FlexiWAN is enabled
    if (!isset($config['installedpackages']['flexiwan']) || 
        !$config['installedpackages']['flexiwan']['enabled']) {
        return;
    }
    
    // Load the sync engine
    require_once(FLEXIWAN_SRC_PATH . '/FlexiWANSyncEngine.php');
    
    try {
        $sync_engine = new FlexiWANSyncEngine();
        $result = $sync_engine->syncFromFlexiWAN();
        
        if ($result['success']) {
            log_error("FlexiWAN: Configuration synced successfully");
        } else {
            log_error("FlexiWAN: Sync failed - " . $result['message']);
        }
    } catch (Exception $e) {
        log_error("FlexiWAN: Error during sync - " . $e->getMessage());
    }
}

/**
 * Report health metrics to FlexiWAN backend
 * 
 * This function is called periodically by the daemon
 */
function flexiwan_report_health() {
    global $config;
    
    // Check if FlexiWAN is enabled
    if (!isset($config['installedpackages']['flexiwan']) || 
        !$config['installedpackages']['flexiwan']['enabled']) {
        return;
    }
    
    // Load the health monitor
    require_once(FLEXIWAN_SRC_PATH . '/FlexiWANHealthMonitor.php');
    
    try {
        $health_monitor = new FlexiWANHealthMonitor();
        $result = $health_monitor->reportHealth();
        
        if (!$result['success']) {
            log_error("FlexiWAN: Health report failed - " . $result['message']);
        }
    } catch (Exception $e) {
        log_error("FlexiWAN: Error during health report - " . $e->getMessage());
    }
}

/**
 * Get FlexiWAN configuration
 * 
 * @return array Configuration array
 */
function flexiwan_get_config() {
    global $config;
    
    if (!isset($config['installedpackages']['flexiwan'])) {
        $config['installedpackages']['flexiwan'] = array();
    }
    
    return $config['installedpackages']['flexiwan'];
}

/**
 * Check if FlexiWAN is enabled
 * 
 * @return bool Enabled status
 */
function flexiwan_is_enabled() {
    $config = flexiwan_get_config();
    return isset($config['enabled']) && $config['enabled'];
}

/**
 * Get device registration status
 * 
 * @return array Status information
 */
function flexiwan_get_device_status() {
    require_once(FLEXIWAN_SRC_PATH . '/FlexiWANDeviceRegistration.php');
    
    $registration = new FlexiWANDeviceRegistration();
    return $registration->checkRegistrationStatus();
}

/**
 * Get device information
 * 
 * @return array Device information
 */
function flexiwan_get_device_info() {
    require_once(FLEXIWAN_SRC_PATH . '/FlexiWANDeviceRegistration.php');
    
    $registration = new FlexiWANDeviceRegistration();
    return $registration->getDeviceInfo();
}

/**
 * Get health status
 * 
 * @return array Health status
 */
function flexiwan_get_health_status() {
    require_once(FLEXIWAN_SRC_PATH . '/FlexiWANHealthMonitor.php');
    
    $health_monitor = new FlexiWANHealthMonitor();
    return $health_monitor->getHealthStatus();
}

/**
 * Get synchronized tunnels
 * 
 * @return array Tunnels configuration
 */
function flexiwan_get_tunnels() {
    require_once(FLEXIWAN_SRC_PATH . '/FlexiWANConfigManager.php');
    
    return FlexiWANConfigManager::getSynchronizedTunnels();
}

/**
 * Get last sync status
 * 
 * @return array Sync status
 */
function flexiwan_get_last_sync_status() {
    require_once(FLEXIWAN_SRC_PATH . '/FlexiWANSyncEngine.php');
    
    $sync_engine = new FlexiWANSyncEngine();
    return $sync_engine->getLastSyncStatus();
}

/**
 * Format bytes to human-readable format
 * 
 * @param int $bytes Number of bytes
 * @param int $precision Decimal precision
 * @return string Formatted size
 */
function flexiwan_format_bytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * Format uptime to human-readable format
 * 
 * @param int $seconds Uptime in seconds
 * @return string Formatted uptime
 */
function flexiwan_format_uptime($seconds) {
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    $parts = [];
    if ($days > 0) $parts[] = $days . 'd';
    if ($hours > 0) $parts[] = $hours . 'h';
    if ($minutes > 0) $parts[] = $minutes . 'm';
    
    return implode(' ', $parts) ?: '0m';
}

/**
 * Get status badge HTML
 * 
 * @param string $status Status value
 * @return string HTML badge
 */
function flexiwan_get_status_badge($status) {
    $badges = array(
        'approved' => '<span class="badge badge-success">Approved</span>',
        'pending' => '<span class="badge badge-warning">Pending</span>',
        'unregistered' => '<span class="badge badge-secondary">Unregistered</span>',
        'error' => '<span class="badge badge-danger">Error</span>',
        'up' => '<span class="badge badge-success">Up</span>',
        'down' => '<span class="badge badge-danger">Down</span>',
        'unknown' => '<span class="badge badge-secondary">Unknown</span>'
    );
    
    return $badges[$status] ?? '<span class="badge badge-secondary">' . htmlspecialchars($status) . '</span>';
}

/**
 * Log FlexiWAN event
 * 
 * @param string $level Log level (info, warning, error)
 * @param string $message Log message
 */
function flexiwan_log($level, $message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[{$timestamp}] [{$level}] {$message}\n";
    
    @error_log($log_message, 3, '/var/log/flexiwan.log');
}

?>
