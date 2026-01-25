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
        __('Bitcoin SV Gateway Status', 'bsvanon-bitcoin-sv-payments'),
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
        echo '<p>' . esc_html__('WooCommerce is not active.', 'bsvanon-bitcoin-sv-payments') . '</p>';
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
        echo '<strong style="color: #46b450;">✓ ' . esc_html__('Gateway Operational', 'bsvanon-bitcoin-sv-payments') . '</strong>';
        echo '</div>';
    } else {
        echo '<div style="padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">';
        echo '<strong style="color: #856404;">⚠ ' . esc_html__('Gateway Issue', 'bsvanon-bitcoin-sv-payments') . '</strong><br>';
        echo '<span style="font-size: 12px;">' . wp_kses_post($reason_message) . '</span>';
        echo '</div>';
    }
    echo '</div>';
    
    // Exchange rate status
    $store_currency = get_woocommerce_currency();
    if ($store_currency != 'BTC') {
        $exchange_rate = BWWC__get_exchange_rate_per_bitcoin($store_currency, 'getfirst', false);
        echo '<div style="margin-bottom: 15px; padding: 10px; background: #f0f0f1; border-radius: 4px;">';
        echo '<strong>' . esc_html__('Exchange Rate:', 'bsvanon-bitcoin-sv-payments') . '</strong> ';
        if ($exchange_rate && is_numeric($exchange_rate) && $exchange_rate > 0) {
            echo '1 BSV = ' . number_format((float)$exchange_rate, 2) . ' ' . esc_html($store_currency);
        } else {
            echo '<span style="color: #dc3545;">' . esc_html__('Unable to fetch', 'bsvanon-bitcoin-sv-payments') . '</span>';
        }
        echo '</div>';
    }
    
    // Recent BSV orders
    echo '<h4 style="margin: 15px 0 10px 0;">' . esc_html__('Recent BSV Orders', 'bsvanon-bitcoin-sv-payments') . '</h4>';
    
    $args = array(
        'limit' => 5,
        'payment_method' => 'bitcoin',
        'orderby' => 'date',
        'order' => 'DESC',
    );
    
    $orders = wc_get_orders($args);
    
    if (empty($orders)) {
        echo '<p style="color: #666; font-style: italic;">' . esc_html__('No BSV orders yet.', 'bsvanon-bitcoin-sv-payments') . '</p>';
    } else {
        echo '<table style="width: 100%; font-size: 12px;">';
        echo '<thead><tr style="border-bottom: 1px solid #ddd;">';
        echo '<th style="text-align: left; padding: 5px;">' . esc_html__('Order', 'bsvanon-bitcoin-sv-payments') . '</th>';
        echo '<th style="text-align: left; padding: 5px;">' . esc_html__('Status', 'bsvanon-bitcoin-sv-payments') . '</th>';
        echo '<th style="text-align: right; padding: 5px;">' . esc_html__('Total', 'bsvanon-bitcoin-sv-payments') . '</th>';
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
            echo '<td style="padding: 5px;"><a href="' . esc_url(admin_url('post.php?post=' . $order_id . '&action=edit')) . '">#' . esc_html($order_id) . '</a></td>';
            echo '<td style="padding: 5px;"><span style="color: ' . esc_attr($status_color) . ';">●</span> ' . esc_html(wc_get_order_status_name($status)) . '</td>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<td style="padding: 5px; text-align: right;">' . wc_price($total, array('currency' => $currency)) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    // Quick links
    echo '<p style="margin-top: 15px; text-align: center;">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=BWWC-settings')) . '" class="button button-small">' . esc_html__('Gateway Settings', 'bsvanon-bitcoin-sv-payments') . '</a> ';
    echo '<a href="' . esc_url(admin_url('admin.php?page=wc-orders&payment_method=bitcoin')) . '" class="button button-small">' . esc_html__('View All BSV Orders', 'bsvanon-bitcoin-sv-payments') . '</a>';
    echo '</p>';
}
