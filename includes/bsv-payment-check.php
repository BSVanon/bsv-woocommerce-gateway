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
    $required_confirmations = max(1, intval($bwwc_settings['confs_num']));
    $btc_addresses_table_name = esc_sql($wpdb->prefix . 'bwwc_btc_addresses');
    $address_cache_group = 'bwwc_btc_addresses';
    
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
    
    $confirmed_btc = floatval($balance_info['balance']);
    $confirmed_sats = isset($balance_info['confirmed_sats']) ? intval($balance_info['confirmed_sats']) : intval(round($confirmed_btc * 100000000));
    $unconfirmed_sats = isset($balance_info['unconfirmed_sats']) ? intval($balance_info['unconfirmed_sats']) : 0;
    $total_sats = isset($balance_info['total_sats']) ? intval($balance_info['total_sats']) : ($confirmed_sats + $unconfirmed_sats);
    $total_btc = $total_sats / 100000000;
    
    BWWC__log_event(__FILE__, __LINE__, "Payment check: Order {$order_id} - Confirmed: {$confirmed_sats} sats, Total (incl. mempool): {$total_sats} sats, Expected: {$expected_sats} sats");
    
    // Update received amount and last checked time
    update_post_meta($order_id, 'received_sats', $total_sats);
    update_post_meta($order_id, 'confirmed_sats', $confirmed_sats);
    update_post_meta($order_id, 'last_checked_at', time());
    
    // Fetch transaction history if payment detected
    $best_confirmations = intval(get_post_meta($order_id, 'best_confirmations', true));
    if ($total_sats > 0) {
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
                $chain_height = BWWC__get_current_chain_height($bwwc_settings['blockchain_api_timeout_secs']);
                
                foreach ($tx_history as $tx) {
                    if (isset($tx['tx_hash'])) {
                        $txids[] = $tx['tx_hash'];
                    }
                    if (isset($tx['height']) && $tx['height'] > 0) {
                        $confirmations = 1;
                        if ($chain_height && $tx['height'] > 0) {
                            $confirmations = max(1, ($chain_height - intval($tx['height'])) + 1);
                        }
                        $max_confirmations = max($max_confirmations, $confirmations);
                    }
                }
                
                if (!empty($txids)) {
                    update_post_meta($order_id, 'txids', implode(',', $txids));
                    $best_confirmations = $max_confirmations;
                    update_post_meta($order_id, 'best_confirmations', $best_confirmations);
                    BWWC__log_event(__FILE__, __LINE__, "Payment check: Stored " . count($txids) . " transaction ID(s) for order {$order_id}");
                }
            }
        }
    }
    
    // Determine payment state using consistent logic
    $order_total_btc = floatval(get_post_meta($order_id, 'order_total_in_btc', true));
    
    // Extend expiration if funds detected
    if ($total_sats > 0) {
        $expires_at = intval(get_post_meta($order_id, 'address_expires_at', true));
        $assigned_address_expires_in_secs = intval($bwwc_settings['assigned_address_expires_in_mins']) * 60;
        $pending_extension_secs = max($assigned_address_expires_in_secs, intval($bwwc_settings['confs_num']) * 10 * 60);
        $proposed_expiration = time() + $pending_extension_secs;
        if ($proposed_expiration > $expires_at) {
            update_post_meta($order_id, 'address_expires_at', $proposed_expiration);
        }
    }
    
    // Consistent state machine logic
    if ($total_sats == 0) {
        update_post_meta($order_id, 'payment_state', 'waiting');
        BWWC__log_event(__FILE__, __LINE__, "Payment check: Order {$order_id} state = waiting (no payment detected)");
        return false;
    }

    if ($total_sats < $expected_sats) {
        update_post_meta($order_id, 'payment_state', 'underpaid');
        BWWC__log_event(__FILE__, __LINE__, "Payment check: Order {$order_id} state = underpaid (received {$total_sats} of {$expected_sats} sats)");
        return false;
    }

    // Full amount received - check if confirmed
    if ($confirmed_sats >= $expected_sats && $best_confirmations >= $required_confirmations) {
        update_post_meta($order_id, 'payment_state', 'confirmed');
        BWWC__log_event(__FILE__, __LINE__, "Payment check: Order {$order_id} state = confirmed (confirmed {$confirmed_sats} sats, {$best_confirmations} confirmations)");
    } else {
        update_post_meta($order_id, 'payment_state', 'pending');
        BWWC__log_event(__FILE__, __LINE__, "Payment check: Order {$order_id} state = pending (total {$total_sats} sats, confirmed {$confirmed_sats} sats, {$best_confirmations}/{$required_confirmations} confirmations)");
        return true;
    }
    
    // Payment detected - check if order needs to be completed
    $order = wc_get_order($order_id);
    if ($order && $order->get_status() === 'on-hold') {
        // Get address record from database
        $address_cache_key = 'bsv_addr_' . md5($bsv_address);
        $address_record = wp_cache_get($address_cache_key, $address_cache_group);

        if (false === $address_record) {
            $select_sql = $wpdb->prepare(
                "SELECT * FROM `{$btc_addresses_table_name}` WHERE `btc_address` = %s",
                $bsv_address
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $address_record = $wpdb->get_row($select_sql, ARRAY_A);
            if ($address_record) {
                wp_cache_set($address_cache_key, $address_record, $address_cache_group, MINUTE_IN_SECONDS * 5);
            }
        }
        
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
            $update_sql = $wpdb->prepare(
                "UPDATE `{$btc_addresses_table_name}` SET `status` = 'used', `total_received_funds` = %s, `received_funds_checked_at` = %d, `address_meta` = %s WHERE `btc_address` = %s",
                $confirmed_btc,
                time(),
                $address_meta_serialized,
                $bsv_address
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query($update_sql);
            if (!empty($address_cache_key)) {
                wp_cache_delete($address_cache_key, $address_cache_group);
            }
            
            // Process payment completion
            BWWC__process_payment_completed_for_order($order_id, $confirmed_btc);
            
            BWWC__log_event(__FILE__, __LINE__, "Payment check: Order {$order_id} payment confirmed and processed");
            return true;
        }
    }
    
    return $total_sats > 0;
}
