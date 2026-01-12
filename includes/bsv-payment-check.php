<?php
/**
 * Payment Check Functions
 * Handles immediate payment verification for specific orders
 */

if (!defined('ABSPATH')) exit;

/**
 * Check payment status for a specific order
 * Called by "I've Paid" button and can be used for manual checks
 * 
 * @param int $order_id WooCommerce order ID
 * @return bool True if payment detected, false otherwise
 */
function BWWC__check_payment_for_order($order_id) {
    global $wpdb;
    
    if (!$order_id) {
        return false;
    }
    
    $bwwc_settings = BWWC__get_settings();
    $btc_addresses_table_name = $wpdb->prefix . 'bwwc_btc_addresses';
    
    // Get the Bitcoin address for this order
    $bsv_address = get_post_meta($order_id, 'bitcoins_address', true);
    if (!$bsv_address) {
        BWWC__log_event(__FILE__, __LINE__, "Payment check: No BSV address found for order {$order_id}");
        return false;
    }
    
    // Get expected amount
    $expected_sats = get_post_meta($order_id, 'expected_sats', true);
    if (!$expected_sats) {
        // Try to calculate from order total
        $order = wc_get_order($order_id);
        if ($order) {
            $order_total_btc = floatval(get_post_meta($order_id, 'order_total_in_btc', true));
            if ($order_total_btc > 0) {
                $expected_sats = intval(round($order_total_btc * 100000000));
                update_post_meta($order_id, 'expected_sats', $expected_sats);
            }
        }
    }
    
    BWWC__log_event(__FILE__, __LINE__, "Payment check: Checking address {$bsv_address} for order {$order_id}");
    
    // Check balance using the standard function
    $address_request_array = array(
        'btc_address' => $bsv_address,
        'required_confirmations' => intval($bwwc_settings['confs_num']),
        'api_timeout' => intval($bwwc_settings['blockchain_api_timeout_secs'])
    );
    
    $balance_info = BWWC__getreceivedbyaddress_info($address_request_array, $bwwc_settings);
    
    if ($balance_info['result'] !== 'success') {
        BWWC__log_event(__FILE__, __LINE__, "Payment check: API error for order {$order_id}: " . $balance_info['message']);
        update_post_meta($order_id, 'last_checked_at', time());
        return false;
    }
    
    $received_btc = floatval($balance_info['balance']);
    $received_sats = intval(round($received_btc * 100000000));
    
    BWWC__log_event(__FILE__, __LINE__, "Payment check: Order {$order_id} - Received: {$received_sats} sats, Expected: {$expected_sats} sats");
    
    // Update received amount and last checked time
    update_post_meta($order_id, 'received_sats', $received_sats);
    update_post_meta($order_id, 'last_checked_at', time());
    
    // Fetch transaction history if payment detected
    if ($received_sats > 0) {
        $tx_history_response = BWWC__file_get_contents(
            'https://api.whatsonchain.com/v1/bsv/main/address/' . $bsv_address . '/history',
            false,
            $bwwc_settings['blockchain_api_timeout_secs']
        );
        
        if ($tx_history_response) {
            $tx_history = json_decode(trim($tx_history_response), true);
            if (is_array($tx_history) && count($tx_history) > 0) {
                $txids = array();
                $max_confirmations = 0;
                
                foreach ($tx_history as $tx) {
                    if (isset($tx['tx_hash'])) {
                        $txids[] = $tx['tx_hash'];
                    }
                    if (isset($tx['height']) && $tx['height'] > 0) {
                        // Estimate confirmations (rough - would need current block height for accuracy)
                        $confirmations = 1; // At least 1 if it has a height
                        $max_confirmations = max($max_confirmations, $confirmations);
                    }
                }
                
                if (!empty($txids)) {
                    update_post_meta($order_id, 'txids', implode(',', $txids));
                    update_post_meta($order_id, 'best_confirmations', $max_confirmations);
                    BWWC__log_event(__FILE__, __LINE__, "Payment check: Stored " . count($txids) . " transaction ID(s) for order {$order_id}");
                }
            }
        }
    }
    
    // Determine payment state
    $order_total_btc = floatval(get_post_meta($order_id, 'order_total_in_btc', true));
    
    if ($received_btc == 0) {
        update_post_meta($order_id, 'payment_state', 'waiting');
        return false;
    } elseif ($received_btc < $order_total_btc) {
        update_post_meta($order_id, 'payment_state', 'underpaid');
        BWWC__log_event(__FILE__, __LINE__, "Payment check: Order {$order_id} underpaid - Received: {$received_btc} BTC, Expected: {$order_total_btc} BTC");
        return false;
    } elseif ($received_btc > $order_total_btc) {
        update_post_meta($order_id, 'payment_state', 'overpaid');
        BWWC__log_event(__FILE__, __LINE__, "Payment check: Order {$order_id} overpaid - Received: {$received_btc} BTC, Expected: {$order_total_btc} BTC");
        // Still process as paid
    } else {
        update_post_meta($order_id, 'payment_state', 'detected');
    }
    
    // Payment detected - check if order needs to be completed
    $order = wc_get_order($order_id);
    if ($order && $order->get_status() === 'on-hold') {
        // Get address record from database
        $address_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$btc_addresses_table_name}` WHERE `btc_address` = %s",
            $bsv_address
        ), ARRAY_A);
        
        if ($address_record && $address_record['status'] !== 'used') {
            // Update payment state to confirmed
            update_post_meta($order_id, 'payment_state', 'confirmed');
            
            // Update address metadata
            $address_meta = BWWC_unserialize_address_meta($address_record['address_meta']);
            if (isset($address_meta['orders'][0])) {
                $address_meta['orders'][0]['paid'] = true;
            }
            $address_meta_serialized = BWWC_serialize_address_meta($address_meta);
            
            // Mark address as used and update balance
            $wpdb->query($wpdb->prepare(
                "UPDATE `{$btc_addresses_table_name}` SET `status` = 'used', `total_received_funds` = %s, `received_funds_checked_at` = %d, `address_meta` = %s WHERE `btc_address` = %s",
                $received_btc,
                time(),
                $address_meta_serialized,
                $bsv_address
            ));
            
            // Process payment completion
            BWWC__process_payment_completed_for_order($order_id, $received_btc);
            
            BWWC__log_event(__FILE__, __LINE__, "Payment check: Order {$order_id} payment confirmed and processed");
            return true;
        }
    }
    
    return $received_sats > 0;
}
