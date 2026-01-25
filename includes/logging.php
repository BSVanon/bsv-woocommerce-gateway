<?php
/**
 * Logging - WooCommerce logger wrapper with proper log levels
 *
 * Replaces direct file logging with WooCommerce's built-in logger.
 * Only logs important events (state transitions, errors, provider failures).
 *
 * @package BSV_WooCommerce_Gateway
 * @since 6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get WooCommerce logger instance
 *
 * @return WC_Logger|null Logger instance or null if WooCommerce not available
 */
function BWWC__get_logger() {
	if ( function_exists( 'wc_get_logger' ) ) {
		return wc_get_logger();
	}
	return null;
}

/**
 * Check if debug logging is enabled
 *
 * @return bool True if debug mode enabled
 */
function BWWC__is_debug_mode() {
	static $cached = null;
	if ( $cached !== null ) {
		return $cached;
	}

	$settings = BWWC__get_settings();
	$enabled  = ! empty( $settings['debug_mode'] );

	if ( defined( 'BWWC_DEBUG' ) ) {
		$enabled = $enabled || (bool) BWWC_DEBUG;
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$enabled = true;
	}

	$cached = $enabled;
	return $cached;
}

/**
 * Log an event (replaces BWWC__log_event)
 *
 * @param string $file Source file (use __FILE__)
 * @param int    $line Source line (use __LINE__)
 * @param string $message Log message
 * @param string $level Log level: debug, info, notice, warning, error, critical
 */
function BWWC__log_event( $file, $line, $message, $level = 'info' ) {
	// Only log debug messages if debug mode is enabled
	if ( $level === 'debug' && ! BWWC__is_debug_mode() ) {
		return;
	}

	$logger = BWWC__get_logger();
	if ( ! $logger ) {
		return; // WooCommerce not available
	}

	// Redact sensitive information
	$message = BWWC__redact_sensitive_data( $message );

	// Format message with file/line context
	$context_info      = basename( $file ) . ':' . $line;
	$formatted_message = '[' . $context_info . '] ' . $message;

	// Log to WooCommerce logger with appropriate level
	$logger->log( $level, $formatted_message, array( 'source' => 'bsv-woocommerce-gateway' ) );
}

/**
 * Redact sensitive information from log messages
 *
 * @param string $message Message to redact
 * @return string Redacted message
 */
function BWWC__redact_sensitive_data( $message ) {
	// Redact anything that looks like a secret key or API key
	$message = preg_replace( '/secret[_-]?key[\'"]?\s*[:=]\s*[\'"]?([a-zA-Z0-9]+)[\'"]?/i', 'secret_key=***REDACTED***', $message );
	$message = preg_replace( '/api[_-]?key[\'"]?\s*[:=]\s*[\'"]?([a-zA-Z0-9]+)[\'"]?/i', 'api_key=***REDACTED***', $message );

	// Redact extended public keys (xpub)
	$message = preg_replace( '/xpub[a-zA-Z0-9]{100,}/', 'xpub***REDACTED***', $message );

	// Redact private keys if somehow logged
	$message = preg_replace( '/xprv[a-zA-Z0-9]{100,}/', 'xprv***REDACTED***', $message );

	return $message;
}

/**
 * Log payment state transition
 *
 * @param int    $order_id Order ID
 * @param string $old_state Previous state
 * @param string $new_state New state
 * @param string $reason Reason for transition
 */
function BWWC__log_payment_state_transition( $order_id, $old_state, $new_state, $reason = '' ) {
	$message = sprintf(
		'Order #%d payment state: %s → %s%s',
		$order_id,
		$old_state ?: 'none',
		$new_state,
		$reason ? ' (' . $reason . ')' : ''
	);

	BWWC__log_event( __FILE__, __LINE__, $message, 'info' );
}

/**
 * Log provider failure
 *
 * @param string $provider Provider name (e.g., 'WhatsOnChain', 'CoinGecko')
 * @param string $operation Operation attempted (e.g., 'get_balance', 'get_rate')
 * @param string $error Error message
 */
function BWWC__log_provider_failure( $provider, $operation, $error ) {
	$message = sprintf(
		'Provider %s failed: %s - %s',
		$provider,
		$operation,
		$error
	);

	BWWC__log_event( __FILE__, __LINE__, $message, 'warning' );
}

/**
 * Log critical error
 *
 * @param string $message Error message
 * @param array  $context Additional context
 */
function BWWC__log_error( $message, $context = array() ) {
	if ( ! empty( $context ) ) {
		$message .= ' | Context: ' . wp_json_encode( $context );
	}

	BWWC__log_event( __FILE__, __LINE__, $message, 'error' );
}

/**
 * Log debug information (only when debug mode enabled)
 *
 * @param string $message Debug message
 */
function BWWC__log_debug( $message ) {
	BWWC__log_event( __FILE__, __LINE__, $message, 'debug' );
}
