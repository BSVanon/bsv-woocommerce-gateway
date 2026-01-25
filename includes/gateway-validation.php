<?php
/*
Bitcoin SV Payments for WooCommerce - Gateway Validation Module
https://github.com/mboyd1/bsvanon-bitcoin-sv-payments
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ===========================================================================
function BWWC__is_gateway_valid_for_use( &$ret_reason_message = null ) {
	$valid         = true;
	$bwwc_settings = BWWC__get_settings();

	// 'service_provider'                     =>  'electrum_wallet',    // 'blockchain_info'

	// ----------------------------------
	// Validate settings
	if ( $bwwc_settings['service_provider'] == 'electrum_wallet' ) {
		$mpk = BWWC__get_next_available_mpk();
		if ( ! $mpk ) {
			$reason_message = __( 'Please specify ElectrumSV  Master Public Key (MPK). <br />To retrieve MPK: launch your ElectrumSV wallet, select: Wallet->Information', 'bsvanon-bitcoin-sv-payments' );
			$valid          = false;
		} elseif ( ! preg_match( '/^[a-f0-9]{128}$/', $mpk ) && ! preg_match( '/^xpub[a-zA-Z0-9]{107}$/', $mpk ) ) {
			$reason_message = __( 'ElectrumSV Master Public Key is invalid. Must be 128 or 111 characters long, consisting of digits and letters.', 'bsvanon-bitcoin-sv-payments' );
			$valid          = false;
		} elseif ( ! extension_loaded( 'gmp' ) && ! extension_loaded( 'bcmath' ) ) {
			$reason_message = __( "ERROR: neither 'bcmath' nor 'gmp' math extensions are loaded For ElectrumSV wallet options to function. Contact your hosting company and ask them to enable either 'bcmath' or 'gmp' extensions. 'gmp' is preferred (much faster)!", 'bsvanon-bitcoin-sv-payments' );
			$valid          = false;
		}
	}

	if ( ! $valid ) {
		if ( $ret_reason_message !== null ) {
			$ret_reason_message = $reason_message;
		}
		return false;
	}

	// ----------------------------------

	// ----------------------------------
	// Validate connection to exchange rate services

	$store_currency_code = get_woocommerce_currency();
	if ( $store_currency_code != 'BTC' ) {
		$currency_rate = BWWC__get_exchange_rate_per_bitcoin( $store_currency_code, 'getfirst', false );
		if ( ! $currency_rate ) {
			$valid = false;

			// Assemble error message.
			$error_msg           = "ERROR: Cannot determine exchange rates (for '$store_currency_code')! {{{ERROR_MESSAGE}}} Make sure your PHP settings are configured properly and your server can (is allowed to) connect to external WEB services via PHP.";
			$extra_error_message = '';
			$fns                 = array( 'file_get_contents', 'curl_init', 'curl_setopt', 'curl_setopt_array', 'curl_exec' );
			$fns                 = array_filter( $fns, 'BWWC__function_not_exists' );
			$extra_error_message = '';
			if ( count( $fns ) ) {
				$extra_error_message = 'The following PHP functions are disabled on your server: ' . implode( ', ', $fns ) . '.';
			}

			$reason_message = str_replace( '{{{ERROR_MESSAGE}}}', $extra_error_message, $error_msg );

			if ( $ret_reason_message !== null ) {
				$ret_reason_message = $reason_message;
			}
			return false;
		}

		// Check if exchange rate is too stale based on settings
		$exchange_rate_type          = $bwwc_settings['exchange_rate_type'];
		$requested_cache_method_type = 'getfirst|' . $exchange_rate_type;
		$this_currency_info          = @$bwwc_settings['exchange_rates'][ $store_currency_code ][ $requested_cache_method_type ];

		if ( $this_currency_info && isset( $this_currency_info['time-last-checked'] ) ) {
			$age_in_seconds = time() - $this_currency_info['time-last-checked'];
			$age_in_hours   = $age_in_seconds / 3600;

			// 'vwap' = use last available rate (no age limit)
			// 'realtime' = disable if older than 1 hour
			// 'bestrate' = disable if older than 6 hours
			$max_age_hours = 0;
			if ( $exchange_rate_type == 'realtime' ) {
				$max_age_hours = 1;
			} elseif ( $exchange_rate_type == 'bestrate' ) {
				$max_age_hours = 6;
			}

			if ( $max_age_hours > 0 && $age_in_hours > $max_age_hours ) {
				$valid = false;
				/* translators: 1: current age in hours, 2: maximum allowed hours */
				$reason_message = sprintf(
					__( 'Bitcoin SV payment option is temporarily unavailable. Exchange rate data is %1$s hours old (maximum allowed: %2$s hours). Please try again later or contact the store owner.', 'bsvanon-bitcoin-sv-payments' ),
					number_format( $age_in_hours, 1 ),
					$max_age_hours
				);

				if ( $ret_reason_message !== null ) {
					$ret_reason_message = $reason_message;
				}
				return false;
			}
		}
	}
	// ----------------------------------

	// ----------------------------------
	// NOTE: currenly this check is not performed.
	// Do not limit support with present list of currencies. This was originally created because exchange rate APIs did not support many, but today
	// they do support many more currencies, hence this check is removed for now.

	// Validate currency
	// $currency_code            = get_woocommerce_currency();
	// $supported_currencies_arr = BWWC__get_settings ('supported_currencies_arr');

	// if ($currency_code != 'BTC' && !@in_array($currency_code, $supported_currencies_arr))
	// {
	// $reason_message = __("Store currency is set to unsupported value", 'bsvanon-bitcoin-sv-payments') . "('{$currency_code}'). " . __("Valid currencies: ", 'bsvanon-bitcoin-sv-payments') . implode ($supported_currencies_arr, ", ");
	// if ($ret_reason_message !== NULL)
	// $ret_reason_message = $reason_message;
	// return false;
	// }

	return true;
	// ----------------------------------
}
// ===========================================================================
