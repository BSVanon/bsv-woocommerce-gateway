<?php
/**
 * Bitails Provider - Backup blockchain data provider
 *
 * @package BSV_WooCommerce_Gateway
 * @since 6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get address balance from Bitails
 *
 * @param string $address BSV address
 * @return array|false Array with 'balance' and 'confirmed' keys, or false on failure
 */
function BWWC__bitails_get_balance( $address ) {
	$url = 'https://api.bitails.io/address/' . $address;

	$response = BWWC__http_get( $url, 30 );

	if ( $response === false ) {
		BWWC__log_provider_failure( 'Bitails', 'get_balance', 'HTTP request failed for address: ' . $address );
		return false;
	}

	$data = json_decode( $response, true );

	if ( ! isset( $data['balance'] ) ) {
		BWWC__log_provider_failure( 'Bitails', 'get_balance', 'Invalid response format' );
		return false;
	}

	// Bitails returns balance in satoshis, convert to BSV
	$balance_sats = (float) $data['balance'];
	$balance_bsv  = $balance_sats / 100000000;

	return array(
		'balance'     => $balance_bsv,
		'confirmed'   => $balance_bsv, // Bitails doesn't separate confirmed/unconfirmed
		'unconfirmed' => 0,
	);
}

/**
 * Get address transactions from Bitails
 *
 * @param string $address BSV address
 * @return array|false Array of transactions, or false on failure
 */
function BWWC__bitails_get_transactions( $address ) {
	$url = 'https://api.bitails.io/address/' . $address . '/txs';

	$response = BWWC__http_get( $url, 30 );

	if ( $response === false ) {
		BWWC__log_provider_failure( 'Bitails', 'get_transactions', 'HTTP request failed for address: ' . $address );
		return false;
	}

	$data = json_decode( $response, true );

	if ( ! is_array( $data ) ) {
		BWWC__log_provider_failure( 'Bitails', 'get_transactions', 'Invalid response format' );
		return false;
	}

	return $data;
}

/**
 * Get current blockchain height from Bitails
 *
 * @return int|false Block height, or false on failure
 */
function BWWC__bitails_get_height() {
	// Check cache first (cache for 30 seconds)
	$cache_key     = 'bwwc_bitails_height';
	$cached_height = get_transient( $cache_key );

	if ( $cached_height !== false ) {
		return (int) $cached_height;
	}

	$url = 'https://api.bitails.io/status';

	$response = BWWC__http_get( $url, 15 );

	if ( $response === false ) {
		BWWC__log_provider_failure( 'Bitails', 'get_height', 'HTTP request failed' );
		return false;
	}

	$data = json_decode( $response, true );

	if ( ! isset( $data['height'] ) ) {
		BWWC__log_provider_failure( 'Bitails', 'get_height', 'Invalid response format' );
		return false;
	}

	$height = (int) $data['height'];

	// Cache for 30 seconds
	set_transient( $cache_key, $height, 30 );

	return $height;
}

/**
 * Get transaction details from Bitails
 *
 * @param string $txid Transaction ID
 * @return array|false Transaction data, or false on failure
 */
function BWWC__bitails_get_transaction( $txid ) {
	$url = 'https://api.bitails.io/tx/' . $txid;

	$response = BWWC__http_get( $url, 30 );

	if ( $response === false ) {
		BWWC__log_provider_failure( 'Bitails', 'get_transaction', 'HTTP request failed for txid: ' . $txid );
		return false;
	}

	$data = json_decode( $response, true );

	if ( ! isset( $data['txid'] ) ) {
		BWWC__log_provider_failure( 'Bitails', 'get_transaction', 'Invalid response format' );
		return false;
	}

	return $data;
}
