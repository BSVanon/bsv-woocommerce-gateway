<?php
/**
 * Test cron query to see what addresses would be selected
 */

// Load WordPress
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
    die("ERROR: Could not load WordPress.\n");
}

global $wpdb;

$bwwc_settings = get_option('BWWC-Settings');
$btc_addresses_table_name = $wpdb->prefix . 'bwwc_btc_addresses';

$current_time = time();
$funds_received_value_expires_in_secs = $bwwc_settings['funds_received_value_expires_in_mins'] * 60;
$assigned_address_expires_in_secs = $bwwc_settings['assigned_address_expires_in_mins'] * 60;

echo "=== CRON QUERY TEST ===\n\n";
echo "Current time: " . date('Y-m-d H:i:s', $current_time) . " ($current_time)\n";
echo "Funds check expires after: $funds_received_value_expires_in_secs seconds (" . ($funds_received_value_expires_in_secs/60) . " mins)\n";
echo "Address expires after: $assigned_address_expires_in_secs seconds (" . ($assigned_address_expires_in_secs/60) . " mins)\n\n";

// Get ALL assigned addresses first
$all_assigned = $wpdb->get_results("SELECT * FROM `$btc_addresses_table_name` WHERE status='assigned' ORDER BY assigned_at DESC", ARRAY_A);

echo "Total assigned addresses: " . count($all_assigned) . "\n\n";

if ($all_assigned) {
    foreach ($all_assigned as $addr) {
        $time_since_check = $current_time - $addr['received_funds_checked_at'];
        $time_since_assigned = $current_time - $addr['assigned_at'];
        
        echo "Address: " . $addr['btc_address'] . "\n";
        echo "  Status: " . $addr['status'] . "\n";
        echo "  Assigned at: " . date('Y-m-d H:i:s', $addr['assigned_at']) . " ({$addr['assigned_at']})\n";
        echo "  Last checked: " . date('Y-m-d H:i:s', $addr['received_funds_checked_at']) . " ({$addr['received_funds_checked_at']})\n";
        echo "  Time since assigned: $time_since_assigned seconds (" . round($time_since_assigned/60, 1) . " mins)\n";
        echo "  Time since last check: $time_since_check seconds (" . round($time_since_check/60, 1) . " mins)\n";
        
        // Check conditions
        $is_assigned_unexpired = ($addr['status'] == 'assigned' && $time_since_assigned < $assigned_address_expires_in_secs);
        $needs_check = ($time_since_check > $funds_received_value_expires_in_secs);
        
        echo "  Is assigned & unexpired: " . ($is_assigned_unexpired ? 'YES' : 'NO') . "\n";
        echo "  Needs check (time > {$funds_received_value_expires_in_secs}s): " . ($needs_check ? 'YES' : 'NO') . "\n";
        echo "  WOULD BE SELECTED: " . ($is_assigned_unexpired && $needs_check ? 'YES' : 'NO') . "\n\n";
    }
}

// Now run the actual cron query
$query = $wpdb->prepare(
    "SELECT * FROM `$btc_addresses_table_name`
        WHERE
        (
          (`status`='assigned' AND ((%d - `assigned_at`) < %d))
          OR
          (`status`='revalidate')
        )
        AND ((%d - `received_funds_checked_at`) > %d)
        ORDER BY `received_funds_checked_at` ASC",
    $current_time,
    $assigned_address_expires_in_secs,
    $current_time,
    $funds_received_value_expires_in_secs
);

echo "=== ACTUAL CRON QUERY ===\n";
echo "Query: " . $query . "\n\n";

$rows_for_balance_check = $wpdb->get_results($query, ARRAY_A);

echo "Addresses selected by cron query: " . count($rows_for_balance_check) . "\n\n";

if ($rows_for_balance_check) {
    foreach ($rows_for_balance_check as $row) {
        echo "  - " . $row['btc_address'] . "\n";
    }
} else {
    echo "  NONE! This is why payment detection isn't working!\n";
}

echo "\n";
