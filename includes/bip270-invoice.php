<?php
/**
 * BIP270 Invoice Protocol Endpoint
 * Serves PaymentTerms JSON for BSV wallets supporting invoice protocol
 * 
 * Wallets: HandCash, ElectrumSV, Centi, SimplyCash
 * Spec: https://tsc.bsvblockchain.org/standards/direct-payment-protocol/
 * 
 * IMPORTANT: BSV Direct Payment Protocol requires HTTPS. Wallets will refuse
 * to fetch invoice URLs over plain HTTP for security (anti-MITM).
 * For local testing, use Cloudflare Tunnel or similar to provide HTTPS.
 * See docs/CLOUDFLARE_TUNNEL_SETUP.md for setup instructions.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register BIP270 invoice endpoint
 */
function BWWC__register_bip270_endpoint() {
    add_action('woocommerce_api_bsv_invoice', 'BWWC__serve_bip270_invoice');
}
add_action('init', 'BWWC__register_bip270_endpoint');

/**
 * Serve BIP270 PaymentTerms for an order
 * 
 * URL: https://yoursite.com/wc-api/bsv_invoice?order_id=123&key=abc&sig=hmac
 */
function BWWC__serve_bip270_invoice() {
    // Verify HTTPS (required for payment protocols)
    if (!is_ssl() && !defined('BWWC_ALLOW_HTTP_INVOICE')) {
        BWWC__log_bip270_invoice('Rejected invoice request: HTTPS required', array(
            'scheme' => (is_ssl() ? 'https' : 'http'),
            'host' => isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : null,
        ));
        wp_send_json_error(array(
            'message' => 'HTTPS required for invoice protocol'
        ), 400);
        exit;
    }

    // Get and validate parameters
    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    $order_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
    $signature = isset($_GET['sig']) ? sanitize_text_field($_GET['sig']) : '';

    BWWC__log_bip270_invoice('Incoming invoice request', array(
        'orderId' => $order_id,
        'hasKey' => $order_key !== '' ? 'yes' : 'no',
        'hasSig' => $signature !== '' ? 'yes' : 'no',
        'remoteAddr' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : null,
    ));

    if (!$order_id || !$order_key || !$signature) {
        BWWC__log_bip270_invoice('Invoice request missing parameters', array(
            'orderId' => $order_id,
            'hasKey' => $order_key !== '' ? 'yes' : 'no',
            'hasSig' => $signature !== '' ? 'yes' : 'no',
        ));
        wp_send_json_error(array(
            'message' => 'Missing required parameters'
        ), 400);
        exit;
    }

    // Load order
    $order = wc_get_order($order_id);
    if (!$order) {
        BWWC__log_bip270_invoice('Invoice request failed: order not found', array(
            'orderId' => $order_id,
        ));
        wp_send_json_error(array(
            'message' => 'Order not found'
        ), 404);
        exit;
    }

    // Verify order key
    if ($order->get_order_key() !== $order_key) {
        BWWC__log_bip270_invoice('Invoice request failed: invalid order key', array(
            'orderId' => $order_id,
        ));
        wp_send_json_error(array(
            'message' => 'Invalid order key'
        ), 403);
        exit;
    }

    // Verify signature (HMAC to prevent tampering)
    $expected_sig = BWWC__generate_invoice_signature($order_id, $order_key);
    if (!hash_equals($expected_sig, $signature)) {
        BWWC__log_bip270_invoice('Invoice request failed: invalid signature', array(
            'orderId' => $order_id,
        ));
        wp_send_json_error(array(
            'message' => 'Invalid signature'
        ), 403);
        exit;
    }

    // Check if order is still payable
    $payment_state = BWWC__get_payment_state($order_id);
    if (!in_array($payment_state, array('waiting', 'underpaid', 'pending', 'detected'))) {
        BWWC__log_bip270_invoice('Invoice request rejected due to order state', array(
            'orderId' => $order_id,
            'state' => $payment_state,
        ));
        wp_send_json_error(array(
            'message' => 'Order is no longer accepting payment',
            'state' => $payment_state
        ), 410);
        exit;
    }

    // Get payment details
    $bsv_address = $order->get_meta('_bwwc_address', true);
    if (empty($bsv_address)) {
        $bsv_address = get_post_meta($order_id, 'bitcoins_address', true);
    }
    $bsv_amount = $order->get_meta('_bwwc_order_total_in_btc', true);
    if (empty($bsv_amount)) {
        $bsv_amount = get_post_meta($order_id, 'order_total_in_btc', true);
    }
    $expected_sats = $order->get_meta('_bwwc_expected_sats', true);
    if (empty($expected_sats)) {
        $expected_sats = get_post_meta($order_id, 'expected_sats', true);
    }
    $expires_at = $order->get_meta('_bwwc_expires_at', true);
    if (empty($expires_at)) {
        $expires_at = get_post_meta($order_id, 'address_expires_at', true);
    }

    if (!$bsv_address || !$bsv_amount || !$expected_sats) {
        BWWC__log_bip270_invoice('Invoice request failed: missing payment details', array(
            'orderId' => $order_id,
            'hasAddress' => $bsv_address ? 'yes' : 'no',
            'hasAmount' => $bsv_amount ? 'yes' : 'no',
            'hasExpectedSats' => $expected_sats ? 'yes' : 'no',
        ));
        wp_send_json_error(array(
            'message' => 'Payment details not available'
        ), 500);
        exit;
    }

    // Check expiration
    if ($expires_at && time() > $expires_at) {
        BWWC__log_bip270_invoice('Invoice request failed: invoice expired', array(
            'orderId' => $order_id,
            'expiresAt' => $expires_at,
            'now' => time(),
        ));
        wp_send_json_error(array(
            'message' => 'Invoice expired',
            'expires' => gmdate('c', $expires_at)
        ), 410);
        exit;
    }

    // Build locking script for P2PKH address
    $locking_script = BWWC__address_to_locking_script($bsv_address);
    if (!$locking_script) {
        BWWC__log_bip270_invoice('Invoice request failed: locking script generation failed', array(
            'orderId' => $order_id,
        ));
        wp_send_json_error(array(
            'message' => 'Failed to generate payment script'
        ), 500);
        exit;
    }

    // Build BIP270 DPP PaymentTerms response per BSV TSC spec
    $creation_timestamp = time();
    $expiration_timestamp = $expires_at ? (int) $expires_at : ($creation_timestamp + 3600);

    $merchant_data_payload = array(
        'orderId' => $order_id,
        'orderKey' => $order_key,
        'plugin' => 'bsv-woocommerce-gateway',
        'version' => BWWC_VERSION,
    );

    // BSV TSC DPP PaymentTerms structure
    $payment_terms = array(
        'network' => 'bitcoin-sv',
        'version' => '1.0',
        'creationTimestamp' => $creation_timestamp,
        'expirationTimestamp' => $expiration_timestamp,
        'memo' => sprintf('Payment for WooCommerce Order #%d', $order_id),
        'paymentUrl' => BWWC__get_payment_callback_url($order_id, $order_key),
        'merchantData' => base64_encode(wp_json_encode($merchant_data_payload)),
        'modes' => array(
            array(
                'mode' => 'HybridPaymentMode',
                'brfcId' => 'ef63d9775da5',
                'outputs' => array(
                    array(
                        'amount' => intval($expected_sats),
                        'script' => $locking_script,
                        'description' => sprintf('Order #%d payment', $order_id)
                    )
                )
            )
        ),
        // Deprecated but kept for backward compatibility
        'outputs' => array(
            array(
                'script' => $locking_script,
                'amount' => intval($expected_sats),
                'description' => sprintf('Order #%d payment', $order_id)
            )
        )
    );

    // Set proper headers for BIP270
    header('Content-Type: application/payment-terms+json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    BWWC__log_bip270_invoice('Serving BIP270 PaymentTerms', array(
        'orderId' => $order_id,
        'expectedSats' => (int) $expected_sats,
        'expiresAt' => $payment_terms['expirationTimestamp'],
        'merchantDataBase64' => substr($payment_terms['merchantData'], 0, 64) . '...'
    ));

    // Return PaymentTerms JSON
    echo wp_json_encode($payment_terms, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Helper logger for invoice endpoint.
 *
 * @param string $message
 * @param array  $context
 */
function BWWC__log_bip270_invoice($message, $context = array()) {
    if (!function_exists('BWWC__is_debug_mode') || !function_exists('BWWC__log_debug')) {
        return;
    }

    if (!BWWC__is_debug_mode()) {
        return;
    }

    if (!empty($context)) {
        $message .= ' | ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES);
    }

    BWWC__log_debug($message);
}

/**
 * Generate HMAC signature for invoice URL
 * Prevents tampering with order_id or key parameters
 */
function BWWC__generate_invoice_signature($order_id, $order_key) {
    $secret = wp_salt('nonce');
    $data = sprintf('%d:%s', $order_id, $order_key);
    return hash_hmac('sha256', $data, $secret);
}

/**
 * Generate signed receipt download URL
 */
function BWWC__get_receipt_url($order_id, $order_key) {
    $signature = BWWC__generate_invoice_signature($order_id, $order_key);
    
    $params = array(
        'order_id' => $order_id,
        'key' => $order_key,
        'sig' => $signature
    );
    
    return add_query_arg($params, home_url('/wc-api/bsv_receipt'));
}

/**
 * Serve receipt download
 */
function BWWC__serve_receipt_download() {
    BWWC__log_bip270_invoice('Incoming receipt download request', array());

    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    $order_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
    $signature = isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : '';

    if (!$order_id || !$order_key || !$signature) {
        BWWC__log_bip270_invoice('Receipt download request missing parameters', array());
        wp_die(esc_html__('Invalid request', 'sendbsv-bsv-payments-for-woocommerce'), '', array('response' => 400));
    }

    // Load order
    $order = wc_get_order($order_id);
    if (!$order) {
        BWWC__log_bip270_invoice('Receipt download failed: order not found', array(
            'orderId' => $order_id,
        ));
        wp_die(esc_html__('Order not found', 'sendbsv-bsv-payments-for-woocommerce'), '', array('response' => 404));
    }

    // Verify order key
    if ($order->get_order_key() !== $order_key) {
        BWWC__log_bip270_invoice('Receipt download failed: invalid order key', array(
            'orderId' => $order_id,
        ));
        wp_die(esc_html__('Invalid order key', 'sendbsv-bsv-payments-for-woocommerce'), '', array('response' => 403));
    }

    // Verify signature
    $expected_sig = BWWC__generate_invoice_signature($order_id, $order_key);
    if (!hash_equals($expected_sig, $signature)) {
        BWWC__log_bip270_invoice('Receipt download failed: invalid signature', array(
            'orderId' => $order_id,
        ));
        wp_die(esc_html__('Invalid signature', 'sendbsv-bsv-payments-for-woocommerce'), '', array('response' => 403));
    }

    // Get receipt data
    $raw_tx = $order->get_meta('_bwwc_raw_tx', true);
    $beef = $order->get_meta('_bwwc_beef', true);

    if (!$raw_tx && !$beef) {
        BWWC__log_bip270_invoice('Receipt download failed: no receipt data', array(
            'orderId' => $order_id,
        ));
        wp_die(esc_html__('No receipt available', 'sendbsv-bsv-payments-for-woocommerce'), '', array('response' => 404));
    }

    // Serve the receipt
    if ($beef) {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="receipt-' . $order_id . '.json"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $beef;
    } else {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="receipt-' . $order_id . '.hex"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $raw_tx;
    }

    BWWC__log_bip270_invoice('Served receipt download', array(
        'orderId' => $order_id,
        'type' => $beef ? 'beef' : 'raw_tx'
    ));

    exit;
}

/**
 * Get payment callback URL for BIP270 paymentUrl field
 */
function BWWC__get_payment_callback_url($order_id, $order_key) {
    $signature = BWWC__generate_invoice_signature($order_id, $order_key);
    
    $params = array(
        'order_id' => $order_id,
        'key' => $order_key,
        'sig' => $signature
    );
    
    return add_query_arg($params, home_url('/wc-api/bsv_payment'));
}

/**
 * Convert base58 P2PKH address to locking script hex
 * 
 * P2PKH script: OP_DUP OP_HASH160 <pubkeyhash> OP_EQUALVERIFY OP_CHECKSIG
 * Hex format: 76a914<20-byte-hash>88ac
 */
function BWWC__address_to_locking_script($address) {
    // Decode base58 address
    $decoded = BWWC__base58_decode($address);
    if (!$decoded || strlen($decoded) !== 25) {
        return false;
    }
    
    // Extract pubkey hash (skip version byte, take 20 bytes, skip 4-byte checksum)
    $pubkey_hash = substr($decoded, 1, 20);
    
    // Build P2PKH locking script
    // OP_DUP (0x76) OP_HASH160 (0xa9) PUSH20 (0x14) <20-byte-hash> OP_EQUALVERIFY (0x88) OP_CHECKSIG (0xac)
    $script = '76a914' . bin2hex($pubkey_hash) . '88ac';
    
    return $script;
}

/**
 * Base58 decode (Bitcoin-style)
 */
function BWWC__base58_decode($input) {
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $base = strlen($alphabet);
    
    // Convert to decimal
    $decimal = '0';
    $length = strlen($input);
    for ($i = 0; $i < $length; $i++) {
        $char = $input[$i];
        $digit = strpos($alphabet, $char);
        if ($digit === false) {
            return false;
        }
        $decimal = bcadd(bcmul($decimal, $base), $digit);
    }
    
    // Convert to binary
    $binary = '';
    while (bccomp($decimal, '0') > 0) {
        $byte = bcmod($decimal, '256');
        $binary = chr($byte) . $binary;
        $decimal = bcdiv($decimal, '256');
    }
    
    // Add leading zeros
    for ($i = 0; $i < $length && $input[$i] === '1'; $i++) {
        $binary = "\x00" . $binary;
    }
    
    return $binary;
}
