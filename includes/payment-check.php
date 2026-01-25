<?php
/**
 * Payment Check - Address payment evaluation and verification
 *
 * Aggregates transactions, checks confirmations, handles under/overpayment.
 *
 * @package BSV_WooCommerce_Gateway
 * @since 6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check payment status for an order
 *
 * @param int  $order_id Order ID
 * @param bool $force Force check even if recently checked
 * @return array Payment check result with keys: state, amount_received, confirmations, txids
 */
function BWWC__check_order_payment( $order_id, $force = false ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return array(
			'state'           => BWWC_PAYMENT_STATE_WAITING,
			'amount_received' => 0,
			'confirmations'   => 0,
			'txids'           => array(),
		);
	}

	// Get payment address
	$address = $order->get_meta( '_bwwc_address', true );
	if ( empty( $address ) ) {
		BWWC__log_error( 'No payment address found for order #' . $order_id );
		return array(
			'state'           => BWWC_PAYMENT_STATE_WAITING,
			'amount_received' => 0,
			'confirmations'   => 0,
			'txids'           => array(),
		);
	}

	// Rate limiting: don't check too frequently unless forced
	if ( ! $force ) {
		$last_checked = $order->get_meta( '_bwwc_last_payment_check', true );
		if ( $last_checked && ( time() - $last_checked ) < 30 ) {
			// Return cached result
			return array(
				'state'           => BWWC__get_payment_state( $order_id ),
				'amount_received' => (float) $order->get_meta( '_bwwc_amount_received', true ),
				'confirmations'   => (int) $order->get_meta( '_bwwc_confirmations', true ),
				'txids'           => $order->get_meta( '_bwwc_txids', true ) ?: array(),
			);
		}
	}

	// Get expected amount
	$expected_sats = (int) $order->get_meta( '_bwwc_expected_sats', true );
	if ( $expected_sats <= 0 ) {
		BWWC__log_error( 'Invalid expected_sats for order #' . $order_id );
		return array(
			'state'           => BWWC_PAYMENT_STATE_WAITING,
			'amount_received' => 0,
			'confirmations'   => 0,
			'txids'           => array(),
		);
	}

	// Fetch transactions from blockchain with failover
	$settings  = BWWC__get_settings();
	$providers = ! empty( $settings['blockchain_providers'] )
		? explode( ',', $settings['blockchain_providers'] )
		: array( 'whatsonchain', 'bitails' );

	$transactions = BWWC__provider_with_failover(
		'BWWC__get_address_transactions',
		array( $address ),
		$providers
	);

	if ( $transactions === false ) {
		BWWC__log_error( 'Failed to fetch transactions for address: ' . $address );
		return array(
			'state'           => BWWC__get_payment_state( $order_id ),
			'amount_received' => 0,
			'confirmations'   => 0,
			'txids'           => array(),
		);
	}

	// Aggregate payments
	$result = BWWC__aggregate_payments( $transactions, $address, $expected_sats );

	// Update order meta
	$order->update_meta_data( '_bwwc_amount_received', $result['amount_received'] );
	$order->update_meta_data( '_bwwc_confirmations', $result['confirmations'] );
	$order->update_meta_data( '_bwwc_txids', $result['txids'] );
	$order->update_meta_data( '_bwwc_last_payment_check', time() );
	$order->save();

	// Determine new payment state
	$new_state = BWWC__determine_payment_state( $result, $expected_sats, $settings );

	// Update payment state if changed
	$current_state = BWWC__get_payment_state( $order_id );
	if ( $new_state !== $current_state ) {
		BWWC__set_payment_state( $order_id, $new_state, 'Payment check result' );
	}

	$result['state'] = $new_state;
	return $result;
}

/**
 * Aggregate payments from transactions
 *
 * @param array  $transactions Transaction list
 * @param string $address Payment address
 * @param int    $expected_sats Expected amount in satoshis
 * @return array Aggregated result
 */
function BWWC__aggregate_payments( $transactions, $address, $expected_sats ) {
	$total_received    = 0;
	$min_confirmations = PHP_INT_MAX;
	$txids             = array();

	$current_height = BWWC__provider_with_failover(
		'BWWC__get_blockchain_height',
		array(),
		array( 'whatsonchain', 'bitails' )
	);

	foreach ( $transactions as $tx ) {
		// Calculate amount received to this address
		$amount_to_address = 0;

		if ( isset( $tx['vout'] ) ) {
			foreach ( $tx['vout'] as $output ) {
				if ( isset( $output['scriptPubKey']['addresses'] ) &&
					in_array( $address, $output['scriptPubKey']['addresses'], true ) ) {
					$amount_to_address += (float) $output['value'];
				}
			}
		}

		if ( $amount_to_address > 0 ) {
			$total_received += $amount_to_address;
			$txids[]         = $tx['txid'];

			// Calculate confirmations
			if ( isset( $tx['confirmations'] ) ) {
				$confs = (int) $tx['confirmations'];
			} elseif ( isset( $tx['blockheight'] ) && $current_height ) {
				$confs = $current_height - (int) $tx['blockheight'] + 1;
			} else {
				$confs = 0; // Unconfirmed
			}

			$min_confirmations = min( $min_confirmations, $confs );
		}
	}

	// Convert BSV to satoshis
	$total_sats = (int) round( $total_received * 100000000 );

	if ( $min_confirmations === PHP_INT_MAX ) {
		$min_confirmations = 0;
	}

	return array(
		'amount_received' => $total_sats,
		'confirmations'   => $min_confirmations,
		'txids'           => $txids,
	);
}

/**
 * Determine payment state based on check result
 *
 * @param array $result Payment check result
 * @param int   $expected_sats Expected amount in satoshis
 * @param array $settings Plugin settings
 * @return string Payment state
 */
function BWWC__determine_payment_state( $result, $expected_sats, $settings ) {
	$received_sats = $result['amount_received'];
	$confirmations = $result['confirmations'];

	// No payment received
	if ( $received_sats === 0 ) {
		return BWWC_PAYMENT_STATE_WAITING;
	}

	// Required confirmations
	$required_confs = isset( $settings['confs_num'] ) ? (int) $settings['confs_num'] : 1;

	// Underpaid
	if ( $received_sats < $expected_sats ) {
		// Allow small tolerance (0.1%)
		$tolerance = max( 1000, (int) ( $expected_sats * 0.001 ) ); // 1000 sats or 0.1%
		if ( ( $expected_sats - $received_sats ) > $tolerance ) {
			return BWWC_PAYMENT_STATE_UNDERPAID;
		}
	}

	// Overpaid
	if ( $received_sats > $expected_sats ) {
		$tolerance = max( 1000, (int) ( $expected_sats * 0.001 ) );
		if ( ( $received_sats - $expected_sats ) > $tolerance ) {
			return BWWC_PAYMENT_STATE_OVERPAID;
		}
	}

	// Payment amount is correct (within tolerance)
	if ( $confirmations >= $required_confs ) {
		return BWWC_PAYMENT_STATE_VERIFIED;
	} else {
		return BWWC_PAYMENT_STATE_DETECTED;
	}
}

/**
 * Force payment check for an order (with rate limiting)
 *
 * @param int $order_id Order ID
 * @return array Payment check result
 */
function BWWC__force_payment_check( $order_id ) {
	// Rate limit forced checks (max once per 10 seconds)
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return array( 'error' => 'Order not found' );
	}

	$last_forced = $order->get_meta( '_bwwc_last_forced_check', true );
	if ( $last_forced && ( time() - $last_forced ) < 10 ) {
		return array( 'error' => 'Please wait before checking again' );
	}

	$order->update_meta_data( '_bwwc_last_forced_check', time() );
	$order->save();

	return BWWC__check_order_payment( $order_id, true );
}
