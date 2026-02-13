<?php
/**
 * Hosted Invoicing Connect Module
 *
 * @package BSV_WooCommerce_Gateway
 * @since 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register hosted connect AJAX handlers.
 */
function BWWC__hosted_connect_init() {
	add_action( 'wp_ajax_bwwc_hosted_connect_start', 'BWWC__ajax_hosted_connect_start' );
	add_action( 'wp_ajax_bwwc_hosted_disconnect', 'BWWC__ajax_hosted_disconnect' );
	add_action( 'wp_ajax_bwwc_hosted_test_connection', 'BWWC__ajax_hosted_test_connection' );
}
add_action( 'init', 'BWWC__hosted_connect_init' );

/**
 * Utility: mask key hints for UI/logging.
 *
 * @param string $value Secret value.
 * @return string
 */
function BWWC__mask_secret_hint( $value ) {
	$value = trim( (string) $value );
	$len   = strlen( $value );
	if ( $len <= 8 ) {
		return str_repeat( '*', max( 4, $len ) );
	}
	return substr( $value, 0, 4 ) . '...' . substr( $value, -4 );
}

/**
 * Utility: for capability checks and hosted calls.
 *
 * @return array<string,mixed>
 */
function BWWC__get_hosted_api_config() {
	$bwwc_settings = BWWC__get_settings();

	return array(
		'base_url' => ! empty( $bwwc_settings['hosted_api_base_url'] )
			? rtrim( $bwwc_settings['hosted_api_base_url'], '/' )
			: 'https://sendbsv-invoicing.proud-mode-7a3d.workers.dev',
		'connector_key'    => isset( $bwwc_settings['hosted_connector_key'] ) ? trim( (string) $bwwc_settings['hosted_connector_key'] ) : '',
		'timeout'          => isset( $bwwc_settings['hosted_timeout_ms'] ) ? max( 1, intval( $bwwc_settings['hosted_timeout_ms'] ) ) / 1000 : 30,
		'webhook_secret'   => isset( $bwwc_settings['hosted_webhook_secret'] ) ? (string) $bwwc_settings['hosted_webhook_secret'] : '',
		'connection_state' => isset( $bwwc_settings['hosted_connection_state'] ) ? (string) $bwwc_settings['hosted_connection_state'] : 'not_connected',
		'connection_ref'   => isset( $bwwc_settings['hosted_connection_ref'] ) ? (string) $bwwc_settings['hosted_connection_ref'] : '',
	);
}

/**
 * Shared request helper for hosted API calls.
 *
 * @param string               $endpoint Relative endpoint.
 * @param array<string,mixed>  $data Request payload.
 * @param string               $method HTTP method.
 * @return array<string,mixed>|WP_Error
 */
function BWWC__hosted_api_request( $endpoint, $data = array(), $method = 'POST' ) {
	$config = BWWC__get_hosted_api_config();

	if ( empty( $config['connector_key'] ) || $config['connection_state'] !== 'connected' ) {
		return new WP_Error( 'not_connected', 'Not connected to Hosted Invoicing service' );
	}

	$url = $config['base_url'] . $endpoint;
	$args = array(
		'method'  => strtoupper( $method ),
		'timeout' => $config['timeout'],
		'headers' => array(
			'x-connector-key' => $config['connector_key'],
			'content-type'    => 'application/json',
		),
	);

	if ( ! empty( $data ) ) {
		$args['body'] = wp_json_encode( $data );
	}

	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status        = wp_remote_retrieve_response_code( $response );
	$body          = wp_remote_retrieve_body( $response );
	$decoded       = json_decode( $body, true );
	$response_data = is_array( $decoded ) ? $decoded : array();

	if ( $status >= 200 && $status < 300 ) {
		return array(
			'success' => true,
			'status'  => $status,
			'data'    => $response_data,
		);
	}

	return array(
		'success' => false,
		'status'  => $status,
		'data'    => $response_data,
		'error'   => isset( $response_data['error'] ) ? (string) $response_data['error'] : 'Hosted API request failed',
	);
}

/**
 * Start hosted connection flow (manual connector-key flow for current backend).
 */
function BWWC__ajax_hosted_connect_start() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized', 403 );
	}

	check_ajax_referer( 'bwwc_hosted_connect', 'nonce' );

	$bwwc_settings = BWWC__get_settings();
	$api_base_url  = ! empty( $bwwc_settings['hosted_api_base_url'] )
		? rtrim( $bwwc_settings['hosted_api_base_url'], '/' )
		: 'https://sendbsv-invoicing.proud-mode-7a3d.workers.dev';

	$setup_url = add_query_arg(
		array(
			'from'     => 'woocommerce',
			'site_url' => rawurlencode( get_site_url() ),
		),
		$api_base_url . '/setup'
	);

	wp_send_json_success(
		array(
			'connect_url' => $setup_url,
			'message'     => 'Open SendBSV setup, issue WooCommerce connector key, paste key in Advanced settings, then Save + Test.',
		)
	);
}

/**
 * Disconnect from hosted service.
 */
function BWWC__ajax_hosted_disconnect() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized', 403 );
	}

	check_ajax_referer( 'bwwc_hosted_connect', 'nonce' );

	$bwwc_settings                            = BWWC__get_settings();
	$bwwc_settings['hosted_connector_key']    = '';
	$bwwc_settings['hosted_connection_state'] = 'not_connected';
	$bwwc_settings['hosted_connection_ref']   = '';
	$bwwc_settings['hosted_webhook_secret']   = '';
	BWWC__update_settings( $bwwc_settings );

	wp_send_json_success( array( 'message' => 'Disconnected from Hosted Invoicing' ) );
}

/**
 * Test hosted connection against connector capabilities endpoint.
 *
 * @return array<string,mixed>
 */
function BWWC__test_hosted_connection() {
	$result = BWWC__hosted_api_request( '/v1/connectors/woocommerce/capabilities', array(), 'GET' );
	if ( is_wp_error( $result ) ) {
		return array(
			'success' => false,
			'message' => 'Connection error: ' . $result->get_error_message(),
			'data'    => array(),
		);
	}
	if ( empty( $result['success'] ) ) {
		return array(
			'success' => false,
			'message' => 'Connection test failed (HTTP ' . intval( $result['status'] ) . ')',
			'data'    => isset( $result['data'] ) ? $result['data'] : array(),
		);
	}

	return array(
		'success' => true,
		'message' => 'Connected successfully to Hosted Invoicing service',
		'data'    => isset( $result['data'] ) ? $result['data'] : array(),
	);
}

/**
 * AJAX wrapper for test connection.
 */
function BWWC__ajax_hosted_test_connection() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized', 403 );
	}

	check_ajax_referer( 'bwwc_hosted_connect', 'nonce' );
	$result = BWWC__test_hosted_connection();

	if ( ! empty( $result['success'] ) ) {
		$bwwc_settings                            = BWWC__get_settings();
		$bwwc_settings['hosted_connection_state'] = 'connected';
		BWWC__update_settings( $bwwc_settings );
		wp_send_json_success( $result );
	}
	$bwwc_settings                            = BWWC__get_settings();
	$bwwc_settings['hosted_connection_state'] = 'reauth_required';
	BWWC__update_settings( $bwwc_settings );
	wp_send_json_error( $result );
}

/**
 * Build and create hosted checkout session from Woo order.
 *
 * @param int    $order_id Woo order id.
 * @param float  $amount   Store amount.
 * @param string $currency Store currency.
 * @return array<string,mixed>|WP_Error
 */
function BWWC__create_hosted_session( $order_id, $amount, $currency ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return new WP_Error( 'invalid_order', 'Invalid order ID' );
	}

	$session_request = array(
		'amount'       => floatval( $amount ),
		'currency'     => strtoupper( (string) $currency ),
		'order_ref'    => 'wc-' . strval( $order_id ),
		'merchant_ref' => $order->get_order_key(),
		'mode'         => 'brc100',
	);

	$result = BWWC__hosted_api_request( '/v1/connectors/woocommerce/sessions', $session_request, 'POST' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	$payload = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
	$session = isset( $payload['connector_session'] ) && is_array( $payload['connector_session'] ) ? $payload['connector_session'] : array();
	$invoice = isset( $session['invoice'] ) && is_array( $session['invoice'] ) ? $session['invoice'] : array();

	$normalized = array(
		'session_id'      => isset( $session['session_token'] ) ? (string) $session['session_token'] : '',
		'intent_id'       => isset( $session['intent_id'] ) ? (string) $session['intent_id'] : '',
		'invoice_id'      => isset( $invoice['invoice_id'] ) ? (string) $invoice['invoice_id'] : '',
		'payment_url'     => isset( $session['pay_page_url'] ) ? (string) $session['pay_page_url'] : '',
		'status_url'      => isset( $session['status_url'] ) ? (string) $session['status_url'] : '',
		'recheck_url'     => isset( $session['recheck_url'] ) ? (string) $session['recheck_url'] : '',
		'internalize_url' => isset( $session['internalize_url'] ) ? (string) $session['internalize_url'] : '',
		'expires_at'      => isset( $session['expires_at'] ) ? (string) $session['expires_at'] : '',
		'expected_sats'   => isset( $invoice['expected_sats'] ) ? intval( $invoice['expected_sats'] ) : 0,
		'payment_address' => isset( $invoice['pay_address'] ) ? (string) $invoice['pay_address'] : '',
		'qr_payload'      => isset( $invoice['qr_payload'] ) ? (string) $invoice['qr_payload'] : '',
		'raw'             => $payload,
	);

	return array(
		'success' => true,
		'data'    => $normalized,
	);
}

/**
 * Build canonical signature payload.
 *
 * @param string $timestamp ISO timestamp.
 * @param string $body Raw body.
 * @return string
 */
function BWWC__hosted_signature_payload( $timestamp, $body ) {
	return $timestamp . '.' . $body;
}

/**
 * Verify sendbsv webhook signature with canonical and legacy support.
 *
 * @param string $payload Body.
 * @param string $timestamp Timestamp.
 * @param string $signature Canonical signature.
 * @param string $legacy_signature Legacy signature.
 * @return bool
 */
function BWWC__verify_webhook_signature( $payload, $timestamp, $signature, $legacy_signature = '' ) {
	$config = BWWC__get_hosted_api_config();
	$secret = trim( (string) $config['webhook_secret'] );
	if ( $secret === '' ) {
		return false;
	}

	$canonical_expected = hash_hmac( 'sha256', BWWC__hosted_signature_payload( $timestamp, $payload ), $secret );
	if ( $signature !== '' && hash_equals( $canonical_expected, $signature ) ) {
		return true;
	}

	$legacy_expected = hash_hmac( 'sha256', $payload, $secret );
	return $legacy_signature !== '' && hash_equals( $legacy_expected, $legacy_signature );
}

/**
 * Resolve Woo order from webhook payload.
 *
 * @param array<string,mixed> $data Webhook payload.
 * @return WC_Order|null
 */
function BWWC__resolve_hosted_order_from_payload( $data ) {
	$invoice = isset( $data['invoice'] ) && is_array( $data['invoice'] ) ? $data['invoice'] : array();
	$order_ref = isset( $invoice['order_ref'] ) ? trim( (string) $invoice['order_ref'] ) : '';

	if ( $order_ref !== '' ) {
		if ( preg_match( '/^wc-(\d+)$/', $order_ref, $m ) ) {
			$order = wc_get_order( intval( $m[1] ) );
			if ( $order ) {
				return $order;
			}
		}
		if ( ctype_digit( $order_ref ) ) {
			$order = wc_get_order( intval( $order_ref ) );
			if ( $order ) {
				return $order;
			}
		}
	}

	$invoice_id = isset( $invoice['invoice_id'] ) ? trim( (string) $invoice['invoice_id'] ) : '';
	if ( $invoice_id !== '' ) {
		$ids = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'meta_key'   => '_bwwc_hosted_invoice_id',
				'meta_value' => $invoice_id,
			)
		);
		if ( is_array( $ids ) && ! empty( $ids[0] ) ) {
			$order = wc_get_order( intval( $ids[0] ) );
			if ( $order ) {
				return $order;
			}
		}
	}

	return null;
}

/**
 * Apply hosted status/event to Woo order (idempotent).
 *
 * @param WC_Order           $order Woo order.
 * @param array<string,mixed> $data payload.
 */
function BWWC__apply_hosted_webhook_to_order( $order, $data ) {
	$invoice = isset( $data['invoice'] ) && is_array( $data['invoice'] ) ? $data['invoice'] : array();
	$event   = isset( $data['event'] ) ? strtolower( trim( (string) $data['event'] ) ) : '';
	$status  = isset( $invoice['status'] ) ? strtoupper( trim( (string) $invoice['status'] ) ) : '';
	$txids   = isset( $invoice['txids'] ) && is_array( $invoice['txids'] ) ? array_values( array_filter( array_map( 'strval', $invoice['txids'] ) ) ) : array();

	$expected_sats = isset( $invoice['expected_sats'] ) ? intval( $invoice['expected_sats'] ) : 0;
	$received_sats = isset( $invoice['received_sats'] ) ? intval( $invoice['received_sats'] ) : 0;
	$best_conf     = isset( $invoice['best_confirmations'] ) ? intval( $invoice['best_confirmations'] ) : 0;

	if ( $expected_sats > 0 ) {
		$order->update_meta_data( '_bwwc_expected_sats', $expected_sats );
	}
	if ( $received_sats >= 0 ) {
		$order->update_meta_data( '_bwwc_received_sats', $received_sats );
		$order->update_meta_data( '_bwwc_confirmed_sats', $received_sats );
	}
	if ( $best_conf >= 0 ) {
		$order->update_meta_data( '_bwwc_best_confirmations', $best_conf );
	}
	if ( ! empty( $txids ) ) {
		$order->update_meta_data( '_bwwc_txids', $txids );
	}

	if ( isset( $invoice['invoice_id'] ) ) {
		$order->update_meta_data( '_bwwc_hosted_invoice_id', sanitize_text_field( (string) $invoice['invoice_id'] ) );
	}
	$order->update_meta_data( '_bwwc_hosted_last_sync_at', time() );

	$bwwc_settings = BWWC__get_settings();
	$order_status  = $order->get_status();

	$is_paid_event = in_array( $event, array( 'invoice.verified', 'invoice.paid' ), true ) || in_array( $status, array( 'VERIFIED', 'PAID' ), true );
	$is_detected   = $event === 'invoice.detected' || in_array( $status, array( 'DETECTED', 'PENDING' ), true );
	$is_expired    = $event === 'invoice.expired' || $status === 'EXPIRED';
	$is_underpaid  = $event === 'invoice.underpaid' || $status === 'UNDERPAID';
	$is_overpaid   = $event === 'invoice.overpaid' || $status === 'OVERPAID';

	if ( $is_paid_event ) {
		BWWC__set_payment_state( $order->get_id(), BWWC_PAYMENT_STATE_VERIFIED, 'Hosted webhook: payment verified' );
		if ( in_array( $order_status, array( 'on-hold', 'pending', 'failed' ), true ) ) {
			if ( ! empty( $bwwc_settings['autocomplete_paid_orders'] ) ) {
				$order->update_status( 'completed', __( 'Payment verified via Hosted Invoicing webhook', 'bsvanon-bitcoin-sv-payments' ) );
			} else {
				$order->update_status( 'processing', __( 'Payment verified via Hosted Invoicing webhook', 'bsvanon-bitcoin-sv-payments' ) );
			}
		}
		$order->add_order_note( __( 'Hosted settlement verified.', 'bsvanon-bitcoin-sv-payments' ) );
	} elseif ( $is_expired ) {
		BWWC__set_payment_state( $order->get_id(), BWWC_PAYMENT_STATE_EXPIRED, 'Hosted webhook: invoice expired' );
		if ( in_array( $order_status, array( 'on-hold', 'pending' ), true ) ) {
			$order->update_status( 'cancelled', __( 'Hosted payment expired', 'bsvanon-bitcoin-sv-payments' ) );
		}
	} elseif ( $is_underpaid ) {
		BWWC__set_payment_state( $order->get_id(), BWWC_PAYMENT_STATE_UNDERPAID, 'Hosted webhook: underpaid' );
		$order->add_order_note( __( 'Hosted payment underpaid.', 'bsvanon-bitcoin-sv-payments' ) );
	} elseif ( $is_overpaid ) {
		BWWC__set_payment_state( $order->get_id(), BWWC_PAYMENT_STATE_OVERPAID, 'Hosted webhook: overpaid' );
		$order->add_order_note( __( 'Hosted payment overpaid.', 'bsvanon-bitcoin-sv-payments' ) );
	} elseif ( $is_detected ) {
		BWWC__set_payment_state( $order->get_id(), BWWC_PAYMENT_STATE_DETECTED, 'Hosted webhook: payment detected' );
	}

	$order->save();
}

/**
 * Webhook receiver endpoint for hosted settlement notifications.
 */
function BWWC__handle_hosted_settlement_webhook() {
	$payload = file_get_contents( 'php://input' );
	if ( ! is_string( $payload ) || $payload === '' ) {
		status_header( 400 );
		echo wp_json_encode( array( 'error' => 'empty payload' ) );
		exit;
	}

	$timestamp = isset( $_SERVER['HTTP_X_SENDBSV_TIMESTAMP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SENDBSV_TIMESTAMP'] ) ) : '';
	$signature = isset( $_SERVER['HTTP_X_SENDBSV_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SENDBSV_SIGNATURE'] ) ) : '';
	$legacy    = isset( $_SERVER['HTTP_X_SENDBSV_SIGNATURE_LEGACY'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SENDBSV_SIGNATURE_LEGACY'] ) ) : '';
	$event_id  = isset( $_SERVER['HTTP_X_SENDBSV_EVENT_ID'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SENDBSV_EVENT_ID'] ) ) : '';

	if ( $timestamp === '' || ( $signature === '' && $legacy === '' ) ) {
		status_header( 401 );
		echo wp_json_encode( array( 'error' => 'missing signature headers' ) );
		exit;
	}

	$ts_epoch = strtotime( $timestamp );
	if ( ! $ts_epoch || abs( time() - $ts_epoch ) > 900 ) {
		status_header( 401 );
		echo wp_json_encode( array( 'error' => 'timestamp outside allowed window' ) );
		exit;
	}

	if ( ! BWWC__verify_webhook_signature( $payload, $timestamp, $signature, $legacy ) ) {
		status_header( 401 );
		echo wp_json_encode( array( 'error' => 'invalid signature' ) );
		exit;
	}

	if ( $event_id !== '' ) {
		$replay_key = 'bwwc_hosted_evt_' . md5( $event_id );
		if ( get_transient( $replay_key ) ) {
			status_header( 200 );
			echo wp_json_encode( array( 'status' => 'duplicate_ignored' ) );
			exit;
		}
		set_transient( $replay_key, 1, DAY_IN_SECONDS );
	}

	$data = json_decode( $payload, true );
	if ( ! is_array( $data ) || ! isset( $data['invoice'] ) ) {
		status_header( 400 );
		echo wp_json_encode( array( 'error' => 'invalid payload shape' ) );
		exit;
	}
	$config = BWWC__get_hosted_api_config();
	$invoice = isset( $data['invoice'] ) && is_array( $data['invoice'] ) ? $data['invoice'] : array();
	if ( ! empty( $config['connection_ref'] ) && ! empty( $invoice['merchant_id'] ) ) {
		if ( trim( (string) $config['connection_ref'] ) !== trim( (string) $invoice['merchant_id'] ) ) {
			status_header( 403 );
			echo wp_json_encode( array( 'error' => 'merchant binding mismatch' ) );
			exit;
		}
	}

	$order = BWWC__resolve_hosted_order_from_payload( $data );
	if ( ! $order ) {
		status_header( 404 );
		echo wp_json_encode( array( 'error' => 'order not found' ) );
		exit;
	}

	BWWC__apply_hosted_webhook_to_order( $order, $data );

	status_header( 200 );
	echo wp_json_encode( array( 'status' => 'ok' ) );
	exit;
}
add_action( 'woocommerce_api_bwwc_hosted_settlement', 'BWWC__handle_hosted_settlement_webhook' );

/**
 * Success admin notice.
 */
function BWWC__hosted_connection_admin_notice() {
	if ( ! isset( $_GET['bwwc_hosted_connected'] ) || $_GET['bwwc_hosted_connected'] !== '1' ) {
		return;
	}
	printf(
		'<div class="%1$s"><p>%2$s</p></div>',
		esc_attr( 'notice notice-success is-dismissible' ),
		esc_html__( 'Hosted Invoicing settings saved. Run “Test Connection” to verify connector key.', 'bsvanon-bitcoin-sv-payments' )
	);
}
add_action( 'admin_notices', 'BWWC__hosted_connection_admin_notice' );
