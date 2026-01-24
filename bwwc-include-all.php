<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/*
Bitcoin SV Payments for WooCommerce
https://github.com/mboyd1/sendbsv-bsv-payments-for-woocommerce
*/

//---------------------------------------------------------------------------
// Global definitions
if (!defined('BWWC_PLUGIN_NAME')) {
    define('BWWC_VERSION', '6.1.0');

    //-----------------------------------------------
    define('BWWC_EDITION', 'BSV');


    //-----------------------------------------------
    define('BWWC_SETTINGS_NAME', 'BWWC-Settings');
    define('BWWC_PLUGIN_NAME', 'Bitcoin SV Payments for WooCommerce');


    // i18n plugin domain for language files
    define('BWWC_I18N_DOMAIN', 'sendbsv-bsv-payments-for-woocommerce');
}

// Determine which math extension is available (runs on every load so upgrades are detected).
if (!defined('BWWC_USE_EXT')) {
    if (extension_loaded('gmp')) {
        define('BWWC_USE_EXT', 'GMP');
    } elseif (extension_loaded('bcmath')) {
        define('BWWC_USE_EXT', 'BCMATH');
    } else {
        define('BWWC_USE_EXT', 'NONE');
    }
}

// Load gateway ID migration (v6.0.0)
require_once(dirname(__FILE__) . '/includes/gateway-migration.php');
//---------------------------------------------------------------------------

//------------------------------------------
// Load wordpress for POSTback, WebHook and API pages that are called by external services directly.
if (defined('BWWC_MUST_LOAD_WP') && !defined('ABSPATH')) {
    $bwwc_blog_dir = preg_replace('|(/+[^/]+){4}$|', '', str_replace('\\', '/', __FILE__)); // For love of the art of regex-ing

    require_once($bwwc_blog_dir . '/wp-load.php');

    // Force-elimination of header 404 for non-wordpress pages.
    header("HTTP/1.1 200 OK");
    header("Status: 200 OK");

    require_once($bwwc_blog_dir . '/wp-admin/includes/admin.php');
}
//------------------------------------------


// This loads necessary modules and selects best math library
require_once(dirname(__FILE__) . '/libs/util/bcmath_Utils.php');
require_once(dirname(__FILE__) . '/libs/util/gmp_Utils.php');
require_once(dirname(__FILE__) . '/libs/CurveFp.php');
require_once(dirname(__FILE__) . '/libs/Point.php');
require_once(dirname(__FILE__) . '/libs/NumberTheory.php');
require_once(dirname(__FILE__) . '/libs/ElectrumHelper.php');

// Load v6 modular architecture
require_once(dirname(__FILE__) . '/includes/bootstrap.php');

require_once(dirname(__FILE__) . '/bwwc-cron.php');
require_once(dirname(__FILE__) . '/bwwc-mpkgen.php');
require_once(dirname(__FILE__) . '/bwwc-utils.php');
require_once(dirname(__FILE__) . '/bwwc-admin.php');
require_once(dirname(__FILE__) . '/bwwc-render-settings.php');
require_once(dirname(__FILE__) . '/bwwc-bitcoin-gateway.php');
require_once(dirname(__FILE__) . '/bwwc-dashboard-widget.php');
require_once(dirname(__FILE__) . '/includes/bsv-payment-console.php');
require_once(dirname(__FILE__) . '/includes/bsv-payment-check.php');
