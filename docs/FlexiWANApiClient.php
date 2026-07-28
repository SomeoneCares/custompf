<?php
/**
 * FlexiWAN API Client
 * 
 * Provides a PHP client for interacting with the FlexiWAN flexiManage REST API.
 * Handles authentication, device registration, configuration sync, and telemetry reporting.
 * 
 * @package FlexiWAN
 * @author Manus AI
 * @version 1.0.0
 */

class FlexiWANApiClient {
    
    /**
     * Base URL for the FlexiWAN flexiManage API
     * @var string
     */
    private $base_url;
    
    /**
     * Bearer token (Access Key) for API authentication
     * @var string
     */
    private $bearer_token;
    
    /**
     * Organization token for device registration
     * @var string
     */
    private $organization_token;
    
    /**
     * Organization ID obtained after device registration
     * @var string
     */
    private $organization_id;
    
    /**
     * Device ID assigned by FlexiWAN upon registration
     * @var string
     */
    private $device_id;
    
    /**
     * Logger instance for debugging and error tracking
     * @var object
     */
    private $logger;
    
    /**
     * HTTP timeout in seconds
     * @var int
     */
    private $http_timeout = 30;
    
    /**
     * Constructor
     * 
     * @param string $base_url The FlexiWAN flexiManage API base URL (default: https://manage.flexiwan.com)
     * @param string $bearer_token Optional API access key for authentication
     */
    public function __construct($base_url = 'https://manage.flexiwan.com', $bearer_token = null) {
        $this->base_url = rtrim($base_url, '/');
        $this->bearer_token = $bearer_token;
        $this->logger = new FlexiWANLogger();
    }
    
    /**
     * Set the bearer token (API Access Key)
     * 
     * @param string $token The bearer token
     * @return void
     */
    public function setBearerToken($token) {
        $this->bearer_token = $token;
    }
    
    /**
     * Set the organization token for device registration
     * 
     * @param string $token The organization token
     * @return void
     */
    public function setOrganizationToken($token) {
        $this->organization_token = $token;
    }
    
    /**
     * Set the organization ID
     * 
     * @param string $org_id The organization ID
     * @return void
     */
    public function setOrganizationId($org_id) {
        $this->organization_id = $org_id;
    }
    
    /**
     * Set the device ID
     * 
     * @param string $device_id The device ID
     * @return void
     */
    public function setDeviceId($device_id) {
        $this->device_id = $device_id;
    }
    
    /**
     * Register a new device with FlexiWAN using the organization token
     * 
     * The device registration flow:
     * 1. Device sends organization token to flexiManage
     * 2. flexiManage creates a pending device entry
     * 3. Admin approves the device in flexiManage UI
     * 4. Device transitions to "Approved" state
     * 
     * @param string $device_name The name for the device
     * @param string $device_description Optional description
     * @return array Registration response containing device_id and organization_id
     * @throws FlexiWANException
     */
    public function registerDevice($device_name, $device_description = '') {
        if (empty($this->organization_token)) {
            throw new FlexiWANException('Organization token is required for device registration');
        }
        
        $payload = [
            'name' => $device_name,
            'description' => $device_description,
            'type' => 'pfsense',
            'token' => $this->organization_token
        ];
        
        $response = $this->post('/api/devices/register', $payload, false);
        
        if (isset($response['device_id'])) {
            $this->device_id = $response['device_id'];
            $this->organization_id = $response['organization_id'] ?? null;
            $this->logger->info("Device registered successfully: {$this->device_id}");
        }
        
        return $response;
    }
    
    /**
     * Get device status from FlexiWAN
     * 
     * @return array Device status information
     * @throws FlexiWANException
     */
    public function getDeviceStatus() {
        if (empty($this->device_id)) {
            throw new FlexiWANException('Device ID is required');
        }
        
        return $this->get("/api/devices/{$this->device_id}/status");
    }
    
    /**
     * Get list of all devices in the organization
     * 
     * @return array List of devices
     * @throws FlexiWANException
     */
    public function getDevices() {
        if (empty($this->organization_id)) {
            throw new FlexiWANException('Organization ID is required');
        }
        
        return $this->get('/api/devices', ['organization_id' => $this->organization_id]);
    }
    
    /**
     * Get device configuration
     * 
     * @return array Device configuration
     * @throws FlexiWANException
     */
    public function getDeviceConfig() {
        if (empty($this->device_id)) {
            throw new FlexiWANException('Device ID is required');
        }
        
        return $this->get("/api/devices/{$this->device_id}/config");
    }
    
    /**
     * Apply configuration to device
     * 
     * This endpoint is used to apply configuration changes to the device.
     * It accepts various methods like "tunnels", "deltunnels", "start", "stop", etc.
     * 
     * @param array $config Configuration payload
     * @return array Response containing job information
     * @throws FlexiWANException
     */
    public function applyDeviceConfig($config) {
        if (empty($this->device_id)) {
            throw new FlexiWANException('Device ID is required');
        }
        
        return $this->post("/api/devices/{$this->device_id}/apply", $config);
    }
    
    /**
     * Create tunnels between devices
     * 
     * @param array $device_ids Array of device IDs to create tunnels between
     * @param array $options Optional configuration (topology, pathLabels, etc.)
     * @return array Response containing tunnel information
     * @throws FlexiWANException
     */
    public function createTunnels($device_ids, $options = []) {
        $payload = [
            'method' => 'tunnels',
            'devices' => array_fill_keys($device_ids, true),
            'meta' => array_merge([
                'pathLabels' => [],
                'tunnelType' => 'site-to-site',
                'topology' => 'fullMesh',
                'advancedOptions' => []
            ], $options)
        ];
        
        return $this->applyDeviceConfig($payload);
    }
    
    /**
     * Delete tunnels
     * 
     * @param array $tunnel_ids Array of tunnel IDs to delete
     * @return array Response
     * @throws FlexiWANException
     */
    public function deleteTunnels($tunnel_ids) {
        $payload = [
            'method' => 'deltunnels',
            'tunnels' => array_fill_keys($tunnel_ids, true)
        ];
        
        return $this->applyDeviceConfig($payload);
    }
    
    /**
     * Start the vRouter on the device
     * 
     * @return array Response
     * @throws FlexiWANException
     */
    public function startDevice() {
        $payload = ['method' => 'start'];
        return $this->applyDeviceConfig($payload);
    }
    
    /**
     * Stop the vRouter on the device
     * 
     * @return array Response
     * @throws FlexiWANException
     */
    public function stopDevice() {
        $payload = ['method' => 'stop'];
        return $this->applyDeviceConfig($payload);
    }
    
    /**
     * Report device health metrics to FlexiWAN
     * 
     * @param array $metrics Health metrics (cpu, memory, interfaces, tunnels, etc.)
     * @return array Response
     * @throws FlexiWANException
     */
    public function reportHealth($metrics) {
        if (empty($this->device_id)) {
            throw new FlexiWANException('Device ID is required');
        }
        
        $payload = [
            'timestamp' => time(),
            'device_id' => $this->device_id,
            'metrics' => $metrics
        ];
        
        return $this->post("/api/devices/{$this->device_id}/health", $payload);
    }
    
    /**
     * Get list of organizations
     * 
     * @return array List of organizations
     * @throws FlexiWANException
     */
    public function getOrganizations() {
        return $this->get('/api/organizations');
    }
    
    /**
     * Get list of tunnels in the organization
     * 
     * @return array List of tunnels
     * @throws FlexiWANException
     */
    public function getTunnels() {
        if (empty($this->organization_id)) {
            throw new FlexiWANException('Organization ID is required');
        }
        
        return $this->get('/api/tunnels', ['organization_id' => $this->organization_id]);
    }
    
    /**
     * Perform a GET request to the API
     * 
     * @param string $endpoint API endpoint path
     * @param array $params Optional query parameters
     * @return array Decoded JSON response
     * @throws FlexiWANException
     */
    private function get($endpoint, $params = []) {
        $url = $this->base_url . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        return $this->request('GET', $url);
    }
    
    /**
     * Perform a POST request to the API
     * 
     * @param string $endpoint API endpoint path
     * @param array $data Data to send in request body
     * @param bool $use_bearer Whether to use bearer token (default: true)
     * @return array Decoded JSON response
     * @throws FlexiWANException
     */
    private function post($endpoint, $data = [], $use_bearer = true) {
        $url = $this->base_url . $endpoint;
        
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                ],
                'content' => json_encode($data),
                'timeout' => $this->http_timeout
            ]
        ];
        
        if ($use_bearer && !empty($this->bearer_token)) {
            $options['http']['header'][] = 'Authorization: Bearer ' . $this->bearer_token;
        }
        
        return $this->executeRequest($url, $options);
    }
    
    /**
     * Perform an HTTP request using cURL
     * 
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $url Full URL
     * @param array $data Optional data for POST/PUT requests
     * @return array Decoded JSON response
     * @throws FlexiWANException
     */
    private function request($method, $url, $data = []) {
        $ch = curl_init();
        
        $headers = [
            'Content-Type: application/json',
        ];
        
        if (!empty($this->bearer_token)) {
            $headers[] = 'Authorization: Bearer ' . $this->bearer_token;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->http_timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        
        if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        
        curl_close($ch);
        
        if ($curl_error) {
            $this->logger->error("cURL error: {$curl_error}");
            throw new FlexiWANException("HTTP request failed: {$curl_error}");
        }
        
        $decoded = json_decode($response, true);
        
        if ($http_code >= 400) {
            $error_msg = $decoded['error'] ?? $response ?? "HTTP {$http_code}";
            $this->logger->error("API error ({$http_code}): {$error_msg}");
            throw new FlexiWANException("API error ({$http_code}): {$error_msg}", $http_code);
        }
        
        return $decoded ?? [];
    }
    
    /**
     * Execute an HTTP request using stream context
     * 
     * @param string $url Full URL
     * @param array $options Stream context options
     * @return array Decoded JSON response
     * @throws FlexiWANException
     */
    private function executeRequest($url, $options) {
        $context = stream_context_create($options);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            $this->logger->error("Stream error: " . $error['message']);
            throw new FlexiWANException("HTTP request failed: " . $error['message']);
        }
        
        return json_decode($response, true) ?? [];
    }
}

/**
 * FlexiWAN Exception
 */
if (!class_exists("FlexiWANException")) {
class FlexiWANException extends Exception {
}
}  // end class_exists FlexiWANException

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
