<?php
/*
NiobioCash for WooCommerce
https://github.com/niobio-cash/gateway-woocommerce
*/


// Include everything
define('NBR_MUST_LOAD_WP', '1');
include(dirname(__FILE__) . '/nbr-include-all.php');

// Cpanel-scheduled cron job call
if (@$_REQUEST['hardcron']=='1') {
    NBR_cron_job_worker(true);
}

//===========================================================================
// '$hardcron' == true if job is ran by Cpanel's cron job.

function NBR_cron_job_worker($hardcron=false)
{
    global $wpdb;


    $nbr_settings = NBR__get_settings();

    // status = "unused", "assigned", "used"
    $nbr_payments_table_name = $wpdb->prefix . 'nbr_nbr_payments';

    $funds_received_value_expires_in_secs = $nbr_settings['funds_received_value_expires_in_mins'] * 60;
    $assigned_address_expires_in_secs = $nbr_settings['assigned_address_expires_in_mins'] * 60;
    $confirmations_required = $nbr_settings['confs_num'];

    $clean_address = null;
    $current_time = time();

    // Search for completed orders (addresses that received full payments for their orders) ...

    $query =
        "SELECT * FROM `$nbr_payments_table_name`
      WHERE
      (
        (`status`='assigned' AND (('$current_time' - `assigned_at`) < '$assigned_address_expires_in_secs'))
        OR
        (`status`='revalidate')
      )
      ORDER BY `received_funds_checked_at` ASC;"; // Check the ones that haven't been checked for the longest of time

    $rows_for_balance_check = $wpdb->get_results($query, ARRAY_A);

    if (is_array($rows_for_balance_check)) {
        $count_rows_for_balance_check = count($rows_for_balance_check);
    } else {
        $count_rows_for_balance_check = 0;
    }


    if (is_array($rows_for_balance_check)) {
        $ran_cycles = 0;
        foreach ($rows_for_balance_check as $row_for_balance_check) {
            $ran_cycles++;    // To limit number of cycles per soft cron job.

            // Prepare 'address_meta' for use.
            $address_meta = NBR_unserialize_address_meta(@$row_for_balance_check['address_meta']);
            $address_request_array = array();
            //$address_request_array['dcontext1'] = strlen(@$row_for_balance_check['address_meta']) . ":" . strlen($address_meta); // Arr test, delete it.
            $address_request_array['address_meta'] = $address_meta;


            // Retrieve current balance at address considering required confirmations number and api_timemout value.
            $address_request_array['nbr_payment_id'] = $row_for_balance_check['nbr_payment_id'];
            $address_request_array['nbr_address'] = $row_for_balance_check['nbr_address'];
            $address_request_array['block_index'] = $row_for_balance_check['index_in_wallet'];
            $address_request_array['required_confirmations'] = $confirmations_required;
            $address_request_array['api_timeout'] = $nbr_settings['blockchain_api_timeout_secs'];
            $balance_info_array = NBR__getreceivedbyaddress_info($address_request_array, $nbr_settings);

            $last_order_info = @$address_request_array['address_meta']['orders'][0];
            $row_id = $row_for_balance_check['id'];

            if ($balance_info_array['result'] == 'success') {
                /*
                $balance_info_array = array (
                        'result'                      => 'success',
                        'message'                     => "",
                        'host_reply_raw'              => "",
                        'balance'                     => $funds_received,
                        );
                */

                // Refresh 'received_funds_checked_at' field
                $current_time = time();
                $query =
                    "UPDATE `$nbr_payments_table_name`
             SET
                `total_received_funds` = '{$balance_info_array['balance']}',
                `received_funds_checked_at`='$current_time'
            WHERE `id`='$row_id';";
                $ret_code = $wpdb->query($query);

                if ($balance_info_array['balance'] > 0) {
                    NBR__log_event(__FILE__, __LINE__, "Cron job: NOTE: Detected non-zero balance at address: '{$row_for_balance_check['nbr_address']}, Payment ID = {$row_for_balance_check['nbr_payment_id']}, order ID = '{$last_order_info['order_id']}'. Detected balance ='{$balance_info_array['balance']}'.");

                    if ($balance_info_array['balance'] < $last_order_info['order_total']) {
                        NBR__log_event(__FILE__, __LINE__, "Cron job: NOTE: balance at address: '{$row_for_balance_check['nbr_address']}, Payment ID = {$row_for_balance_check['nbr_payment_id']}' (NBR '{$balance_info_array['balance']}') is not yet sufficient to complete it's order (order ID = '{$last_order_info['order_id']}'). Total required: '{$last_order_info['order_total']}'. Will wait for more funds to arrive...");
                    }
                }

                if ($balance_info_array['balance'] >= $last_order_info['order_total']) {
                    // Process full payment event

                    /*
                    $address_meta =
                       array (
                          'orders' =>
                             array (
                                // All orders placed on this address in reverse chronological order
                                array (
                                   'order_id'     => $order_id,
                                   'order_total'  => $order_total_in_nbr,
                                   'order_datetime'  => date('Y-m-d H:i:s T'),
                                   'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
                                ),
                                array (
                                   ...
                                ),
                             ),
                          'other_meta_info' => array (...)
                       );
                    */

                    // Last order was fully paid! Complete it...
                    NBR__log_event(__FILE__, __LINE__, "Cron job: NOTE: Full payment for order ID '{$last_order_info['order_id']}' detected at address: '{$row_for_balance_check['nbr_address']}, Payment ID = {$row_for_balance_check['nbr_payment_id']}' (NBR '{$balance_info_array['balance']}'). Total was required for this order: '{$last_order_info['order_total']}'. Processing order ...");

                    // Update order' meta info
                    $address_meta['orders'][0]['paid'] = true;

                    // Process and complete the order within WooCommerce (send confirmation emails, etc...)
                    NBR__process_payment_completed_for_order($last_order_info['order_id'], $balance_info_array['balance']);

                    // Update address' record
                    $address_meta_serialized = NBR_serialize_address_meta($address_meta);

                    // Update DB - mark address as 'used'.
                    //
                    $current_time = time();

                    // Note: `total_received_funds` and `received_funds_checked_at` are already updated above.
                    //
                    $query =
                        "UPDATE `$nbr_payments_table_name`
	             SET
	                `status`='used',
	                `address_meta`='$address_meta_serialized'
	            WHERE `id`='$row_id';";
                    $ret_code = $wpdb->query($query);
                    NBR__log_event(__FILE__, __LINE__, "Cron job: SUCCESS: Order ID '{$last_order_info['order_id']}' successfully completed.");
                }
            } else {
                NBR__log_event(__FILE__, __LINE__, "Cron job: Warning: Cannot retrieve balance for address: '{$row_for_balance_check['nbr_address']} Payment ID: {$row_for_balance_check['nbr_payment_id']}: " . $balance_info_array['message']);
            }
            //..//
        }
    }
}