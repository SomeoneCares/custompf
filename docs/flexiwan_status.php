<?php
require_once('guiconfig.inc');
require_once('/usr/local/pkg/flexiwan.inc');

$pgtitle = array('Services', 'FlexiWAN', 'Status');
include('head.inc');

$info    = flexiwan_get_device_info();
$health  = flexiwan_get_health_status();
$tunnels = flexiwan_get_tunnels();
$sys     = $health['system'];
?>
<div class="panel panel-default">
  <div class="panel-heading"><h2 class="panel-title">FlexiWAN SD-WAN Status</h2></div>
  <div class="panel-body">
    <ul class="nav nav-tabs">
      <li><a href="/pkg_edit.php?xml=flexiwan.xml">Settings</a></li>
      <li><a href="/pkg_edit.php?xml=flexiwan_device.xml">Device Registration</a></li>
      <li class="active"><a href="#">Status</a></li>
    </ul><br/>
    <table class="table table-condensed" style="width:auto">
      <tr><td><strong>Registration Status</strong></td>
          <td><?php echo flexiwan_get_status_badge($info['registration_status']); ?></td></tr>
      <tr><td><strong>Device ID</strong></td>
          <td><code><?php echo htmlspecialchars($info['device_id'] ?: 'Not registered'); ?></code></td></tr>
      <tr><td><strong>Device Name</strong></td>
          <td><?php echo htmlspecialchars($info['device_name'] ?? ''); ?></td></tr>
      <tr><td><strong>Backend URL</strong></td>
          <td><?php echo htmlspecialchars($info['backend_url']); ?></td></tr>
      <tr><td><strong>Tunnels Synced</strong></td>
          <td><?php echo count($tunnels); ?></td></tr>
      <tr><td><strong>CPU Usage</strong></td>
          <td><?php echo round($sys['cpu_usage'] ?? 0, 1); ?>%</td></tr>
      <tr><td><strong>Uptime</strong></td>
          <td><?php echo flexiwan_format_uptime($sys['uptime'] ?? 0); ?></td></tr>
    </table>
  </div>
</div>
<?php include('foot.inc'); ?>
