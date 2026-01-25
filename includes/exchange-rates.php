<?php
/*
Bitcoin SV Payments for WooCommerce - Exchange Rate Module
https://github.com/mboyd1/bsvanon-bitcoin-sv-payments
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ===========================================================================
// Returns:
// success: number of currency units (dollars, etc...) would take to convert to 1 bitcoin, ex: "15.32476".
// failure: false
//
// $currency_code, one of: USD, AUD, CAD, CHF, CNY, DKK, EUR, GBP, HKD, JPY, NZD, PLN, RUB, SEK, SGD, THB
// $rate_retrieval_method
// 'getfirst' -- pick first successfully retireved rate
// 'getall'   -- retrieve from all possible exchange rate services and then pick the best rate.
//
// $get_ticker_string - true - HTML formatted text message instead of pure number returned.

function BWWC__get_exchange_rate_per_bitcoin( $currency_code, $rate_retrieval_method = 'getfirst', $get_ticker_string = false ) {
	if ( $currency_code == 'BTC' ) {
		return '1.00';
	}   // 1:1

	// Do not limit support with present list of currencies. This was originally created because exchange rate APIs did not support many, but today
	// they do support many more currencies, hence this check is removed for now.
	// if (!@in_array($currency_code, BWWC__get_settings ('supported_currencies_arr')))
	// return false;

	// Exchange rate data is provided through our modular providers (see includes/providers/).
	// Primary: CoinGecko (https://api.coingecko.com)
	// Fallback: CoinPaprika (https://api.coinpaprika.com)

	$bwwc_settings       = BWWC__get_settings();
	$exchange_rate_type  = $bwwc_settings['exchange_rate_type'];
	$exchange_multiplier = $bwwc_settings['exchange_multiplier'];
	if ( ! $exchange_multiplier ) {
		$exchange_multiplier = 1;
	}

	$current_time                = time();
	$cache_hit                   = false;
	$requested_cache_method_type = $rate_retrieval_method . '|' . $exchange_rate_type;
	$ticker_string               = "<span style='color:#222;'>According to your settings (including multiplier), current calculated rate for 1 Bitcoin SV (in {$currency_code})={{{EXCHANGE_RATE}}}</span>";
	$ticker_string_error         = "<span style='color:red;background-color:#FFA'>WARNING: Cannot determine exchange rates (for '$currency_code')! {{{ERROR_MESSAGE}}} Make sure your PHP settings are configured properly and your server can (is allowed to) connect to external WEB services via PHP.</wspan>";

	$this_currency_info = @$bwwc_settings['exchange_rates'][ $currency_code ][ $requested_cache_method_type ];

	if ( $this_currency_info && isset( $this_currency_info['time-last-checked'] ) ) {
		$delta = $current_time - $this_currency_info['time-last-checked'];
		if ( $delta < ( @$bwwc_settings['cache_exchange_rates_for_minutes'] * 60 ) ) {

			// Exchange rates cache hit
			// Use cached value as it is still fresh.
			$final_rate = $this_currency_info['exchange_rate'] / $exchange_multiplier;
			if ( $get_ticker_string ) {
				return str_replace( '{{{EXCHANGE_RATE}}}', $final_rate, $ticker_string );
			} else {
				return $final_rate;
			}
		}
	}

	$rates = array();

	// Use new modular provider system with failover (v6.0.0)
	$exchange_rate = BWWC__get_exchange_rate( $currency_code, 'coingecko' );

	// If CoinGecko fails, try CoinPaprika fallback
	if ( ! $exchange_rate ) {
		$exchange_rate = BWWC__get_exchange_rate( $currency_code, 'coinpaprika' );
	}

	if ( $exchange_rate ) {
		// Save new currency exchange rate info in cache
		BWWC__update_exchange_rate_cache( $currency_code, $requested_cache_method_type, $exchange_rate );
	}

	if ( $get_ticker_string ) {
		if ( $exchange_rate ) {
			return str_replace( '{{{EXCHANGE_RATE}}}', $exchange_rate / $exchange_multiplier, $ticker_string );
		} else {
			$extra_error_message = '';
			$fns                 = array( 'file_get_contents', 'curl_init', 'curl_setopt', 'curl_setopt_array', 'curl_exec' );
			$fns                 = array_filter( $fns, 'BWWC__function_not_exists' );

			if ( count( $fns ) ) {
				$extra_error_message = 'The following PHP functions are disabled on your server: ' . implode( ', ', $fns ) . '.';
			}

			return str_replace( '{{{ERROR_MESSAGE}}}', $extra_error_message, $ticker_string_error );
		}
	} else {
		return $exchange_rate / $exchange_multiplier;
	}
}
// ===========================================================================

// ===========================================================================
function BWWC__function_not_exists( $fname ) {
	return ! function_exists( $fname );
}
// ===========================================================================

// ===========================================================================
function BWWC__update_exchange_rate_cache( $currency_code, $requested_cache_method_type, $exchange_rate ) {
	// Save new currency exchange rate info in cache
	$bwwc_settings = BWWC__get_settings();   // Re-get settings in case other piece updated something while we were pulling exchange rate API's...
	$bwwc_settings['exchange_rates'][ $currency_code ][ $requested_cache_method_type ]['time-last-checked'] = time();
	$bwwc_settings['exchange_rates'][ $currency_code ][ $requested_cache_method_type ]['exchange_rate']     = $exchange_rate;
	BWWC__update_settings( $bwwc_settings );
}
// ===========================================================================

// ===========================================================================
// REMOVED: Legacy rate provider functions (v6.0.0)
// - BWWC__get_exchange_rate_from_coingecko() - replaced by includes/providers/coingecko.php
// - BWWC__get_exchange_rate_from_blockchair() - removed (broken API, replaced with CoinPaprika)
// - BWWC__get_exchange_rate_from_coinmarketcap() - removed (legacy, unused)
// - BWWC__get_exchange_rate_from_bitpay() - removed (dead XXX placeholder URL)
//
// Use new modular providers: BWWC__get_exchange_rate($currency, $provider)
// Available providers: 'coingecko' (primary), 'coinpaprika' (fallback)
// ===========================================================================
