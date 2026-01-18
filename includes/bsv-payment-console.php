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
    $required_confirmations = isset($bwwc_settings['confs_num']) ? intval($bwwc_settings['confs_num']) : 1;
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
    
    // Calculate time remaining for display
    $time_remaining = '';
    if ($expires_at) {
        $seconds_remaining = $expires_at - time();
        $time_remaining = BWWC__format_time_remaining($seconds_remaining);
    }

    // Enqueue assets with AGGRESSIVE cache busting
    $plugin_base_dir = dirname(plugin_dir_path(__FILE__));
    
    // CSS versions with filemtime() for proper cache busting
    $css_clean_path = trailingslashit($plugin_base_dir) . 'assets/css/bsv-payment-clean.css';
    $css_clean_version = BWWC_VERSION;
    if (file_exists($css_clean_path)) {
        $css_clean_version .= '.' . filemtime($css_clean_path);
    }
    
    $css_grid_path = trailingslashit($plugin_base_dir) . 'assets/css/bsv-payment-grid.css';
    $css_grid_version = BWWC_VERSION;
    if (file_exists($css_grid_path)) {
        $css_grid_version .= '.' . filemtime($css_grid_path);
    }
    
    $css_console_path = trailingslashit($plugin_base_dir) . 'assets/css/bsv-payment-console.css';
    $css_console_version = BWWC_VERSION;
    if (file_exists($css_console_path)) {
        $css_console_version .= '.' . filemtime($css_console_path);
    }
    
    // JS versions with filemtime()
    $script_file_path = trailingslashit($plugin_base_dir) . 'assets/js/bsv-payment-console.js';
    $script_version = BWWC_VERSION;
    if (file_exists($script_file_path)) {
        $script_version .= '.' . filemtime($script_file_path);
    }
    
    $qr_script_path = trailingslashit($plugin_base_dir) . 'assets/js/vendor/jquery-qrcode.min.js';
    $qr_script_version = BWWC_VERSION;
    if (file_exists($qr_script_path)) {
        $qr_script_version .= '.' . filemtime($qr_script_path);
    }

    wp_enqueue_style('bsv-payment-console', plugins_url('/assets/css/bsv-payment-console.css', dirname(__FILE__)), array(), $css_console_version);
    wp_enqueue_style('bsv-payment-grid', plugins_url('/assets/css/bsv-payment-grid.css', dirname(__FILE__)), array('bsv-payment-console'), $css_grid_version);
    wp_enqueue_style('bsv-payment-clean', plugins_url('/assets/css/bsv-payment-clean.css', dirname(__FILE__)), array('bsv-payment-grid'), $css_clean_version);
    
    // Use bundled jQuery QR code library (avoids WooCommerce dependency)
    if (wp_script_is('jquery-qrcode', 'registered')) {
        wp_deregister_script('jquery-qrcode');
    }
    wp_register_script('jquery-qrcode', plugins_url('/assets/js/vendor/jquery.qrcode.js', dirname(__FILE__)), array('jquery'), $qr_script_version, true);
    wp_enqueue_script('jquery-qrcode');
    
    wp_enqueue_script('bsv-payment-console', plugins_url('/assets/js/bsv-payment-console.js', dirname(__FILE__)), array('jquery', 'jquery-qrcode'), $script_version, true);
    
    // BRC-100 integration removed from v6.0.0
    // Not production-ready: calls localhost, mixed-content issues, placeholder crypto
    // Will be reintroduced in future version with proper HTTPS + origin verification
    
    // Include BIP270 invoice endpoint
    require_once(dirname(__FILE__) . '/bip270-invoice.php');
    
    // Generate signed invoice URL for BIP270
    $invoice_url = BWWC__get_invoice_url($order_id, $order->get_order_key());
    
    // Localize script
    wp_localize_script('bsv-payment-console', 'bsvPaymentData', array(
        'statusEndpoint' => admin_url('admin-ajax.php?action=bsv_check_payment_status'),
        'nonce' => wp_create_nonce('bsv_payment_status_' . $order_id),
        'orderId' => $order_id,
        'bsvAddress' => $bsv_address,
        'bsvAmount' => $bsv_amount,
        'orderKey' => $order->get_order_key(),
        'invoiceUrl' => $invoice_url
    ));

    // Render console
    ?>
    <div class="bsv-payment-console" data-order-id="<?php echo esc_attr($order_id); ?>" data-order-key="<?php echo esc_attr($order->get_order_key()); ?>" data-polling-interval="<?php echo esc_attr($polling_interval); ?>">
        
        <!-- Header with title and timer badge -->
        <div class="bsv-payment-header">
            <div class="bsv-header-left">
                <h2><?php esc_html_e('Pay with Bitcoin SV', 'bitcoin-sv-payments-for-woocommerce'); ?></h2>
                <?php if ($payment_state === 'waiting' || $payment_state === 'underpaid'): ?>
                <p class="bsv-instruction"><?php esc_html_e('Scan the QR with a BSV wallet and send the exact amount.', 'bitcoin-sv-payments-for-woocommerce'); ?></p>
                <?php endif; ?>
            </div>
            <?php if ($payment_state === 'waiting' || $payment_state === 'underpaid'): ?>
            <div class="bsv-timer-wrapper">
                <div class="bsv-timer-label"><?php esc_html_e('Order Expires in:', 'bitcoin-sv-payments-for-woocommerce'); ?></div>
                <div class="bsv-timer-badge">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px;">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <span class="bsv-expiration-timer" data-expires="<?php echo esc_attr($expires_at); ?>">
                        <?php echo esc_html($time_remaining); ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Main payment card -->
        <div class="bsv-payment-card">
            
            <?php if ($payment_state === 'waiting' || $payment_state === 'underpaid'): ?>
            <div class="bsv-card-top">
                <button id="bsv-brc100-pay-button" class="bsv-wallet-button" type="button" style="display: none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
                    </svg>
                    <span><?php esc_html_e('Open Wallet', 'bitcoin-sv-payments-for-woocommerce'); ?></span>
                </button>
            </div>
            <?php endif; ?>
            
            <!-- Two-column grid: QR left, details right -->
            <div class="bsv-payment-grid">
                
                <!-- Left: QR Code -->
                <div class="bsv-qr-column">
                
                    <?php if ($payment_state === 'waiting' || $payment_state === 'underpaid'): ?>
                    <div class="bsv-expected">
                        <?php esc_html_e('Expected:', 'bitcoin-sv-payments-for-woocommerce'); ?>
                        <strong><?php echo esc_html(number_format($expected_sats, 0, '.', ',')); ?> sats</strong>
                    </div>
                    <?php endif; ?>

                    <!-- QR Code (hero element) -->
                    <div class="bsv-qr-wrapper">
                        <div id="bsv-qr-code" 
                             data-address="<?php echo esc_attr($bsv_address); ?>" 
                             data-amount="<?php echo esc_attr($bsv_amount); ?>" 
                             data-order-id="<?php echo esc_attr($order_id); ?>"
                             data-order-key="<?php echo esc_attr($order->get_order_key()); ?>"
                             data-protocol="bip21"
                             style="display: inline-block;"></div>
                    </div>
                    
                    <!-- Protocol Tabs (small, secondary) -->
                    <div class="bsv-protocol-tabs" role="tablist">
                        <button class="bsv-protocol-tab active" data-protocol="bip21" role="tab" aria-selected="true">
                            <?php esc_html_e('Standard', 'bitcoin-sv-payments-for-woocommerce'); ?>
                        </button>
                        <button class="bsv-protocol-tab" data-protocol="bip270" role="tab" aria-selected="false">
                            <?php esc_html_e('Invoice', 'bitcoin-sv-payments-for-woocommerce'); ?>
                        </button>
                    </div>
                    
                    <!-- One-line hint below QR -->
                    <p class="bsv-qr-hint" data-protocol="bip21">
                        <?php esc_html_e('Scan with your BSV wallet', 'bitcoin-sv-payments-for-woocommerce'); ?>
                    </p>
                    <p class="bsv-qr-hint" data-protocol="bip270" style="display: none;">
                        <?php esc_html_e('For invoice protocol wallets', 'bitcoin-sv-payments-for-woocommerce'); ?>
                    </p>
                </div>

                <!-- Right: Details -->
                <div class="bsv-details-column">
                
                <div class="bsv-address-section">
            <div class="bsv-address-label"><?php esc_html_e('Payment Address', 'bitcoin-sv-payments-for-woocommerce'); ?></div>
            <div class="bsv-address-wrapper">
                <div class="bsv-address"><?php echo esc_html($bsv_address); ?></div>
                <button class="bsv-copy-btn" data-copy="<?php echo esc_attr($bsv_address); ?>">
                    <?php esc_html_e('Copy', 'bitcoin-sv-payments-for-woocommerce'); ?>
                </button>
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
                    
                </div><!-- end details column -->
            </div><!-- end payment grid -->
            
            <!-- Status strip (bottom of card) -->
            <div class="bsv-status-strip status-<?php echo esc_attr($payment_state); ?>">
                <div class="bsv-status-indicator"></div>
                <div class="bsv-status-text">
                    <strong><?php echo esc_html(BWWC__get_payment_state_label($payment_state)); ?></strong>
                    <span><?php echo esc_html(BWWC__get_payment_console_state_message($payment_state, $received_sats, $expected_sats)); ?></span>
                </div>
            </div>

            <!-- Stepper (inside card, after status strip) -->
            <?php 
            $show_stepper = !in_array($payment_state, array('waiting'));
            if ($show_stepper): 
            ?>
            <div class="bsv-confirmations" data-state="<?php echo esc_attr($payment_state); ?>">
                <div class="bsv-conf-label">
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

            <!-- Payment details (inside card) -->
            <?php if ($received_sats > 0 && ($payment_state === 'underpaid' || $payment_state === 'pending' || $payment_state === 'detected')): ?>
            <div class="bsv-payment-details">
                <div class="bsv-detail-row">
                    <span class="bsv-detail-label"><?php esc_html_e('Received', 'bitcoin-sv-payments-for-woocommerce'); ?></span>
                    <span class="bsv-detail-value"><?php echo esc_html(number_format($received_sats, 0, '.', ',')); ?> sats</span>
                </div>
                <?php if ($payment_state === 'pending' || $payment_state === 'detected' || $payment_state === 'confirmed'): ?>
                <div class="bsv-detail-row">
                    <span class="bsv-detail-label"><?php esc_html_e('Confirmed', 'bitcoin-sv-payments-for-woocommerce'); ?></span>
                    <span class="bsv-detail-value"><?php echo esc_html(number_format($confirmed_sats, 0, '.', ',')); ?> sats</span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
        </div><!-- .bsv-payment-card -->

        <!-- Footer actions (tertiary links) -->
        <div class="bsv-footer-actions">
            <button class="bsv-link-btn bsv-recheck-btn" data-paid-state="<?php echo esc_attr($payment_state); ?>">
                <?php 
                if ($payment_state === 'detected' || $payment_state === 'confirmed') {
                    esc_html_e('✓ Payment Received', 'bitcoin-sv-payments-for-woocommerce');
                } else {
                    esc_html_e("I've Paid", 'bitcoin-sv-payments-for-woocommerce');
                }
                ?>
            </button>
            <span class="bsv-footer-separator">•</span>
            <a href="<?php echo esc_url($order->get_view_order_url()); ?>" class="bsv-link-btn">
                <?php esc_html_e('View Order', 'bitcoin-sv-payments-for-woocommerce'); ?>
            </a>
        </div>

        <div class="bsv-explorer-link" style="display: none;">
            <!-- Populated by JS when tx detected -->
        </div>
        
        <?php if ($payment_state === 'waiting' || $payment_state === 'underpaid'): ?>
        <div class="bsv-get-bsv">
            <a href="https://swap.sendbsv.com/" target="_blank" rel="noopener" class="bsv-get-bsv-button">
                <?php esc_html_e('Need BSV? Get BSV', 'bitcoin-sv-payments-for-woocommerce'); ?> ↗
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

// v6.0.0: QR code generation moved to JavaScript using WooCommerce's jQuery QRCode library
// This eliminates the need for server-side QR generation and provides better browser compatibility

// v6.0.0: Helper functions now provided by includes/constants.php and includes/expiry.php
// - BWWC__format_time_remaining() from expiry.php
// - BWWC__get_payment_state_label() from constants.php
// - BWWC__get_payment_state_message() from constants.php

/**
 * Get payment state message (local version for payment console)
 */
function BWWC__get_payment_console_state_message($state, $received_sats = 0, $expected_sats = 0) {
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
                'AJAX status error: Order %d key mismatch (keys redacted for security)',
                $order_id
            )
        );
        wp_send_json_error(array('message' => 'Invalid order'));
        return;
    }
    
    // Trigger blockchain check for pending orders
    // For waiting/pending/underpaid states, check blockchain on every poll (with cooldown)
    $payment_state = get_post_meta($order_id, 'payment_state', true);
    $should_check_blockchain = $force || in_array($payment_state, array('waiting', 'pending', 'underpaid', 'detected'));
    
    if ($should_check_blockchain) {
        $last_check = get_post_meta($order_id, 'last_blockchain_check', true);
        $cooldown = $force ? 3 : 10; // 3 seconds for manual, 10 seconds for auto
        
        if (!$last_check || (time() - $last_check) >= $cooldown) {
            update_post_meta($order_id, 'last_blockchain_check', time());
            
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
    $required_confirmations = isset($bwwc_settings['confs_num']) ? intval($bwwc_settings['confs_num']) : 1;
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
