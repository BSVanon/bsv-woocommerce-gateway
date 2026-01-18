<?php
/**
 * Gateway Migration - Handle gateway ID change from 'bitcoin' to 'bitcoin_sv'
 * 
 * Migrates settings and order meta for existing installations.
 * 
 * @package BSV_WooCommerce_Gateway
 * @since 6.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Migrate gateway settings from old ID to new ID
 * 
 * Called on plugin activation/upgrade to migrate existing installations.
 */
function BWWC__migrate_gateway_id()
{
    $migration_done = get_option('bwwc_gateway_id_migration_done', false);
    
    if ($migration_done) {
        return; // Already migrated
    }
    
    // Migrate WooCommerce gateway settings
    $old_settings = get_option('woocommerce_bitcoin_settings');
    $new_settings = get_option('woocommerce_bitcoin_sv_settings');
    
    if ($old_settings && !$new_settings) {
        // Copy old settings to new ID
        update_option('woocommerce_bitcoin_sv_settings', $old_settings);
        BWWC__log_event(__FILE__, __LINE__, 'Migrated gateway settings from bitcoin to bitcoin_sv', 'info');
    }
    
    // Update payment method in existing orders (HPOS compatible)
    // Use WooCommerce CRUD to handle both legacy postmeta and HPOS tables
    $orders = wc_get_orders(array(
        'payment_method' => 'bitcoin',
        'limit' => -1,
        'return' => 'ids',
    ));
    
    $updated = 0;
    foreach ($orders as $order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            $order->set_payment_method('bitcoin_sv');
            $order->save();
            $updated++;
        }
    }
    
    if ($updated > 0) {
        BWWC__log_event(__FILE__, __LINE__, "Migrated payment method for {$updated} orders from bitcoin to bitcoin_sv", 'info');
    }
    
    // Mark migration as complete
    update_option('bwwc_gateway_id_migration_done', true);
}

// Run migration on admin init
add_action('admin_init', 'BWWC__migrate_gateway_id');
