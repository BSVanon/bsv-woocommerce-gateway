<?php
/**
 * HPOS-compatible order meta helpers
 * 
 * Provides unified meta access for both legacy postmeta and HPOS custom order tables.
 * 
 * @package BSV_WooCommerce_Gateway
 * @since 6.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get order meta value (HPOS-compatible)
 * 
 * @param int|WC_Order $order Order ID or object
 * @param string $key Meta key
 * @param bool $single Return single value (default true)
 * @return mixed Meta value or false if not found
 */
function BWWC_get_order_meta($order, $key, $single = true)
{
    if (!$order instanceof WC_Order) {
        $order = wc_get_order($order);
    }
    
    if (!$order) {
        return false;
    }
    
    return $order->get_meta($key, $single);
}

/**
 * Update order meta value (HPOS-compatible)
 * 
 * @param int|WC_Order $order Order ID or object
 * @param string $key Meta key
 * @param mixed $value Meta value
 * @return bool Success
 */
function BWWC_update_order_meta($order, $key, $value)
{
    if (!$order instanceof WC_Order) {
        $order = wc_get_order($order);
    }
    
    if (!$order) {
        return false;
    }
    
    $order->update_meta_data($key, $value);
    $order->save();
    
    return true;
}

/**
 * Delete order meta value (HPOS-compatible)
 * 
 * @param int|WC_Order $order Order ID or object
 * @param string $key Meta key
 * @return bool Success
 */
function BWWC_delete_order_meta($order, $key)
{
    if (!$order instanceof WC_Order) {
        $order = wc_get_order($order);
    }
    
    if (!$order) {
        return false;
    }
    
    $order->delete_meta_data($key);
    $order->save();
    
    return true;
}
