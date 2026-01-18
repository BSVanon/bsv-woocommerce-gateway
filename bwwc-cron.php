<?php
/*
Bitcoin SV Payments for WooCommerce
https://github.com/mboyd1/bitcoin-sv-payments-for-woocommerce
*/

if ( ! defined( 'ABSPATH' ) ) exit;


// Include everything
define('BWWC_MUST_LOAD_WP', '1');
include(dirname(__FILE__) . '/bwwc-include-all.php');

// REMOVED: Public hardcron trigger (DoS vulnerability - A0.6)
// This file is now only called by WP-Cron scheduled events
// For reliable cron, configure server cron to call wp-cron.php directly

//===========================================================================
// Cron job worker - called by WP-Cron scheduled events

function BWWC_cron_job_worker()
{
    global $wpdb;


    $bwwc_settings = BWWC__get_settings();

    if (@$bwwc_settings['service_provider'] != 'electrum_wallet') {
        return; // Only active electrum wallet as a service provider needs cron job
    }

    // status = "unused", "assigned", "used"
    $btc_addresses_table_name     = $wpdb->prefix . 'bwwc_btc_addresses';

    $funds_received_value_expires_in_secs = $bwwc_settings['funds_received_value_expires_in_mins'] * 60;
    $assigned_address_expires_in_secs     = $bwwc_settings['assigned_address_expires_in_mins'] * 60;
    $confirmations_required = $bwwc_settings['confs_num'];

    $clean_address = null;
    $current_time = time();

    // Search for completed orders (addresses that received full payments for their orders) ...

    // NULL == not found
    // Retrieve:
    //     'assigned'   - unexpired, with old balances (due for revalidation. Fresh balances and still 'assigned' means no [full] payment received yet)
    //     'revalidate' - all
    //        order results by most recently assigned
    $query = $wpdb->prepare(
        "SELECT * FROM `$btc_addresses_table_name`
            WHERE
            (
              (`status`='assigned' AND ((%d - `assigned_at`) < %d))
              OR
              (`status`='revalidate')
            )
            AND ((%d - `received_funds_checked_at`) > %d)
            ORDER BY `received_funds_checked_at` ASC",
        $current_time,
        $assigned_address_expires_in_secs,
        $current_time,
        $funds_received_value_expires_in_secs
    ); // Check the ones that haven't been checked for longest time

    $rows_for_balance_check = $wpdb->get_results($query, ARRAY_A);

    if (is_array($rows_for_balance_check)) {
        $count_rows_for_balance_check = count($rows_for_balance_check);
    } else {
        $count_rows_for_balance_check = 0;
    }


    if (is_array($rows_for_balance_check)) {
        $ran_cycles = 0;
        foreach ($rows_for_balance_check as $row_for_balance_check) {
            $ran_cycles++;	// To limit number of cycles per soft cron job.

            // Prepare 'address_meta' for use.
            $address_meta    = BWWC_unserialize_address_meta(@$row_for_balance_check['address_meta']);
            $address_request_array = array();
            $address_request_array['address_meta'] = $address_meta;


            // Retrieve current balance at address considering required confirmations number and api_timemout value.
            $address_request_array['btc_address'] = $row_for_balance_check['btc_address'];
            $address_request_array['required_confirmations'] = $confirmations_required;
            $address_request_array['api_timeout'] = $bwwc_settings['blockchain_api_timeout_secs'];
            $balance_info_array = BWWC__getreceivedbyaddress_info($address_request_array, $bwwc_settings);

            $last_order_info = @$address_request_array['address_meta']['orders'][0];
            $row_id          = $row_for_balance_check['id'];
            
            // Skip if order is already completed or processing
            if ($last_order_info && isset($last_order_info['order_id'])) {
                $order = wc_get_order($last_order_info['order_id']);
                if ($order && in_array($order->get_status(), array('completed', 'processing', 'cancelled', 'refunded', 'failed'))) {
                    // Ensure payment_state reflects final status to stop frontend polling
                    $current_payment_state = get_post_meta($last_order_info['order_id'], 'payment_state', true);
                    if (($order->get_status() === 'completed' || $order->get_status() === 'processing') && $current_payment_state !== 'confirmed') {
                        update_post_meta($last_order_info['order_id'], 'payment_state', 'confirmed');
                        BWWC__log_event(__FILE__, __LINE__, "Cron: Updated payment_state to 'confirmed' for order {$last_order_info['order_id']} (status: {$order->get_status()})");
                    }
                    
                    // Mark address as used and skip further processing
                    $wpdb->query($wpdb->prepare(
                        "UPDATE `$btc_addresses_table_name` SET `status` = 'used' WHERE `id` = %d",
                        $row_id
                    ));
                    continue;
                }
            }

            if ($balance_info_array['result'] == 'success') {
                $current_time = time();
                // BWWC__getreceivedbyaddress_info() returns confirmed BTC (8-decimal string)
                $confirmed_btc = floatval($balance_info_array['balance']);
                $confirmed_sats = isset($balance_info_array['confirmed_sats']) ? intval($balance_info_array['confirmed_sats']) : intval(round($confirmed_btc * 100000000));
                $unconfirmed_sats = isset($balance_info_array['unconfirmed_sats']) ? intval($balance_info_array['unconfirmed_sats']) : 0;
                $total_sats = isset($balance_info_array['total_sats']) ? intval($balance_info_array['total_sats']) : ($confirmed_sats + $unconfirmed_sats);
                $total_btc = $total_sats / 100000000;

                // Refresh 'received_funds_checked_at' field with confirmed balance
                $ret_code = $wpdb->query($wpdb->prepare(
                    "UPDATE `$btc_addresses_table_name` SET `total_received_funds` = %s, `received_funds_checked_at` = %d WHERE `id` = %d",
                    $confirmed_btc,
                    $current_time,
                    $row_id
                ));

                if ($confirmed_sats > 0 || $unconfirmed_sats > 0) {
                    if ($row_for_balance_check['status'] == 'revalidate') {
                        // Address with suddenly appeared balance. Check if that is matching to previously-placed [likely expired] order
                        if (!$last_order_info || !@$last_order_info['order_id'] || !@$balance_info_array['balance'] || !@$last_order_info['order_total']) {
                            // No proper metadata present. Mark this address as 'xused' (used by unknown entity outside of this application) and be done with it forever.
                            $ret_code = $wpdb->query($wpdb->prepare(
                                "UPDATE `$btc_addresses_table_name` SET `status` = 'xused' WHERE `id` = %d",
                                $row_id
                            ));
                            continue;
                        } else {
                            // Metadata for this address is present. Mark this address as 'assigned' and treat it like that further down...
                            $ret_code = $wpdb->query($wpdb->prepare(
                                "UPDATE `$btc_addresses_table_name` SET `status` = 'assigned' WHERE `id` = %d",
                                $row_id
                            ));
                        }
                    }

                    BWWC__log_event(__FILE__, __LINE__, "Cron job: NOTE: Detected balance at address '{$row_for_balance_check['btc_address']}' for order ID {$last_order_info['order_id']}. Confirmed={$confirmed_sats} sats, Total including mempool={$total_sats} sats.");

                    // Update payment state meta for UI
                    $order_id = $last_order_info['order_id'];
                    $expected_btc = floatval($last_order_info['order_total']);
                    $expected_sats = intval(round($expected_btc * 100000000));
                    $received_btc = $total_btc;
                    
                    update_post_meta($order_id, 'received_sats', $total_sats);
                    update_post_meta($order_id, 'confirmed_sats', $confirmed_sats);
                    update_post_meta($order_id, 'last_checked_at', time());
                    
                    // Fetch transaction history to get txids and confirmations
                    $max_confirmations = 0;
                    $tx_history_response = BWWC__file_get_contents(
                        'https://api.whatsonchain.com/v1/bsv/main/address/' . $row_for_balance_check['btc_address'] . '/history',
                        false,
                        $bwwc_settings['blockchain_api_timeout_secs']
                    );
                    if ($tx_history_response) {
                        $tx_history = json_decode(trim($tx_history_response), true);
                        if (is_array($tx_history) && count($tx_history) > 0) {
                            $txids = array();
                            $chain_height = BWWC__get_current_chain_height($bwwc_settings['blockchain_api_timeout_secs']);
                            
                            foreach ($tx_history as $tx) {
                                if (isset($tx['tx_hash'])) {
                                    $txids[] = $tx['tx_hash'];
                                }
                                if (isset($tx['height']) && $tx['height'] > 0) {
                                    $confirmations = 1;
                                    if ($chain_height && $tx['height'] > 0) {
                                        $confirmations = max(1, ($chain_height - intval($tx['height'])) + 1);
                                    }
                                    $max_confirmations = max($max_confirmations, $confirmations);
                                }
                            }
                            
                            if (!empty($txids)) {
                                update_post_meta($order_id, 'txids', implode(',', $txids));
                                update_post_meta($order_id, 'best_confirmations', $max_confirmations);
                                BWWC__log_event(__FILE__, __LINE__, "Cron job: Stored " . count($txids) . " transaction ID(s) for order {$order_id}, best confirmations: {$max_confirmations}");
                            }
                        }
                    }
                    // Extend expiration if funds detected (prevents scary "expired" while awaiting confirmations)
                    if ($total_sats > 0) {
                        $expires_at = intval(get_post_meta($order_id, 'address_expires_at', true));
                        $pending_extension_secs = max($assigned_address_expires_in_secs, $confirmations_required * 10 * 60);
                        $proposed_expiration = $current_time + $pending_extension_secs;
                        if ($proposed_expiration > $expires_at) {
                            update_post_meta($order_id, 'address_expires_at', $proposed_expiration);
                        }
                    }

                    // Determine payment state using consistent logic
                    if ($total_sats < $expected_sats) {
                        update_post_meta($order_id, 'payment_state', 'underpaid');
                        BWWC__log_event(__FILE__, __LINE__, "Cron: Set payment_state to 'underpaid' for order {$order_id} (received {$total_sats} of {$expected_sats} sats)");
                    } elseif ($confirmed_sats >= $expected_sats && $max_confirmations >= $confirmations_required) {
                        update_post_meta($order_id, 'payment_state', 'confirmed');
                        BWWC__log_event(__FILE__, __LINE__, "Cron: Set payment_state to 'confirmed' for order {$order_id} (confirmed {$confirmed_sats} sats, {$max_confirmations} confirmations)");
                    } else {
                        update_post_meta($order_id, 'payment_state', 'pending');
                        BWWC__log_event(__FILE__, __LINE__, "Cron: Set payment_state to 'pending' for order {$order_id} (total {$total_sats} sats, confirmed {$confirmed_sats} sats, {$max_confirmations}/{$confirmations_required} confirmations)");
                    }
                } else {
                    // No funds detected - reset to waiting if previously pending/underpaid
                    update_post_meta($last_order_info['order_id'], 'received_sats', 0);
                    update_post_meta($last_order_info['order_id'], 'confirmed_sats', 0);
                    $current_state = get_post_meta($last_order_info['order_id'], 'payment_state', true);
                    if (in_array($current_state, array('pending', 'underpaid'))) {
                        update_post_meta($last_order_info['order_id'], 'payment_state', 'waiting');
                    }
                }

                // Note: to be perfectly safe against late-paid orders, we need to:
                //	Scan '$address_meta['orders']' for first UNPAID order that is exactly matching amount at address.

                // Check if payment is fully confirmed and ready to process
                if ($confirmed_sats >= $expected_sats && $max_confirmations >= $confirmations_required) {
                    // Process full payment event

                    /*
                    $address_meta =
                       array (
                          'orders' =>
                             array (
                                // All orders placed on this address in reverse chronological order
                                array (
                                   'order_id'     => $order_id,
                                   'order_total'  => $order_total_in_btc,
                                   'order_datetime'  => gmdate('Y-m-d H:i:s T'),
                                   'requested_by_ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
                                ),
                                array (
                                   ...
                                ),
                             ),
                          'other_meta_info' => array (...)
                       );
                    */

                    // Last order was fully paid! Complete it...
                    BWWC__log_event(__FILE__, __LINE__, "Cron job: NOTE: Full payment for order ID '{$last_order_info['order_id']}' detected at address: '{$row_for_balance_check['btc_address']}' (BTC '$received_btc'). Total was required for this order: '$expected_btc'. Processing order ...");

                    // Update payment state to confirmed
                    update_post_meta($last_order_info['order_id'], 'payment_state', 'confirmed');
                    update_post_meta($last_order_info['order_id'], 'best_confirmations', 1); // Will be updated by blockchain check
                    BWWC__log_event(__FILE__, __LINE__, "Cron: Set payment_state to 'confirmed' for order {$last_order_info['order_id']} - processing payment completion");

                    // Update order' meta info
                    $address_meta['orders'][0]['paid'] = true;

                    // Process and complete the order within WooCommerce (send confirmation emails, etc...)
                    BWWC__process_payment_completed_for_order($last_order_info['order_id'], $confirmed_btc);

                    // Update address' record
                    $address_meta_serialized = BWWC_serialize_address_meta($address_meta);

                    // Update DB - mark address as 'used'.
                    //
                    $current_time = time();

                    // Note: `total_received_funds` and `received_funds_checked_at` are already updated above.
                    //
                    $ret_code = $wpdb->query($wpdb->prepare(
                        "UPDATE `$btc_addresses_table_name` SET `status` = 'used', `address_meta` = %s WHERE `id` = %d",
                        $address_meta_serialized,
                        $row_id
                    ));
                    BWWC__log_event(__FILE__, __LINE__, "Cron job: SUCCESS: Order ID '{$last_order_info['order_id']}' successfully completed.");


                    // This is not needed here. Let it process as many orders as are paid for in the same loop.
// Maybe to be moved there --> //..// (to avoid soft-cron checking of balance of hundreds of addresses in a same loop)
//
// 	        //	Return here to avoid overloading too many processing needs to one random visitor.
// 	        //	Then it means no more than one order can be processed per 2.5 minutes (or whatever soft cron schedule is).
// 	        //	Hard cron is immune to this limitation.
// 	        if (!$hardcron && $ran_cycles >= $bwwc_settings['soft_cron_max_loops_per_run'])
// 	        {

// 	        	return;
// 	        }
                }
            } else {
                BWWC__log_event(__FILE__, __LINE__, "Cron job: Warning: Cannot retrieve balance for address: '{$row_for_balance_check['btc_address']}: " . $balance_info_array['message']);
            }
            //..//
        }
    }

    // Process all 'revalidate' addresses here.
    // ...

    //-----------------------------------------------------
    // Pre-generate new Bitcoin SV address for ElectrumSV wallet

    // Try to retrieve mpk from copy of settings.
    $electrum_mpk = BWWC__get_next_available_mpk();

    if ($electrum_mpk && @$bwwc_settings['service_provider'] == 'electrum_wallet') {
        // Calculate number of unused addresses belonging to currently active ElectrumSV wallet

        $origin_id = $electrum_mpk;

        $current_time = time();
        $assigned_address_expires_in_secs     = $bwwc_settings['assigned_address_expires_in_mins'] * 60;

        if ($bwwc_settings['reuse_expired_addresses']) {
            $reuse_expired_addresses_query_part = $wpdb->prepare(
                "OR (`status`='assigned' AND ((%d - `assigned_at`) > %d))",
                $current_time,
                $assigned_address_expires_in_secs
            );
        } else {
            $reuse_expired_addresses_query_part = "";
        }

        // Calculate total number of currently unused addresses in a system. Make sure there aren't too many.

        // NULL == not found
        // Retrieve:
        //     'unused'   - with fresh zero balances
        //     'assigned' - expired, with fresh zero balances (if 'reuse_expired_addresses' is true)
        //
        // Hence - any returned address will be clean to use.
        $query = $wpdb->prepare(
            "SELECT COUNT(*) as `total_unused_addresses` FROM `$btc_addresses_table_name`
               WHERE `origin_id` = %s
               AND `total_received_funds` = %s
               AND (`status`='unused' $reuse_expired_addresses_query_part)",
            $origin_id,
            '0'
        );
        $total_unused_addresses = $wpdb->get_var($query);


        if ($total_unused_addresses < $bwwc_settings['max_unused_addresses_buffer']) {
            BWWC__generate_new_bitcoin_address_for_electrum_wallet($bwwc_settings, $electrum_mpk);
        }
    }
    //-----------------------------------------------------
}
//===========================================================================
