<?php
/**
 * FlexiWAN Configuration Manager
 * 
 * Manages the storage and retrieval of FlexiWAN plugin configuration
 * within pfSense's config.xml file.
 * 
 * @package FlexiWAN
 * @author Manus AI
 * @version 1.0.0
 */

class FlexiWANConfigManager {
    
    /**
     * Configuration section name in pfSense config.xml
     */
    const CONFIG_SECTION = 'flexiwan';
    
    /**
     * Get the FlexiWAN configuration section
     * 
     * @return array Configuration array
     */
    public static function getConfig() {
        global $config;
        
        if (!isset($config['installedpackages'][self::CONFIG_SECTION])) {
            $config['installedpackages'][self::CONFIG_SECTION] = [];
        }
        
        return $config['installedpackages'][self::CONFIG_SECTION];
    }
    
    /**
     * Set the entire FlexiWAN configuration
     * 
     * @param array $config Configuration array
     * @return bool Success
     */
    public static function setConfig($config) {
        global $config;
        
        $config['installedpackages'][self::CONFIG_SECTION] = $config;
        
        return write_config('FlexiWAN configuration updated');
    }
    
    /**
     * Get a specific configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed Configuration value
     */
    public static function get($key, $default = null) {
        $config = self::getConfig();
        return $config[$key] ?? $default;
    }
    
    /**
     * Set a specific configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return bool Success
     */
    public static function set($key, $value) {
        global $config;
        
        if (!isset($config['installedpackages'][self::CONFIG_SECTION])) {
            $config['installedpackages'][self::CONFIG_SECTION] = [];
        }
        
        $config['installedpackages'][self::CONFIG_SECTION][$key] = $value;
        
        return write_config("FlexiWAN configuration updated: {$key}");
    }
    
    /**
     * Get backend URL
     * 
     * @return string Backend URL
     */
    public static function getBackendUrl() {
        return self::get('backend_url', 'https://manage.flexiwan.com');
    }
    
    /**
     * Set backend URL
     * 
     * @param string $url Backend URL
     * @return bool Success
     */
    public static function setBackendUrl($url) {
        return self::set('backend_url', $url);
    }
    
    /**
     * Get organization token
     * 
     * @return string Organization token
     */
    public static function getOrganizationToken() {
        return self::get('organization_token', '');
    }
    
    /**
     * Set organization token
     * 
     * @param string $token Organization token
     * @return bool Success
     */
    public static function setOrganizationToken($token) {
        return self::set('organization_token', $token);
    }
    
    /**
     * Get API access key (bearer token)
     * 
     * @return string API access key
     */
    public static function getApiAccessKey() {
        return self::get('api_access_key', '');
    }
    
    /**
     * Set API access key
     * 
     * @param string $key API access key
     * @return bool Success
     */
    public static function setApiAccessKey($key) {
        return self::set('api_access_key', $key);
    }
    
    /**
     * Get device ID
     * 
     * @return string Device ID
     */
    public static function getDeviceId() {
        return self::get('device_id', '');
    }
    
    /**
     * Set device ID
     * 
     * @param string $device_id Device ID
     * @return bool Success
     */
    public static function setDeviceId($device_id) {
        return self::set('device_id', $device_id);
    }
    
    /**
     * Get organization ID
     * 
     * @return string Organization ID
     */
    public static function getOrganizationId() {
        return self::get('organization_id', '');
    }
    
    /**
     * Set organization ID
     * 
     * @param string $org_id Organization ID
     * @return bool Success
     */
    public static function setOrganizationId($org_id) {
        return self::set('organization_id', $org_id);
    }
    
    /**
     * Get registration status
     * 
     * @return string Registration status (unregistered, pending, approved, error)
     */
    public static function getRegistrationStatus() {
        return self::get('registration_status', 'unregistered');
    }
    
    /**
     * Set registration status
     * 
     * @param string $status Registration status
     * @return bool Success
     */
    public static function setRegistrationStatus($status) {
        return self::set('registration_status', $status);
    }
    
    /**
     * Get device name
     * 
     * @return string Device name
     */
    public static function getDeviceName() {
        return self::get('device_name', gethostname());
    }
    
    /**
     * Set device name
     * 
     * @param string $name Device name
     * @return bool Success
     */
    public static function setDeviceName($name) {
        return self::set('device_name', $name);
    }
    
    /**
     * Get device description
     * 
     * @return string Device description
     */
    public static function getDeviceDescription() {
        return self::get('device_description', '');
    }
    
    /**
     * Set device description
     * 
     * @param string $description Device description
     * @return bool Success
     */
    public static function setDeviceDescription($description) {
        return self::set('device_description', $description);
    }
    
    /**
     * Check if plugin is enabled
     * 
     * @return bool Enabled status
     */
    public static function isEnabled() {
        return (bool)self::get('enabled', false);
    }
    
    /**
     * Set enabled status
     * 
     * @param bool $enabled Enabled status
     * @return bool Success
     */
    public static function setEnabled($enabled) {
        return self::set('enabled', (bool)$enabled);
    }
    
    /**
     * Get sync interval in seconds
     * 
     * @return int Sync interval
     */
    public static function getSyncInterval() {
        return (int)self::get('sync_interval', 300); // Default 5 minutes
    }
    
    /**
     * Set sync interval
     * 
     * @param int $interval Sync interval in seconds
     * @return bool Success
     */
    public static function setSyncInterval($interval) {
        return self::set('sync_interval', (int)$interval);
    }
    
    /**
     * Get health reporting interval in seconds
     * 
     * @return int Health reporting interval
     */
    public static function getHealthReportInterval() {
        return (int)self::get('health_report_interval', 60); // Default 1 minute
    }
    
    /**
     * Set health reporting interval
     * 
     * @param int $interval Health reporting interval in seconds
     * @return bool Success
     */
    public static function setHealthReportInterval($interval) {
        return self::set('health_report_interval', (int)$interval);
    }
    
    /**
     * Get last sync timestamp
     * 
     * @return int Unix timestamp
     */
    public static function getLastSyncTime() {
        return (int)self::get('last_sync_time', 0);
    }
    
    /**
     * Set last sync timestamp
     * 
     * @param int $timestamp Unix timestamp
     * @return bool Success
     */
    public static function setLastSyncTime($timestamp) {
        return self::set('last_sync_time', (int)$timestamp);
    }
    
    /**
     * Get synchronized tunnels configuration
     * 
     * @return array Tunnels configuration
     */
    public static function getSynchronizedTunnels() {
        $config = self::getConfig();
        return $config['tunnels'] ?? [];
    }
    
    /**
     * Set synchronized tunnels configuration
     * 
     * @param array $tunnels Tunnels configuration
     * @return bool Success
     */
    public static function setSynchronizedTunnels($tunnels) {
        global $config;
        
        if (!isset($config['installedpackages'][self::CONFIG_SECTION])) {
            $config['installedpackages'][self::CONFIG_SECTION] = [];
        }
        
        $config['installedpackages'][self::CONFIG_SECTION]['tunnels'] = $tunnels;
        
        return write_config('FlexiWAN tunnels configuration updated');
    }
    
    /**
     * Get synchronized policies configuration
     * 
     * @return array Policies configuration
     */
    public static function getSynchronizedPolicies() {
        $config = self::getConfig();
        return $config['policies'] ?? [];
    }
    
    /**
     * Set synchronized policies configuration
     * 
     * @param array $policies Policies configuration
     * @return bool Success
     */
    public static function setSynchronizedPolicies($policies) {
        global $config;
        
        if (!isset($config['installedpackages'][self::CONFIG_SECTION])) {
            $config['installedpackages'][self::CONFIG_SECTION] = [];
        }
        
        $config['installedpackages'][self::CONFIG_SECTION]['policies'] = $policies;
        
        return write_config('FlexiWAN policies configuration updated');
    }
    
    /**
     * Clear all FlexiWAN configuration
     * 
     * @return bool Success
     */
    public static function clearConfig() {
        global $config;
        
        unset($config['installedpackages'][self::CONFIG_SECTION]);
        
        return write_config('FlexiWAN configuration cleared');
    }
}
?>
