<?php
/**
 * FlexiWAN Health Monitoring and Telemetry Module
 * 
 * Collects system health metrics and reports them to FlexiWAN backend.
 * Monitors CPU, memory, interface statistics, tunnel status, and performance metrics.
 * 
 * @package FlexiWAN
 * @author Manus AI
 * @version 1.0.0
 */

class FlexiWANHealthMonitor {
    
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
     * Collect and report health metrics to FlexiWAN backend
     * 
     * @return array Report result
     */
    public function reportHealth() {
        try {
            $device_id = FlexiWANConfigManager::getDeviceId();
            if (empty($device_id)) {
                return [
                    'success' => false,
                    'message' => 'Device not registered'
                ];
            }
            
            $this->api_client->setDeviceId($device_id);
            
            // Collect all health metrics
            $metrics = [
                'system' => $this->collectSystemMetrics(),
                'interfaces' => $this->collectInterfaceMetrics(),
                'tunnels' => $this->collectTunnelMetrics(),
                'performance' => $this->collectPerformanceMetrics()
            ];
            
            // Report metrics to backend
            $response = $this->api_client->reportHealth($metrics);
            
            $this->logger->info("Health metrics reported successfully");
            
            return [
                'success' => true,
                'message' => 'Health metrics reported',
                'metrics_count' => count($metrics)
            ];
            
        } catch (FlexiWANException $e) {
            $this->logger->error("Health report error: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Health report failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Collect system health metrics
     * 
     * @return array System metrics
     */
    private function collectSystemMetrics() {
        $metrics = [
            'timestamp' => time(),
            'uptime' => $this->getSystemUptime(),
            'cpu_usage' => $this->getCPUUsage(),
            'memory_usage' => $this->getMemoryUsage(),
            'disk_usage' => $this->getDiskUsage(),
            'load_average' => $this->getLoadAverage()
        ];
        
        return $metrics;
    }
    
    /**
     * Get system uptime in seconds
     * 
     * @return int Uptime in seconds
     */
    private function getSystemUptime() {
        if (file_exists('/proc/uptime')) {
            $uptime_str = file_get_contents('/proc/uptime');
            $uptime_parts = explode(' ', $uptime_str);
            return (int)$uptime_parts[0];
        }
        
        return 0;
    }
    
    /**
     * Get CPU usage percentage
     * 
     * @return float CPU usage percentage (0-100)
     */
    private function getCPUUsage() {
        $load = sys_getloadavg();
        $cpu_count = shell_exec('nproc') ?: 1;
        
        // Calculate CPU usage as percentage
        $cpu_usage = ($load[0] / $cpu_count) * 100;
        
        return min(100, max(0, $cpu_usage));
    }
    
    /**
     * Get memory usage information
     * 
     * @return array Memory usage metrics
     */
    private function getMemoryUsage() {
        $memory = [
            'total' => 0,
            'used' => 0,
            'free' => 0,
            'percent' => 0
        ];
        
        if (file_exists('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            $lines = explode("\n", $meminfo);
            
            $mem_total = 0;
            $mem_free = 0;
            $mem_buffers = 0;
            $mem_cached = 0;
            
            foreach ($lines as $line) {
                if (strpos($line, 'MemTotal:') === 0) {
                    $mem_total = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                } elseif (strpos($line, 'MemFree:') === 0) {
                    $mem_free = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                } elseif (strpos($line, 'Buffers:') === 0) {
                    $mem_buffers = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                } elseif (strpos($line, 'Cached:') === 0) {
                    $mem_cached = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                }
            }
            
            $memory['total'] = $mem_total * 1024; // Convert to bytes
            $memory['free'] = ($mem_free + $mem_buffers + $mem_cached) * 1024;
            $memory['used'] = $memory['total'] - $memory['free'];
            $memory['percent'] = $memory['total'] > 0 ? 
                ($memory['used'] / $memory['total']) * 100 : 0;
        }
        
        return $memory;
    }
    
    /**
     * Get disk usage information
     * 
     * @return array Disk usage metrics
     */
    private function getDiskUsage() {
        $disk = [
            'total' => 0,
            'used' => 0,
            'free' => 0,
            'percent' => 0
        ];
        
        $disk_total = disk_total_space('/');
        $disk_free = disk_free_space('/');
        
        if ($disk_total && $disk_free !== false) {
            $disk['total'] = $disk_total;
            $disk['free'] = $disk_free;
            $disk['used'] = $disk_total - $disk_free;
            $disk['percent'] = ($disk['used'] / $disk_total) * 100;
        }
        
        return $disk;
    }
    
    /**
     * Get system load average
     * 
     * @return array Load average (1min, 5min, 15min)
     */
    private function getLoadAverage() {
        $load = sys_getloadavg();
        
        return [
            'one_minute' => $load[0],
            'five_minutes' => $load[1],
            'fifteen_minutes' => $load[2]
        ];
    }
    
    /**
     * Collect interface metrics
     * 
     * @return array Interface metrics
     */
    private function collectInterfaceMetrics() {
        $interfaces = [];
        
        // Get pfSense interfaces
        if (function_exists('get_interface_list')) {
            $if_list = get_interface_list();
            
            foreach ($if_list as $if_name => $if_info) {
                $interfaces[$if_name] = [
                    'name' => $if_name,
                    'status' => $this->getInterfaceStatus($if_name),
                    'mtu' => $if_info['mtu'] ?? 1500,
                    'mac' => $if_info['mac'] ?? '',
                    'ipaddr' => $this->getInterfaceIP($if_name),
                    'statistics' => $this->getInterfaceStatistics($if_name)
                ];
            }
        }
        
        return $interfaces;
    }
    
    /**
     * Get interface operational status
     * 
     * @param string $interface Interface name
     * @return string Interface status (up, down, unknown)
     */
    private function getInterfaceStatus($interface) {
        $status_file = "/sys/class/net/{$interface}/operstate";
        
        if (file_exists($status_file)) {
            $status = trim(file_get_contents($status_file));
            return $status;
        }
        
        return 'unknown';
    }
    
    /**
     * Get interface IP address
     * 
     * @param string $interface Interface name
     * @return string IP address
     */
    private function getInterfaceIP($interface) {
        $output = shell_exec("ip addr show {$interface} 2>/dev/null | grep 'inet ' | awk '{print $2}'");
        return trim($output) ?: '';
    }
    
    /**
     * Get interface statistics
     * 
     * @param string $interface Interface name
     * @return array Interface statistics
     */
    private function getInterfaceStatistics($interface) {
        $stats = [
            'rx_bytes' => 0,
            'rx_packets' => 0,
            'rx_errors' => 0,
            'tx_bytes' => 0,
            'tx_packets' => 0,
            'tx_errors' => 0
        ];
        
        $stats_file = "/sys/class/net/{$interface}/statistics";
        
        if (is_dir($stats_file)) {
            foreach ($stats as $key => &$value) {
                $file = "{$stats_file}/{$key}";
                if (file_exists($file)) {
                    $value = (int)trim(file_get_contents($file));
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * Collect tunnel metrics
     * 
     * @return array Tunnel metrics
     */
    private function collectTunnelMetrics() {
        $tunnels = [];
        
        // Get synchronized tunnels from configuration
        $sync_tunnels = FlexiWANConfigManager::getSynchronizedTunnels();
        
        foreach ($sync_tunnels as $tunnel) {
            $tunnel_id = $tunnel['id'] ?? '';
            
            $tunnels[$tunnel_id] = [
                'id' => $tunnel_id,
                'name' => $tunnel['name'] ?? '',
                'status' => $this->getTunnelStatus($tunnel_id),
                'latency' => $this->getTunnelLatency($tunnel_id),
                'packet_loss' => $this->getTunnelPacketLoss($tunnel_id),
                'throughput' => $this->getTunnelThroughput($tunnel_id)
            ];
        }
        
        return $tunnels;
    }
    
    /**
     * Get tunnel operational status
     * 
     * @param string $tunnel_id Tunnel ID
     * @return string Tunnel status (up, down, unknown)
     */
    private function getTunnelStatus($tunnel_id) {
        // This would check the actual tunnel status
        // For now, return a placeholder
        return 'unknown';
    }
    
    /**
     * Get tunnel latency in milliseconds
     * 
     * @param string $tunnel_id Tunnel ID
     * @return float Latency in milliseconds
     */
    private function getTunnelLatency($tunnel_id) {
        // This would measure actual tunnel latency using ping or similar
        // For now, return 0
        return 0;
    }
    
    /**
     * Get tunnel packet loss percentage
     * 
     * @param string $tunnel_id Tunnel ID
     * @return float Packet loss percentage
     */
    private function getTunnelPacketLoss($tunnel_id) {
        // This would measure actual packet loss
        // For now, return 0
        return 0;
    }
    
    /**
     * Get tunnel throughput in bytes per second
     * 
     * @param string $tunnel_id Tunnel ID
     * @return float Throughput in bytes per second
     */
    private function getTunnelThroughput($tunnel_id) {
        // This would measure actual throughput
        // For now, return 0
        return 0;
    }
    
    /**
     * Collect performance metrics
     * 
     * @return array Performance metrics
     */
    private function collectPerformanceMetrics() {
        return [
            'timestamp' => time(),
            'response_time' => $this->measureResponseTime(),
            'packet_processing_rate' => $this->getPacketProcessingRate(),
            'connection_count' => $this->getConnectionCount()
        ];
    }
    
    /**
     * Measure API response time
     * 
     * @return float Response time in milliseconds
     */
    private function measureResponseTime() {
        $start = microtime(true);
        
        try {
            // Try a simple API call to measure response time
            $this->api_client->getDeviceStatus();
        } catch (Exception $e) {
            // Ignore errors
        }
        
        $end = microtime(true);
        return ($end - $start) * 1000; // Convert to milliseconds
    }
    
    /**
     * Get packet processing rate
     * 
     * @return int Packets per second
     */
    private function getPacketProcessingRate() {
        // This would measure actual packet processing rate
        // For now, return 0
        return 0;
    }
    
    /**
     * Get current connection count
     * 
     * @return int Connection count
     */
    private function getConnectionCount() {
        $output = shell_exec('netstat -an 2>/dev/null | grep ESTABLISHED | wc -l');
        return (int)trim($output);
    }
    
    /**
     * Get current health status summary
     * 
     * @return array Health status summary
     */
    public function getHealthStatus() {
        return [
            'system' => $this->collectSystemMetrics(),
            'interfaces' => $this->collectInterfaceMetrics(),
            'tunnels' => $this->collectTunnelMetrics(),
            'performance' => $this->collectPerformanceMetrics()
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
    
    private function log($level, $message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] [{$level}] {$message}\n";
        
        @error_log($log_message, 3, $this->log_file);
    }
}
?>
