<?php
/*
Bitcoin SV Payments for WooCommerce
https://github.com/mboyd1/sendbsv-bsv-payments-for-woocommerce
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// Include everything
include(dirname(__FILE__) . '/bwwc-include-all.php');

//===========================================================================
// Global vars.

global $g_BWWC__plugin_directory_url;
$g_BWWC__plugin_directory_url = plugins_url('', __FILE__);

global $g_BWWC__cron_script_url;
$g_BWWC__cron_script_url = $g_BWWC__plugin_directory_url . '/bwwc-cron.php';

//===========================================================================

//===========================================================================
// Global default settings
global $g_BWWC__config_defaults;
$g_BWWC__config_defaults = array(

   // ------- Hidden constants
// 'supported_currencies_arr'             =>  array ('USD', 'AUD', 'CAD', 'CHF', 'CNY', 'DKK', 'EUR', 'GBP', 'HKD', 'JPY', 'NZD', 'PLN', 'RUB', 'SEK', 'SGD', 'THB'), // Not used right now.
   'database_schema_version'              =>  1.4,
   'assigned_address_expires_in_mins'     =>  4*60,   // 4 hours to pay for order and receive necessary number of confirmations.
   'funds_received_value_expires_in_mins' =>  '5',		// 'received_funds_checked_at' is fresh (considered to be a valid value) if it was last checked within 'funds_received_value_expires_in_mins' minutes.
   'derivation_path_type'                 =>  'm/0/i', // BIP32 derivation path: m/0/i (receiving), m/1/i (change), or m/i (root)
   'starting_index_for_new_btc_addresses' =>  '2',    // Generate new addresses for the wallet starting from this index.
   'max_blockchains_api_failures'         =>  '3',    // Return error after this number of sequential failed attempts to retrieve blockchain data.
   'max_unusable_generated_addresses'     =>  '20',   // Return error after this number of unusable (non-empty) bitcoin addresses were sequentially generated
   'blockchain_api_timeout_secs'          =>  '20',   // Connection and request timeouts for curl operations dealing with blockchain requests.
   'exchange_rate_api_timeout_secs'       =>  '10',   // Connection and request timeouts for curl operations dealing with exchange rate API requests.
   'soft_cron_job_schedule_name'          =>  'minutes_2.5',   // WP cron job frequency
   'delete_expired_unpaid_orders'         =>  '0',   // v6.0.0: Changed to OFF by default (merchant-safe). Automatically delete expired, unpaid orders from WooCommerce->Orders database
   'reuse_expired_addresses'              =>  '0',   // v6.0.0: Changed to OFF by default (better privacy). True - may reduce anonymouty of store customers (someone may click/generate bunch of fake orders to list many addresses that in a future will be used by real customers).
                                                      // False - better anonymouty but may leave many addresses in wallet unused (and hence will require very high 'gap limit') due to many unpaid order clicks.
                                                      //        In this case it is recommended to regenerate new wallet after 'gap limit' reaches 1000.
   'max_unused_addresses_buffer'          =>  10,     // Do not pre-generate more than these number of unused addresses. Pregeneration is done only by hard cron job or manually at plugin settings.
   'cache_exchange_rates_for_minutes'			=>	10,			// Cache exchange rate for that number of minutes without re-calling exchange rate API's.
// 'soft_cron_max_loops_per_run'					=>	2,			// NOT USED. Check up to this number of assigned bitcoin addresses per soft cron run. Each loop involves number of DB queries as well as API query to blockchain - and this may slow down the site.
   'elists'																=>	array(),
   'use_aggregated_api'										=>  '0',		// Use aggregated API to efficiently retrieve bitcoin address balance
   'bip270_enabled'                                         =>  '1',
   'broadcaster_preference'                                =>  'whatsonchain',
   'webhook_url'                                           =>  '',
   'webhook_secret'                                        =>  '',

   // ------- General Settings
   'license_key'                          =>  'UNLICENSED',
   'api_key'                              =>  substr(md5(microtime()), -16),
   // New, ported from WooCommerce settings pages.
   'service_provider'				 						  =>  'electrum_wallet',		// 'blockchain_info'
   'electrum_mpk_saved'                   =>  '', // Saved, non-normalized value - MPK's separated by space / \n / ,
   'electrum_mpks'                        =>  array(), // Normalized array of MPK's - derived from saved.
   'confs_num'                            =>  '4', // number of confirmations required before accepting payment.
   'exchange_rate_type'                   =>  'vwap', // 'realtime', 'bestrate'.
   'exchange_multiplier'                  =>  '1.00',

   'delete_db_tables_on_uninstall'        =>  '0',
   'autocomplete_paid_orders'							=>  '0',   // v6.0.0: Changed to OFF by default (merchant-safe). Merchants should manually review orders before marking complete.
   'enable_soft_cron_job'                 =>  '1',    // Enable "soft" Wordpress-driven cron jobs.

    // New BSV settings
    'selected_checkout_icon'               =>  '/images/checkout-icons/BSV-1.svg',
    //'checkout_icon_select'                 =>  '',
    
    // UI/UX Settings (v5.3.0+)
    'email_instructions_enabled'           =>  '1',    // Include payment instructions in WooCommerce emails
    'email_instructions_include_qr'        =>  '1',    // Include QR code in email instructions
    'email_instructions_intro'             =>  '',     // Custom intro text for email instructions (empty = use default)
    'status_polling_interval'              =>  '10',   // Seconds between status checks on payment console
    ////////

   // ------- Copy of $this->settings of 'BWWC_Bitcoin' class.
   // DEPRECATED (only blockchain.info related settings still remain there.)
   'gateway_settings'                     =>  array('confirmations' => 6),

   // ------- Special settings
   'exchange_rates'                       =>  array('EUR' => array('method|type' => array('time-last-checked' => 0, 'exchange_rate' => 1), 'GBP' => array())),
   );
//===========================================================================

//===========================================================================
function BWWC__GetPluginNameVersionEdition($please_donate = true)
{
    $return_data = '<h2 style="border-bottom:1px solid #DDD;padding-bottom:10px;margin-bottom:20px;">' .
            BWWC_PLUGIN_NAME . ', version: <span style="color:#EE0000;">' .
            BWWC_VERSION . '</span>' .
          '</h2>';

    return $return_data;
}
//===========================================================================

//===========================================================================
// Pro version functions removed in v6.0.0
// This is a free, open-source plugin with no Pro version
//===========================================================================

/**
 * Recursively sanitize incoming values from forms.
 *
 * @param mixed $value Value to sanitize.
 *
 * @return mixed
 */
function BWWC__sanitize_recursive($value)
{
    if (is_array($value)) {
        return array_map('BWWC__sanitize_recursive', $value);
    }

    if (is_scalar($value)) {
        return sanitize_text_field(wp_unslash($value));
    }

    return $value;
}
//===========================================================================

//===========================================================================
// These are coming from plugin-specific table.
function BWWC__get_persistent_settings($key=false)
{
    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}bwwc_persistent_settings` WHERE `id` = %d",
            1
        ),
        ARRAY_A
    );
    if ($row) {
        $settings = @unserialize($row['settings'], ['allowed_classes' => false]);
        if ($key) {
            return $settings[$key];
        } else {
            return $settings;
        }
    } else {
        return array();
    }
}
//===========================================================================

//===========================================================================
function BWWC__update_persistent_settings($bwwc_use_these_settings_array=false)
{
    global $wpdb;

    $persistent_settings_table_name = $wpdb->prefix . 'bwwc_persistent_settings';

    if (!$bwwc_use_these_settings_array) {
        $bwwc_use_these_settings_array = array();
    }

    $db_ready_settings = BWWC__safe_string_escape(serialize($bwwc_use_these_settings_array));

    $wpdb->update(
        $persistent_settings_table_name,
        array('settings' => $db_ready_settings),
        array('id' => 1),
        array('%s'),
        array('%d')
    );
}
//===========================================================================

//===========================================================================
// Wipe existing table's contents and recreate first record with all defaults.
function BWWC__reset_all_persistent_settings()
{
    global $wpdb;
    global $g_BWWC__config_defaults;

    $initial_settings = BWWC__safe_string_escape(serialize($g_BWWC__config_defaults));

    $wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}bwwc_persistent_settings`");

    $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO `{$wpdb->prefix}bwwc_persistent_settings` (`id`, `settings`) VALUES ( %d, %s )",
            1,
            $initial_settings
        )
    );
}
//===========================================================================

//===========================================================================
function BWWC__get_settings($key=false)
{
    global   $g_BWWC__plugin_directory_url;
    global   $g_BWWC__config_defaults;

    $bwwc_settings = get_option(BWWC_SETTINGS_NAME);
    if (!is_array($bwwc_settings)) {
        $bwwc_settings = array();
    }


    if ($key) {
        return (@$bwwc_settings[$key]);
    } else {
        return ($bwwc_settings);
    }
}
//===========================================================================

//===========================================================================
function BWWC__update_settings($bwwc_use_these_settings=false, $also_update_persistent_settings=false)
{
    if ($bwwc_use_these_settings) {
        if ($also_update_persistent_settings) {
            BWWC__update_persistent_settings($bwwc_use_these_settings);
        }

        update_option(BWWC_SETTINGS_NAME, $bwwc_use_these_settings);
        return;
    }

    global   $g_BWWC__config_defaults;

    // Load current settings and overwrite them with whatever values are present on submitted form
    $bwwc_settings = BWWC__get_settings();

    $incoming_settings = array();
    if (isset($_POST['bwwc_settings']) && is_array($_POST['bwwc_settings'])) {
        $incoming_settings = BWWC__sanitize_recursive($_POST['bwwc_settings']);
    }

    foreach ($g_BWWC__config_defaults as $k=>$v) {
        if (array_key_exists($k, $incoming_settings)) {
            if (!isset($bwwc_settings[$k])) {
                $bwwc_settings[$k] = "";
            }
            BWWC__update_individual_bwwc_setting($bwwc_settings[$k], $incoming_settings[$k]);
            continue;
        }

        if (isset($_POST[$k])) {
            $sanitized_value = BWWC__sanitize_recursive($_POST[$k]);
            if (!isset($bwwc_settings[$k])) {
                $bwwc_settings[$k] = "";
            } // Force set to something.
            BWWC__update_individual_bwwc_setting($bwwc_settings[$k], $sanitized_value);
        }
        // If not in POST - existing will be used.
    }

    //---------------------------------------
    // Validation
    // Enforce minimum 1 confirmation (blockchain APIs only return confirmed transactions)
    if (isset($bwwc_settings['confs_num']) && intval($bwwc_settings['confs_num']) < 1) {
        $bwwc_settings['confs_num'] = '1';
    }
    //---------------------------------------

    // ---------------------------------------
    // Post-process variables.

    // Array of MPK's. Single MPK = element with idx=0
    $bwwc_settings['electrum_mpks'] = preg_split("/[\s,]+/", $bwwc_settings['electrum_mpk_saved']);
    // ---------------------------------------

    // ---------------------------------------
    // Reschedule cron if settings changed
    $old_settings = get_option(BWWC_SETTINGS_NAME);
    $cron_settings_changed = (
        !$old_settings ||
        $old_settings['enable_soft_cron_job'] != $bwwc_settings['enable_soft_cron_job'] ||
        $old_settings['soft_cron_job_schedule_name'] != $bwwc_settings['soft_cron_job_schedule_name']
    );
    
    if ($cron_settings_changed) {
        // Clear existing cron
        wp_clear_scheduled_hook('BWWC_cron_action');
        
        // Reschedule if enabled
        if ($bwwc_settings['enable_soft_cron_job']) {
            $cron_job_schedule_name = $bwwc_settings['soft_cron_job_schedule_name'];
            wp_schedule_event(time(), $cron_job_schedule_name, 'BWWC_cron_action');
        }
    }
    // ---------------------------------------

    if ($also_update_persistent_settings) {
        BWWC__update_persistent_settings($bwwc_settings);
    }

    update_option(BWWC_SETTINGS_NAME, $bwwc_settings);
}
//===========================================================================

//===========================================================================
// Takes care of recursive updating
function BWWC__update_individual_bwwc_setting(&$bwwc_current_setting, $bwwc_new_setting)
{
    if (is_string($bwwc_new_setting)) {
        $bwwc_current_setting = BWWC__stripslashes($bwwc_new_setting);
    } elseif (is_array($bwwc_new_setting)) {  // Note: new setting may not exist yet in current setting: curr[t5] - not set yet, while new[t5] set.
      // Need to do recursive
        foreach ($bwwc_new_setting as $k=>$v) {
            if (!isset($bwwc_current_setting[$k])) {
                $bwwc_current_setting[$k] = "";
            }   // If not set yet - force set it to something.
            BWWC__update_individual_bwwc_setting($bwwc_current_setting[$k], $v);
        }
    } else {
        $bwwc_current_setting = $bwwc_new_setting;
    }
}
//===========================================================================

//===========================================================================
//
// Reset settings only for one screen
function BWWC__reset_partial_settings($also_reset_persistent_settings=false)
{
    global   $g_BWWC__config_defaults;

    // Load current settings and overwrite ones that are present on submitted form with defaults
    $bwwc_settings = BWWC__get_settings();

    foreach ($_POST as $k=>$v) {
        $sanitized_value = BWWC__sanitize_recursive($v);
        if (isset($g_BWWC__config_defaults[$k])) {
            if (!isset($bwwc_settings[$k])) {
                $bwwc_settings[$k] = "";
            } // Force set to something.
            BWWC__update_individual_bwwc_setting($bwwc_settings[$k], $g_BWWC__config_defaults[$k]);
        }
    }

    update_option(BWWC_SETTINGS_NAME, $bwwc_settings);

    if ($also_reset_persistent_settings) {
        BWWC__update_persistent_settings($bwwc_settings);
    }
}
//===========================================================================

//===========================================================================
function BWWC__reset_all_settings($also_reset_persistent_settings=false)
{
    global   $g_BWWC__config_defaults;

    update_option(BWWC_SETTINGS_NAME, $g_BWWC__config_defaults);

    if ($also_reset_persistent_settings) {
        BWWC__reset_all_persistent_settings();
    }
}
//===========================================================================

//===========================================================================
// Recursively strip slashes from all elements of multi-nested array
function BWWC__stripslashes(&$val)
{
    if (is_string($val)) {
        return (stripslashes($val));
    }
    if (!is_array($val)) {
        return $val;
    }

    foreach ($val as $k=>$v) {
        $val[$k] = BWWC__stripslashes($v);
    }

    return $val;
}
//===========================================================================

//===========================================================================
/*
    ----------------------------------
    : Table 'btc_addresses' :
    ----------------------------------
      status                "unused"      - never been used address with last known zero balance
                            "assigned"    - order was placed and this address was assigned for payment
                            "revalidate"  - assigned/expired, unused or unknown address suddenly got non-zero balance in it. Revalidate it for possible late order payment against meta_data.
                            "used"        - order was placed and this address and payment in full was received. Address will not be used again.
                            "xused"       - address was used (touched with funds) by unknown entity outside of this application. No metadata is present for this address, will not be able to correlated it with any order.
                            "unknown"     - new address was generated but cannot retrieve balance due to blockchain API failure.
*/
function BWWC__create_database_tables($bwwc_settings)
{
    global $wpdb;

    $bwwc_settings = BWWC__get_settings();
    $must_update_settings = false;

    ///$persistent_settings_table_name       = $wpdb->prefix . 'bwwc_persistent_settings';
    $btc_addresses_table_name = $wpdb->prefix . 'bwwc_btc_addresses';

    if ($wpdb->get_var("SHOW TABLES LIKE '$btc_addresses_table_name'") != $btc_addresses_table_name) {
        $b_first_time = true;
    } else {
        $b_first_time = false;
    }

    //----------------------------------------------------------
    // Create tables
    $wpdb->query("CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}bwwc_btc_addresses` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `btc_address` char(36) NOT NULL,
    `origin_id` char(128) NOT NULL DEFAULT '',
    `index_in_wallet` bigint(20) NOT NULL DEFAULT '0',
    `status` char(16)  NOT NULL DEFAULT 'unknown',
    `last_assigned_to_ip` char(16) NOT NULL DEFAULT '0.0.0.0',
    `assigned_at` bigint(20) NOT NULL DEFAULT '0',
    `total_received_funds` DECIMAL( 16, 8 ) NOT NULL DEFAULT '0.00000000',
    `received_funds_checked_at` bigint(20) NOT NULL DEFAULT '0',
    `address_meta` MEDIUMBLOB NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `btc_address` (`btc_address`),
    KEY `index_in_wallet` (`index_in_wallet`),
    KEY `origin_id` (`origin_id`),
    KEY `status` (`status`)
    )");
    //----------------------------------------------------------

    // v6.0.0: Removed ancient migration code (A15)
    // Support floor: v5.3.4+ with database schema version 1.4
    // Users on older versions must upgrade to v5.3.4 first before upgrading to v6.0.0
    // No migration path from pre-v5.3.4 versions

    if ($must_update_settings) {
        BWWC__update_settings($bwwc_settings);
    }

    //----------------------------------------------------------
  // Seed DB tables with initial set of data
  /* PERSISTENT SETTINGS CURRENTLY UNUNSED
  if ($b_first_time || !is_array(BWWC__get_persistent_settings()))
  {
    // Wipes table and then creates first record and populate it with defaults
    BWWC__reset_all_persistent_settings();
  }
  */
   //----------------------------------------------------------
}
//===========================================================================

//===========================================================================
// NOTE: Irreversibly deletes all plugin tables and data
function BWWC__delete_database_tables()
{
    global $wpdb;

    ///$persistent_settings_table_name       = $wpdb->prefix . 'bwwc_persistent_settings';
    ///$electrum_wallets_table_name          = $wpdb->prefix . 'bwwc_electrum_wallets';
    $btc_addresses_table_name    = $wpdb->prefix . 'bwwc_btc_addresses';

    ///$wpdb->query("DROP TABLE IF EXISTS `$persistent_settings_table_name`");
    ///$wpdb->query("DROP TABLE IF EXISTS `$electrum_wallets_table_name`");
    $wpdb->query("DROP TABLE IF EXISTS `$btc_addresses_table_name`");
}
//===========================================================================

//===========================================================================
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'bsv-fix-metadata', 'BWWC_Fix_Legacy_Metadata_Command' );
}

//===========================================================================
class BWWC_Fix_Legacy_Metadata_Command {
    /**
     * Fix legacy address metadata by adding missing order_id
     *
     * ## EXAMPLES
     *
     *     wp bsv-fix-metadata
     *
     * @when after_wp_load
     */
    public function __invoke( $args, $assoc_args ) {
        global $wpdb;

        $btc_addresses_table_name = $wpdb->prefix . 'bwwc_btc_addresses';

        // Get all addresses with status assigned or used
        $addresses = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, btc_address, address_meta FROM `$btc_addresses_table_name` WHERE status IN ('assigned', 'used')"
            ),
            ARRAY_A
        );

        if ( empty( $addresses ) ) {
            WP_CLI::success( 'No addresses found to check.' );
            return;
        }

        $updated = 0;
        foreach ( $addresses as $address ) {
            $address_meta = BWWC_unserialize_address_meta( $address['address_meta'] );

            // Check if orders array exists and has order_id
            if ( isset( $address_meta['orders'][0]['order_id'] ) && !empty( $address_meta['orders'][0]['order_id'] ) ) {
                continue; // Already has order_id
            }

            // Look up order_id from post meta
            $order_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'bitcoins_address' AND meta_value = %s LIMIT 1",
                $address['btc_address']
            ) );

            if ( $order_id ) {
                // Get order details
                $order_total = floatval( get_post_meta( $order_id, 'order_total_in_btc', true ) );
                $order_datetime = gmdate( 'Y-m-d H:i:s' ) . ' UTC';

                // Build normalized entry
                $normalized_order_entry = array(
                    'order_id' => intval( $order_id ),
                    'order_total' => $order_total,
                    'order_datetime' => $order_datetime,
                    'requested_by_ip' => '',
                    'paid' => isset( $address_meta['orders'][0]['paid'] ) ? $address_meta['orders'][0]['paid'] : false,
                );

                // Update address_meta
                $address_meta['orders'] = array( $normalized_order_entry );
                $serialized = BWWC_serialize_address_meta( $address_meta );

                $wpdb->update(
                    $btc_addresses_table_name,
                    array( 'address_meta' => $serialized ),
                    array( 'id' => $address['id'] ),
                    array( '%s' ),
                    array( '%d' )
                );

                $updated++;
                WP_CLI::log( "Updated address {$address['btc_address']} with order_id {$order_id}" );
            }
        }

        WP_CLI::success( "Updated {$updated} legacy addresses." );
    }
}
//===========================================================================

//===========================================================================
add_action('admin_menu', 'BWWC__admin_menu');

function BWWC__admin_menu() {
    add_submenu_page(
        'woocommerce',
        'BSV Diagnostics',
        'BSV Diagnostics',
        'manage_options',
        'bsv-diagnostics',
        'BWWC__render_diagnostics_page'
    );
}
//===========================================================================
