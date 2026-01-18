<?php
/**
 * Bootstrap - Module loader for v6.0.0+
 * 
 * Loads all modular components in the correct order.
 * 
 * @package BSV_WooCommerce_Gateway
 * @since 6.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load core modules first
require_once dirname(__FILE__) . '/constants.php';
require_once dirname(__FILE__) . '/logging.php';
require_once dirname(__FILE__) . '/http.php';
require_once dirname(__FILE__) . '/order-meta.php';

// Load provider modules
require_once dirname(__FILE__) . '/providers/interface.php';
require_once dirname(__FILE__) . '/providers/coingecko.php';
require_once dirname(__FILE__) . '/providers/coinpaprika.php';
require_once dirname(__FILE__) . '/providers/whatsonchain.php';
require_once dirname(__FILE__) . '/providers/bitails.php';

// Load payment state machine
require_once dirname(__FILE__) . '/payment-state.php';
// payment-check.php and expiry.php removed in v6.0.0
// These modules used incorrect meta keys (_bwwc_* vs actual keys)
// and weren't HPOS-compatible. Will be refactored for v6.1
