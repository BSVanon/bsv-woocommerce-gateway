<?php
/**
 * Provider Interface - Standard interface for blockchain and rate providers
 *
 * All providers (WhatsOnChain, Bitails, CoinGecko, etc.) must implement
 * these methods to ensure consistent behavior and easy swapping.
 *
 * @package BSV_WooCommerce_Gateway
 * @since 6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get balance for a BSV address
 *
 * @param string $address BSV address
 * @param string $provider Provider name ('whatsonchain' or 'bitails')
 * @return array|false Array with 'balance' and 'confirmed' keys, or false on failure
 */
function BWWC__get_address_balance( $address, $provider = 'whatsonchain' ) {
	switch ( $provider ) {
		case 'whatsonchain':
			return BWWC__whatsonchain_get_balance( $address );
		case 'bitails':
			return BWWC__bitails_get_balance( $address );
		default:
			BWWC__log_error( 'Unknown blockchain provider: ' . $provider );
			return false;
	}
}

/**
 * Get transactions for a BSV address
 *
 * @param string $address BSV address
 * @param string $provider Provider name ('whatsonchain' or 'bitails')
 * @return array|false Array of transactions, or false on failure
 */
function BWWC__get_address_transactions( $address, $provider = 'whatsonchain' ) {
	switch ( $provider ) {
		case 'whatsonchain':
			return BWWC__whatsonchain_get_transactions( $address );
		case 'bitails':
			return BWWC__bitails_get_transactions( $address );
		default:
			BWWC__log_error( 'Unknown blockchain provider: ' . $provider );
			return false;
	}
}

/**
 * Get current blockchain height
 *
 * @param string $provider Provider name ('whatsonchain' or 'bitails')
 * @return int|false Block height, or false on failure
 */
function BWWC__get_blockchain_height( $provider = 'whatsonchain' ) {
	switch ( $provider ) {
		case 'whatsonchain':
			return BWWC__whatsonchain_get_height();
		case 'bitails':
			return BWWC__bitails_get_height();
		default:
			BWWC__log_error( 'Unknown blockchain provider: ' . $provider );
			return false;
	}
}

/**
 * Get BSV exchange rate in specified currency
 *
 * @param string $currency Target currency (e.g., 'USD', 'EUR')
 * @param string $provider Provider name ('coingecko' or 'coinpaprika')
 * @return float|false Exchange rate, or false on failure
 */
function BWWC__get_exchange_rate( $currency = 'USD', $provider = 'coingecko' ) {
	switch ( $provider ) {
		case 'coingecko':
			return BWWC__coingecko_get_rate( $currency );
		case 'coinpaprika':
			return BWWC__coinpaprika_get_rate( $currency );
		default:
			BWWC__log_error( 'Unknown rate provider: ' . $provider );
			return false;
	}
}

/**
 * Get list of available blockchain providers
 *
 * @return array Provider names
 */
function BWWC__get_blockchain_providers() {
	return array( 'whatsonchain', 'bitails' );
}

/**
 * Get list of available rate providers
 *
 * @return array Provider names
 */
function BWWC__get_rate_providers() {
	return array( 'coingecko', 'coinpaprika' );
}

/**
 * Get provider with failover
 *
 * Tries primary provider first, falls back to secondary if primary fails.
 *
 * @param callable $callback Function to call (must accept provider name as last param)
 * @param array    $params Parameters to pass to callback
 * @param array    $providers List of providers to try in order
 * @return mixed Result from first successful provider, or false if all fail
 */
function BWWC__provider_with_failover( $callback, $params, $providers ) {
	foreach ( $providers as $provider ) {
		$params[] = $provider;
		$result   = call_user_func_array( $callback, $params );

		if ( $result !== false ) {
			return $result;
		}

		// Remove provider param for next iteration
		array_pop( $params );
	}

	return false;
}
