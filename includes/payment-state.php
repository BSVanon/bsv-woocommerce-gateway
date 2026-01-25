<?php
/**
 * Payment State Machine - Canonical payment state management
 *
 * Manages payment state transitions with idempotent operations.
 * All state changes go through this module.
 *
 * @package BSV_WooCommerce_Gateway
 * @since 6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get current payment state for an order
 *
 * @param int $order_id Order ID
 * @return string Current payment state (defaults to 'waiting')
 */
function BWWC__get_payment_state( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return BWWC_PAYMENT_STATE_WAITING;
	}

	$state = $order->get_meta( '_bwwc_payment_state', true );

	// Default to 'waiting' if not set
	if ( empty( $state ) ) {
		$state = BWWC_PAYMENT_STATE_WAITING;
	}

	// Validate state
	if ( ! BWWC__is_valid_payment_state( $state ) ) {
		BWWC__log_error( 'Invalid payment state detected for order #' . $order_id . ': ' . $state );
		$state = BWWC_PAYMENT_STATE_WAITING;
	}

	return $state;
}

/**
 * Set payment state for an order (idempotent)
 *
 * @param int    $order_id Order ID
 * @param string $new_state New payment state
 * @param string $reason Optional reason for state change
 * @return bool True on success, false on failure
 */
function BWWC__set_payment_state( $order_id, $new_state, $reason = '' ) {
	// Validate new state
	if ( ! BWWC__is_valid_payment_state( $new_state ) ) {
		BWWC__log_error( 'Attempted to set invalid payment state: ' . $new_state );
		return false;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		BWWC__log_error( 'Order not found: #' . $order_id );
		return false;
	}

	$old_state = BWWC__get_payment_state( $order_id );

	// Idempotent: if already in this state, do nothing
	if ( $old_state === $new_state ) {
		return true;
	}

	// Validate transition is allowed
	if ( ! BWWC__is_valid_state_transition( $old_state, $new_state ) ) {
		BWWC__log_error( 'Invalid state transition: ' . $old_state . ' → ' . $new_state . ' for order #' . $order_id );
		return false;
	}

	// Update state
	$order->update_meta_data( '_bwwc_payment_state', $new_state );
	$order->update_meta_data( '_bwwc_payment_state_changed_at', time() );
	$order->save();

	// Send webhook if payment just became verified
	if ( $new_state === BWWC_PAYMENT_STATE_VERIFIED && $old_state !== BWWC_PAYMENT_STATE_VERIFIED ) {
		BWWC__send_payment_verified_webhook( $order_id );
	}

	// Log transition
	BWWC__log_payment_state_transition( $order_id, $old_state, $new_state, $reason );

	// Add order note
	$note = sprintf(
		/* translators: 1: old payment state, 2: new payment state */
		__( 'Payment state changed: %1$s → %2$s', 'bsvanon-bitcoin-sv-payments' ),
		BWWC__get_payment_state_label( $old_state ),
		BWWC__get_payment_state_label( $new_state )
	);
	if ( $reason ) {
		$note .= ' (' . $reason . ')';
	}
	$order->add_order_note( $note );

	// Trigger state-specific actions
	BWWC__handle_payment_state_change( $order_id, $old_state, $new_state );

	return true;
}

/**
 * Send webhook notification when payment is verified
 *
 * @param int $order_id Order ID
 */
function BWWC__send_payment_verified_webhook( $order_id ) {
	$settings = BWWC__get_settings();
	$url      = $settings['webhook_url'];
	if ( empty( $url ) ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	$payload = array(
		'event'       => 'payment_verified',
		'order_id'    => $order_id,
		'order_key'   => $order->get_order_key(),
		'amount_sats' => $order->get_meta( '_bwwc_expected_sats', true ),
		'txids'       => $order->get_meta( '_bwwc_txids', true ),
		'timestamp'   => time(),
	);

	$secret  = $settings['webhook_secret'];
	$headers = array( 'Content-Type' => 'application/json' );
	if ( $secret ) {
		$signature                  = hash_hmac( 'sha256', wp_json_encode( $payload ), $secret );
		$headers['X-BSV-Signature'] = $signature;
	}

	$response = wp_remote_post(
		$url,
		array(
			'body'    => wp_json_encode( $payload ),
			'headers' => $headers,
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		BWWC__log_event( __FILE__, __LINE__, 'Webhook send failed: ' . $response->get_error_message() );
	} else {
		BWWC__log_event( __FILE__, __LINE__, 'Webhook sent to ' . $url . ' with status ' . wp_remote_retrieve_response_code( $response ) );
	}
}

/**
 * Validate if a state transition is allowed
 *
 * @param string $from_state Current state
 * @param string $to_state Target state
 * @return bool True if transition is valid
 */
function BWWC__is_valid_state_transition( $from_state, $to_state ) {
	// Define allowed transitions
	$allowed_transitions = array(
		BWWC_PAYMENT_STATE_WAITING   => array(
			BWWC_PAYMENT_STATE_DETECTED,
			BWWC_PAYMENT_STATE_VERIFIED,
			BWWC_PAYMENT_STATE_EXPIRED,
			BWWC_PAYMENT_STATE_UNDERPAID,
		),
		BWWC_PAYMENT_STATE_DETECTED  => array(
			BWWC_PAYMENT_STATE_VERIFIED,
			BWWC_PAYMENT_STATE_UNDERPAID,
			BWWC_PAYMENT_STATE_OVERPAID,
			BWWC_PAYMENT_STATE_CONFLICT,
		),
		BWWC_PAYMENT_STATE_UNDERPAID => array(
			BWWC_PAYMENT_STATE_DETECTED,
			BWWC_PAYMENT_STATE_VERIFIED,
			BWWC_PAYMENT_STATE_EXPIRED,
		),
		BWWC_PAYMENT_STATE_EXPIRED   => array(
			BWWC_PAYMENT_STATE_DETECTED, // Late payment
			BWWC_PAYMENT_STATE_VERIFIED,  // Late payment confirmed
		),
		BWWC_PAYMENT_STATE_VERIFIED  => array(
			// Terminal state - no transitions out
		),
		BWWC_PAYMENT_STATE_OVERPAID  => array(
			BWWC_PAYMENT_STATE_VERIFIED,
		),
		BWWC_PAYMENT_STATE_CONFLICT  => array(
			BWWC_PAYMENT_STATE_VERIFIED,
		),
	);

	if ( ! isset( $allowed_transitions[ $from_state ] ) ) {
		return false;
	}

	return in_array( $to_state, $allowed_transitions[ $from_state ], true );
}

/**
 * Handle payment state change side effects
 *
 * @param int    $order_id Order ID
 * @param string $old_state Previous state
 * @param string $new_state New state
 */
function BWWC__handle_payment_state_change( $order_id, $old_state, $new_state ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	$settings = BWWC__get_settings();

	switch ( $new_state ) {
		case BWWC_PAYMENT_STATE_DETECTED:
			// Payment detected (0-conf or unconfirmed)
			// Update order status to 'processing' if configured
			if ( ! empty( $settings['autocomplete_paid_orders'] ) ) {
				$order->update_status( 'processing', __( 'Payment detected on blockchain', 'bsvanon-bitcoin-sv-payments' ) );
			}
			break;

		case BWWC_PAYMENT_STATE_VERIFIED:
			// Payment verified (confirmations met)
			// Complete the order
			if ( ! empty( $settings['autocomplete_paid_orders'] ) ) {
				$order->update_status( 'completed', __( 'Payment verified with required confirmations', 'bsvanon-bitcoin-sv-payments' ) );
			} else {
				$order->update_status( 'processing', __( 'Payment verified with required confirmations', 'bsvanon-bitcoin-sv-payments' ) );
			}
			$order->payment_complete();
			break;

		case BWWC_PAYMENT_STATE_EXPIRED:
			// Payment window expired
			if ( $old_state === BWWC_PAYMENT_STATE_WAITING ) {
				// Only auto-cancel if configured and no payment detected
				if ( ! empty( $settings['delete_expired_unpaid_orders'] ) ) {
					$order->update_status( 'cancelled', __( 'Payment window expired', 'bsvanon-bitcoin-sv-payments' ) );
				}
			}
			break;

		case BWWC_PAYMENT_STATE_UNDERPAID:
			// Partial payment received
			$order->add_order_note( __( 'Partial payment received. Waiting for remaining amount.', 'bsvanon-bitcoin-sv-payments' ) );
			break;

		case BWWC_PAYMENT_STATE_OVERPAID:
			// Overpayment received
			$order->add_order_note( __( 'Overpayment received. Please contact customer to arrange refund.', 'bsvanon-bitcoin-sv-payments' ) );
			break;

		case BWWC_PAYMENT_STATE_CONFLICT:
			// Conflicting transactions detected
			$order->update_status( 'on-hold', __( 'Payment conflict detected. Manual review required.', 'bsvanon-bitcoin-sv-payments' ) );
			break;
	}

	// Handle late payments (payment after expiry)
	if ( $old_state === BWWC_PAYMENT_STATE_EXPIRED &&
		in_array( $new_state, array( BWWC_PAYMENT_STATE_DETECTED, BWWC_PAYMENT_STATE_VERIFIED ), true ) ) {

		$order->update_status( 'on-hold', __( 'Late payment received after expiry. Manual review required.', 'bsvanon-bitcoin-sv-payments' ) );

		// Send admin notification
		BWWC__send_late_payment_notification( $order_id );
	}
}

/**
 * Send late payment notification to admin
 *
 * @param int $order_id Order ID
 */
function BWWC__send_late_payment_notification( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	$to      = get_option( 'admin_email' );
	$subject = sprintf(
		/* translators: 1: site name, 2: order ID */
		__( '[%1$s] Late Payment Received - Order #%2$d', 'bsvanon-bitcoin-sv-payments' ),
		get_bloginfo( 'name' ),
		$order_id
	);

	$message = sprintf(
		/* translators: %d: order ID */
		__( 'A late payment has been received for order #%d after the payment window expired.', 'bsvanon-bitcoin-sv-payments' ),
		$order_id
	) . "\n\n";

	$message .= __( 'Order Details:', 'bsvanon-bitcoin-sv-payments' ) . "\n";
	/* translators: %d: order ID */
	$message .= sprintf( __( 'Order ID: %d', 'bsvanon-bitcoin-sv-payments' ), $order_id ) . "\n";
	/* translators: %s: formatted order total */
	$message .= sprintf( __( 'Order Total: %s', 'bsvanon-bitcoin-sv-payments' ), $order->get_formatted_order_total() ) . "\n";
	/* translators: %s: order edit URL */
	$message .= sprintf( __( 'Order URL: %s', 'bsvanon-bitcoin-sv-payments' ), $order->get_edit_order_url() ) . "\n\n";

	$message .= __( 'Please review this order and decide whether to fulfill it.', 'bsvanon-bitcoin-sv-payments' ) . "\n";

	wp_mail( $to, $subject, $message );
}
