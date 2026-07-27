#!/usr/bin/env php
<?php
/**
 * FlexiWAN Integration Daemon
 * 
 * Background daemon that handles periodic synchronization and health reporting
 * to the FlexiWAN backend. Runs as a service managed by pfSense.
 * 
 * @package FlexiWAN
 * @author Manus AI
 * @version 1.0.0
 */

// Define paths
define('FLEXIWAN_PKG_PATH', '/usr/local/pkg/flexiwan');
define('FLEXIWAN_SRC_PATH', '/usr/local/pkg/flexiwan/src');
define('FLEXIWAN_LOG_FILE', '/var/log/flexiwan.log');

// Configuration
$config_path = isset($argv[2]) && $argv[2] == '-c' ? $argv[3] : FLEXIWAN_PKG_PATH;
$pid_file = '/var/run/flexiwand.pid';
$log_file = FLEXIWAN_LOG_FILE;

// Write PID
file_put_contents($pid_file, getmypid());

// Setup signal handlers
pcntl_signal(SIGTERM, 'signal_handler');
pcntl_signal(SIGINT, 'signal_handler');

$running = true;

function signal_handler($signo) {
    global $running;
    
    switch ($signo) {
        case SIGTERM:
        case SIGINT:
            flexiwan_log('INFO', 'Received shutdown signal');
            $running = false;
            break;
    }
}

/**
 * Log message to file
 */
function flexiwan_log($level, $message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[{$timestamp}] [{$level}] {$message}\n";
    error_log($log_message, 3, FLEXIWAN_LOG_FILE);
}

/**
 * Load pfSense configuration
 */
function load_pfsense_config() {
    global $config;
    
    if (!function_exists('parse_config')) {
        require_once('/etc/inc/config.inc');
    }
    
    if (empty($config)) {
        $config = parse_config();
    }
    
    return $config;
}

/**
 * Main daemon loop
 */
function daemon_main() {
    global $running;
    
    flexiwan_log('INFO', 'FlexiWAN daemon started');
    
    // Load configuration
    $config = load_pfsense_config();
    
    // Check if FlexiWAN is enabled
    if (!isset($config['installedpackages']['flexiwan']) || 
        !$config['installedpackages']['flexiwan']['enabled']) {
        flexiwan_log('WARN', 'FlexiWAN is not enabled, exiting');
        return;
    }
    
    // Load required classes
    require_once(FLEXIWAN_SRC_PATH . '/FlexiWANConfigManager.php');
    require_once(FLEXIWAN_SRC_PATH . '/FlexiWANSyncEngine.php');
    require_once(FLEXIWAN_SRC_PATH . '/FlexiWANHealthMonitor.php');
    
    // Get configuration
    $sync_interval = FlexiWANConfigManager::getSyncInterval();
    $health_report_interval = FlexiWANConfigManager::getHealthReportInterval();
    
    flexiwan_log('INFO', "Sync interval: {$sync_interval}s, Health report interval: {$health_report_interval}s");
    
    $last_sync_time = 0;
    $last_health_report_time = 0;
    $current_time = time();
    
    // Main loop
    while ($running) {
        $current_time = time();
        
        try {
            // Check if device is registered
            $device_id = FlexiWANConfigManager::getDeviceId();
            if (empty($device_id)) {
                // Device not registered, wait and retry
                sleep(10);
                continue;
            }
            
            // Perform configuration sync
            if ($current_time - $last_sync_time >= $sync_interval) {
                flexiwan_log('INFO', 'Starting configuration sync');
                
                $sync_engine = new FlexiWANSyncEngine();
                $result = $sync_engine->syncFromFlexiWAN();
                
                if ($result['success']) {
                    flexiwan_log('INFO', 'Configuration sync completed: ' . $result['message']);
                    $last_sync_time = $current_time;
                } else {
                    flexiwan_log('WARN', 'Configuration sync failed: ' . $result['message']);
                }
            }
            
            // Report health metrics
            if ($current_time - $last_health_report_time >= $health_report_interval) {
                flexiwan_log('DEBUG', 'Reporting health metrics');
                
                $health_monitor = new FlexiWANHealthMonitor();
                $result = $health_monitor->reportHealth();
                
                if ($result['success']) {
                    flexiwan_log('DEBUG', 'Health metrics reported');
                    $last_health_report_time = $current_time;
                } else {
                    flexiwan_log('WARN', 'Health report failed: ' . $result['message']);
                }
            }
            
        } catch (Exception $e) {
            flexiwan_log('ERROR', 'Daemon error: ' . $e->getMessage());
        }
        
        // Sleep before next iteration
        sleep(1);
    }
    
    flexiwan_log('INFO', 'FlexiWAN daemon stopped');
}

// Run daemon
try {
    daemon_main();
} catch (Exception $e) {
    flexiwan_log('ERROR', 'Fatal error: ' . $e->getMessage());
    exit(1);
}

// Clean up
@unlink($pid_file);
exit(0);
?>
