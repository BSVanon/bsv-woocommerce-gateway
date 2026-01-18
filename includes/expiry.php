<?php
/**
 * Expiry Management - Order expiry enforcement and late payment monitoring
 * 
 * Handles payment window expiration and late payment detection.
 * 
 * @package BSV_WooCommerce_Gateway
 * @since 6.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check and enforce expiry for all pending BSV orders
 * 
 * Called by scheduled task to find and expire unpaid orders.
 */
function BWWC__enforce_order_expiry()
{
    global $wpdb;
    
    $current_time = time();
    
    // Find orders that are:
    // - Using BSV gateway
    // - Status: pending or on-hold
    // - Payment state: waiting or detected (not verified)
    // - Past expiry time
    
    $query = $wpdb->prepare(
        "SELECT p.ID as order_id, pm1.meta_value as expires_at, pm2.meta_value as payment_state
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_bwwc_address_expires_at'
        LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_bwwc_payment_state'
        INNER JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_payment_method' AND pm3.meta_value = 'bitcoin_sv'
        WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('wc-pending', 'wc-on-hold')
        AND CAST(pm1.meta_value AS UNSIGNED) < %d
        AND (pm2.meta_value IS NULL OR pm2.meta_value IN (%s, %s))",
        $current_time,
        BWWC_PAYMENT_STATE_WAITING,
        BWWC_PAYMENT_STATE_DETECTED
    );
    
    $expired_orders = $wpdb->get_results($query);
    
    foreach ($expired_orders as $row) {
        $order_id = (int) $row->order_id;
        
        // Double-check no payment received
        $result = BWWC__check_order_payment($order_id, true);
        
        if ($result['amount_received'] === 0) {
            // No payment - mark as expired
            BWWC__set_payment_state($order_id, BWWC_PAYMENT_STATE_EXPIRED, 'Payment window expired');
            
            BWWC__log_event(__FILE__, __LINE__, 'Order #' . $order_id . ' expired (no payment received)', 'info');
        }
    }
}

/**
 * Monitor for late payments (payments after expiry/cancellation)
 * 
 * Called by scheduled task to check recently expired/cancelled orders
 * for late payments.
 */
function BWWC__monitor_late_payments()
{
    global $wpdb;
    
    $settings = BWWC__get_settings();
    $late_watch_days = isset($settings['late_payment_watch_days']) ? (int) $settings['late_payment_watch_days'] : 7;
    
    $current_time = time();
    $watch_cutoff = $current_time - ($late_watch_days * 86400); // Convert days to seconds
    
    // Find orders that:
    // - Using BSV gateway
    // - Payment state: expired
    // - Expired within watch window
    // - Status: cancelled or failed
    
    $query = $wpdb->prepare(
        "SELECT p.ID as order_id, pm1.meta_value as expires_at
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_bwwc_address_expires_at'
        INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_bwwc_payment_state' AND pm2.meta_value = %s
        INNER JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_payment_method' AND pm3.meta_value = 'bitcoin_sv'
        WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('wc-cancelled', 'wc-failed')
        AND CAST(pm1.meta_value AS UNSIGNED) > %d",
        BWWC_PAYMENT_STATE_EXPIRED,
        $watch_cutoff
    );
    
    $watch_orders = $wpdb->get_results($query);
    
    foreach ($watch_orders as $row) {
        $order_id = (int) $row->order_id;
        
        // Check for payment
        $result = BWWC__check_order_payment($order_id, true);
        
        if ($result['amount_received'] > 0) {
            // Late payment detected!
            $new_state = $result['confirmations'] > 0 
                ? BWWC_PAYMENT_STATE_VERIFIED 
                : BWWC_PAYMENT_STATE_DETECTED;
            
            BWWC__set_payment_state($order_id, $new_state, 'Late payment received');
            
            BWWC__log_event(__FILE__, __LINE__, 'Late payment detected for order #' . $order_id, 'warning');
        }
    }
}

/**
 * Get expiry timestamp for an order
 * 
 * @param int $order_id Order ID
 * @return int|false Expiry timestamp, or false if not set
 */
function BWWC__get_order_expiry($order_id)
{
    $order = wc_get_order($order_id);
    if (!$order) {
        return false;
    }
    
    $expires_at = $order->get_meta('_bwwc_address_expires_at', true);
    return $expires_at ? (int) $expires_at : false;
}

/**
 * Check if an order has expired
 * 
 * @param int $order_id Order ID
 * @return bool True if expired
 */
function BWWC__is_order_expired($order_id)
{
    $expires_at = BWWC__get_order_expiry($order_id);
    if (!$expires_at) {
        return false;
    }
    
    return time() > $expires_at;
}

/**
 * Get time remaining until expiry
 * 
 * @param int $order_id Order ID
 * @return int Seconds remaining (negative if expired)
 */
function BWWC__get_time_until_expiry($order_id)
{
    $expires_at = BWWC__get_order_expiry($order_id);
    if (!$expires_at) {
        return 0;
    }
    
    return $expires_at - time();
}

/**
 * Format time remaining for display
 * 
 * @param int $seconds Seconds remaining
 * @return string Formatted time string
 */
function BWWC__format_time_remaining($seconds)
{
    if ($seconds <= 0) {
        return __('Expired', 'bitcoin-payments-for-woocommerce');
    }
    
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    
    if ($hours > 0) {
        return sprintf(__('%d hours %d minutes', 'bitcoin-payments-for-woocommerce'), $hours, $minutes);
    } elseif ($minutes > 0) {
        return sprintf(__('%d minutes %d seconds', 'bitcoin-payments-for-woocommerce'), $minutes, $secs);
    } else {
        return sprintf(__('%d seconds', 'bitcoin-payments-for-woocommerce'), $secs);
    }
}
