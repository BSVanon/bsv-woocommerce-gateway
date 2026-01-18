<?php
/*
Bitcoin SV Payments for WooCommerce - Address Generation Module
https://github.com/mboyd1/sendbsv-bsv-payments-for-woocommerce
*/

if ( ! defined( 'ABSPATH' ) ) exit;

//===========================================================================
/*
   Input:
   ------
      $order_info =
         array (
            'order_id'        => $order_id,
            'order_total'     => $order_total_in_btc,
            'order_datetime'  => date('Y-m-d H:i:s T'),
            'requested_by_ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
            );
*/
// Returns:
// --------
/*
    $ret_info_array = array (
       'result'                      => 'success', // OR 'error'
       'message'                     => '...',
       'host_reply_raw'              => '......',
       'generated_bitcoin_address'   => '18vzABPyVbbia8TDCKDtXJYXcoAFAPk2cj', // or false
       );
*/
//

function BWWC__get_bitcoin_address_for_payment__electrum($electrum_mpk, $order_info)
{
    global $wpdb;

    // status = "unused", "assigned", "used"
    $btc_addresses_table_name     = $wpdb->prefix . 'bwwc_btc_addresses';
    $origin_id                    = $electrum_mpk;

    $bwwc_settings = BWWC__get_settings();
    $funds_received_value_expires_in_secs = $bwwc_settings['funds_received_value_expires_in_mins'] * 60;
    $assigned_address_expires_in_secs     = $bwwc_settings['assigned_address_expires_in_mins'] * 60;

    $clean_address = null;
    $current_time = time();

    if ($bwwc_settings['reuse_expired_addresses']) {
        $reuse_expired_addresses_freshb_query_part = $wpdb->prepare(
            "OR (`status`='assigned'
                AND ((%d - `assigned_at`) > %d)
                AND ((%d - `received_funds_checked_at`) < %d)
            )",
            $current_time,
            $assigned_address_expires_in_secs,
            $current_time,
            $funds_received_value_expires_in_secs
        );
    } else {
        $reuse_expired_addresses_freshb_query_part = "";
    }

    //-------------------------------------------------------
    // Quick scan for ready-to-use address
    // NULL == not found
    // Retrieve:
    //     'unused'   - with fresh zero balances
    //     'assigned' - expired, with fresh zero balances (if 'reuse_expired_addresses' is true)
    //
    // Hence - any returned address will be clean to use.
    $query = $wpdb->prepare(
        "SELECT `btc_address` FROM `$btc_addresses_table_name`
            WHERE `origin_id` = %s
              AND `total_received_funds` = %s
              AND (`status` = 'unused' $reuse_expired_addresses_freshb_query_part)
            ORDER BY `index_in_wallet` ASC
            LIMIT 1",
        $origin_id,
        '0'
    );
    $clean_address = $wpdb->get_var($query);

    //-------------------------------------------------------

    if (!$clean_address) {

      //-------------------------------------------------------
        // Find all unused addresses belonging to this mpk with possibly (to be verified right after) zero balances
        // Array(rows) or NULL
        // Retrieve:
        //    'unused'    - with old zero balances
        //    'unknown'   - ALL
        //    'assigned'  - expired with old zero balances (if 'reuse_expired_addresses' is true)
        //
        // Hence - any returned address with freshened balance==0 will be clean to use.
        if ($bwwc_settings['reuse_expired_addresses']) {
            $reuse_expired_addresses_oldb_query_part = $wpdb->prepare(
                "OR (`status`='assigned'
                    AND ((%d - `assigned_at`) > %d)
                    AND ((%d - `received_funds_checked_at`) > %d)
                )",
                $current_time,
                $assigned_address_expires_in_secs,
                $current_time,
                $funds_received_value_expires_in_secs
            );
        } else {
            $reuse_expired_addresses_oldb_query_part = "";
        }

        $query = $wpdb->prepare(
            "SELECT * FROM `$btc_addresses_table_name`
                WHERE `origin_id` = %s
                  AND `total_received_funds` = %s
                  AND (
                        `status`='unused'
                        OR `status`='unknown'
                        $reuse_expired_addresses_oldb_query_part
                  )
                ORDER BY `index_in_wallet` ASC",
            $origin_id,
            '0'
        ); // Try to use lower indexes first
        $addresses_to_verify_for_zero_balances_rows = $wpdb->get_results($query, ARRAY_A);

        if (!is_array($addresses_to_verify_for_zero_balances_rows)) {
            $addresses_to_verify_for_zero_balances_rows = array();
        }
        //-------------------------------------------------------

        //-------------------------------------------------------
        // Try to re-verify balances of existing addresses (with old or non-existing balances) before reverting to slow operation of generating new address.
        //
        $blockchains_api_failures = 0;
        foreach ($addresses_to_verify_for_zero_balances_rows as $address_to_verify_for_zero_balance_row) {
            // http://blockexplorer.com/q/getreceivedbyaddress/18vzABPyVbbia8TDCKDtXJYXcoAFAPk2cj
            // http://blockchain.info/q/getreceivedbyaddress/18vzABPyVbbia8TDCKDtXJYXcoAFAPk2cj [?confirmations=6]
            //
            $address_to_verify_for_zero_balance = $address_to_verify_for_zero_balance_row['btc_address'];

            $address_request_array = array();
            $address_request_array['btc_address'] = $address_to_verify_for_zero_balance;
            $address_request_array['required_confirmations'] = 0;
            $address_request_array['api_timeout'] = $bwwc_settings['blockchain_api_timeout_secs'];
            $ret_info_array = BWWC__getreceivedbyaddress_info($address_request_array, $bwwc_settings);

            if ($ret_info_array['balance'] === false) {
                $blockchains_api_failures ++;
                if ($blockchains_api_failures >= $bwwc_settings['max_blockchains_api_failures']) {
                    // Allow no more than 3 contigious blockchains API failures. After which return error reply.
                    $ret_info_array = array(
               'result'                      => 'error',
               'message'                     => $ret_info_array['message'],
               'host_reply_raw'              => $ret_info_array['host_reply_raw'],
               'generated_bitcoin_address'   => false,
               );
                    return $ret_info_array;
                }
            } else {
                if ($ret_info_array['balance'] == 0) {
                    // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
                    $clean_address    = $address_to_verify_for_zero_balance;
                    break;
                } else {
                    // Balance at this address suddenly became non-zero!
                    // It means either order was paid after expiration or "unknown" address suddenly showed up with non-zero balance or payment was sent to this address outside of this online store business.
                    // Mark it as 'revalidate' so cron job would check if that's possible delayed payment.
                    //
                    $address_meta    = BWWC_unserialize_address_meta(@$address_to_verify_for_zero_balance_row['address_meta']);
                    if (isset($address_meta['orders'][0])) {
                        $new_status = 'revalidate';
                    }	// Past orders are present. There is a chance (for cron job) to match this payment to past (albeit expired) order.
                    else {
                        $new_status = 'used';
                    }				// No orders were ever placed to this address. Likely payment was sent to this address outside of this online store business.

                    $current_time = time();
                    $query = $wpdb->prepare(
                        "UPDATE `$btc_addresses_table_name`
                            SET
                                `status` = %s,
                                `total_received_funds` = %f,
                                `received_funds_checked_at` = %d
                            WHERE `btc_address` = %s",
                        $new_status,
                        $ret_info_array['balance'],
                        $current_time,
                        $address_to_verify_for_zero_balance
                    );
                    $wpdb->query($query);
                }
            }
        }
        //-------------------------------------------------------
    }

    //-------------------------------------------------------
    if (!$clean_address) {
        // Still could not find unused virgin address. Time to generate it from scratch.
        /*
        Returns:
           $ret_info_array = array (
              'result'                      => 'success', // 'error'
              'message'                     => '', // Failed to find/generate bitcoin address',
              'host_reply_raw'              => '', // Error. No host reply availabe.',
              'generated_bitcoin_address'   => '1FVai2j2FsFvCbgsy22ZbSMfUd3HLUHvKx', // false,
              );
        */
        $ret_addr_array = BWWC__generate_new_bitcoin_address_for_electrum_wallet($bwwc_settings, $electrum_mpk);
        if ($ret_addr_array['result'] == 'success') {
            $clean_address = $ret_addr_array['generated_bitcoin_address'];
        }
    }
    //-------------------------------------------------------

    //-------------------------------------------------------
    if ($clean_address) {
        /*
              $order_info =
              array (
                 'order_id'     => $order_id,
                 'order_total'  => $order_total_in_btc,
                 'order_datetime'  => date('Y-m-d H:i:s T'),
                 'requested_by_ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
                 );

*/

        /*
        $address_meta =
           array (
              'orders' =>
                 array (
                    // All orders placed on this address in reverse chronological order
                    array (
                       'order_id'     => $order_id,
                       'order_total'  => $order_total_in_btc,
                       'order_datetime'  => date('Y-m-d H:i:s T'),
                       'requested_by_ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
                    ),
                    array (
                       ...
                    ),
                 ),
              'other_meta_info' => array (...)
           );
        */

        // Prepare `address_meta` field for this clean address.
        $address_meta = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT `address_meta` FROM `$btc_addresses_table_name` WHERE `btc_address` = %s",
                $clean_address
            )
        );
        $address_meta = BWWC_unserialize_address_meta($address_meta);

        if (!isset($address_meta['orders']) || !is_array($address_meta['orders'])) {
            $address_meta['orders'] = array();
        }

        $normalized_order_entry = BWWC__prepare_address_order_entry($order_info);
        array_unshift($address_meta['orders'], $normalized_order_entry);    // Prepend new order to array of orders
        if (count($address_meta['orders']) > 10) {
            array_pop($address_meta['orders']);
        }   // Do not keep history of more than 10 unfullfilled orders per address.
        $address_meta_serialized = BWWC_serialize_address_meta($address_meta);

        // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
        //
        $current_time = time();
        $remote_addr  = $order_info['requested_by_ip'];
        $query = $wpdb->prepare(
            "UPDATE `$btc_addresses_table_name`
                SET
                    `total_received_funds` = %s,
                    `received_funds_checked_at` = %d,
                    `status` = %s,
                    `assigned_at` = %d,
                    `last_assigned_to_ip` = %s,
                    `address_meta` = %s
                WHERE `btc_address` = %s",
            '0',
            $current_time,
            'assigned',
            $current_time,
            $remote_addr,
            $address_meta_serialized,
            $clean_address
        );
        $wpdb->query($query);

        $ret_info_array = array(
         'result'                      => 'success',
         'message'                     => "",
         'host_reply_raw'              => "",
         'generated_bitcoin_address'   => $clean_address,
         );

        return $ret_info_array;
    }
    //-------------------------------------------------------

    $ret_info_array = array(
      'result'                      => 'error',
      'message'                     => 'Failed to find/generate Bitcoin SV address. ' . $ret_addr_array['message'],
      'host_reply_raw'              => $ret_addr_array['host_reply_raw'],
      'generated_bitcoin_address'   => false,
      );
    return $ret_info_array;
}
//===========================================================================

//===========================================================================
// To accomodate for multiple MPK's and allowed key limits per MPK
function BWWC__get_next_available_mpk($bwwc_settings=false)
{
    //global $wpdb;
    //$btc_addresses_table_name = $wpdb->prefix . 'bwwc_btc_addresses';
    // Scan DB for MPK which has number of in-use keys less than alowed limit
    // ...

    if (!$bwwc_settings) {
        $bwwc_settings = BWWC__get_settings();
    }

    return @$bwwc_settings['electrum_mpks'][0];
}
//===========================================================================

//===========================================================================
/*
Returns:
   $ret_info_array = array (
      'result'                      => 'success', // 'error'
      'message'                     => '', // Failed to find/generate Bitcoin SV address',
      'host_reply_raw'              => '', // Error. No host reply availabe.',
      'generated_bitcoin_address'   => '18vzABPyVbbia8TDCKDtXJYXcoAFAPk2cj', // false,
      );
*/
// If $bwwc_settings or $electrum_mpk are missing - the best attempt will be made to manifest them.
// For performance reasons it is better to pass in these vars. if available.
//
function BWWC__generate_new_bitcoin_address_for_electrum_wallet($bwwc_settings=false, $electrum_mpk=false)
{
    global $wpdb;

    $btc_addresses_table_name = $wpdb->prefix . 'bwwc_btc_addresses';

    if (!$bwwc_settings) {
        $bwwc_settings = BWWC__get_settings();
    }

    if (!$electrum_mpk) {
        // Try to retrieve it from copy of settings.
        $electrum_mpk = BWWC__get_next_available_mpk();

        if (!$electrum_mpk || @$bwwc_settings['service_provider'] != 'electrum_wallet') {
            // Bitcoin SV gateway settings either were not saved
            $ret_info_array = array(
        'result'                      => 'error',
        'message'                     => 'No MPK passed and either no MPK present in copy-settings or service provider is not ElectrumSV',
        'host_reply_raw'              => '',
        'generated_bitcoin_address'   => false,
        );
            return $ret_info_array;
        }
    }

    $origin_id = $electrum_mpk;

    $funds_received_value_expires_in_secs = $bwwc_settings['funds_received_value_expires_in_mins'] * 60;
    $assigned_address_expires_in_secs     = $bwwc_settings['assigned_address_expires_in_mins'] * 60;

    $clean_address = false;

    // Find next index to generate
    $next_key_index = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT MAX(`index_in_wallet`) AS `max_index_in_wallet` FROM `$btc_addresses_table_name` WHERE `origin_id` = %s",
            $origin_id
        )
    );
    if ($next_key_index === null) {
        $next_key_index = $bwwc_settings['starting_index_for_new_btc_addresses'];
    } // Start generation of addresses from index #2 (skip two leading wallet's addresses)
    else {
        $next_key_index = $next_key_index+1;
    }  // Continue with next index

    $total_new_keys_generated = 0;
    $blockchains_api_failures = 0;
    do {
        $new_btc_address = BWWC__MATH_generate_bitcoin_address_from_mpk($electrum_mpk, $next_key_index);

        $address_request_array = array();
        $address_request_array['btc_address'] = $new_btc_address;
        $address_request_array['required_confirmations'] = 0;
        $address_request_array['api_timeout'] = $bwwc_settings['blockchain_api_timeout_secs'];
        $ret_info_array = BWWC__getreceivedbyaddress_info($address_request_array, $bwwc_settings);
        $total_new_keys_generated ++;

        if ($ret_info_array['balance'] === false) {
            $status = 'unknown';
        } elseif ($ret_info_array['balance'] == 0) {
            $status = 'unused';
        } // Newly generated address with freshly checked zero balance is unused and will be assigned.
        else {
            $status = 'used';
        }   // Generated address that was already used to receive money.

        $funds_received                  = ($ret_info_array['balance'] === false)?-1:$ret_info_array['balance'];
        $received_funds_checked_at_time  = ($ret_info_array['balance'] === false)?0:time();

        // Insert newly generated address into DB
        $query = $wpdb->prepare(
            "INSERT INTO `$btc_addresses_table_name`
                (`btc_address`, `origin_id`, `index_in_wallet`, `total_received_funds`, `received_funds_checked_at`, `status`)
                VALUES ( %s, %s, %d, %f, %d, %s )",
            $new_btc_address,
            $origin_id,
            $next_key_index,
            $funds_received,
            $received_funds_checked_at_time,
            $status
        );
        $wpdb->query($query);

        $next_key_index++;

        if ($ret_info_array['balance'] === false) {
            $blockchains_api_failures ++;
            if ($blockchains_api_failures >= $bwwc_settings['max_blockchains_api_failures']) {
                // Allow no more than 3 contigious blockchains API failures. After which return error reply.
                $ret_info_array = array(
          'result'                      => 'error',
          'message'                     => $ret_info_array['message'],
          'host_reply_raw'              => $ret_info_array['host_reply_raw'],
          'generated_bitcoin_address'   => false,
          );
                return $ret_info_array;
            }
        } else {
            if ($ret_info_array['balance'] == 0) {
                // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
                $clean_address    = $new_btc_address;
            }
        }

        if ($clean_address) {
            break;
        }

        if ($total_new_keys_generated >= $bwwc_settings['max_unusable_generated_addresses']) {
            // Stop it after generating of 20 unproductive addresses.
            // Something is wrong. Possibly old merchant's wallet (with many used addresses) is used for new installation. - For this case 'starting_index_for_new_btc_addresses'
            //  needs to be proper set to high value.
            $ret_info_array = array(
        'result'                      => 'error',
        'message'                     => "Problem: Generated '$total_new_keys_generated' addresses and none were found to be unused. Possibly old merchant's wallet (with many used addresses) is used for new installation. If that is the case - 'starting_index_for_new_btc_addresses' needs to be proper set to high value",
        'host_reply_raw'              => '',
        'generated_bitcoin_address'   => false,
        );
            return $ret_info_array;
        }
    } while (true);

    // Here only in case of clean address.
    $ret_info_array = array(
    'result'                      => 'success',
    'message'                     => '',
    'host_reply_raw'              => '',
    'generated_bitcoin_address'   => $clean_address,
    );

    return $ret_info_array;
}
//===========================================================================

//===========================================================================
// Function makes sure that returned value is valid array
function BWWC_unserialize_address_meta($flat_address_meta)
{
    // Strip escapes added by BWWC__safe_string_escape before unserializing
    $unserialized = @unserialize(stripslashes($flat_address_meta), ['allowed_classes' => false]);
    if (is_array($unserialized)) {
        return $unserialized;
    }
    return array();
}
//===========================================================================

//===========================================================================
// Function makes sure that value is ready to be stored in DB
function BWWC_serialize_address_meta($address_meta_arr)
{
    return BWWC__safe_string_escape(serialize($address_meta_arr));
}
//===========================================================================

//===========================================================================
/**
 * Normalize order metadata structure stored inside address_meta so cron jobs
 * can always rely on the presence of order_id / totals.
 *
 * @param array $order_info Raw order info captured during assignment.
 * @return array Normalized structure.
 */
function BWWC__prepare_address_order_entry($order_info)
{
    $normalized = array(
        'order_id'        => isset($order_info['order_id']) ? intval($order_info['order_id']) : 0,
        'order_total'     => isset($order_info['order_total']) ? floatval($order_info['order_total']) : 0,
        'order_datetime'  => isset($order_info['order_datetime']) ? $order_info['order_datetime'] : gmdate('Y-m-d H:i:s T'),
        'requested_by_ip' => isset($order_info['requested_by_ip']) ? $order_info['requested_by_ip'] : '',
        'paid'            => false,
    );

    if (isset($order_info['expected_sats'])) {
        $normalized['expected_sats'] = intval($order_info['expected_sats']);
    }

    if (isset($order_info['order_meta']['bw_currency'])) {
        $normalized['currency'] = sanitize_text_field($order_info['order_meta']['bw_currency']);
    }

    return $normalized;
}
//===========================================================================
