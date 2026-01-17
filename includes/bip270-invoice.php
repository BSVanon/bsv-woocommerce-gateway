<?php
/**
 * BIP270 Invoice Protocol Endpoint
 * Serves PaymentTerms JSON for BSV wallets supporting invoice protocol
 * 
 * Wallets: HandCash, ElectrumSV, Centi, SimplyCash
 * Spec: https://github.com/moneybutton/bips/blob/master/bip-0270.mediawiki
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
        wp_send_json_error(array(
            'message' => 'HTTPS required for invoice protocol'
        ), 400);
        exit;
    }

    // Get and validate parameters
    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    $order_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
    $signature = isset($_GET['sig']) ? sanitize_text_field($_GET['sig']) : '';

    if (!$order_id || !$order_key || !$signature) {
        wp_send_json_error(array(
            'message' => 'Missing required parameters'
        ), 400);
        exit;
    }

    // Load order
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array(
            'message' => 'Order not found'
        ), 404);
        exit;
    }

    // Verify order key
    if ($order->get_order_key() !== $order_key) {
        wp_send_json_error(array(
            'message' => 'Invalid order key'
        ), 403);
        exit;
    }

    // Verify signature (HMAC to prevent tampering)
    $expected_sig = BWWC__generate_invoice_signature($order_id, $order_key);
    if (!hash_equals($expected_sig, $signature)) {
        wp_send_json_error(array(
            'message' => 'Invalid signature'
        ), 403);
        exit;
    }

    // Check if order is still payable
    $payment_state = get_post_meta($order_id, 'bsv_payment_state', true);
    if (!in_array($payment_state, array('waiting', 'underpaid', 'pending', 'detected'))) {
        wp_send_json_error(array(
            'message' => 'Order is no longer accepting payment',
            'state' => $payment_state
        ), 410);
        exit;
    }

    // Get payment details
    $bsv_address = get_post_meta($order_id, 'bsv_address', true);
    $bsv_amount = get_post_meta($order_id, 'bsv_amount', true);
    $expected_sats = get_post_meta($order_id, 'bsv_expected_sats', true);
    $expires_at = get_post_meta($order_id, 'bsv_expires_at', true);

    if (!$bsv_address || !$bsv_amount || !$expected_sats) {
        wp_send_json_error(array(
            'message' => 'Payment details not available'
        ), 500);
        exit;
    }

    // Check expiration
    if ($expires_at && time() > $expires_at) {
        wp_send_json_error(array(
            'message' => 'Invoice expired',
            'expires' => date('c', $expires_at)
        ), 410);
        exit;
    }

    // Build locking script for P2PKH address
    $locking_script = BWWC__address_to_locking_script($bsv_address);
    if (!$locking_script) {
        wp_send_json_error(array(
            'message' => 'Failed to generate payment script'
        ), 500);
        exit;
    }

    // Build BIP270 PaymentTerms response
    $payment_terms = array(
        'network' => 'bitcoin-sv',
        'creationTimestamp' => time(),
        'expirationTimestamp' => $expires_at ?: (time() + 3600),
        'memo' => sprintf('Payment for WooCommerce Order #%d', $order_id),
        'paymentUrl' => BWWC__get_payment_callback_url($order_id, $order_key),
        'merchantData' => array(
            'orderId' => $order_id,
            'orderKey' => $order_key,
            'plugin' => 'bsv-woocommerce-gateway',
            'version' => BWWC_VERSION
        ),
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

    // Return PaymentTerms JSON
    echo wp_json_encode($payment_terms, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
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
 * Generate signed invoice URL for BIP270 QR code
 */
function BWWC__get_invoice_url($order_id, $order_key) {
    $signature = BWWC__generate_invoice_signature($order_id, $order_key);
    
    $params = array(
        'order_id' => $order_id,
        'key' => $order_key,
        'sig' => $signature
    );
    
    return add_query_arg($params, home_url('/wc-api/bsv_invoice'));
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
