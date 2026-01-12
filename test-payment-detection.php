<?php
/**
 * Payment Detection Test Script
 * Tests the entire payment detection flow for address: 1GTViF65Y3ketPX5SMpimeFbt8xDqJKmYt
 */

// Load WordPress
define('BWWC_MUST_LOAD_WP', '1');
require_once(dirname(__FILE__) . '/bwwc-include-all.php');

echo "=== BSV Payment Detection Test ===\n\n";

// Test address with known payment
$test_address = '1GTViF65Y3ketPX5SMpimeFbt8xDqJKmYt';
$expected_sats = 50226;

echo "Testing address: {$test_address}\n";
echo "Expected balance: {$expected_sats} sats\n\n";

// Test 1: Settings check
echo "--- Test 1: Plugin Settings ---\n";
$bwwc_settings = BWWC__get_settings();
echo "Service Provider: " . $bwwc_settings['service_provider'] . "\n";
echo "Cron Enabled: " . ($bwwc_settings['enable_soft_cron_job'] ? 'YES' : 'NO') . "\n";
echo "Confirmations Required: " . $bwwc_settings['confs_num'] . "\n";
echo "API Timeout: " . $bwwc_settings['blockchain_api_timeout_secs'] . " seconds\n";
echo "Funds Check Interval: " . $bwwc_settings['funds_received_value_expires_in_mins'] . " minutes\n\n";

// Test 2: Direct API call to WhatsOnChain
echo "--- Test 2: WhatsOnChain API Direct Call ---\n";
$api_url = "https://api.whatsonchain.com/v1/bsv/main/address/{$test_address}/balance";
echo "URL: {$api_url}\n";

$response = BWWC__file_get_contents($api_url, false, 10);
if ($response) {
    echo "Raw Response: {$response}\n";
    $json = json_decode($response, true);
    if ($json && isset($json['confirmed'])) {
        echo "✅ API Call Successful\n";
        echo "Confirmed: {$json['confirmed']} sats\n";
        echo "Unconfirmed: {$json['unconfirmed']} sats\n";
    } else {
        echo "❌ Failed to parse JSON response\n";
    }
} else {
    echo "❌ API call failed - no response\n";
}
echo "\n";

// Test 3: Using plugin's balance check function
echo "--- Test 3: Plugin Balance Check Function ---\n";
$address_request_array = array(
    'btc_address' => $test_address,
    'required_confirmations' => 1,
    'api_timeout' => 10
);

$balance_info = BWWC__getreceivedbyaddress_info($address_request_array, $bwwc_settings);
echo "Result: " . $balance_info['result'] . "\n";
if ($balance_info['result'] == 'success') {
    echo "✅ Balance Check Successful\n";
    echo "Balance (BTC): " . $balance_info['balance'] . "\n";
    $balance_sats = intval(round(floatval($balance_info['balance']) * 100000000));
    echo "Balance (sats): {$balance_sats}\n";
    
    if ($balance_sats == $expected_sats) {
        echo "✅ Balance matches expected value!\n";
    } else {
        echo "⚠️  Balance mismatch! Expected: {$expected_sats}, Got: {$balance_sats}\n";
    }
} else {
    echo "❌ Balance Check Failed\n";
    echo "Message: " . $balance_info['message'] . "\n";
}
echo "\n";

// Test 4: Check if cron would process this
echo "--- Test 4: Cron Job Logic Check ---\n";
if ($bwwc_settings['service_provider'] != 'electrum_wallet') {
    echo "❌ CRITICAL: Cron will NOT run - service_provider is '{$bwwc_settings['service_provider']}'\n";
    echo "   Cron only runs when service_provider = 'electrum_wallet'\n";
} else {
    echo "✅ Cron will run - service_provider is correct\n";
}

if (!$bwwc_settings['enable_soft_cron_job']) {
    echo "❌ WARNING: Soft cron is disabled\n";
} else {
    echo "✅ Soft cron is enabled\n";
}

$next_cron = wp_next_scheduled('BWWC_cron_action');
if ($next_cron) {
    echo "✅ Cron job is scheduled\n";
    echo "   Next run: " . date('Y-m-d H:i:s', $next_cron) . "\n";
} else {
    echo "❌ WARNING: No cron job scheduled!\n";
}
echo "\n";

// Test 5: Check database for this address
echo "--- Test 5: Database Check ---\n";
global $wpdb;
$btc_addresses_table = $wpdb->prefix . 'bwwc_btc_addresses';
$address_record = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM `{$btc_addresses_table}` WHERE `btc_address` = %s",
    $test_address
), ARRAY_A);

if ($address_record) {
    echo "✅ Address found in database\n";
    echo "Status: " . $address_record['status'] . "\n";
    echo "Total Received: " . $address_record['total_received_funds'] . " BTC\n";
    echo "Last Checked: " . ($address_record['received_funds_checked_at'] ? date('Y-m-d H:i:s', $address_record['received_funds_checked_at']) : 'Never') . "\n";
    echo "Assigned At: " . ($address_record['assigned_at'] ? date('Y-m-d H:i:s', $address_record['assigned_at']) : 'N/A') . "\n";
    
    // Check address meta
    $address_meta = BWWC_unserialize_address_meta($address_record['address_meta']);
    if ($address_meta && isset($address_meta['orders']) && is_array($address_meta['orders'])) {
        echo "Orders associated: " . count($address_meta['orders']) . "\n";
        foreach ($address_meta['orders'] as $idx => $order_info) {
            echo "  Order #{$idx}: ID={$order_info['order_id']}, Total={$order_info['order_total']} BTC, Paid=" . (isset($order_info['paid']) && $order_info['paid'] ? 'YES' : 'NO') . "\n";
        }
    }
} else {
    echo "❌ Address NOT found in database\n";
    echo "   This means no order was created with this address\n";
}
echo "\n";

// Test 6: Bitails API fallback
echo "--- Test 6: Bitails API Fallback Test ---\n";
$bitails_url = "https://api.bitails.io/address/{$test_address}/balance";
echo "URL: {$bitails_url}\n";

$bitails_response = BWWC__file_get_contents($bitails_url, false, 10);
if ($bitails_response) {
    echo "Raw Response: {$bitails_response}\n";
    $bitails_json = json_decode($bitails_response, true);
    if ($bitails_json && isset($bitails_json['confirmed'])) {
        echo "✅ Bitails API Call Successful\n";
        echo "Confirmed: {$bitails_json['confirmed']} sats\n";
    } else {
        echo "⚠️  Bitails response format different\n";
    }
} else {
    echo "❌ Bitails API call failed\n";
}
echo "\n";

// Test 7: Explorer URL check
echo "--- Test 7: Explorer URL Generation ---\n";
$explorer_base = 'https://whatsonchain.com/';
$address_url = $explorer_base . $test_address;
$correct_url = $explorer_base . 'address/' . $test_address;

echo "Current URL format: {$address_url}\n";
echo "Correct URL format: {$correct_url}\n";
if (strpos($address_url, '/address/') === false) {
    echo "❌ CRITICAL BUG: Explorer URL is missing '/address/' path!\n";
    echo "   This creates broken links in the UI\n";
} else {
    echo "✅ Explorer URL format is correct\n";
}
echo "\n";

// Summary
echo "=== SUMMARY ===\n";
echo "Payment Detection: " . ($balance_info['result'] == 'success' && $balance_sats == $expected_sats ? '✅ WORKING' : '❌ BROKEN') . "\n";
echo "API Connectivity: " . ($response ? '✅ WORKING' : '❌ BROKEN') . "\n";
echo "Cron Configuration: " . ($bwwc_settings['service_provider'] == 'electrum_wallet' && $bwwc_settings['enable_soft_cron_job'] ? '✅ CORRECT' : '❌ MISCONFIGURED') . "\n";
echo "Explorer URLs: " . (strpos($explorer_base . $test_address, '/address/') === false ? '❌ BROKEN' : '✅ WORKING') . "\n";
echo "\n";

if ($address_record && $balance_info['result'] == 'success' && $balance_sats > 0) {
    echo "💡 RECOMMENDATION: Payment is detected by API but may not be processed by cron.\n";
    echo "   Check if cron is actually running and processing this address.\n";
}
