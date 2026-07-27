<?php
/**
 * FlexiWAN Status Dashboard
 * 
 * Displays real-time status information about the FlexiWAN integration,
 * device registration, and health metrics.
 * 
 * @package FlexiWAN
 * @author Manus AI
 * @version 1.0.0
 */

require_once('guiconfig.inc');
require_once('/usr/local/pkg/flexiwan.inc');

$pgtitle = array('Services', 'FlexiWAN', 'Status');
include('head.inc');

?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1>FlexiWAN SD-WAN Integration Status</h1>
        </div>
    </div>

    <?php if (!flexiwan_is_enabled()): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <strong>Warning:</strong> FlexiWAN integration is not enabled. 
        <a href="pkg_edit.php?xml=flexiwan.xml">Enable it in settings</a>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <?php endif; ?>

    <!-- Device Registration Status -->
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">Device Registration</h5>
                </div>
                <div class="card-body">
                    <?php
                    $device_info = flexiwan_get_device_info();
                    $status = flexiwan_get_device_status();
                    ?>
                    <table class="table table-sm">
                        <tr>
                            <td><strong>Status:</strong></td>
                            <td><?php echo flexiwan_get_status_badge($device_info['registration_status']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Device ID:</strong></td>
                            <td><code><?php echo empty($device_info['device_id']) ? 'Not registered' : htmlspecialchars($device_info['device_id']); ?></code></td>
                        </tr>
                        <tr>
                            <td><strong>Device Name:</strong></td>
                            <td><?php echo htmlspecialchars($device_info['device_name']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Organization:</strong></td>
                            <td><?php echo empty($device_info['organization_id']) ? 'N/A' : htmlspecialchars($device_info['organization_id']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Backend:</strong></td>
                            <td><a href="<?php echo htmlspecialchars($device_info['backend_url']); ?>" target="_blank">
                                <?php echo htmlspecialchars($device_info['backend_url']); ?>
                            </a></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Synchronization Status -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="card-title mb-0">Synchronization</h5>
                </div>
                <div class="card-body">
                    <?php
                    $sync_status = flexiwan_get_last_sync_status();
                    ?>
                    <table class="table table-sm">
                        <tr>
                            <td><strong>Last Sync:</strong></td>
                            <td><?php echo $sync_status['formatted_time']; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Time Ago:</strong></td>
                            <td><?php echo empty($sync_status['last_sync_ago']) ? 'Never' : flexiwan_format_uptime($sync_status['last_sync_ago']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Tunnels Synced:</strong></td>
                            <td><?php echo count(flexiwan_get_tunnels()); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- System Health Metrics -->
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0">System Health Metrics</h5>
                </div>
                <div class="card-body">
                    <?php
                    $health = flexiwan_get_health_status();
                    $system = $health['system'];
                    $memory = $system['memory_usage'];
                    $disk = $system['disk_usage'];
                    $cpu = $system['cpu_usage'];
                    ?>
                    <div class="row">
                        <div class="col-md-4">
                            <h6>CPU Usage</h6>
                            <div class="progress">
                                <div class="progress-bar <?php echo $cpu > 80 ? 'bg-danger' : ($cpu > 50 ? 'bg-warning' : 'bg-success'); ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo $cpu; ?>%"
                                     aria-valuenow="<?php echo $cpu; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                    <?php echo round($cpu, 1); ?>%
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <h6>Memory Usage</h6>
                            <div class="progress">
                                <div class="progress-bar <?php echo $memory['percent'] > 80 ? 'bg-danger' : ($memory['percent'] > 50 ? 'bg-warning' : 'bg-success'); ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo $memory['percent']; ?>%"
                                     aria-valuenow="<?php echo $memory['percent']; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                    <?php echo round($memory['percent'], 1); ?>%
                                </div>
                            </div>
                            <small><?php echo flexiwan_format_bytes($memory['used']) . ' / ' . flexiwan_format_bytes($memory['total']); ?></small>
                        </div>
                        <div class="col-md-4">
                            <h6>Disk Usage</h6>
                            <div class="progress">
                                <div class="progress-bar <?php echo $disk['percent'] > 80 ? 'bg-danger' : ($disk['percent'] > 50 ? 'bg-warning' : 'bg-success'); ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo $disk['percent']; ?>%"
                                     aria-valuenow="<?php echo $disk['percent']; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                    <?php echo round($disk['percent'], 1); ?>%
                                </div>
                            </div>
                            <small><?php echo flexiwan_format_bytes($disk['used']) . ' / ' . flexiwan_format_bytes($disk['total']); ?></small>
                        </div>
                    </div>

                    <hr/>

                    <div class="row">
                        <div class="col-md-6">
                            <h6>System Information</h6>
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Uptime:</strong></td>
                                    <td><?php echo flexiwan_format_uptime($system['uptime']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Load Average (1m):</strong></td>
                                    <td><?php echo round($system['load_average']['one_minute'], 2); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Load Average (5m):</strong></td>
                                    <td><?php echo round($system['load_average']['five_minutes'], 2); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Load Average (15m):</strong></td>
                                    <td><?php echo round($system['load_average']['fifteen_minutes'], 2); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Network Interfaces -->
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h5 class="card-title mb-0">Network Interfaces</h5>
                </div>
                <div class="card-body">
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>Interface</th>
                                <th>Status</th>
                                <th>IP Address</th>
                                <th>RX Packets</th>
                                <th>TX Packets</th>
                                <th>RX Errors</th>
                                <th>TX Errors</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $interfaces = $health['interfaces'];
                            foreach ($interfaces as $if_name => $if_data):
                            ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($if_name); ?></code></td>
                                <td><?php echo flexiwan_get_status_badge($if_data['status']); ?></td>
                                <td><?php echo htmlspecialchars($if_data['ipaddr']); ?></td>
                                <td><?php echo number_format($if_data['statistics']['rx_packets']); ?></td>
                                <td><?php echo number_format($if_data['statistics']['tx_packets']); ?></td>
                                <td><?php echo $if_data['statistics']['rx_errors']; ?></td>
                                <td><?php echo $if_data['statistics']['tx_errors']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Tunnels -->
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h5 class="card-title mb-0">SD-WAN Tunnels</h5>
                </div>
                <div class="card-body">
                    <?php
                    $tunnels = flexiwan_get_tunnels();
                    if (empty($tunnels)):
                    ?>
                    <p class="text-muted">No tunnels configured</p>
                    <?php else: ?>
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>Tunnel ID</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Latency</th>
                                <th>Packet Loss</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tunnels as $tunnel): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($tunnel['id']); ?></code></td>
                                <td><?php echo htmlspecialchars($tunnel['name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($tunnel['type'] ?? 'unknown'); ?></td>
                                <td><?php echo flexiwan_get_status_badge($tunnel['status'] ?? 'unknown'); ?></td>
                                <td><?php echo isset($tunnel['latency']) ? round($tunnel['latency'], 2) . ' ms' : 'N/A'; ?></td>
                                <td><?php echo isset($tunnel['packet_loss']) ? round($tunnel['packet_loss'], 2) . '%' : 'N/A'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<?php include('foot.inc'); ?>
