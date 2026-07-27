<?php
/**
 * FlexiWAN Configuration Synchronization Engine
 * 
 * Handles bidirectional synchronization between pfSense configurations
 * and FlexiWAN backend policies. Maps FlexiWAN tunnels and policies to
 * pfSense native constructs (IPsec, WireGuard, Gateway Groups, PBR rules).
 * 
 * @package FlexiWAN
 * @author Manus AI
 * @version 1.0.0
 */

class FlexiWANSyncEngine {
    
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
        
        $this->config_manager = FlexiWANConfigManager::class;
        $this->logger = new FlexiWANLogger();
    }
    
    /**
     * Synchronize configuration from FlexiWAN backend to pfSense
     * 
     * This method:
     * 1. Fetches tunnels and policies from FlexiWAN backend
     * 2. Maps them to pfSense configurations
     * 3. Applies changes to pfSense
     * 4. Updates local sync timestamp
     * 
     * @return array Sync result with status and details
     */
    public function syncFromFlexiWAN() {
        try {
            $this->logger->info("Starting sync from FlexiWAN backend");
            
            // Set device ID for API client
            $device_id = FlexiWANConfigManager::getDeviceId();
            if (empty($device_id)) {
                return [
                    'success' => false,
                    'message' => 'Device not registered'
                ];
            }
            
            $this->api_client->setDeviceId($device_id);
            $this->api_client->setOrganizationId(FlexiWANConfigManager::getOrganizationId());
            
            // Fetch tunnels from backend
            $tunnels = $this->api_client->getTunnels();
            $this->logger->info("Fetched " . count($tunnels) . " tunnels from backend");
            
            // Map FlexiWAN tunnels to pfSense VPN configurations
            $vpn_config = $this->mapTunnelsToPfSense($tunnels);
            
            // Apply VPN configurations to pfSense
            $this->applyVPNConfiguration($vpn_config);
            
            // Store synchronized tunnels locally
            FlexiWANConfigManager::setSynchronizedTunnels($tunnels);
            
            // Update sync timestamp
            FlexiWANConfigManager::setLastSyncTime(time());
            
            $this->logger->info("Sync from FlexiWAN completed successfully");
            
            return [
                'success' => true,
                'message' => 'Configuration synchronized from FlexiWAN',
                'tunnels_count' => count($tunnels)
            ];
            
        } catch (FlexiWANException $e) {
            $this->logger->error("Sync error: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage()
            ];
        } catch (Exception $e) {
            $this->logger->error("Unexpected error during sync: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Unexpected error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Map FlexiWAN tunnel definitions to pfSense VPN configurations
     * 
     * @param array $tunnels FlexiWAN tunnel definitions
     * @return array pfSense VPN configuration
     */
    private function mapTunnelsToPfSense($tunnels) {
        $vpn_config = [];
        
        foreach ($tunnels as $tunnel) {
            // Determine tunnel type (IPsec, WireGuard, etc.)
            $tunnel_type = $tunnel['type'] ?? 'ipsec';
            
            switch ($tunnel_type) {
                case 'ipsec':
                    $vpn_config['ipsec'][] = $this->mapIPsecTunnel($tunnel);
                    break;
                    
                case 'wireguard':
                    $vpn_config['wireguard'][] = $this->mapWireGuardTunnel($tunnel);
                    break;
                    
                default:
                    $this->logger->warn("Unknown tunnel type: {$tunnel_type}");
            }
        }
        
        return $vpn_config;
    }
    
    /**
     * Map a FlexiWAN IPsec tunnel to pfSense IPsec configuration
     * 
     * @param array $tunnel FlexiWAN tunnel definition
     * @return array pfSense IPsec configuration
     */
    private function mapIPsecTunnel($tunnel) {
        return [
            'tunnel_id' => $tunnel['id'],
            'name' => $tunnel['name'] ?? 'FlexiWAN-' . substr($tunnel['id'], 0, 8),
            'description' => $tunnel['description'] ?? 'Synced from FlexiWAN',
            'remote_gateway' => $tunnel['remote_gateway'] ?? '',
            'local_subnet' => $tunnel['local_subnet'] ?? '0.0.0.0/0',
            'remote_subnet' => $tunnel['remote_subnet'] ?? '0.0.0.0/0',
            'encryption' => $tunnel['encryption'] ?? 'aes-128-cbc',
            'hash' => $tunnel['hash'] ?? 'sha1',
            'dh_group' => $tunnel['dh_group'] ?? 'modp1024',
            'lifetime' => $tunnel['lifetime'] ?? 3600,
            'flexiwan_managed' => true
        ];
    }
    
    /**
     * Map a FlexiWAN WireGuard tunnel to pfSense WireGuard configuration
     * 
     * @param array $tunnel FlexiWAN tunnel definition
     * @return array pfSense WireGuard configuration
     */
    private function mapWireGuardTunnel($tunnel) {
        return [
            'tunnel_id' => $tunnel['id'],
            'name' => $tunnel['name'] ?? 'FlexiWAN-WG-' . substr($tunnel['id'], 0, 8),
            'description' => $tunnel['description'] ?? 'Synced from FlexiWAN',
            'interface' => $tunnel['interface'] ?? 'wg0',
            'private_key' => $tunnel['private_key'] ?? '',
            'public_key' => $tunnel['public_key'] ?? '',
            'endpoint' => $tunnel['endpoint'] ?? '',
            'allowed_ips' => $tunnel['allowed_ips'] ?? '0.0.0.0/0',
            'persistent_keepalive' => $tunnel['persistent_keepalive'] ?? 25,
            'flexiwan_managed' => true
        ];
    }
    
    /**
     * Apply VPN configuration to pfSense
     * 
     * @param array $vpn_config VPN configuration to apply
     * @return bool Success
     */
    private function applyVPNConfiguration($vpn_config) {
        global $config;
        
        try {
            // Initialize VPN sections if they don't exist
            if (!isset($config['ipsec'])) {
                $config['ipsec'] = [];
            }
            
            // Apply IPsec configurations
            if (isset($vpn_config['ipsec'])) {
                foreach ($vpn_config['ipsec'] as $ipsec_tunnel) {
                    $this->applyIPsecTunnel($ipsec_tunnel);
                }
            }
            
            // Apply WireGuard configurations
            if (isset($vpn_config['wireguard'])) {
                foreach ($vpn_config['wireguard'] as $wg_tunnel) {
                    $this->applyWireGuardTunnel($wg_tunnel);
                }
            }
            
            // Reload firewall rules to apply any PBR changes
            if (function_exists('filter_configure')) {
                filter_configure();
            }
            
            // Reload routing configuration
            if (function_exists('system_routing_configure')) {
                system_routing_configure();
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->error("Failed to apply VPN configuration: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Apply IPsec tunnel configuration to pfSense
     * 
     * @param array $tunnel IPsec tunnel configuration
     * @return bool Success
     */
    private function applyIPsecTunnel($tunnel) {
        global $config;
        
        try {
            // Create or update IPsec phase 1 entry
            $phase1 = [
                'iketype' => 'ikev2',
                'protocol' => 'inet',
                'interface' => 'wan',
                'remote-gateway' => $tunnel['remote_gateway'],
                'authentication_method' => 'pre_shared_key',
                'encryption' => [
                    'name' => $tunnel['encryption']
                ],
                'hash' => [
                    'name' => $tunnel['hash']
                ],
                'dhgroup' => [
                    'name' => $tunnel['dh_group']
                ],
                'lifetime' => $tunnel['lifetime'],
                'disabled' => false
            ];
            
            // Create or update IPsec phase 2 entry
            $phase2 = [
                'protocol' => 'esp',
                'encryption' => [
                    'name' => $tunnel['encryption']
                ],
                'hash' => [
                    'name' => $tunnel['hash']
                ],
                'lifetime' => $tunnel['lifetime'],
                'pfsgroup' => $tunnel['dh_group'],
                'localid' => [
                    'type' => 'network',
                    'address' => $tunnel['local_subnet']
                ],
                'remoteid' => [
                    'type' => 'network',
                    'address' => $tunnel['remote_subnet']
                ],
                'disabled' => false
            ];
            
            // Store tunnel metadata
            if (!isset($config['ipsec']['flexiwan_tunnels'])) {
                $config['ipsec']['flexiwan_tunnels'] = [];
            }
            
            $config['ipsec']['flexiwan_tunnels'][$tunnel['tunnel_id']] = [
                'phase1' => $phase1,
                'phase2' => $phase2,
                'description' => $tunnel['description']
            ];
            
            $this->logger->info("IPsec tunnel applied: {$tunnel['name']}");
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->error("Failed to apply IPsec tunnel: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Apply WireGuard tunnel configuration to pfSense
     * 
     * @param array $tunnel WireGuard tunnel configuration
     * @return bool Success
     */
    private function applyWireGuardTunnel($tunnel) {
        global $config;
        
        try {
            if (!isset($config['wireguard'])) {
                $config['wireguard'] = [];
            }
            
            if (!isset($config['wireguard']['flexiwan_tunnels'])) {
                $config['wireguard']['flexiwan_tunnels'] = [];
            }
            
            $config['wireguard']['flexiwan_tunnels'][$tunnel['tunnel_id']] = [
                'interface' => $tunnel['interface'],
                'private_key' => $tunnel['private_key'],
                'public_key' => $tunnel['public_key'],
                'endpoint' => $tunnel['endpoint'],
                'allowed_ips' => $tunnel['allowed_ips'],
                'persistent_keepalive' => $tunnel['persistent_keepalive'],
                'description' => $tunnel['description']
            ];
            
            $this->logger->info("WireGuard tunnel applied: {$tunnel['name']}");
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->error("Failed to apply WireGuard tunnel: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get last sync status
     * 
     * @return array Sync status information
     */
    public function getLastSyncStatus() {
        $last_sync = FlexiWANConfigManager::getLastSyncTime();
        
        return [
            'last_sync_time' => $last_sync,
            'last_sync_ago' => $last_sync ? time() - $last_sync : null,
            'formatted_time' => $last_sync ? date('Y-m-d H:i:s', $last_sync) : 'Never'
        ];
    }
}

/**
 * FlexiWAN Logger
 */
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
    
    private function log($level, $message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] [{$level}] {$message}\n";
        
        @error_log($log_message, 3, $this->log_file);
    }
}
?>
