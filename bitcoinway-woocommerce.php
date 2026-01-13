<?php
/**
 * Plugin Name: Bitcoin SV Payments for WooCommerce
 * Plugin URI: https://github.com/BSVanon/bsv-woocommerce-gateway
 * Description: Accept Bitcoin SV (BSV) payments directly to your wallet for physical and digital products at your WooCommerce store. Self-custody, no third-party processor required.
 * Version: 5.3.2
 * Author: BSVanon
 * Author URI: https://plugins.svn.wordpress.org/bitcoin-sv-payments-for-woocommerce/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bitcoin-sv-payments-for-woocommerce
 * Domain Path: /lang
 * Requires at least: 5.8
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.5
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}


// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Include everything
include(dirname(__FILE__) . '/bwwc-include-all.php');

//---------------------------------------------------------------------------
// Add hooks and filters

// create custom plugin settings menu
add_action('admin_menu', 'BWWC_create_menu');

register_activation_hook(__FILE__, 'BWWC_activate');
register_deactivation_hook(__FILE__, 'BWWC_deactivate');
register_uninstall_hook(__FILE__, 'BWWC_uninstall');

add_filter('cron_schedules', 'BWWC__add_custom_scheduled_intervals');
add_action('BWWC_cron_action', 'BWWC_cron_job_worker');     // Multiple functions can be attached to 'BWWC_cron_action' action

// Add Settings link to plugin list
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'BWWC__plugin_action_links');

// Admin notices for Blocks checkout detection
add_action('admin_notices', 'BWWC__blocks_checkout_notice');

add_action('init', 'BWWC_set_lang_file');

// Add wallet top-up link to checkout page
add_action('woocommerce_review_order_before_payment', 'BWWC__add_wallet_topup_link');
//---------------------------------------------------------------------------

//===========================================================================
// activating the default values
function BWWC_activate()
{
    global  $g_BWWC__config_defaults;

    $bwwc_default_options = $g_BWWC__config_defaults;

    // This will overwrite default options with already existing options but leave new options (in case of upgrading to new version) untouched.
    $bwwc_settings = BWWC__get_settings();

    foreach ($bwwc_settings as $key=>$value) {
        $bwwc_default_options[$key] = $value;
    }

    update_option(BWWC_SETTINGS_NAME, $bwwc_default_options);

    // Re-get new settings.
    $bwwc_settings = BWWC__get_settings();

    // Create necessary database tables if not already exists...
    BWWC__create_database_tables($bwwc_settings);

    //----------------------------------
    // Setup cron jobs

    if ($bwwc_settings['enable_soft_cron_job'] && !wp_next_scheduled('BWWC_cron_action')) {
        $cron_job_schedule_name = strpos($_SERVER['HTTP_HOST'], 'ttt.com')===false ? $bwwc_settings['soft_cron_job_schedule_name'] : 'seconds_30';
        wp_schedule_event(time(), $cron_job_schedule_name, 'BWWC_cron_action');
    }
    //----------------------------------
}
//---------------------------------------------------------------------------
// Cron Subfunctions
function BWWC__add_custom_scheduled_intervals($schedules)
{
    $schedules['seconds_30']     = array('interval'=>30,     'display'=>__('Once every 30 seconds'));     // For testing only.
    $schedules['minutes_1']      = array('interval'=>1*60,   'display'=>__('Once every 1 minute'));
    $schedules['minutes_2.5']    = array('interval'=>2.5*60, 'display'=>__('Once every 2.5 minutes'));
    $schedules['minutes_5']      = array('interval'=>5*60,   'display'=>__('Once every 5 minutes'));

    return $schedules;
}
//---------------------------------------------------------------------------
//===========================================================================

//===========================================================================
/**
 * Add Settings link to plugin actions
 */
function BWWC__plugin_action_links($links)
{
    $settings_link = '<a href="' . admin_url('admin.php?page=BWWC-settings') . '">' . __('Settings', 'bitcoin-sv-payments-for-woocommerce') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
//---------------------------------------------------------------------------

//===========================================================================
/**
 * Show admin notice if WooCommerce Blocks checkout is detected
 */
function BWWC__blocks_checkout_notice()
{
    // Only show on relevant admin pages
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->id, array('plugins', 'woocommerce_page_wc-settings', 'dashboard'))) {
        return;
    }

    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        return;
    }

    // Check if checkout page uses Blocks
    $checkout_page_id = wc_get_page_id('checkout');
    if ($checkout_page_id > 0) {
        $checkout_page = get_post($checkout_page_id);
        if ($checkout_page && has_block('woocommerce/checkout', $checkout_page)) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>' . __('Bitcoin SV Gateway:', 'bitcoin-sv-payments-for-woocommerce') . '</strong> ';
            echo __('Your checkout page uses WooCommerce Blocks, which is not yet supported. Please create a classic checkout page with the <code>[woocommerce_checkout]</code> shortcode.', 'bitcoin-sv-payments-for-woocommerce');
            echo ' <a href="https://github.com/BSVanon/bsv-woocommerce-gateway#classic-checkout-required" target="_blank">' . __('Learn more', 'bitcoin-sv-payments-for-woocommerce') . '</a></p>';
            echo '</div>';
        }
    }
}
//---------------------------------------------------------------------------

//===========================================================================
// deactivating
function BWWC_deactivate()
{
    // Do deactivation cleanup. Do not delete previous settings in case user will reactivate plugin again...

    //----------------------------------
    // Clear cron jobs
    wp_clear_scheduled_hook('BWWC_cron_action');
    //----------------------------------
}
//===========================================================================

//===========================================================================
// uninstalling
function BWWC_uninstall()
{
    $bwwc_settings = BWWC__get_settings();

    if ($bwwc_settings['delete_db_tables_on_uninstall']) {
        // delete all settings.
        delete_option(BWWC_SETTINGS_NAME);

        // delete all DB tables and data.
        BWWC__delete_database_tables();
    }
}
//===========================================================================

//===========================================================================
function BWWC_create_menu()
{

    // create new top-level menu
    // http://www.fileformat.info/info/unicode/char/e3f/index.htm
    add_menu_page(
        __('Woo Bitcoin SV', 'bitcoin-sv-payments-for-woocommerce'),                    // Page title
        __('Bitcoin SV', 'bitcoin-sv-payments-for-woocommerce'),                        // Menu Title - lower corner of admin menu
        'manage_options',                                        // Capability
        'BWWC-settings',                                        // Handle - First submenu's handle must be equal to parent's handle to avoid duplicate menu entry.
        'BWWC__render_general_settings_page',                   // Function

        plugins_url('/images/bitcoin_16x.png', __FILE__)      // Icon URL
        );

    add_submenu_page(
        'BWWC-settings',                                        // Parent
        __('WooCommerce Bitcoin SV Payments Gateway', 'bitcoin-sv-payments-for-woocommerce'),                   // Page title
        __('General Settings', 'bitcoin-sv-payments-for-woocommerce'),               // Menu Title
        'manage_options',                                        // Capability
        'BWWC-settings',                                        // Handle - First submenu's handle must be equal to parent's handle to avoid duplicate menu entry.
        'BWWC__render_general_settings_page'                    // Function
        );

    add_submenu_page(
        'BWWC-settings',                                        // Parent
        __('Bitcoin SV Plugin Advanced Settings', 'bitcoin-sv-payments-for-woocommerce'),       // Page title
        __('Advanced Settings', 'bitcoin-sv-payments-for-woocommerce'),                // Menu title
        'manage_options',                                        // Capability
        'BWWC-settings-advanced',                        // Handle - First submenu's handle must be equal to parent's handle to avoid duplicate menu entry.
        'BWWC__render_advanced_settings_page'            // Function
        );
}
//===========================================================================

//===========================================================================
// load language files
function BWWC_set_lang_file()
{
    # set the language file
    $currentLocale = get_locale();
    if (!empty($currentLocale)) {
        $moFile = dirname(__FILE__) . "/lang/" . $currentLocale . ".mo";
        if (@file_exists($moFile) && is_readable($moFile)) {
            load_textdomain('bitcoin-sv-payments-for-woocommerce', $moFile);
        }
    }
}
//===========================================================================
