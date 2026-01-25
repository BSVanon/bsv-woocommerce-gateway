<?php
/**
 * Bootstrap - Module loader for v6.0.0+
 *
 * Loads all modular components in the correct order.
 *
 * @package BSV_WooCommerce_Gateway
 * @since 6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load core modules first
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/http.php';

// Load provider modules
require_once __DIR__ . '/providers/interface.php';
require_once __DIR__ . '/providers/coingecko.php';
require_once __DIR__ . '/providers/coinpaprika.php';
require_once __DIR__ . '/providers/whatsonchain.php';
require_once __DIR__ . '/providers/bitails.php';

// Load payment state machine
require_once __DIR__ . '/payment-state.php';
require_once __DIR__ . '/payment-check.php';
require_once __DIR__ . '/expiry.php';

// Load BIP270 protocol endpoints
require_once __DIR__ . '/bip270-invoice.php';
require_once __DIR__ . '/bip270-payment-receiver.php';

// Register API endpoints
add_action( 'woocommerce_api_bsv_invoice', 'BWWC__serve_bip270_invoice' );
add_action( 'woocommerce_api_bsv_payment', 'BWWC__receive_bip270_payment' );
add_action( 'woocommerce_api_bsv_receipt', 'BWWC__serve_receipt_download' );
