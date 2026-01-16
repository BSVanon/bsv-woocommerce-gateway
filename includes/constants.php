<?php
/**
 * Constants - Plugin constants and version information
 * 
 * @package BSV_WooCommerce_Gateway
 * @since 6.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical payment states
 * 
 * These are the only valid payment states in v6+
 */
define('BWWC_PAYMENT_STATE_WAITING', 'waiting');       // Order created, waiting for payment
define('BWWC_PAYMENT_STATE_DETECTED', 'detected');     // Payment seen on blockchain (0-conf)
define('BWWC_PAYMENT_STATE_VERIFIED', 'verified');     // Payment confirmed (required confs met)
define('BWWC_PAYMENT_STATE_EXPIRED', 'expired');       // Payment window expired
define('BWWC_PAYMENT_STATE_UNDERPAID', 'underpaid');   // Partial payment received
define('BWWC_PAYMENT_STATE_OVERPAID', 'overpaid');     // More than expected received
define('BWWC_PAYMENT_STATE_CONFLICT', 'conflict');     // Multiple conflicting transactions

/**
 * Get all valid payment states
 * 
 * @return array List of valid payment states
 */
function BWWC__get_valid_payment_states()
{
    return array(
        BWWC_PAYMENT_STATE_WAITING,
        BWWC_PAYMENT_STATE_DETECTED,
        BWWC_PAYMENT_STATE_VERIFIED,
        BWWC_PAYMENT_STATE_EXPIRED,
        BWWC_PAYMENT_STATE_UNDERPAID,
        BWWC_PAYMENT_STATE_OVERPAID,
        BWWC_PAYMENT_STATE_CONFLICT,
    );
}

/**
 * Validate payment state
 * 
 * @param string $state State to validate
 * @return bool True if valid state
 */
function BWWC__is_valid_payment_state($state)
{
    return in_array($state, BWWC__get_valid_payment_states(), true);
}

/**
 * Get human-readable payment state label
 * 
 * @param string $state Payment state
 * @return string Human-readable label
 */
function BWWC__get_payment_state_label($state)
{
    $labels = array(
        BWWC_PAYMENT_STATE_WAITING   => __('Waiting for Payment', 'bitcoin-payments-for-woocommerce'),
        BWWC_PAYMENT_STATE_DETECTED  => __('Payment Detected', 'bitcoin-payments-for-woocommerce'),
        BWWC_PAYMENT_STATE_VERIFIED  => __('Payment Verified', 'bitcoin-payments-for-woocommerce'),
        BWWC_PAYMENT_STATE_EXPIRED   => __('Payment Expired', 'bitcoin-payments-for-woocommerce'),
        BWWC_PAYMENT_STATE_UNDERPAID => __('Underpaid', 'bitcoin-payments-for-woocommerce'),
        BWWC_PAYMENT_STATE_OVERPAID  => __('Overpaid', 'bitcoin-payments-for-woocommerce'),
        BWWC_PAYMENT_STATE_CONFLICT  => __('Payment Conflict', 'bitcoin-payments-for-woocommerce'),
    );
    
    return isset($labels[$state]) ? $labels[$state] : $state;
}
