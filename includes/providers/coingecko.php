<?php
/**
 * CoinGecko Provider - Exchange rate provider
 *
 * @package BSV_WooCommerce_Gateway
 * @since 6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get BSV exchange rate from CoinGecko
 *
 * @param string $currency Target currency (e.g., 'USD', 'EUR')
 * @return float|false Exchange rate, or false on failure
 */
function BWWC__coingecko_get_rate( $currency = 'USD' ) {
	$currency = strtolower( $currency );

	// CoinGecko's canonical ID for Bitcoin SV is 'bitcoin-cash-sv'
	// DO NOT CHANGE unless CoinGecko changes it upstream
	$url = 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin-cash-sv&vs_currencies=' . $currency;

	// Check cache first
	$cache_key   = 'bwwc_coingecko_rate_' . $currency;
	$cached_rate = get_transient( $cache_key );

	if ( $cached_rate !== false ) {
		BWWC__log_debug( 'CoinGecko rate cache hit for ' . strtoupper( $currency ) );
		return (float) $cached_rate;
	}

	// Fetch from API
	$response = BWWC__http_get( $url, 15 );

	if ( $response === false ) {
		BWWC__log_provider_failure( 'CoinGecko', 'get_rate', 'HTTP request failed' );
		return false;
	}

	$data = json_decode( $response, true );

	if ( ! isset( $data['bitcoin-cash-sv'][ $currency ] ) ) {
		BWWC__log_provider_failure( 'CoinGecko', 'get_rate', 'Invalid response format' );
		return false;
	}

	$rate = (float) $data['bitcoin-cash-sv'][ $currency ];

	if ( $rate <= 0 ) {
		BWWC__log_provider_failure( 'CoinGecko', 'get_rate', 'Invalid rate value: ' . $rate );
		return false;
	}

	// Cache for 5 minutes (300 seconds)
	$settings       = BWWC__get_settings();
	$cache_duration = isset( $settings['cache_exchange_rates_for_minutes'] )
		? (int) $settings['cache_exchange_rates_for_minutes'] * 60
		: 300;

	set_transient( $cache_key, $rate, $cache_duration );

	BWWC__log_debug( 'CoinGecko rate fetched: 1 BSV = ' . $rate . ' ' . strtoupper( $currency ) );

	return $rate;
}

/**
 * Clear CoinGecko rate cache
 *
 * @param string|null $currency Specific currency to clear, or null for all
 */
function BWWC__coingecko_clear_cache( $currency = null ) {
	if ( $currency ) {
		delete_transient( 'bwwc_coingecko_rate_' . strtolower( $currency ) );
	} else {
		// Clear all common currencies
		$currencies = array( 'usd', 'eur', 'gbp', 'jpy', 'cny', 'aud', 'cad' );
		foreach ( $currencies as $curr ) {
			delete_transient( 'bwwc_coingecko_rate_' . $curr );
		}
	}
}
