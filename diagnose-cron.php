<?php
/**
 * Diagnostic script for BSV payment detection issues
 * Run this from WordPress root or include WordPress
 */

// Try to load WordPress
$wp_load_paths = [
    __DIR__ . '/docker/wp/wp-load.php',
    __DIR__ . '/docker/wp-modern/wp-load.php',
    __DIR__ . '/../../../wp-load.php',
];

foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        break;
    }
}

if (!defined('ABSPATH')) {
    die("ERROR: Could not load WordPress. Run this script from WordPress root or adjust paths.\n");
}

echo "=== BSV PAYMENT DETECTION DIAGNOSTIC ===\n\n";

// 1. Check plugin settings
echo "1. PLUGIN SETTINGS:\n";
$bwwc_settings = get_option('BWWC-Settings');
if (!$bwwc_settings) {
    echo "   ERROR: Plugin settings not found!\n\n";
    exit(1);
}

echo "   - Service Provider: " . ($bwwc_settings['service_provider'] ?? 'NOT SET') . "\n";
echo "   - Enable Soft Cron: " . ($bwwc_settings['enable_soft_cron_job'] ?? 'NOT SET') . "\n";
echo "   - Cron Schedule: " . ($bwwc_settings['soft_cron_job_schedule_name'] ?? 'NOT SET') . "\n";
echo "   - Confirmations Required: " . ($bwwc_settings['confs_num'] ?? 'NOT SET') . "\n";
echo "   - Autocomplete Orders: " . ($bwwc_settings['autocomplete_paid_orders'] ?? 'NOT SET') . "\n";
echo "   - Funds Check Expires (mins): " . ($bwwc_settings['funds_received_value_expires_in_mins'] ?? 'NOT SET') . "\n";
echo "\n";

// 2. Check WordPress cron status
echo "2. WORDPRESS CRON STATUS:\n";
$cron_array = _get_cron_array();
$bwwc_cron_found = false;
$next_run = null;

if ($cron_array) {
    foreach ($cron_array as $timestamp => $cron) {
        if (isset($cron['BWWC_cron_action'])) {
            $bwwc_cron_found = true;
            $next_run = $timestamp;
            echo "   - BWWC Cron Scheduled: YES\n";
            echo "   - Next Run: " . date('Y-m-d H:i:s', $timestamp) . " (" . ($timestamp - time()) . " seconds from now)\n";
            break;
        }
    }
}

if (!$bwwc_cron_found) {
    echo "   - BWWC Cron Scheduled: NO - THIS IS THE PROBLEM!\n";
    echo "   - Cron job is not scheduled in WordPress!\n";
}
echo "\n";

// 3. Check database for addresses
echo "3. DATABASE CHECK:\n";
global $wpdb;
$table_name = $wpdb->prefix . 'bwwc_btc_addresses';

$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
if (!$table_exists) {
    echo "   ERROR: Table $table_name does not exist!\n\n";
    exit(1);
}

echo "   - Table exists: YES\n";

// Check for assigned addresses
$assigned = $wpdb->get_results("SELECT * FROM `$table_name` WHERE status='assigned' ORDER BY assigned_at DESC LIMIT 5", ARRAY_A);
echo "   - Assigned addresses: " . count($assigned) . "\n";

if ($assigned) {
    foreach ($assigned as $addr) {
        echo "\n   Address: " . $addr['btc_address'] . "\n";
        echo "   - Status: " . $addr['status'] . "\n";
        echo "   - Assigned at: " . date('Y-m-d H:i:s', $addr['assigned_at']) . "\n";
        echo "   - Last checked: " . date('Y-m-d H:i:s', $addr['received_funds_checked_at']) . "\n";
        echo "   - Total received: " . $addr['total_received_funds'] . " BTC\n";
        
        // Check if this is the problem address
        if ($addr['btc_address'] == '12mXVVCR1zUrF29sgu9D2ftxqkrXovm1Zj') {
            echo "   - THIS IS THE PROBLEM ADDRESS!\n";
            
            // Check API
            $api_url = 'https://api.whatsonchain.com/v1/bsv/main/address/' . $addr['btc_address'] . '/balance';
            $response = file_get_contents($api_url);
            if ($response) {
                $data = json_decode($response, true);
                echo "   - API Balance: " . ($data['confirmed'] ?? 0) . " sats\n";
                echo "   - DB Balance: " . ($addr['total_received_funds'] * 100000000) . " sats\n";
                
                if ($data['confirmed'] > 0 && $addr['total_received_funds'] == 0) {
                    echo "   - MISMATCH: API shows payment but DB shows zero!\n";
                }
            }
            
            // Check order meta
            $address_meta = maybe_unserialize($addr['address_meta']);
            if ($address_meta && isset($address_meta['orders'][0])) {
                $order_id = $address_meta['orders'][0]['order_id'];
                echo "   - Order ID: " . $order_id . "\n";
                
                $payment_state = get_post_meta($order_id, 'payment_state', true);
                $received_sats = get_post_meta($order_id, 'received_sats', true);
                $expected_sats = get_post_meta($order_id, 'expected_sats', true);
                
                echo "   - Payment State: " . ($payment_state ?: 'NOT SET') . "\n";
                echo "   - Received Sats: " . ($received_sats ?: 0) . "\n";
                echo "   - Expected Sats: " . ($expected_sats ?: 0) . "\n";
            }
        }
    }
}
echo "\n";

// 4. Check if cron should run now
echo "4. CRON ELIGIBILITY CHECK:\n";
$current_time = time();
$funds_received_value_expires_in_secs = $bwwc_settings['funds_received_value_expires_in_mins'] * 60;
$assigned_address_expires_in_secs = $bwwc_settings['assigned_address_expires_in_mins'] * 60;

echo "   - Current time: " . date('Y-m-d H:i:s', $current_time) . "\n";
echo "   - Funds check expires after: " . $funds_received_value_expires_in_secs . " seconds\n";

if ($assigned) {
    foreach ($assigned as $addr) {
        $time_since_check = $current_time - $addr['received_funds_checked_at'];
        $time_since_assigned = $current_time - $addr['assigned_at'];
        
        $is_assigned_unexpired = ($addr['status'] == 'assigned' && $time_since_assigned < $assigned_address_expires_in_secs);
        $is_revalidate = ($addr['status'] == 'revalidate');
        $needs_check = ($time_since_check > $funds_received_value_expires_in_secs);
        
        echo "\n   Address: " . substr($addr['btc_address'], 0, 20) . "...\n";
        echo "   - Time since last check: " . $time_since_check . " seconds\n";
        echo "   - Needs check: " . ($needs_check ? 'YES' : 'NO') . "\n";
        echo "   - Would be selected by cron: " . (($is_assigned_unexpired || $is_revalidate) && $needs_check ? 'YES' : 'NO') . "\n";
    }
}

echo "\n";
echo "=== DIAGNOSIS COMPLETE ===\n";
echo "\nRECOMMENDATIONS:\n";

if (!$bwwc_cron_found) {
    echo "1. CRITICAL: WordPress cron is not scheduled for BWWC_cron_action\n";
    echo "   - Deactivate and reactivate the plugin to reschedule\n";
    echo "   - OR manually trigger: wp_schedule_event(time(), 'minutes_1', 'BWWC_cron_action');\n";
}

if ($bwwc_settings['service_provider'] != 'electrum_wallet') {
    echo "2. CRITICAL: Service provider is not set to 'electrum_wallet'\n";
    echo "   - Cron job will not run unless service_provider = 'electrum_wallet'\n";
}

if ($bwwc_settings['enable_soft_cron_job'] != '1') {
    echo "3. WARNING: Soft cron is not enabled in settings\n";
}

echo "\n";
