<?php
/*
Bitcoin SV Payments for WooCommerce - Blockchain API Module
https://github.com/mboyd1/bsvanon-bitcoin-sv-payments
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ===========================================================================
/*
$address_request_array = array (
	'btc_address'            => '1xxxxxxx',
	'required_confirmations' => '6',
	'api_timeout'                      => 10,
	);

$ret_info_array = array (
	'result'                      => 'success',
	'message'                     => "",
	'host_reply_raw'              => "",
	'balance'                     => false == error, else - balance
	);
*/

function BWWC__getreceivedbyaddress_info( $address_request_array, $bwwc_settings = false ) {
	// https://blockchain.bitcoinway.com/?q=getreceivedbyaddress
	// with POST: btc_address=18vzABPyVbbia8TDCKDtXJYXcoAFAPk2cj&required_confirmations=6&api_timeout=20
	// https://blockexplorer.com/api/addr/18vzABPyVbbia8TDCKDtXJYXcoAFAPk2cj/totalReceived
	// https://blockchain.info/q/getreceivedbyaddress/18vzABPyVbbia8TDCKDtXJYXcoAFAPk2cj [?confirmations=6]
	if ( ! $bwwc_settings ) {
		$bwwc_settings = BWWC__get_settings();
	}

	$btc_address            = $address_request_array['btc_address'];
	$required_confirmations = $address_request_array['required_confirmations'];
	$api_timeout            = $address_request_array['api_timeout'];

	if ( $required_confirmations ) {
		$confirmations_url_part_bec = ''; // No longer seems to be available
		$confirmations_url_part_bci = "?confirmations=$required_confirmations";
	} else {
		$confirmations_url_part_bec = '';
		$confirmations_url_part_bci = '';
	}

	$funds_received = false;
	// Removed legacy aggregated API call (dead service)

	$confirmed_sats   = null;
	$unconfirmed_sats = 0;

	if ( ! is_numeric( $funds_received ) ) {
		// Primary provider: WhatsOnChain (returns sats)
		$whatsonchain_response = BWWC__file_get_contents(
			'https://api.whatsonchain.com/v1/bsv/main/address/' . $btc_address . '/balance',
			false,
			$api_timeout
		);
		if ( $whatsonchain_response ) {
			$whatsonchain_json = json_decode( trim( $whatsonchain_response ), true );
			if ( is_array( $whatsonchain_json ) && isset( $whatsonchain_json['confirmed'] ) ) {
				$confirmed_sats = intval( $whatsonchain_json['confirmed'] );
				$funds_received = $confirmed_sats;
				if ( isset( $whatsonchain_json['unconfirmed'] ) ) {
					$unconfirmed_sats = intval( $whatsonchain_json['unconfirmed'] );
				}
			}
		}
	}

	if ( ! is_numeric( $funds_received ) ) {
		// Fallback provider: Bitails (returns sats)
		$bitails_response = BWWC__file_get_contents(
			'https://api.bitails.io/address/' . $btc_address . '/balance',
			false,
			$api_timeout
		);
		if ( $bitails_response ) {
			$bitails_json = json_decode( trim( $bitails_response ), true );
			if ( is_array( $bitails_json ) && isset( $bitails_json['confirmed'] ) ) {
				$confirmed_sats = intval( $bitails_json['confirmed'] );
				$funds_received = $confirmed_sats;
				if ( isset( $bitails_json['unconfirmed'] ) ) {
					$unconfirmed_sats = intval( $bitails_json['unconfirmed'] );
				}
			}
		}
	}

	// Removed legacy bchsvexplorer.com fallbacks (insecure HTTP + unreliable service)

	if ( is_numeric( $funds_received ) ) {
		if ( $confirmed_sats === null ) {
			$confirmed_sats = intval( $funds_received );
		}
		$confirmed_btc   = sprintf( '%.8f', $confirmed_sats / 100000000.0 );
		$total_sats      = $confirmed_sats + $unconfirmed_sats;
		$total_btc       = sprintf( '%.8f', $total_sats / 100000000.0 );
		$unconfirmed_btc = sprintf( '%.8f', $unconfirmed_sats / 100000000.0 );
	}

	if ( is_numeric( $funds_received ) ) {
		$ret_info_array = array(
			'result'              => 'success',
			'message'             => '',
			'host_reply_raw'      => '',
			'balance'             => $confirmed_btc,
			'confirmed_sats'      => $confirmed_sats,
			'unconfirmed_sats'    => $unconfirmed_sats,
			'unconfirmed_balance' => $unconfirmed_btc,
			'total_balance'       => $total_btc,
			'total_sats'          => $total_sats,
		);
	} else {
		$ret_info_array = array(
			'result'         => 'error',
			'message'        => 'Blockchain API failure. Both WhatsOnChain and Bitails providers returned invalid responses.',
			'host_reply_raw' => '',
			'balance'        => false,
		);
	}

	return $ret_info_array;
}
// ===========================================================================

// ===========================================================================
// Fetch and cache current chain height (WhatsOnChain)
function BWWC__get_current_chain_height( $timeout_secs = 30 ) {
	static $cached_height = null;
	static $cached_at     = 0;

	if ( $cached_height !== null && ( time() - $cached_at ) < 60 ) {
		return $cached_height;
	}

	$timeout_secs = intval( $timeout_secs ) > 0 ? intval( $timeout_secs ) : 20;
	$response     = BWWC__file_get_contents(
		'https://api.whatsonchain.com/v1/bsv/main/chain/info',
		false,
		$timeout_secs
	);

	if ( $response ) {
		$data = json_decode( trim( $response ), true );
		if ( is_array( $data ) && isset( $data['blocks'] ) ) {
			$cached_height = intval( $data['blocks'] );
			$cached_at     = time();
			return $cached_height;
		}
	}

	return false;
}
// ===========================================================================
