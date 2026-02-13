<?php
/**
 * Plugin Name: SendBSV BSV Payments for WooCommerce
 * Plugin URI: https://github.com/BSVanon/bsv-woocommerce-gateway
 * Description: Accept Bitcoin SV (BSV) payments directly to your wallet for physical and digital products at your WooCommerce store. Self-custody, no third-party processor required.
 * Version: 6.2.0
 * Author: BSVanon
 * Author URI: https://sendbsv.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bsvanon-bitcoin-sv-payments
 * Domain Path: /lang
 * Requires at least: 5.8
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 * WC tested up to: 9.5
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// Declare WooCommerce feature compatibilities - MUST be called inside before_woocommerce_init hook
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

// Include everything
require __DIR__ . '/bwwc-include-all.php';

// ---------------------------------------------------------------------------
// Add hooks and filters

// create custom plugin settings menu
add_action( 'admin_menu', 'BWWC_create_menu' );

register_activation_hook( __FILE__, 'BWWC_activate' );
register_deactivation_hook( __FILE__, 'BWWC_deactivate' );
register_uninstall_hook( __FILE__, 'BWWC_uninstall' );

add_filter( 'cron_schedules', 'BWWC__add_custom_scheduled_intervals' );
add_action( 'BWWC_cron_action', 'BWWC_cron_job_worker' );     // Multiple functions can be attached to 'BWWC_cron_action' action

// Add Settings link to plugin list
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'BWWC__plugin_action_links' );

// Admin notices for Blocks checkout detection
add_action( 'admin_notices', 'BWWC__blocks_checkout_notice' );

add_action( 'init', 'BWWC_set_lang_file' );

// v6.0.0: Removed top-up link from checkout (A0.3 - merchant trust + WP.org concerns)
// Top-up link now only appears on payment console page after checkout
// ---------------------------------------------------------------------------

// ===========================================================================
// activating the default values
function BWWC_activate() {
	global  $g_BWWC__config_defaults;

	$bwwc_default_options = $g_BWWC__config_defaults;

	// This will overwrite default options with already existing options but leave new options (in case of upgrading to new version) untouched.
	$bwwc_settings = BWWC__get_settings();

	foreach ( $bwwc_settings as $key => $value ) {
		$bwwc_default_options[ $key ] = $value;
	}

	update_option( BWWC_SETTINGS_NAME, $bwwc_default_options );

	// Re-get new settings.
	$bwwc_settings = BWWC__get_settings();

	// Create necessary database tables if not already exists...
	BWWC__create_database_tables( $bwwc_settings );

	// ----------------------------------
	// Setup cron jobs

	if ( $bwwc_settings['enable_soft_cron_job'] && ! wp_next_scheduled( 'BWWC_cron_action' ) ) {
		$cron_job_schedule_name = $bwwc_settings['soft_cron_job_schedule_name'];
		wp_schedule_event( time(), $cron_job_schedule_name, 'BWWC_cron_action' );
	}
	// ----------------------------------
}
// ---------------------------------------------------------------------------
// Cron Subfunctions
function BWWC__add_custom_scheduled_intervals( $schedules ) {
	$schedules['seconds_30']  = array(
		'interval' => 30,
		'display'  => __( 'Once every 30 seconds', 'bsvanon-bitcoin-sv-payments' ),
	);     // For testing only.
	$schedules['minutes_1']   = array(
		'interval' => 60,
		'display'  => __( 'Once every 1 minute', 'bsvanon-bitcoin-sv-payments' ),
	);
	$schedules['minutes_2.5'] = array(
		'interval' => 150,
		'display'  => __( 'Once every 2.5 minutes', 'bsvanon-bitcoin-sv-payments' ),
	);
	$schedules['minutes_5']   = array(
		'interval' => 300,
		'display'  => __( 'Once every 5 minutes', 'bsvanon-bitcoin-sv-payments' ),
	);

	return $schedules;
}
// ---------------------------------------------------------------------------
// ===========================================================================

// ===========================================================================
/**
 * Add Settings link to plugin actions
 */
function BWWC__plugin_action_links( $links ) {
	$settings_link = '<a href="' . admin_url( 'admin.php?page=BWWC-settings' ) . '">' . __( 'Settings', 'bsvanon-bitcoin-sv-payments' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
// ---------------------------------------------------------------------------

// ===========================================================================
/**
 * Admin notice if WooCommerce Blocks checkout is detected
 *
 * v6.0.0: Blocks support is now complete via class-bsv-blocks-integration.php
 * No warning needed - both classic and Blocks checkout work seamlessly
 */
function BWWC__blocks_checkout_notice() {
	return;
}
// ---------------------------------------------------------------------------

// ===========================================================================
// deactivating
function BWWC_deactivate() {
	// Do deactivation cleanup. Do not delete previous settings in case user will reactivate plugin again...

	// ----------------------------------
	// Clear cron jobs
	wp_clear_scheduled_hook( 'BWWC_cron_action' );
	// ----------------------------------
}
// ===========================================================================

// ===========================================================================
// uninstalling
function BWWC_uninstall() {
	$bwwc_settings = BWWC__get_settings();

	if ( $bwwc_settings['delete_db_tables_on_uninstall'] ) {
		// delete all settings.
		delete_option( BWWC_SETTINGS_NAME );

		// delete all DB tables and data.
		BWWC__delete_database_tables();
	}
}
// ===========================================================================

// ===========================================================================
function BWWC_create_menu() {

	// create new top-level menu
	// http://www.fileformat.info/info/unicode/char/e3f/index.htm
	add_menu_page(
		__( 'Woo Bitcoin SV', 'bsvanon-bitcoin-sv-payments' ),                    // Page title
		__( 'Bitcoin SV', 'bsvanon-bitcoin-sv-payments' ),                        // Menu Title - lower corner of admin menu
		'manage_options',                                        // Capability
		'BWWC-settings',                                        // Handle - First submenu's handle must be equal to parent's handle to avoid duplicate menu entry.
		'BWWC__render_general_settings_page',                   // Function
		plugins_url( '/images/bitcoin_16x.png', __FILE__ )      // Icon URL
	);

	add_submenu_page(
		'BWWC-settings',                                        // Parent
		__( 'WooCommerce Bitcoin SV Payments Gateway', 'bsvanon-bitcoin-sv-payments' ),                   // Page title
		__( 'General Settings', 'bsvanon-bitcoin-sv-payments' ),               // Menu Title
		'manage_options',                                        // Capability
		'BWWC-settings',                                        // Handle - First submenu's handle must be equal to parent's handle to avoid duplicate menu entry.
		'BWWC__render_general_settings_page'                    // Function
	);

	add_submenu_page(
		'BWWC-settings',                                        // Parent
		__( 'Bitcoin SV Plugin Advanced Settings', 'bsvanon-bitcoin-sv-payments' ),       // Page title
		__( 'Advanced Settings', 'bsvanon-bitcoin-sv-payments' ),                // Menu title
		'manage_options',                                        // Capability
		'BWWC-settings-advanced',                        // Handle - First submenu's handle must be equal to parent's handle to avoid duplicate menu entry.
		'BWWC__render_advanced_settings_page'            // Function
	);
}
// ===========================================================================

// ===========================================================================
// load language files
function BWWC_set_lang_file() {
	// set the language file
	$currentLocale = get_locale();
	if ( ! empty( $currentLocale ) ) {
		$moFile = __DIR__ . '/lang/' . $currentLocale . '.mo';
		if ( @file_exists( $moFile ) && is_readable( $moFile ) ) {
			load_textdomain( 'bsvanon-bitcoin-sv-payments', $moFile );
		}
	}
}
// ===========================================================================
