<?php
/**
 * Hosted Invoicing Connect Module
 * 
 * Handles connection to SendBSV Invoicing service for v7.0+
 * 
 * @package BSV_WooCommerce_Gateway
 * @since 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ===========================================================================
// AJAX Handlers for Hosted Invoicing Connection
// ===========================================================================

/**
 * Initialize hosted connection AJAX handlers
 */
function BWWC__hosted_connect_init() {
	// Register AJAX endpoints for admin only
	add_action( 'wp_ajax_bwwc_hosted_connect_start', 'BWWC__ajax_hosted_connect_start' );
	add_action( 'wp_ajax_bwwc_hosted_connect_callback', 'BWWC__ajax_hosted_connect_callback' );
	add_action( 'wp_ajax_bwwc_hosted_disconnect', 'BWWC__ajax_hosted_disconnect' );
	add_action( 'wp_ajax_bwwc_hosted_test_connection', 'BWWC__ajax_hosted_test_connection' );
}
add_action( 'init', 'BWWC__hosted_connect_init' );

/**
 * Start hosted connection process
 */
function BWWC__ajax_hosted_connect_start() {
	// Security check
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized', 403 );
	}
	
	check_ajax_referer( 'bwwc_hosted_connect', 'nonce' );
	
	$bwwc_settings = BWWC__get_settings();
	$api_base_url = ! empty( $bwwc_settings['hosted_api_base_url'] ) 
		? $bwwc_settings['hosted_api_base_url'] 
		: 'https://sendbsv-invoicing.proud-mode-7a3d.workers.dev';
	
	// Generate a unique state token for CSRF protection
	$state_token = wp_generate_password( 32, false );
	update_option( 'bwwc_hosted_connect_state', $state_token );
	
	// Generate a unique callback URL
	$callback_url = add_query_arg( array(
		'action' => 'bwwc_hosted_connect_callback',
		'state' => $state_token,
	), admin_url( 'admin-ajax.php' ) );
	
	// Build the connect URL
	$site_url = get_site_url();
	$site_name = get_bloginfo( 'name' );
	$admin_email = get_option( 'admin_email' );
	
	$connect_url = add_query_arg( array(
		'callback' => urlencode( $callback_url ),
		'site_url' => urlencode( $site_url ),
		'site_name' => urlencode( $site_name ),
		'admin_email' => urlencode( $admin_email ),
		'plugin_version' => BWWC_VERSION,
	), $api_base_url . '/connect/start' );
	
	wp_send_json_success( array(
		'connect_url' => $connect_url,
		'state_token' => $state_token,
	) );
}

/**
 * Handle hosted connection callback
 */
function BWWC__ajax_hosted_connect_callback() {
	// Security check
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized', 403 );
	}
	
	// Verify state token
	$state_token = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
	$stored_state = get_option( 'bwwc_hosted_connect_state' );
	
	if ( empty( $state_token ) || $state_token !== $stored_state ) {
		wp_die( 'Invalid state token', 400 );
	}
	
	// Get connector key from POST data
	$connector_key = isset( $_POST['connector_key'] ) ? sanitize_text_field( wp_unslash( $_POST['connector_key'] ) ) : '';
	$connection_ref = isset( $_POST['connection_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['connection_ref'] ) ) : '';
	$webhook_secret = isset( $_POST['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) : '';
	
	if ( empty( $connector_key ) ) {
		wp_die( 'Missing connector key', 400 );
	}
	
	// Update settings
	$bwwc_settings = BWWC__get_settings();
	$bwwc_settings['hosted_connector_key'] = $connector_key;
	$bwwc_settings['hosted_connection_state'] = 'connected';
	$bwwc_settings['hosted_connection_ref'] = $connection_ref;
	
	if ( ! empty( $webhook_secret ) ) {
		$bwwc_settings['hosted_webhook_secret'] = $webhook_secret;
	}
	
	BWWC__update_settings( $bwwc_settings );
	
	// Clear the state token
	delete_option( 'bwwc_hosted_connect_state' );
	
	// Test the connection
	$test_result = BWWC__test_hosted_connection();
	
	if ( $test_result['success'] ) {
		// Redirect to settings page with success message
		wp_redirect( add_query_arg( array(
			'page' => 'wc-settings',
			'tab' => 'checkout',
			'section' => 'bwwc_bitcoin',
			'bwwc_hosted_connected' => '1',
		), admin_url( 'admin.php' ) ) );
		exit;
	} else {
		// Connection test failed
		wp_die( 'Connection test failed: ' . $test_result['message'], 500 );
	}
}

/**
 * Disconnect from hosted invoicing
 */
function BWWC__ajax_hosted_disconnect() {
	// Security check
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized', 403 );
	}
	
	check_ajax_referer( 'bwwc_hosted_connect', 'nonce' );
	
	// Update settings to disconnect
	$bwwc_settings = BWWC__get_settings();
	$bwwc_settings['hosted_connector_key'] = '';
	$bwwc_settings['hosted_connection_state'] = 'not_connected';
	$bwwc_settings['hosted_connection_ref'] = '';
	$bwwc_settings['hosted_webhook_secret'] = '';
	
	BWWC__update_settings( $bwwc_settings );
	
	wp_send_json_success( array(
		'message' => 'Disconnected from Hosted Invoicing',
	) );
}

/**
 * Test hosted connection
 */
function BWWC__ajax_hosted_test_connection() {
	// Security check
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized', 403 );
	}
	
	check_ajax_referer( 'bwwc_hosted_connect', 'nonce' );
	
	$result = BWWC__test_hosted_connection();
	
	if ( $result['success'] ) {
		wp_send_json_success( array(
			'message' => $result['message'],
			'data' => $result['data'],
		) );
	} else {
		wp_send_json_error( array(
			'message' => $result['message'],
			'data' => $result['data'],
		) );
	}
}

/**
 * Test connection to hosted invoicing service
 * 
 * @return array Test result with success flag and message
 */
function BWWC__test_hosted_connection() {
	$bwwc_settings = BWWC__get_settings();
	
	if ( empty( $bwwc_settings['hosted_connector_key'] ) ) {
		return array(
			'success' => false,
			'message' => 'No connector key configured',
			'data' => array(),
		);
	}
	
	$api_base_url = ! empty( $bwwc_settings['hosted_api_base_url'] ) 
		? $bwwc_settings['hosted_api_base_url'] 
		: 'https://sendbsv-invoicing.proud-mode-7a3d.workers.dev';
	
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
	
	$response = wp_remote_get( $api_base_url . '/api/v1/health', $args );
	
	if ( is_wp_error( $response ) ) {
		return array(
			'success' => false,
			'message' => 'Connection error: ' . $response->get_error_message(),
			'data' => array(
				'error' => $response->get_error_message(),
			),
		);
	}
	
	$status_code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );
	
	if ( $status_code === 200 && isset( $data['status'] ) && $data['status'] === 'ok' ) {
		return array(
			'success' => true,
			'message' => 'Connected successfully to Hosted Invoicing service',
			'data' => $data,
		);
	} else {
		return array(
			'success' => false,
			'message' => 'Connection test failed (HTTP ' . $status_code . ')',
			'data' => $data,
		);
	}
}

/**
 * Get hosted API client configuration
 * 
 * @return array API client configuration
 */
function BWWC__get_hosted_api_config() {
	$bwwc_settings = BWWC__get_settings();
	
	return array(
		'base_url' => ! empty( $bwwc_settings['hosted_api_base_url'] ) 
			? $bwwc_settings['hosted_api_base_url'] 
			: 'https://sendbsv-invoicing.proud-mode-7a3d.workers.dev',
		'connector_key' => $bwwc_settings['hosted_connector_key'] ?? '',
		'timeout' => isset( $bwwc_settings['hosted_timeout_ms'] ) 
			? intval( $bwwc_settings['hosted_timeout_ms'] ) / 1000 
			: 30,
		'webhook_secret' => $bwwc_settings['hosted_webhook_secret'] ?? '',
		'connection_state' => $bwwc_settings['hosted_connection_state'] ?? 'not_connected',
		'connection_ref' => $bwwc_settings['hosted_connection_ref'] ?? '',
	);
}

/**
 * Make a request to the hosted invoicing API
 * 
 * @param string $endpoint API endpoint
 * @param array $data Request data
 * @param string $method HTTP method
 * @return array|WP_Error Response data or error
 */
function BWWC__hosted_api_request( $endpoint, $data = array(), $method = 'POST' ) {
	$config = BWWC__get_hosted_api_config();
	
	if ( empty( $config['connector_key'] ) || $config['connection_state'] !== 'connected' ) {
		return new WP_Error( 'not_connected', 'Not connected to Hosted Invoicing service' );
	}
	
	$url = rtrim( $config['base_url'], '/' ) . $endpoint;
	
	$args = array(
		'method' => $method,
		'timeout' => $config['timeout'],
		'headers' => array(
			'Authorization' => 'Bearer ' . $config['connector_key'],
			'Content-Type' => 'application/json',
		),
	);
	
	if ( ! empty( $data ) ) {
		$args['body'] = json_encode( $data );
	}
	
	$response = wp_remote_request( $url, $args );
	
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	
	$status_code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$response_data = json_decode( $body, true );
	
	if ( $status_code >= 200 && $status_code < 300 ) {
		return array(
			'success' => true,
			'status' => $status_code,
			'data' => $response_data,
		);
	} else {
		return array(
			'success' => false,
			'status' => $status_code,
			'data' => $response_data,
			'error' => isset( $response_data['error'] ) ? $response_data['error'] : 'API request failed',
		);
	}
}

/**
 * Create a hosted checkout session
 * 
 * @param int $order_id WooCommerce order ID
 * @param float $amount Order amount in store currency
 * @param string $currency Store currency code
 * @return array|WP_Error Session data or error
 */
function BWWC__create_hosted_session( $order_id, $amount, $currency ) {
	$order = wc_get_order( $order_id );
	
	if ( ! $order ) {
		return new WP_Error( 'invalid_order', 'Invalid order ID' );
	}
	
	$session_data = array(
		'order_id' => $order_id,
		'order_key' => $order->get_order_key(),
		'amount' => $amount,
		'currency' => $currency,
		'customer_email' => $order->get_billing_email(),
		'customer_name' => $order->get_formatted_billing_full_name(),
		'return_url' => $order->get_checkout_order_received_url(),
		'cancel_url' => $order->get_cancel_order_url_raw(),
		'webhook_url' => add_query_arg( array(
			'wc-api' => 'bwwc_hosted_settlement',
			'order_id' => $order_id,
			'order_key' => $order->get_order_key(),
		), home_url( '/' ) ),
		'metadata' => array(
			'store_name' => get_bloginfo( 'name' ),
			'store_url' => get_site_url(),
			'plugin_version' => BWWC_VERSION,
		),
	);
	
	return BWWC__hosted_api_request( '/api/v1/sessions', $session_data );
}

/**
 * Verify webhook signature
 * 
 * @param string $payload Raw request body
 * @param string $signature Signature from X-BSV-Signature header
 * @return bool True if signature is valid
 */
function BWWC__verify_webhook_signature( $payload, $signature ) {
	$config = BWWC__get_hosted_api_config();
	$secret = $config['webhook_secret'];
	
	if ( empty( $secret ) ) {
		return false;
	}
	
	$expected_signature = hash_hmac( 'sha256', $payload, $secret );
	return hash_equals( $expected_signature, $signature );
}

// ===========================================================================
// Admin notice for successful connection
// ===========================================================================

add_action( 'admin_notices', 'BWWC__hosted_connection_admin_notice' );

function BWWC__hosted_connection_admin_notice() {
	if ( ! isset( $_GET['bwwc_hosted_connected'] ) || $_GET['bwwc_hosted_connected'] !== '1' ) {
		return;
	}
	
	$class = 'notice notice-success is-dismissible';
	$message = __( 'Successfully connected to Hosted Invoicing service!', 'bsvanon-bitcoin-sv-payments' );
	
	printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
}

// ===========================================================================
// Register webhook endpoint for hosted settlement notifications
// ===========================================================================

add_action( 'woocommerce_api_bwwc_hosted_settlement', 'BWWC__handle_hosted_settlement_webhook' );

function BWWC__handle_hosted_settlement_webhook() {
	// Get raw POST data
	$payload = file_get_contents( 'php://input' );
	$signature = isset( $_SERVER['HTTP_X_BSV_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_BSV_SIGNATURE'] ) ) : '';
	
	// Verify signature
	if ( ! BWWC__verify_webhook_signature( $payload, $signature ) ) {
		status_header( 401 );
		wp_die( 'Invalid signature', 'Unauthorized', array( 'response' => 401 ) );
	}
	
	$data = json_decode( $payload, true );
	
	if ( ! $data || ! isset( $data['order_id'] ) || ! isset( $data['status'] ) ) {
		status_header( 400 );
		wp_die( 'Invalid payload', 'Bad Request', array( 'response' => 400 ) );
	}
	
	$order_id = intval( $data['order_id'] );
	$order_key = isset( $data['order_key'] ) ? sanitize_text_field( $data['order_key'] ) : '';
	$status = sanitize_text_field( $data['status'] );
	$txid = isset( $data['txid'] ) ? sanitize_text_field( $data['txid'] ) : '';
	$amount_sats = isset( $data['amount_sats'] ) ? intval( $data['amount_sats'] ) : 0;
	$timestamp = isset( $data['timestamp'] ) ? intval( $data['timestamp'] ) : time();
	
	// Verify order key matches
	$order = wc_get_order( $order_id );
	if ( ! $order || $order->get_order_key() !== $order_key ) {
		status_header( 404 );
		wp_die( 'Order not found', 'Not Found', array( 'response' => 404 ) );
	}
	
	// Update order based on status
	switch ( $status ) {
		case 'paid':
			// Mark as processing/completed based on settings
			$bwwc_settings = BWWC__get_settings();
			if ( $bwwc_settings['autocomplete_paid_orders'] ) {
				$order->update_status( 'completed', __( 'Payment received via Hosted Invoicing', 'bsvanon-bitcoin-sv-payments' ) );
			} else {
				$order->update_status( 'processing', __( 'Payment received via Hosted Invoicing', 'bsvanon-bitcoin-sv-payments' ) );
			}
			
			// Add order note
			$order->add_order_note( sprintf(
				__( 'Payment confirmed via Hosted Invoicing. TXID: %s, Amount: %d satoshis', 'bsvanon-bitcoin-sv-payments' ),
				$txid,
				$amount_sats
			) );
			
			// Store transaction details
			$order->update_meta_data( '_bsv_hosted_txid', $txid );
			$order->update_meta_data( '_bsv_hosted_amount_sats', $amount_sats );
			$order->update_meta_data( '_bsv_hosted_settled_at', $timestamp );
			$order->save();
			break;
			
		case 'expired':
			$order->update_status( 'cancelled', __( 'Payment expired via Hosted Invoicing', 'bsvanon-bitcoin-sv-payments' ) );
			break;
			
		case 'underpaid':
			$order->add_order_note( sprintf(
				__( 'Partial payment received via Hosted Invoicing. TXID: %s, Amount: %d satoshis', 'bsvanon-bitcoin-sv-payments' ),
				$txid,
				$amount_sats
			) );
			$order->update_meta_data( '_bsv_hosted_underpaid', 'yes' );
			$order->save();
			break;
			
		case 'overpaid':
			$order->add_order_note( sprintf(
				__( 'Overpayment received via Hosted Invoicing. TXID: %s, Amount: %d satoshis', 'bsvanon-bitcoin-sv-payments' ),
				$txid,
				$amount_sats
			) );
			$order->update_meta_data( '_bsv_hosted_overpaid', 'yes' );
			$order->save();
			break;
	}
	
	status_header( 200 );
	echo json_encode( array( 'status' => 'ok' ) );
	exit;
}