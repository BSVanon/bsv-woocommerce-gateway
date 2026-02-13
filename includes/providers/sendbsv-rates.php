<?php
/**
 * SendBSV Rates Provider - Exchange rate provider for hosted invoicing
 *
 * Provides exchange rates from SendBSV Invoicing service with fallback to CoinGecko.
 * This provider is prioritized when hosted invoicing mode is active.
 *
 * @package BSV_WooCommerce_Gateway
 * @since 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get BSV exchange rate from SendBSV Invoicing service
 *
 * @param string $currency Target currency (e.g., 'USD', 'EUR')
 * @return float|false Exchange rate, or false on failure
 */
function BWWC__sendbsv_get_rate( $currency = 'USD' ) {
	$currency = strtoupper( $currency );
	
	// Check if hosted invoicing is configured
	$bwwc_settings = BWWC__get_settings();
	$processing_mode = isset( $bwwc_settings['processing_mode'] ) ? $bwwc_settings['processing_mode'] : 'standalone_xpub';
	
	// Only use SendBSV rates when in hosted invoicing mode and connected
	if ( $processing_mode !== 'hosted_invoicing' || 
	     empty( $bwwc_settings['hosted_connector_key'] ) || 
	     $bwwc_settings['hosted_connection_state'] !== 'connected' ) {
		// Fall back to CoinGecko if not configured for SendBSV rates
		return BWWC__coingecko_get_rate( $currency );
	}
	
	// Check cache first
	$cache_key   = 'bwwc_sendbsv_rate_' . strtolower( $currency );
	$cached_rate = get_transient( $cache_key );
	
	if ( $cached_rate !== false ) {
		BWWC__log_debug( 'SendBSV rate cache hit for ' . $currency );
		return (float) $cached_rate;
	}
	
	// Build API URL
	$api_base_url = ! empty( $bwwc_settings['hosted_api_base_url'] ) 
		? $bwwc_settings['hosted_api_base_url'] 
		: 'https://sendbsv-invoicing.proud-mode-7a3d.workers.dev';
	
	$url = rtrim( $api_base_url, '/' ) . '/api/v1/rates?currency=' . strtolower( $currency );
	
	// Prepare request
	$timeout = isset( $bwwc_settings['hosted_timeout_ms'] ) 
		? intval( $bwwc_settings['hosted_timeout_ms'] ) / 1000 
		: 30;
	
	$args = array(
		'timeout' => $timeout,
		'headers' => array(
			'Authorization' => 'Bearer ' . $bwwc_settings['hosted_connector_key'],
			'Content-Type' => 'application/json',
		),
	);
	
	// Fetch from API
	$response = wp_remote_get( $url, $args );
	
	if ( is_wp_error( $response ) ) {
		BWWC__log_provider_failure( 'SendBSV', 'get_rate', 'HTTP request failed: ' . $response->get_error_message() );
		// Fall back to CoinGecko
		return BWWC__coingecko_get_rate( $currency );
	}
	
	$status_code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );
	
	if ( $status_code !== 200 || ! isset( $data['rate'] ) ) {
		BWWC__log_provider_failure( 'SendBSV', 'get_rate', 'Invalid response (HTTP ' . $status_code . '): ' . $body );
		// Fall back to CoinGecko
		return BWWC__coingecko_get_rate( $currency );
	}
	
	$rate = (float) $data['rate'];
	
	if ( $rate <= 0 ) {
		BWWC__log_provider_failure( 'SendBSV', 'get_rate', 'Invalid rate value: ' . $rate );
		// Fall back to CoinGecko
		return BWWC__coingecko_get_rate( $currency );
	}
	
	// Cache for 5 minutes (300 seconds) or use configured duration
	$cache_duration = isset( $bwwc_settings['cache_exchange_rates_for_minutes'] )
		? (int) $bwwc_settings['cache_exchange_rates_for_minutes'] * 60
		: 300;
	
	set_transient( $cache_key, $rate, $cache_duration );
	
	BWWC__log_debug( 'SendBSV rate fetched: 1 BSV = ' . $rate . ' ' . $currency );
	
	return $rate;
}

/**
 * Clear SendBSV rate cache
 *
 * @param string|null $currency Specific currency to clear, or null for all
 */
function BWWC__sendbsv_clear_cache( $currency = null ) {
	if ( $currency ) {
		delete_transient( 'bwwc_sendbsv_rate_' . strtolower( $currency ) );
	} else {
		// Clear all common currencies
		$currencies = array( 'usd', 'eur', 'gbp', 'jpy', 'cny', 'aud', 'cad' );
		foreach ( $currencies as $curr ) {
			delete_transient( 'bwwc_sendbsv_rate_' . $curr );
		}
	}
}

/**
 * Get provider priority based on processing mode
 *
 * @return array Provider names in priority order
 */
function BWWC__sendbsv_get_rate_provider_priority() {
	$bwwc_settings = BWWC__get_settings();
	$processing_mode = isset( $bwwc_settings['processing_mode'] ) ? $bwwc_settings['processing_mode'] : 'standalone_xpub';
	
	if ( $processing_mode === 'hosted_invoicing' && 
	     ! empty( $bwwc_settings['hosted_connector_key'] ) && 
	     $bwwc_settings['hosted_connection_state'] === 'connected' ) {
		// SendBSV rates first, then CoinGecko, then CoinPaprika
		return array( 'sendbsv', 'coingecko', 'coinpaprika' );
	} else {
		// Default priority: CoinGecko first, then CoinPaprika
		return array( 'coingecko', 'coinpaprika' );
	}
}

/**
 * Get exchange rate with automatic provider selection
 *
 * @param string $currency Target currency (e.g., 'USD', 'EUR')
 * @return float|false Exchange rate, or false on failure
 */
function BWWC__get_exchange_rate_with_priority( $currency = 'USD' ) {
	$providers = BWWC__sendbsv_get_rate_provider_priority();
	
	foreach ( $providers as $provider ) {
		switch ( $provider ) {
			case 'sendbsv':
				$rate = BWWC__sendbsv_get_rate( $currency );
				break;
			case 'coingecko':
				$rate = BWWC__coingecko_get_rate( $currency );
				break;
			case 'coinpaprika':
				$rate = BWWC__coinpaprika_get_rate( $currency );
				break;
			default:
				$rate = false;
				break;
		}
		
		if ( $rate !== false ) {
			BWWC__log_debug( 'Exchange rate provider used: ' . $provider . ' for ' . $currency );
			return $rate;
		}
	}
	
	BWWC__log_error( 'All exchange rate providers failed for currency: ' . $currency );
	return false;
}