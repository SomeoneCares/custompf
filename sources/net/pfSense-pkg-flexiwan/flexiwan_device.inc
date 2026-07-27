<?php
/**
 * FlexiWAN Device Registration Include File
 * 
 * Contains handlers for the device registration form
 * 
 * @package FlexiWAN
 * @author Manus AI
 * @version 1.0.0
 */

define('FLEXIWAN_PKG_PATH', '/usr/local/pkg/flexiwan');
define('FLEXIWAN_SRC_PATH', '/usr/local/pkg/flexiwan/src');

/**
 * Handle form before display
 */
function flexiwan_device_before_form() {
    global $config;
    
    require_once(FLEXIWAN_SRC_PATH . '/FlexiWANConfigManager.php');
    require_once(FLEXIWAN_SRC_PATH . '/FlexiWANDeviceRegistration.php');
    
    // Get current device info
    $device_info = FlexiWANConfigManager::getConfig();
    
    // Populate form fields
    $_POST['device_name'] = $device_info['device_name'] ?? gethostname();
    $_POST['device_description'] = $device_info['device_description'] ?? '';
    $_POST['registration_status'] = $device_info['registration_status'] ?? 'unregistered';
    $_POST['device_id'] = $device_info['device_id'] ?? '';
    $_POST['organization_token'] = $device_info['organization_token'] ?? '';
}

/**
 * Handle form after submission
 */
function flexiwan_device_after_form() {
    global $config;
    
    require_once(FLEXIWAN_SRC_PATH . '/FlexiWANConfigManager.php');
    require_once(FLEXIWAN_SRC_PATH . '/FlexiWANDeviceRegistration.php');
    
    $registration = new FlexiWANDeviceRegistration();
    $result = null;
    $alert_type = 'danger';
    
    // Handle Register Device button
    if ($_POST['Submit'] == 'Register Device') {
        $org_token = $_POST['organization_token'] ?? '';
        $device_name = $_POST['device_name'] ?? gethostname();
        $device_description = $_POST['device_description'] ?? '';
        
        if (empty($org_token)) {
            $result = array(
                'success' => false,
                'message' => 'Organization token is required'
            );
        } else {
            $result = $registration->registerDevice($org_token, $device_name, $device_description);
            
            if ($result['success']) {
                $alert_type = 'success';
                // Refresh the page to show updated status
                header('Location: pkg_edit.php?xml=flexiwan_device.xml');
                exit;
            }
        }
    }
    
    // Handle Unregister Device button
    elseif ($_POST['Submit'] == 'Unregister Device') {
        $result = $registration->unregisterDevice();
        
        if ($result['success']) {
            $alert_type = 'success';
            // Refresh the page to show updated status
            header('Location: pkg_edit.php?xml=flexiwan_device.xml');
            exit;
        }
    }
    
    // Handle Check Status button
    elseif ($_POST['Submit'] == 'Check Status') {
        $status = $registration->checkRegistrationStatus();
        
        if ($status['registered']) {
            $result = array(
                'success' => true,
                'message' => 'Device status: ' . $status['status']
            );
            $alert_type = 'info';
        } else {
            $result = array(
                'success' => false,
                'message' => 'Device not registered: ' . $status['message']
            );
        }
    }
    
    // Display result message
    if ($result) {
        echo '<div class="alert alert-' . $alert_type . ' alert-dismissible fade show" role="alert">';
        echo '<strong>' . ($result['success'] ? 'Success' : 'Error') . ':</strong> ' . htmlspecialchars($result['message']);
        echo '<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
        echo '<span aria-hidden="true">&times;</span>';
        echo '</button>';
        echo '</div>';
    }
    
    // Display current device info
    $device_info = $registration->getDeviceInfo();
    
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h2 class="panel-title">Current Device Information</h2></div>';
    echo '<div class="panel-body">';
    echo '<table class="table table-striped">';
    echo '<tr><td><strong>Registration Status:</strong></td><td>' . 
         flexiwan_get_status_badge($device_info['registration_status']) . '</td></tr>';
    echo '<tr><td><strong>Device ID:</strong></td><td>' . 
         (empty($device_info['device_id']) ? 'Not registered' : htmlspecialchars($device_info['device_id'])) . '</td></tr>';
    echo '<tr><td><strong>Device Name:</strong></td><td>' . 
         htmlspecialchars($device_info['device_name']) . '</td></tr>';
    echo '<tr><td><strong>Organization ID:</strong></td><td>' . 
         (empty($device_info['organization_id']) ? 'N/A' : htmlspecialchars($device_info['organization_id'])) . '</td></tr>';
    echo '<tr><td><strong>Backend URL:</strong></td><td>' . 
         htmlspecialchars($device_info['backend_url']) . '</td></tr>';
    echo '<tr><td><strong>Enabled:</strong></td><td>' . 
         ($device_info['enabled'] ? 'Yes' : 'No') . '</td></tr>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
    
    // Display instructions
    echo '<div class="panel panel-info">';
    echo '<div class="panel-heading"><h2 class="panel-title">Registration Instructions</h2></div>';
    echo '<div class="panel-body">';
    echo '<ol>';
    echo '<li>Log in to <a href="https://manage.flexiwan.com" target="_blank">FlexiWAN Management Console</a></li>';
    echo '<li>Navigate to <strong>Inventory > Tokens</strong></li>';
    echo '<li>Click <strong>"New Token"</strong> to create a new organization token</li>';
    echo '<li>Copy the generated token</li>';
    echo '<li>Paste the token in the "Organization Token" field above</li>';
    echo '<li>Enter a name for this device (optional)</li>';
    echo '<li>Click <strong>"Register Device"</strong></li>';
    echo '<li>Wait for the device to appear in FlexiWAN and approve it</li>';
    echo '<li>Once approved, the device will begin syncing configurations</li>';
    echo '</ol>';
    echo '</div>';
    echo '</div>';
}

/**
 * Get status badge HTML
 */
function flexiwan_get_status_badge($status) {
    $badges = array(
        'approved' => '<span class="badge badge-success">Approved</span>',
        'pending' => '<span class="badge badge-warning">Pending</span>',
        'unregistered' => '<span class="badge badge-secondary">Unregistered</span>',
        'error' => '<span class="badge badge-danger">Error</span>'
    );
    
    return $badges[$status] ?? '<span class="badge badge-secondary">' . htmlspecialchars($status) . '</span>';
}

?>
