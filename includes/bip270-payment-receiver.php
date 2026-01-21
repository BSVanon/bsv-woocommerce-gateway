<?php
/**
 * BIP270 Payment Receiver Endpoint
 *
 * Handles POST /wc-api/bsv_payment requests from invoice-capable wallets.
 * Validates order identity, parses raw transactions, verifies payment outputs,
 * updates WooCommerce order state, and returns PaymentACK responses.
 *
 * @package BSV_WooCommerce_Gateway
 * @since 6.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register BIP270 payment endpoint
 */
function BWWC__register_bip270_payment_endpoint() {
    add_action('woocommerce_api_bsv_payment', 'BWWC__receive_bip270_payment');
}
add_action('init', 'BWWC__register_bip270_payment_endpoint');

/**
 * Handle BIP270 payment submissions
 */
function BWWC__receive_bip270_payment() {
    // Enforce HTTPS unless explicitly allowed for debugging
    if (!is_ssl() && !defined('BWWC_ALLOW_HTTP_INVOICE')) {
        BWWC__send_bip270_error(__('HTTPS is required for BIP270 payments.', 'sendbsv-bsv-payments-for-woocommerce'), 400);
    }

    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    $order_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
    $signature = isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : '';

    if (!$order_id || '' === $order_key || '' === $signature) {
        BWWC__send_bip270_error(__('Missing order parameters.', 'sendbsv-bsv-payments-for-woocommerce'), 400);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        BWWC__send_bip270_error(__('Order not found.', 'sendbsv-bsv-payments-for-woocommerce'), 404);
    }

    if ($order->get_order_key() !== $order_key) {
        BWWC__send_bip270_error(__('Invalid order key.', 'sendbsv-bsv-payments-for-woocommerce'), 403);
    }

    $expected_sig = BWWC__generate_invoice_signature($order_id, $order_key);
    if (!hash_equals($expected_sig, $signature)) {
        BWWC__send_bip270_error(__('Invalid signature.', 'sendbsv-bsv-payments-for-woocommerce'), 403);
    }

    // Load payment details
    $bsv_address = get_post_meta($order_id, 'bitcoins_address', true);
    $expected_sats = (int) get_post_meta($order_id, 'expected_sats', true);
    if (!$expected_sats) {
        $expected_sats = (int) $order->get_meta('_bwwc_expected_sats', true);
    }

    if (!$bsv_address || $expected_sats <= 0) {
        BWWC__send_bip270_error(__('Payment details unavailable for this order.', 'sendbsv-bsv-payments-for-woocommerce'), 500);
    }

    $raw_body = file_get_contents('php://input');
    $content_type = isset($_SERVER['CONTENT_TYPE']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['CONTENT_TYPE']))) : '';

    BWWC__log_bip270_debug('Received BIP270 payment payload', array(
        'orderId' => $order_id,
        'contentType' => $content_type ?: '(none)',
        'bodyBytes' => strlen($raw_body),
        'bodyPreviewHex' => substr(bin2hex(substr($raw_body, 0, 64)), 0, 128),
        'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 200) : null,
    ));

    $raw_transactions = BWWC__extract_raw_transactions_from_payload($raw_body, $content_type);

    BWWC__log_bip270_debug('Extracted transactions from payload', array(
        'orderId' => $order_id,
        'txCount' => count($raw_transactions),
    ));

    if (empty($raw_transactions)) {
        BWWC__send_bip270_error(__('No transaction payload provided.', 'sendbsv-bsv-payments-for-woocommerce'), 400);
    }

    // For now support single-transaction payments
    $raw_tx = $raw_transactions[0];
    $raw_tx = strtolower(trim($raw_tx));
    if (!preg_match('/^[0-9a-f]+$/', $raw_tx) || (strlen($raw_tx) % 2) !== 0) {
        BWWC__send_bip270_error(__('Invalid transaction hex payload.', 'sendbsv-bsv-payments-for-woocommerce'), 400);
    }

    $outputs = BWWC__parse_raw_transaction_outputs($raw_tx);
    if (is_wp_error($outputs)) {
        BWWC__send_bip270_error($outputs->get_error_message(), 400);
    }

    $locking_script = BWWC__address_to_locking_script($bsv_address);
    if (!$locking_script) {
        BWWC__send_bip270_error(__('Unable to derive payment script from destination address.', 'sendbsv-bsv-payments-for-woocommerce'), 500);
    }

    $paid_sats = 0;
    foreach ($outputs as $output) {
        if (strcasecmp($output['script'], $locking_script) === 0) {
            $paid_sats += (int) $output['satoshis'];
        }
    }

    if ($paid_sats === 0) {
        BWWC__send_bip270_error(__('Submitted transaction does not pay the expected address.', 'sendbsv-bsv-payments-for-woocommerce'), 422);
    }

    if ($paid_sats < $expected_sats) {
        BWWC__send_bip270_error(__('Submitted transaction is under the required amount.', 'sendbsv-bsv-payments-for-woocommerce'), 422);
    }

    $txid = BWWC__compute_txid_from_raw($raw_tx);
    if (!$txid) {
        $txid = isset($_GET['txid']) ? sanitize_text_field(wp_unslash($_GET['txid'])) : '';
    }
    if (!$txid) {
        BWWC__send_bip270_error(__('Unable to derive transaction ID.', 'sendbsv-bsv-payments-for-woocommerce'), 400);
    }

    // Idempotency check
    $processed_txids = $order->get_meta('_bwwc_bip270_submitted_txids', true);
    if (empty($processed_txids)) {
        $processed_txids = array();
    } elseif (is_string($processed_txids)) {
        $processed_txids = array_filter(explode(',', $processed_txids));
    } elseif (!is_array($processed_txids)) {
        $processed_txids = array();
    }

    $already_processed = in_array($txid, $processed_txids, true);

    if (!$already_processed) {
        $processed_txids[] = $txid;
        $processed_txids = array_values(array_unique($processed_txids));
        $order->update_meta_data('_bwwc_bip270_submitted_txids', $processed_txids);

        // Update legacy txids meta for compatibility
        $existing_txids = get_post_meta($order_id, 'txids', true);
        if (is_string($existing_txids) && $existing_txids !== '') {
            $legacy_txids = array_filter(explode(',', $existing_txids));
        } else {
            $legacy_txids = array();
        }
        $legacy_txids[] = $txid;
        $legacy_txids = array_values(array_unique($legacy_txids));
        update_post_meta($order_id, 'txids', implode(',', $legacy_txids));
        $order->update_meta_data('_bwwc_txids', $legacy_txids);

        // Store payment metadata
        update_post_meta($order_id, 'received_sats', $paid_sats);
        $order->update_meta_data('_bwwc_amount_received', $paid_sats);
        $order->update_meta_data('_bwwc_detected_source', 'bip270');
        $order->update_meta_data('_bwwc_last_payment_activity', time());

        if ($paid_sats > $expected_sats) {
            update_post_meta($order_id, 'payment_state', 'overpaid');
        } else {
            update_post_meta($order_id, 'payment_state', 'pending');
        }

        BWWC__set_payment_state($order_id, BWWC_PAYMENT_STATE_DETECTED, 'BIP270 payment submission');
        $order->add_order_note(
            sprintf(
                /* translators: 1: txid, 2: satoshis */
                __('BIP270 payment received via PaymentACK. TXID: %1$s, Amount: %2$s sats.', 'sendbsv-bsv-payments-for-woocommerce'),
                $txid,
                number_format_i18n($paid_sats)
            )
        );

        $order->save();
    }

    $broadcast_result = BWWC__broadcast_transaction_raw($raw_tx);

    $ack_payload = array(
        'protocol' => 'bip270',
        'network' => 'bitcoin-sv',
        'memo' => sprintf(__('Payment received for Order #%d', 'sendbsv-bsv-payments-for-woocommerce'), $order_id),
        'merchantData' => array(
            'orderId' => $order_id,
            'orderKey' => $order_key,
            'txid' => $txid,
        ),
        'submittedTransactions' => $raw_transactions,
        'amount' => $paid_sats,
        'currency' => 'satoshi',
        'broadcast' => $broadcast_result,
    );

    if (!empty($raw_body) && strlen($raw_body) <= 1048576) {
        $ack_payload['payment'] = array(
            'contentType' => $content_type ?: 'application/payment',
            'bodyBase64' => base64_encode($raw_body),
        );
    }

    BWWC__log_bip270_debug('Sending BIP270 PaymentACK', array(
        'orderId' => $order_id,
        'txid' => $txid,
        'amount' => $paid_sats,
        'keys' => array_keys($ack_payload),
    ));

    BWWC__respond_with_payment_ack($ack_payload);
}

/**
 * Extract raw transactions from incoming payload.
 *
 * @param string $body         Raw request body
 * @param string $content_type Content-Type header
 * @return array Array of hex transactions
 */
function BWWC__extract_raw_transactions_from_payload($body, $content_type) {
    $body = trim((string) $body);
    if ($body === '') {
        return array();
    }

    $transactions = array();

    $is_json = (strpos($content_type, 'application/json') !== false) || (strpos($content_type, '+json') !== false);
    if ($is_json) {
        $data = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            if (isset($data['transactions']) && is_array($data['transactions'])) {
                foreach ($data['transactions'] as $tx_entry) {
                    $hex = BWWC__extract_hex_from_transaction_entry($tx_entry);
                    if ($hex) {
                        $transactions[] = $hex;
                    }
                }
            }

            $single_hex = BWWC__extract_hex_from_transaction_entry($data);
            if ($single_hex) {
                $transactions[] = $single_hex;
            }
        }
    }

    if (strpos($content_type, 'application/payment') !== false) {
        $payment_txs = BWWC__parse_bip270_payment_message_transactions($body);
        if (!empty($payment_txs)) {
            $transactions = array_merge($transactions, $payment_txs);
        }
    }

    if (empty($transactions) && preg_match('/^[0-9a-fA-F]+$/', $body)) {
        $transactions[] = $body;
    }

    return array_values(array_unique(array_map('strtolower', $transactions)));
}

/**
 * Extract transaction hex strings from a binary BIP270 Payment message.
 *
 * @param string $body Raw request body (binary or base64).
 * @return array
 */
function BWWC__parse_bip270_payment_message_transactions($body) {
    if ($body === '') {
        return array();
    }

    $binary = $body;
    $trimmed = trim($body);
    if ($trimmed !== '' && preg_match('/^[A-Za-z0-9+\/\r\n=]+$/', $trimmed) && (strlen($trimmed) % 4 === 0)) {
        $decoded = base64_decode($trimmed, true);
        if ($decoded !== false && $decoded !== '') {
            $binary = $decoded;
        }
    }

    $transactions = array();
    $length = strlen($binary);
    $offset = 0;

    while ($offset < $length) {
        $key = BWWC__read_proto_varint($binary, $offset);
        if ($key === false) {
            break;
        }

        $field_number = $key >> 3;
        $wire_type = $key & 0x07;

        if ($wire_type !== 2) {
            $skip_len = BWWC__read_proto_varint($binary, $offset);
            if ($skip_len === false || ($offset + $skip_len) > $length) {
                break;
            }
            $offset += $skip_len;
            continue;
        }

        $value_len = BWWC__read_proto_varint($binary, $offset);
        if ($value_len === false || ($offset + $value_len) > $length) {
            break;
        }

        $value = substr($binary, $offset, $value_len);
        $offset += $value_len;

        if ($field_number === 2 && $value_len > 0) {
            $transactions[] = strtolower(bin2hex($value));
        }
    }

    return $transactions;
}

/**
 * Read protobuf-style varint from binary buffer.
 *
 * @param string $binary
 * @param int    $offset Reference offset (will be advanced)
 * @return int|false
 */
function BWWC__read_proto_varint($binary, &$offset) {
    $length = strlen($binary);
    $result = 0;
    $shift = 0;

    while ($offset < $length) {
        $byte = ord($binary[$offset++]);
        $result |= (($byte & 0x7F) << $shift);

        if (($byte & 0x80) === 0) {
            return $result;
        }

        $shift += 7;
        if ($shift > 63) {
            break;
        }
    }

    return false;
}

/**
 * Extract hex string from generic transaction entry structure.
 *
 * @param mixed $entry
 * @return string|false
 */
function BWWC__extract_hex_from_transaction_entry($entry) {
    if (is_string($entry) && preg_match('/^[0-9a-fA-F]+$/', $entry)) {
        return $entry;
    }

    if (!is_array($entry)) {
        return false;
    }

    $possible_keys = array('rawTx', 'rawtx', 'hex', 'transaction', 'tx');
    foreach ($possible_keys as $key) {
        if (!empty($entry[$key]) && is_string($entry[$key]) && preg_match('/^[0-9a-fA-F]+$/', $entry[$key])) {
            return $entry[$key];
        }
    }

    return false;
}

/**
 * Parse raw transaction outputs and return satoshis + scripts.
 *
 * @param string $raw_tx Hex transaction
 * @return array|WP_Error
 */
function BWWC__parse_raw_transaction_outputs($raw_tx) {
    $binary = @hex2bin($raw_tx);
    if ($binary === false) {
        return new WP_Error('bip270_invalid_hex', __('Invalid transaction hex.', 'sendbsv-bsv-payments-for-woocommerce'));
    }

    $length = strlen($binary);
    $offset = 0;

    if ($length < 10) {
        return new WP_Error('bip270_tx_too_short', __('Transaction payload too short.', 'sendbsv-bsv-payments-for-woocommerce'));
    }

    // version
    $offset += 4;
    if ($offset >= $length) {
        return new WP_Error('bip270_tx_parse_error', __('Unexpected end of transaction payload.', 'sendbsv-bsv-payments-for-woocommerce'));
    }

    $input_count = BWWC__read_varint($binary, $offset);
    if ($input_count === false) {
        return new WP_Error('bip270_varint_error', __('Failed to parse transaction inputs.', 'sendbsv-bsv-payments-for-woocommerce'));
    }

    for ($i = 0; $i < $input_count; $i++) {
        if (($offset + 36) > $length) {
            return new WP_Error('bip270_input_overflow', __('Malformed transaction input.', 'sendbsv-bsv-payments-for-woocommerce'));
        }
        $offset += 36; // prevout hash + index

        $script_len = BWWC__read_varint($binary, $offset);
        if ($script_len === false || ($offset + $script_len) > $length) {
            return new WP_Error('bip270_script_overflow', __('Malformed input script.', 'sendbsv-bsv-payments-for-woocommerce'));
        }
        $offset += $script_len; // scriptSig

        if (($offset + 4) > $length) {
            return new WP_Error('bip270_sequence_overflow', __('Malformed sequence field.', 'sendbsv-bsv-payments-for-woocommerce'));
        }
        $offset += 4; // sequence
    }

    $output_count = BWWC__read_varint($binary, $offset);
    if ($output_count === false) {
        return new WP_Error('bip270_output_varint_error', __('Failed to parse transaction outputs.', 'sendbsv-bsv-payments-for-woocommerce'));
    }

    $outputs = array();
    for ($i = 0; $i < $output_count; $i++) {
        if (($offset + 8) > $length) {
            return new WP_Error('bip270_amount_overflow', __('Malformed output amount field.', 'sendbsv-bsv-payments-for-woocommerce'));
        }
        $amount_bytes = substr($binary, $offset, 8);
        $satoshis = BWWC__le_bytes_to_int($amount_bytes);
        $offset += 8;

        $script_len = BWWC__read_varint($binary, $offset);
        if ($script_len === false || ($offset + $script_len) > $length) {
            return new WP_Error('bip270_output_script_overflow', __('Malformed output script.', 'sendbsv-bsv-payments-for-woocommerce'));
        }
        $script = substr($binary, $offset, $script_len);
        $offset += $script_len;

        $outputs[] = array(
            'satoshis' => $satoshis,
            'script' => strtolower(bin2hex($script)),
        );
    }

    return $outputs;
}

/**
 * Read Bitcoin varint from binary string.
 *
 * @param string $binary
 * @param int    $offset  (reference)
 * @return int|false
 */
function BWWC__read_varint($binary, &$offset) {
    $length = strlen($binary);
    if ($offset >= $length) {
        return false;
    }

    $prefix = ord($binary[$offset]);
    $offset++;

    if ($prefix < 0xfd) {
        return $prefix;
    }

    if ($prefix === 0xfd) {
        if (($offset + 2) > $length) {
            return false;
        }
        $value = unpack('v', substr($binary, $offset, 2));
        $offset += 2;
        return (int) $value[1];
    }

    if ($prefix === 0xfe) {
        if (($offset + 4) > $length) {
            return false;
        }
        $value = unpack('V', substr($binary, $offset, 4));
        $offset += 4;
        return (int) $value[1];
    }

    if (($offset + 8) > $length) {
        return false;
    }
    $value = unpack('P', substr($binary, $offset, 8));
    $offset += 8;
    return (int) $value[1];
}

/**
 * Convert little-endian bytes to integer.
 *
 * @param string $bytes
 * @return int
 */
function BWWC__le_bytes_to_int($bytes) {
    $value = 0;
    $length = strlen($bytes);
    for ($i = 0; $i < $length; $i++) {
        $value += ord($bytes[$i]) << ($i * 8);
    }
    return (int) $value;
}

/**
 * Compute transaction ID from raw hex (double SHA256, little-endian).
 *
 * @param string $raw_tx
 * @return string|false
 */
function BWWC__compute_txid_from_raw($raw_tx) {
    $binary = @hex2bin($raw_tx);
    if ($binary === false) {
        return false;
    }

    $hash1 = hash('sha256', $binary, true);
    $hash2 = hash('sha256', $hash1, true);
    $txid = bin2hex(strrev($hash2));
    return $txid ?: false;
}

/**
 * Attempt to broadcast raw transaction via external providers (best-effort).
 *
 * @param string $raw_tx Hex transaction
 * @return array
 */
function BWWC__broadcast_transaction_raw($raw_tx) {
    $providers = array(
        array(
            'name' => 'WhatsOnChain',
            'url' => 'https://api.whatsonchain.com/v1/bsv/main/tx/raw',
        ),
        array(
            'name' => 'Bitails',
            'url' => 'https://api.bitails.io/tx/broadcast',
        ),
    );

    foreach ($providers as $provider) {
        $response = BWWC__http_post(
            $provider['url'],
            wp_json_encode(array('txhex' => $raw_tx), JSON_UNESCAPED_SLASHES),
            30,
            array('Content-Type' => 'application/json')
        );

        if ($response !== false) {
            $decoded = json_decode($response, true);
            $txid = '';
            if (is_array($decoded) && isset($decoded['txid'])) {
                $txid = $decoded['txid'];
            } else {
                $txid = trim($response, '"');
            }

            return array(
                'success' => true,
                'provider' => $provider['name'],
                'txid' => $txid,
            );
        }
    }

    return array(
        'success' => false,
        'provider' => null,
    );
}

/**
 * Send JSON error response for BIP270 handler.
 *
 * @param string $message
 * @param int    $status
 */
function BWWC__send_bip270_error($message, $status = 400) {
    BWWC__log_bip270_debug('BIP270 error response', array(
        'status' => (int) $status,
        'message' => $message,
    ));

    status_header($status);
    header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode(array(
        'error' => $message,
    ));
    exit;
}

/**
 * Helper to emit debug logs for BIP270 traffic when debug mode is enabled.
 *
 * @param string $message
 * @param array  $context
 */
function BWWC__log_bip270_debug($message, $context = array()) {
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
 * Send PaymentACK response.
 *
 * @param array $payload
 */
function BWWC__respond_with_payment_ack($payload) {
    status_header(200);
    header('Content-Type: application/payment-ack');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
