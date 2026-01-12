<?php
/**
 * BSV Gateway Dashboard Widget
 * Shows gateway status and recent BSV orders
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register dashboard widget
 */
function BWWC__register_dashboard_widget()
{
    wp_add_dashboard_widget(
        'bwwc_gateway_status',
        __('Bitcoin SV Gateway Status', 'bitcoin-sv-payments-for-woocommerce'),
        'BWWC__render_dashboard_widget'
    );
}
add_action('wp_dashboard_setup', 'BWWC__register_dashboard_widget');

/**
 * Render dashboard widget content
 */
function BWWC__render_dashboard_widget()
{
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        echo '<p>' . __('WooCommerce is not active.', 'bitcoin-sv-payments-for-woocommerce') . '</p>';
        return;
    }

    // Get gateway settings
    $bwwc_settings = BWWC__get_settings();
    $gateway = new BWWC_Bitcoin();
    
    // Check gateway operational status
    $reason_message = '';
    $is_valid = $gateway->is_gateway_valid_for_use($reason_message);
    
    echo '<div style="margin-bottom: 15px;">';
    if ($is_valid) {
        echo '<div style="padding: 10px; background: #e7f7e7; border-left: 4px solid #46b450;">';
        echo '<strong style="color: #46b450;">✓ ' . __('Gateway Operational', 'bitcoin-sv-payments-for-woocommerce') . '</strong>';
        echo '</div>';
    } else {
        echo '<div style="padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">';
        echo '<strong style="color: #856404;">⚠ ' . __('Gateway Issue', 'bitcoin-sv-payments-for-woocommerce') . '</strong><br>';
        echo '<span style="font-size: 12px;">' . wp_kses_post($reason_message) . '</span>';
        echo '</div>';
    }
    echo '</div>';
    
    // Exchange rate status
    $store_currency = get_woocommerce_currency();
    if ($store_currency != 'BTC') {
        $exchange_rate = BWWC__get_exchange_rate_per_bitcoin($store_currency, 'getfirst', true);
        echo '<div style="margin-bottom: 15px; padding: 10px; background: #f0f0f1; border-radius: 4px;">';
        echo '<strong>' . __('Exchange Rate:', 'bitcoin-sv-payments-for-woocommerce') . '</strong> ';
        if ($exchange_rate) {
            echo '1 BSV = ' . number_format($exchange_rate, 2) . ' ' . esc_html($store_currency);
        } else {
            echo '<span style="color: #dc3545;">' . __('Unable to fetch', 'bitcoin-sv-payments-for-woocommerce') . '</span>';
        }
        echo '</div>';
    }
    
    // Recent BSV orders
    echo '<h4 style="margin: 15px 0 10px 0;">' . __('Recent BSV Orders', 'bitcoin-sv-payments-for-woocommerce') . '</h4>';
    
    $args = array(
        'limit' => 5,
        'payment_method' => 'bitcoin',
        'orderby' => 'date',
        'order' => 'DESC',
    );
    
    $orders = wc_get_orders($args);
    
    if (empty($orders)) {
        echo '<p style="color: #666; font-style: italic;">' . __('No BSV orders yet.', 'bitcoin-sv-payments-for-woocommerce') . '</p>';
    } else {
        echo '<table style="width: 100%; font-size: 12px;">';
        echo '<thead><tr style="border-bottom: 1px solid #ddd;">';
        echo '<th style="text-align: left; padding: 5px;">' . __('Order', 'bitcoin-sv-payments-for-woocommerce') . '</th>';
        echo '<th style="text-align: left; padding: 5px;">' . __('Status', 'bitcoin-sv-payments-for-woocommerce') . '</th>';
        echo '<th style="text-align: right; padding: 5px;">' . __('Total', 'bitcoin-sv-payments-for-woocommerce') . '</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($orders as $order) {
            $order_id = $order->get_id();
            $status = $order->get_status();
            $total = $order->get_total();
            $currency = $order->get_currency();
            
            $status_colors = array(
                'on-hold' => '#f0ad4e',
                'processing' => '#5bc0de',
                'completed' => '#46b450',
                'failed' => '#dc3545',
                'cancelled' => '#999',
            );
            $status_color = isset($status_colors[$status]) ? $status_colors[$status] : '#666';
            
            echo '<tr style="border-bottom: 1px solid #f0f0f1;">';
            echo '<td style="padding: 5px;"><a href="' . esc_url(admin_url('post.php?post=' . $order_id . '&action=edit')) . '">#' . $order_id . '</a></td>';
            echo '<td style="padding: 5px;"><span style="color: ' . $status_color . ';">●</span> ' . wc_get_order_status_name($status) . '</td>';
            echo '<td style="padding: 5px; text-align: right;">' . wc_price($total, array('currency' => $currency)) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    // Quick links
    echo '<p style="margin-top: 15px; text-align: center;">';
    echo '<a href="' . admin_url('admin.php?page=BWWC-settings') . '" class="button button-small">' . __('Gateway Settings', 'bitcoin-sv-payments-for-woocommerce') . '</a> ';
    echo '<a href="' . admin_url('admin.php?page=wc-orders&payment_method=bitcoin') . '" class="button button-small">' . __('View All BSV Orders', 'bitcoin-sv-payments-for-woocommerce') . '</a>';
    echo '</p>';
}
