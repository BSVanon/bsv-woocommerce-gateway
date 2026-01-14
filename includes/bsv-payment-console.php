<?php
/**
 * BSV Payment Console - Modern UI for order-pay and thank-you pages
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render the payment console UI
 */
function BWWC__render_payment_console($order) {
    if (!$order) {
        return;
    }

    $order_id = $order->get_id();
    $bsv_address = get_post_meta($order_id, 'bitcoins_address', true);
    $bsv_amount = get_post_meta($order_id, 'order_total_in_btc', true);
    $expected_sats = get_post_meta($order_id, 'expected_sats', true);
    $received_sats = get_post_meta($order_id, 'received_sats', true);
    $confirmed_sats = get_post_meta($order_id, 'confirmed_sats', true);
    $expires_at = get_post_meta($order_id, 'address_expires_at', true);
    $payment_state = get_post_meta($order_id, 'payment_state', true);
    $txids = get_post_meta($order_id, 'txids', true);
    $confirmations = get_post_meta($order_id, 'best_confirmations', true);
    
    $bwwc_settings = BWWC__get_settings();
    $required_confirmations = isset($bwwc_settings['confirmations']) ? intval($bwwc_settings['confirmations']) : 1;
    $polling_interval = isset($bwwc_settings['status_polling_interval']) ? intval($bwwc_settings['status_polling_interval']) : 10;
    
    if (!$bsv_address || !$bsv_amount) {
        return;
    }

    // Calculate sats if not stored
    if (!$expected_sats) {
        $expected_sats = intval(round($bsv_amount * 100000000));
        update_post_meta($order_id, 'expected_sats', $expected_sats);
    }

    // Default payment state
    if (!$payment_state) {
        $payment_state = 'waiting';
    }
    
    if (!$confirmed_sats) {
        $confirmed_sats = 0;
    }

    // Get store currency for fiat display
    $store_currency = get_woocommerce_currency();
    $order_total = $order->get_total();

    // Generate QR code
    $qr_code_svg = BWWC__generate_qr_code($bsv_address, $bsv_amount);

    // Enqueue assets
    $plugin_base_dir = dirname(plugin_dir_path(__FILE__));
    $script_file_path = trailingslashit($plugin_base_dir) . 'assets/js/bsv-payment-console.js';
    $script_version = BWWC_VERSION;
    if (file_exists($script_file_path)) {
        $script_version .= '.' . filemtime($script_file_path);
    }

    wp_enqueue_style('bsv-payment-console', plugins_url('/assets/css/bsv-payment-console.css', dirname(__FILE__)), array(), BWWC_VERSION);
    wp_enqueue_script('bsv-payment-console', plugins_url('/assets/js/bsv-payment-console.js', dirname(__FILE__)), array('jquery'), $script_version, true);
    
    // Localize script
    wp_localize_script('bsv-payment-console', 'bsvPaymentData', array(
        'statusEndpoint' => admin_url('admin-ajax.php?action=bsv_check_payment_status'),
        'nonce' => wp_create_nonce('bsv_payment_status_' . $order_id),
        'orderId' => $order_id,
        'orderKey' => $order->get_order_key()
    ));

    // Render console
    ?>
    <div class="bsv-payment-console" data-order-id="<?php echo esc_attr($order_id); ?>" data-order-key="<?php echo esc_attr($order->get_order_key()); ?>" data-polling-interval="<?php echo esc_attr($polling_interval); ?>">
        
        <div class="bsv-payment-header">
            <h2><?php esc_html_e('Pay with Bitcoin SV', 'bitcoin-sv-payments-for-woocommerce'); ?></h2>
            <div class="bsv-payment-steps">
                <?php esc_html_e('1. Scan the QR code or copy the address below', 'bitcoin-sv-payments-for-woocommerce'); ?><br>
                <?php esc_html_e('2. Send the exact amount shown', 'bitcoin-sv-payments-for-woocommerce'); ?><br>
                <?php esc_html_e('3. Wait for blockchain confirmation', 'bitcoin-sv-payments-for-woocommerce'); ?>
            </div>
        </div>

        <div class="bsv-qr-container">
            <div class="bsv-qr-card">
                <?php 
                // Output QR code SVG directly - it comes from trusted external API
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $qr_code_svg;
                ?>
            </div>
        </div>

        <div class="bsv-amount-section">
            <div class="bsv-amount" 
                 data-bsv="<?php echo esc_attr($bsv_amount); ?>" 
                 data-sats="<?php echo esc_attr($expected_sats); ?>" 
                 data-mode="bsv"
                 style="cursor: pointer;"
                 title="<?php esc_attr_e('Click to toggle between BSV and sats', 'bitcoin-sv-payments-for-woocommerce'); ?>">
                <span class="bsv-amount-value"><?php echo esc_html($bsv_amount); ?></span>
                <span class="bsv-unit">BSV</span>
            </div>
            <div class="bsv-fiat">
                <?php echo esc_html(sprintf('≈ %s %s', number_format($order_total, 2), $store_currency)); ?>
            </div>
            <button class="bsv-copy-btn bsv-copy-amount" data-copy="<?php echo esc_attr($bsv_amount); ?>" data-copy-sats="<?php echo esc_attr($expected_sats); ?>" style="margin-top: 0.75rem;">
                <?php esc_html_e('Copy Amount', 'bitcoin-sv-payments-for-woocommerce'); ?>
            </button>
        </div>

        <div class="bsv-address-section">
            <div class="bsv-address-label"><?php esc_html_e('Payment Address', 'bitcoin-sv-payments-for-woocommerce'); ?></div>
            <div class="bsv-address-wrapper">
                <div class="bsv-address"><?php echo esc_html($bsv_address); ?></div>
                <button class="bsv-copy-btn" data-copy="<?php echo esc_attr($bsv_address); ?>">
                    <?php esc_html_e('Copy', 'bitcoin-sv-payments-for-woocommerce'); ?>
                </button>
            </div>
        </div>

        <?php if ($expires_at): ?>
        <div class="bsv-expiration">
            <div class="bsv-expiration-label"><?php esc_html_e('Time Remaining', 'bitcoin-sv-payments-for-woocommerce'); ?></div>
            <div class="bsv-expiration-time" data-expires="<?php echo esc_attr($expires_at); ?>">
                <?php echo esc_html(BWWC__format_time_remaining($expires_at)); ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="bsv-status-box status-<?php echo esc_attr($payment_state); ?>">
            <div class="bsv-status-label">
                <?php echo esc_html(BWWC__get_payment_state_label($payment_state)); ?>
            </div>
            <div class="bsv-status-message">
                <?php echo esc_html(BWWC__get_payment_state_message($payment_state, $received_sats, $expected_sats)); ?>
            </div>
        </div>

        <?php 
        // Show stepper for all states except initial waiting
        $show_stepper = !in_array($payment_state, array('waiting'));
        if ($show_stepper): 
        ?>
        <div class="bsv-confirmations" data-state="<?php echo esc_attr($payment_state); ?>">
            <div style="font-size: 14px; opacity: 0.7; margin-bottom: 0.5rem;">
                <?php 
                if ($payment_state === 'expired' && $received_sats == 0) {
                    esc_html_e('Payment Window Expired', 'bitcoin-sv-payments-for-woocommerce');
                } elseif ($payment_state === 'underpaid') {
                    esc_html_e('Partial Payment Received', 'bitcoin-sv-payments-for-woocommerce');
                } else {
                    esc_html_e('Confirmations:', 'bitcoin-sv-payments-for-woocommerce');
                    echo ' <strong class="bsv-conf-current">' . esc_html($confirmations ?: 0) . '</strong> / ';
                    echo '<span class="bsv-conf-required">' . esc_html($required_confirmations) . '</span>';
                }
                ?>
            </div>
            <div class="bsv-conf-progress">
                <?php 
                $max_dots = max($required_confirmations, 3);
                for ($i = 0; $i < $max_dots; $i++): 
                    $dot_class = '';
                    if ($payment_state === 'expired' && $received_sats == 0) {
                        $dot_class = 'failed';
                    } elseif ($payment_state === 'underpaid') {
                        $dot_class = ($i == 0) ? 'partial' : '';
                    } elseif ($payment_state === 'detected' || $payment_state === 'pending') {
                        if ($i < $confirmations) {
                            $dot_class = 'confirmed';
                        } elseif ($i == 0 && $received_sats >= $expected_sats) {
                            $dot_class = 'pending';
                        }
                    } elseif ($payment_state === 'confirmed') {
                        $dot_class = ($i < $confirmations) ? 'confirmed' : '';
                    }
                ?>
                    <div class="bsv-conf-dot <?php echo esc_attr($dot_class); ?>" data-index="<?php echo esc_attr($i); ?>"></div>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($payment_state === 'underpaid' || $payment_state === 'waiting' || $payment_state === 'pending' || $payment_state === 'detected'): ?>
        <div class="bsv-payment-details">
            <?php if ($received_sats > 0): ?>
            <div class="bsv-detail-row">
                <span class="bsv-detail-label"><?php esc_html_e('Received', 'bitcoin-sv-payments-for-woocommerce'); ?></span>
                <span class="bsv-detail-value bsv-detail-received"><?php echo esc_html(number_format($received_sats, 0, '.', ',')); ?> sats</span>
            </div>
            <?php endif; ?>
            <?php if ($payment_state === 'pending' || $payment_state === 'detected' || $payment_state === 'confirmed'): ?>
            <div class="bsv-detail-row bsv-detail-confirmed-row">
                <span class="bsv-detail-label"><?php esc_html_e('Confirmed', 'bitcoin-sv-payments-for-woocommerce'); ?></span>
                <span class="bsv-detail-value bsv-detail-confirmed"><?php echo esc_html(number_format($confirmed_sats, 0, '.', ',')); ?> sats</span>
            </div>
            <?php endif; ?>
            <div class="bsv-detail-row">
                <span class="bsv-detail-label"><?php esc_html_e('Expected', 'bitcoin-sv-payments-for-woocommerce'); ?></span>
                <span class="bsv-detail-value bsv-detail-expected"><?php echo esc_html(number_format($expected_sats, 0, '.', ',')); ?> sats</span>
            </div>
        </div>
        <?php endif; ?>

        <div class="bsv-actions">
            <button class="bsv-btn bsv-btn-primary bsv-recheck-btn" data-paid-state="<?php echo esc_attr($payment_state); ?>">
                <?php 
                if ($payment_state === 'detected' || $payment_state === 'confirmed') {
                    esc_html_e('Payment Received!', 'bitcoin-sv-payments-for-woocommerce');
                } else {
                    esc_html_e("I've Paid", 'bitcoin-sv-payments-for-woocommerce');
                }
                ?>
            </button>
            <a href="<?php echo esc_url($order->get_view_order_url()); ?>" class="bsv-btn bsv-btn-secondary">
                <?php esc_html_e('View Order', 'bitcoin-sv-payments-for-woocommerce'); ?>
            </a>
        </div>

        <div class="bsv-explorer-link" style="display: none;">
            <!-- Populated by JS when tx detected -->
        </div>
        
        <?php if ($payment_state === 'waiting' || $payment_state === 'underpaid'): ?>
        <div class="bsv-wallet-topup" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; text-align: center;">
            <p style="margin: 0 0 10px 0; font-size: 13px; color: #666;">
                <?php esc_html_e('Need to top up your BSV wallet?', 'bitcoin-sv-payments-for-woocommerce'); ?>
            </p>
            <a href="https://swap.sendbsv.com/" target="_blank" rel="noopener" style="display: inline-block; padding: 8px 16px; background: #FCCA09; color: #000; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 13px;">
                <?php esc_html_e('Get BSV', 'bitcoin-sv-payments-for-woocommerce'); ?> ↗
            </a>
        </div>
        <?php endif; ?>
        
        <?php 
        // Show confirmation time estimate
        $bwwc_settings = BWWC__get_settings();
        $required_confs = isset($bwwc_settings['confs_num']) ? intval($bwwc_settings['confs_num']) : 4;
        $estimated_minutes = $required_confs * 10;
        ?>
        <div class="bsv-confirmation-notice" style="margin-top: 15px; padding: 12px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; font-size: 12px; color: #856404;">
            <strong><?php esc_html_e('Note:', 'bitcoin-sv-payments-for-woocommerce'); ?></strong>
            <?php 
            /* translators: 1: number of confirmations required, 2: estimated minutes */
            printf(
                esc_html__('Merchant requires %1$d confirmations (~%2$d minutes) before order is finalized. You will receive email confirmation once payment is verified. Payments to the above address must be in BitcoinSV only—BTC or BCH sent here will be lost.', 'bitcoin-sv-payments-for-woocommerce'),
                esc_html($required_confs),
                esc_html($estimated_minutes)
            );
            ?>
        </div>

    </div>
    <?php
}

/**
 * Generate QR code as SVG
 */
function BWWC__generate_qr_code($address, $amount) {
    // Use BIP21 format for compact QR
    $bip21_uri = 'bitcoin:' . $address . '?amount=' . $amount;
    
    // Use phpqrcode library if available, otherwise fall back to simple implementation
    if (function_exists('QRcode')) {
        ob_start();
        QRcode::svg($bip21_uri, false, QR_ECLEVEL_M, 8, 4);
        return ob_get_clean();
    }
    
    // Fallback: use external QR service (Google Charts API alternative)
    // For production, consider bundling a QR library
    $qr_size = 300;
    $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $qr_size . 'x' . $qr_size . '&data=' . urlencode($bip21_uri) . '&format=svg';
    
    // Try to fetch SVG
    $response = wp_remote_get($qr_url, array('timeout' => 5));
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        return wp_remote_retrieve_body($response);
    }
    
    // Final fallback: simple image tag
    return '<img src="' . esc_url($qr_url) . '" alt="QR Code" style="max-width: 280px; height: auto;" />';
}

/**
 * Format time remaining
 */
function BWWC__format_time_remaining($expires_at) {
    $now = time();
    $diff = $expires_at - $now;
    
    if ($diff <= 0) {
        return __('Expired', 'bitcoin-sv-payments-for-woocommerce');
    }
    
    $minutes = floor($diff / 60);
    $seconds = $diff % 60;
    
    if ($minutes > 0) {
        return sprintf('%dm %ds', $minutes, $seconds);
    }
    
    return sprintf('%ds', $seconds);
}

/**
 * Get payment state label
 */
function BWWC__get_payment_state_label($state) {
    $labels = array(
        'waiting' => __('Waiting for Payment', 'bitcoin-sv-payments-for-woocommerce'),
        'detected' => __('Payment Detected!', 'bitcoin-sv-payments-for-woocommerce'),
        'confirmed' => __('Payment Confirmed', 'bitcoin-sv-payments-for-woocommerce'),
        'pending' => __('Awaiting Confirmation', 'bitcoin-sv-payments-for-woocommerce'),
        'expired' => __('Payment Window Expired', 'bitcoin-sv-payments-for-woocommerce'),
        'underpaid' => __('Underpaid', 'bitcoin-sv-payments-for-woocommerce'),
        'overpaid' => __('Overpaid (Thank You!)', 'bitcoin-sv-payments-for-woocommerce')
    );
    
    return isset($labels[$state]) ? $labels[$state] : $labels['waiting'];
}

/**
 * Get payment state message
 */
function BWWC__get_payment_state_message($state, $received_sats = 0, $expected_sats = 0) {
    // Ensure numeric values
    $received_sats = floatval($received_sats);
    $expected_sats = floatval($expected_sats);
    
    $messages = array(
        'waiting' => __('Send the exact amount to the address above. Payment will be detected within seconds.', 'bitcoin-sv-payments-for-woocommerce'),
        'detected' => __('Your payment has been detected on the blockchain. Waiting for confirmation...', 'bitcoin-sv-payments-for-woocommerce'),
        'pending' => __('Payment broadcast detected. Waiting for miners to confirm—no action needed.', 'bitcoin-sv-payments-for-woocommerce'),
        'confirmed' => __('Your payment has been confirmed. Thank you!', 'bitcoin-sv-payments-for-woocommerce'),
        'expired' => __('The payment window has expired. If you already paid, support will verify and update your order shortly.', 'bitcoin-sv-payments-for-woocommerce')
    );
    
    // Build dynamic messages for underpaid/overpaid states
    if ($state === 'underpaid' && $expected_sats > 0) {
        /* translators: 1: received satoshis, 2: expected satoshis */
        $messages['underpaid'] = sprintf(
            __('Received %1$s sats but expected %2$s sats. Please send the remaining amount.', 'bitcoin-sv-payments-for-woocommerce'),
            number_format($received_sats, 0, '.', ','),
            number_format($expected_sats, 0, '.', ',')
        );
    } elseif ($state === 'overpaid' && $expected_sats > 0) {
        /* translators: 1: received satoshis, 2: extra satoshis */
        $messages['overpaid'] = sprintf(
            __('Received %1$s sats (%2$s sats extra). Payment accepted!', 'bitcoin-sv-payments-for-woocommerce'),
            number_format($received_sats, 0, '.', ','),
            number_format($received_sats - $expected_sats, 0, '.', ',')
        );
    }
    
    return isset($messages[$state]) ? $messages[$state] : $messages['waiting'];
}

/**
 * AJAX handler for payment status checks
 */
function BWWC__ajax_check_payment_status() {
    // Verify nonce
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $order_key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';
    $force = isset($_POST['force']) && $_POST['force'] === '1';
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    
    if (!$order_id || !$order_key) {
        BWWC__log_event(
            __FILE__,
            __LINE__,
            sprintf('AJAX status error: Missing order_id (%d) or order_key (%s)', $order_id, $order_key)
        );
        wp_send_json_error(array('message' => 'Invalid request'));
        return;
    }
    
    // Verify nonce
    if (!wp_verify_nonce($nonce, 'bsv_payment_status_' . $order_id)) {
        BWWC__log_event(
            __FILE__,
            __LINE__,
            sprintf('AJAX status error: Nonce validation failed for order %d', $order_id)
        );
        wp_send_json_error(array('message' => 'Invalid nonce'));
        return;
    }
    
    // Get order
    $order = wc_get_order($order_id);
    if (!$order) {
        BWWC__log_event(__FILE__, __LINE__, sprintf('AJAX status error: Order %d not found (key %s)', $order_id, $order_key));
        wp_send_json_error(array('message' => 'Invalid order'));
        return;
    }

    $expected_order_key = $order->get_order_key();
    if ($expected_order_key !== $order_key) {
        BWWC__log_event(
            __FILE__,
            __LINE__,
            sprintf(
                'AJAX status error: Order %d key mismatch (expected %s got %s)',
                $order_id,
                $expected_order_key,
                $order_key
            )
        );
        wp_send_json_error(array('message' => 'Invalid order'));
        return;
    }
    
    // Force recheck if requested (with cooldown)
    if ($force) {
        $last_check = get_post_meta($order_id, 'last_manual_check', true);
        $cooldown = 3; // 3 seconds
        
        if (!$last_check || (time() - $last_check) >= $cooldown) {
            update_post_meta($order_id, 'last_manual_check', time());
            
            // Trigger immediate payment check
            BWWC__check_payment_for_order($order_id);
        }
    }
    
    // Get current status
    $bsv_address = get_post_meta($order_id, 'bitcoins_address', true);
    $expected_sats = get_post_meta($order_id, 'expected_sats', true);
    $received_sats = get_post_meta($order_id, 'received_sats', true);
    $payment_state = get_post_meta($order_id, 'payment_state', true);
    $confirmed_sats = get_post_meta($order_id, 'confirmed_sats', true);
    $expires_at = get_post_meta($order_id, 'address_expires_at', true);
    $txids = get_post_meta($order_id, 'txids', true);
    $confirmations = get_post_meta($order_id, 'best_confirmations', true);
    $last_checked = get_post_meta($order_id, 'last_checked_at', true);
    
    $bwwc_settings = BWWC__get_settings();
    $required_confirmations = isset($bwwc_settings['confirmations']) ? intval($bwwc_settings['confirmations']) : 1;
    $explorer_base = 'https://whatsonchain.com';
    
    // Parse txids if string
    if (is_string($txids)) {
        $txids = array_filter(explode(',', $txids));
    }
    
    $response_payload = array(
        'address' => $bsv_address,
        'expected_sats' => intval($expected_sats),
        'received_sats' => intval($received_sats),
        'payment_state' => $payment_state ?: 'waiting',
        'confirmed_sats' => intval($confirmed_sats),
        'expires_at' => intval($expires_at),
        'txids' => $txids ?: array(),
        'best_confirmations' => intval($confirmations),
        'required_confirmations' => $required_confirmations,
        'last_checked_at' => intval($last_checked),
        'order_status' => $order->get_status(),
        'explorer_url' => $explorer_base
    );

    BWWC__log_event(
        __FILE__,
        __LINE__,
        sprintf(
            'AJAX status ping order %d → state=%s received=%d confirmed=%d best_conf=%d/%d order_status=%s force=%s',
            $order_id,
            $response_payload['payment_state'],
            $response_payload['received_sats'],
            $response_payload['confirmed_sats'],
            $response_payload['best_confirmations'],
            $response_payload['required_confirmations'],
            $response_payload['order_status'],
            $force ? 'yes' : 'no'
        )
    );

    wp_send_json_success($response_payload);
}
add_action('wp_ajax_bsv_check_payment_status', 'BWWC__ajax_check_payment_status');
add_action('wp_ajax_nopriv_bsv_check_payment_status', 'BWWC__ajax_check_payment_status');
