<?php
/**
 * FlexiWAN Device Registration Handler
 * 
 * Manages the device registration workflow with FlexiWAN backend.
 * Handles token validation, device registration, and status tracking.
 * 
 * @package FlexiWAN
 * @author Manus AI
 * @version 1.0.0
 */

class FlexiWANDeviceRegistration {
    
    /**
     * API client instance
     * @var FlexiWANApiClient
     */
    private $api_client;
    
    /**
     * Configuration manager instance
     * @var FlexiWANConfigManager
     */
    private $config_manager;
    
    /**
     * Logger instance
     * @var object
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        require_once(__DIR__ . '/FlexiWANApiClient.php');
        require_once(__DIR__ . '/FlexiWANConfigManager.php');
        
        $this->api_client = new FlexiWANApiClient(
            FlexiWANConfigManager::getBackendUrl(),
            FlexiWANConfigManager::getApiAccessKey()
        );
        
        $this->config_manager = new FlexiWANConfigManager();
        $this->logger = new FlexiWANLogger();
    }
    
    /**
     * Validate organization token format
     * 
     * @param string $token Organization token
     * @return bool Valid token format
     */
    public static function validateTokenFormat($token) {
        // Token should be a non-empty string
        // FlexiWAN tokens are typically JWT or UUID format
        return !empty($token) && strlen($token) > 10 && strlen($token) < 2000;
    }
    
    /**
     * Register device with FlexiWAN backend
     * 
     * This method initiates the device registration process:
     * 1. Validates the organization token
     * 2. Sends registration request to FlexiWAN backend
     * 3. Stores device ID and organization ID locally
     * 4. Sets registration status to "pending"
     * 
     * @param string $org_token Organization token from FlexiWAN
     * @param string $device_name Name for the device
     * @param string $device_description Optional description
     * @return array Registration result with status and message
     */
    public function registerDevice($org_token, $device_name, $device_description = '') {
        try {
            // Validate token format
            if (!self::validateTokenFormat($org_token)) {
                return [
                    'success' => false,
                    'message' => 'Invalid organization token format',
                    'status' => 'error'
                ];
            }
            
            // Set the organization token
            $this->api_client->setOrganizationToken($org_token);
            
            // Attempt device registration
            $response = $this->api_client->registerDevice($device_name, $device_description);
            
            if (!isset($response['device_id'])) {
                return [
                    'success' => false,
                    'message' => $response['error'] ?? 'Registration failed: No device ID returned',
                    'status' => 'error'
                ];
            }
            
            // Store configuration locally
            FlexiWANConfigManager::setOrganizationToken($org_token);
            FlexiWANConfigManager::setDeviceId($response['device_id']);
            
            if (isset($response['organization_id'])) {
                FlexiWANConfigManager::setOrganizationId($response['organization_id']);
            }
            
            FlexiWANConfigManager::setDeviceName($device_name);
            FlexiWANConfigManager::setDeviceDescription($device_description);
            FlexiWANConfigManager::setRegistrationStatus('pending');
            
            $this->logger->info("Device registration initiated: {$response['device_id']}");
            
            return [
                'success' => true,
                'message' => 'Device registered successfully. Awaiting admin approval in FlexiWAN.',
                'status' => 'pending',
                'device_id' => $response['device_id']
            ];
            
        } catch (FlexiWANException $e) {
            $this->logger->error("Registration error: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Registration error: ' . $e->getMessage(),
                'status' => 'error'
            ];
        } catch (Exception $e) {
            $this->logger->error("Unexpected error during registration: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Unexpected error: ' . $e->getMessage(),
                'status' => 'error'
            ];
        }
    }
    
    /**
     * Check device registration status with FlexiWAN backend
     * 
     * @return array Status information
     */
    public function checkRegistrationStatus() {
        try {
            $device_id = FlexiWANConfigManager::getDeviceId();
            
            if (empty($device_id)) {
                return [
                    'registered' => false,
                    'status' => 'unregistered',
                    'message' => 'Device not registered'
                ];
            }
            
            // Set device ID for API client
            $this->api_client->setDeviceId($device_id);
            
            // Get device status from backend
            $status = $this->api_client->getDeviceStatus();
            
            // Update local status
            $current_status = $status['state'] ?? 'unknown';
            FlexiWANConfigManager::setRegistrationStatus($current_status);
            
            return [
                'registered' => true,
                'status' => $current_status,
                'device_id' => $device_id,
                'details' => $status
            ];
            
        } catch (FlexiWANException $e) {
            $this->logger->error("Status check error: " . $e->getMessage());
            
            return [
                'registered' => false,
                'status' => 'error',
                'message' => 'Failed to check status: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Unregister device from FlexiWAN backend
     * 
     * @return array Unregistration result
     */
    public function unregisterDevice() {
        try {
            $device_id = FlexiWANConfigManager::getDeviceId();
            
            if (empty($device_id)) {
                return [
                    'success' => false,
                    'message' => 'Device not registered'
                ];
            }
            
            // Set device ID for API client
            $this->api_client->setDeviceId($device_id);
            
            // Stop the device first
            try {
                $this->api_client->stopDevice();
            } catch (Exception $e) {
                // Continue even if stop fails
                $this->logger->warn("Failed to stop device: " . $e->getMessage());
            }
            
            // Clear local configuration
            FlexiWANConfigManager::clearConfig();
            
            $this->logger->info("Device unregistered: {$device_id}");
            
            return [
                'success' => true,
                'message' => 'Device unregistered successfully'
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Unregistration error: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Unregistration error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get device registration information
     * 
     * @return array Device information
     */
    public function getDeviceInfo() {
        return [
            'device_id' => FlexiWANConfigManager::getDeviceId(),
            'device_name' => FlexiWANConfigManager::getDeviceName(),
            'device_description' => FlexiWANConfigManager::getDeviceDescription(),
            'organization_id' => FlexiWANConfigManager::getOrganizationId(),
            'registration_status' => FlexiWANConfigManager::getRegistrationStatus(),
            'backend_url' => FlexiWANConfigManager::getBackendUrl(),
            'enabled' => FlexiWANConfigManager::isEnabled()
        ];
    }
    
    /**
     * Update device name
     * 
     * @param string $device_name New device name
     * @return array Result
     */
    public function updateDeviceName($device_name) {
        try {
            FlexiWANConfigManager::setDeviceName($device_name);
            
            $this->logger->info("Device name updated to: {$device_name}");
            
            return [
                'success' => true,
                'message' => 'Device name updated successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update device name: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Update device description
     * 
     * @param string $description New description
     * @return array Result
     */
    public function updateDeviceDescription($description) {
        try {
            FlexiWANConfigManager::setDeviceDescription($description);
            
            $this->logger->info("Device description updated");
            
            return [
                'success' => true,
                'message' => 'Device description updated successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update device description: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate API access key with FlexiWAN backend
     * 
     * @param string $api_key API access key
     * @return array Validation result
     */
    public function validateApiKey($api_key) {
        try {
            $client = new FlexiWANApiClient(
                FlexiWANConfigManager::getBackendUrl(),
                $api_key
            );
            
            // Try to get organizations to validate the key
            $orgs = $client->getOrganizations();
            
            if (is_array($orgs)) {
                FlexiWANConfigManager::setApiAccessKey($api_key);
                
                return [
                    'success' => true,
                    'message' => 'API key validated successfully'
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Invalid API key'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'API key validation failed: ' . $e->getMessage()
            ];
        }
    }
}

/**
 * FlexiWAN Logger
 * 
 * Simple logging class for debugging and error tracking
 */
if (!class_exists("FlexiWANLogger")) {
class FlexiWANLogger {
    
    private $log_file = '/var/log/flexiwan.log';
    
    public function info($message) {
        $this->log('INFO', $message);
    }
    
    public function error($message) {
        $this->log('ERROR', $message);
    }
    
    public function warn($message) {
        $this->log('WARN', $message);
    }
    
    public function debug($message) {
        $this->log('DEBUG', $message);
    }
    
    private function log($level, $message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] [{$level}] {$message}\n";
        
        @error_log($log_message, 3, $this->log_file);
    }
}
}  // end class_exists FlexiWANLogger
?>
