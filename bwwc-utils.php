<?php
/*
Bitcoin SV Payments for WooCommerce
https://github.com/mboyd1/bsvanon-bitcoin-sv-payments
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load modularized components (v6.0.0)
require_once __DIR__ . '/includes/address-generation.php';
require_once __DIR__ . '/includes/blockchain-api.php';
require_once __DIR__ . '/includes/exchange-rates.php';
require_once __DIR__ . '/includes/gateway-validation.php';
require_once __DIR__ . '/includes/string-utilities.php';

// ===========================================================================
/*
	Input:
	------
		$order_info =
		array (
			'order_id'        => $order_id,
			'order_total'     => $order_total_in_btc,
			'order_datetime'  => date('Y-m-d H:i:s T'),
			'requested_by_ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
			);
*/
// Returns:
// --------
/*
	$ret_info_array = array (
		'result'                      => 'success', // OR 'error'
		'message'                     => '...',
		'host_reply_raw'              => '......',
		'generated_bitcoin_address'   => '18vzABPyVbbia8TDCKDtXJYXcoAFAPk2cj', // or false
		);
*/

// ===========================================================================
// Address generation, blockchain API, and metadata functions moved to:
// - includes/address-generation.php
// - includes/blockchain-api.php
// ===========================================================================

// ===========================================================================
// Legacy blockchain.info address generation (deprecated, unused in v6.0.0)
// ===========================================================================

// ===========================================================================
// Exchange rate functions moved to includes/exchange-rates.php
// ===========================================================================

// ===========================================================================
// String utilities, email, and validation functions moved to:
// - includes/string-utilities.php (safe_string_escape, send_email, SubIns, base64 functions)
// - includes/gateway-validation.php (is_gateway_valid_for_use)
// ===========================================================================

// ===========================================================================
// HTTP and utility functions moved to:
// - BWWC__file_get_contents() - deprecated wrapper in this file (uses http-wrapper.php)
// - BWWC__object_to_array() - moved to includes/string-utilities.php
// - BWWC__get_current_chain_height() - moved to includes/blockchain-api.php
// ===========================================================================

// ===========================================================================
// v6.0.0: Removed legacy BWWC__log_event() function
// Now provided by includes/logging.php with WooCommerce logger integration
// Old function used file-based logging with potential disk bloat issues
// ===========================================================================

// ===========================================================================
// v6.0.0: Removed remaining duplicate functions (file_get_contents, object_to_array, get_current_chain_height, safe_string_escape, SubIns, send_email, is_gateway_valid_for_use, base64 functions)
// ===========================================================================
