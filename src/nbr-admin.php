<?php
/*
NiobioCash for WooCommerce
https://github.com/niobio-cash/gateway-woocommerce
*/

// Include everything
include(dirname(__FILE__) . '/nbr-include-all.php');

//===========================================================================
// Global vars.

global $g_NBR__plugin_directory_url;
$g_NBR__plugin_directory_url = plugins_url('', __FILE__);

global $g_NBR__cron_script_url;
$g_NBR__cron_script_url = $g_NBR__plugin_directory_url . '/nbr-cron.php';

//===========================================================================

//===========================================================================
// Global default settings
global $g_NBR__config_defaults;
$g_NBR__config_defaults = array(

    // ------- Hidden constants
    'assigned_address_expires_in_mins' => 12 * 60,   // 12 hours to pay for order and receive necessary number of confirmations.
    'funds_received_value_expires_in_mins' => '5',        // 'received_funds_checked_at' is fresh (considered to be a valid value) if it was last checked within 'funds_received_value_expires_in_mins' minutes.
    'blockchain_api_timeout_secs' => '20',   // Connection and request timeouts for curl operations dealing with blockchain requests.
    'exchange_rate_api_timeout_secs' => '10',   // Connection and request timeouts for curl operations dealing with exchange rate API requests.
    'soft_cron_job_schedule_name' => 'minutes_1',   // WP cron job frequency
    'cache_exchange_rates_for_minutes' => 10,            // Cache exchange rate for that number of minutes without re-calling exchange rate API's.

    // ------- General Settings
    'service_provider' => 'local_wallet',        // 'blockchain_info'
    'address' => '',
    'confs_num' => '4', // number of confirmations required before accepting payment.
    'exchange_multiplier' => '1.00',

    'delete_db_tables_on_uninstall' => '0',
    'autocomplete_paid_orders' => '1',
    'enable_soft_cron_job' => '1',    // Enable "soft" Wordpress-driven cron jobs.

    // ------- Special settings
    'exchange_rates' => array('EUR' => array('method|type' => array('time-last-checked' => 0, 'exchange_rate' => 1), 'GBP' => array())),
);
//===========================================================================

//===========================================================================
function NBR__GetPluginNameVersionEdition($please_donate = false) // false to turn off
{
    $return_data = '<h2 style="border-bottom:1px solid #DDD;padding-bottom:10px;margin-bottom:20px;">' .
        NBR_PLUGIN_NAME . ', version: <span style="color:#EE0000;">' .
        NBR_VERSION . '</span>' .
        '</h2>';


    if ($please_donate) {
        $return_data .= '<p style="border:1px solid #890e4e;padding:5px 10px;color:#004400;background-color:#FFF;"><u>Please donate NBR to</u>:&nbsp;&nbsp;<span style="color:#d21577;font-size:110%;font-weight:bold;"></span></p>';
    }

    return $return_data;
}

//===========================================================================

//===========================================================================
function NBR__withdraw()
{
    $nbr_settings = NBR__get_settings();
    $address = $nbr_settings['address'];

    try {
        $wallet_api = new ForkNoteWalletd("http://127.0.0.1:18888");
        $address_balance = $wallet_api->getBalance($address);
    } catch (Exception $e) {
    }

    if ($address_balance === false) {
        return "NiobioCash address is not found in wallet.";
    } else {
        $address_balance = $address_balance['availableBalance'];
        //round ( float $val [, int $precision = 0 [, int $mode = PHP_ROUND_HALF_UP ]] )
        $display_address_balance = sprintf("%.4f", $address_balance / 1000000000000.0);
        $withdraw_fee = 100000000;
        $display_fee = sprintf("%.4f", $withdraw_fee / 1000000000000.0);
        $send_amount = (floor($address_balance / 100000000) * 100000000) - 200000000; // Only allows sending 4 decimal places
        $display_send_amount = sprintf("%.4f", $send_amount / 1000000000000.0);
        $send_address = $_POST["withdraw_address"];

        try {
            $sent = $wallet_api->sendTransaction(array($address), array(array("amount" => $send_amount, "address" => $send_address)), false, 6, $withdraw_fee, $address);
            return "Withdraw Sent in Transaction: " . $sent["transactionHash"];
            //@TODO Log
        } catch (Exception $e) {
            return $e->GetMessage();
        }
    }
}

//===========================================================================

//===========================================================================
function NBR__get_settings($key = false)
{
    global $g_NBR__plugin_directory_url;
    global $g_NBR__config_defaults;

    $nbr_settings = get_option(NBR_SETTINGS_NAME);
    if (!is_array($nbr_settings)) {
        $nbr_settings = array();
    }

    if ($key) {
        return (@$nbr_settings[$key]);
    } else {
        return ($nbr_settings);
    }
}

//===========================================================================

//===========================================================================
function NBR__update_settings($nbr_use_these_settings = false, $also_update_persistent_settings = false)
{
    if ($nbr_use_these_settings) {
        // if ($also_update_persistent_settings)
        //   NBR__update_persistent_settings ($nbr_use_these_settings);

        update_option(NBR_SETTINGS_NAME, $nbr_use_these_settings);
        return;
    }

    global $g_NBR__config_defaults;

    // Load current settings and overwrite them with whatever values are present on submitted form
    $nbr_settings = NBR__get_settings();

    foreach ($g_NBR__config_defaults as $k => $v) {
        if (isset($_POST[$k])) {
            if (!isset($nbr_settings[$k])) {
                $nbr_settings[$k] = "";
            } // Force set to something.
            NBR__update_individual_nbr_setting($nbr_settings[$k], $_POST[$k]);
        }
        // If not in POST - existing will be used.
    }

    update_option(NBR_SETTINGS_NAME, $nbr_settings);
}

//===========================================================================

//===========================================================================
// Takes care of recursive updating
function NBR__update_individual_nbr_setting(&$nbr_current_setting, $nbr_new_setting)
{
    if (is_string($nbr_new_setting)) {
        $nbr_current_setting = NBR__stripslashes($nbr_new_setting);
    } elseif (is_array($nbr_new_setting)) {  // Note: new setting may not exist yet in current setting: curr[t5] - not set yet, while new[t5] set.
        // Need to do recursive
        foreach ($nbr_new_setting as $k => $v) {
            if (!isset($nbr_current_setting[$k])) {
                $nbr_current_setting[$k] = "";
            }   // If not set yet - force set it to something.
            NBR__update_individual_nbr_setting($nbr_current_setting[$k], $v);
        }
    } else {
        $nbr_current_setting = $nbr_new_setting;
    }
}

//===========================================================================

//===========================================================================
//
// Reset settings only for one screen
function NBR__reset_partial_settings($also_reset_persistent_settings = false)
{
    global $g_NBR__config_defaults;

    // Load current settings and overwrite ones that are present on submitted form with defaults
    $nbr_settings = NBR__get_settings();

    foreach ($_POST as $k => $v) {
        if (isset($g_NBR__config_defaults[$k])) {
            if (!isset($nbr_settings[$k])) {
                $nbr_settings[$k] = "";
            } // Force set to something.
            NBR__update_individual_nbr_setting($nbr_settings[$k], $g_NBR__config_defaults[$k]);
        }
    }

    update_option(NBR_SETTINGS_NAME, $nbr_settings);

    // if ($also_reset_persistent_settings)
    //   NBR__update_persistent_settings ($nbr_settings);
}

//===========================================================================

//===========================================================================
function NBR__reset_all_settings($also_reset_persistent_settings = false)
{
    global $g_NBR__config_defaults;

    update_option(NBR_SETTINGS_NAME, $g_NBR__config_defaults);

    // if ($also_reset_persistent_settings)
    //   NBR__reset_all_persistent_settings ();
}

//===========================================================================

//===========================================================================
// Recursively strip slashes from all elements of multi-nested array
function NBR__stripslashes(&$val)
{
    if (is_string($val)) {
        return (stripslashes($val));
    }
    if (!is_array($val)) {
        return $val;
    }

    foreach ($val as $k => $v) {
        $val[$k] = NBR__stripslashes($v);
    }

    return $val;
}

//===========================================================================

//===========================================================================
/*
    ----------------------------------
    : Table 'nbr_payments' :
    ----------------------------------
      status                "unused"      - never been used address with last known zero balance
                            "assigned"    - order was placed and this address was assigned for payment
                            "revalidate"  - assigned/expired, unused or unknown address suddenly got non-zero balance in it. Revalidate it for possible late order payment against meta_data.
                            "used"        - order was placed and this address and payment in full was received. Address will not be used again.
                            "xused"       - address was used (touched with funds) by unknown entity outside of this application. No metadata is present for this address, will not be able to correlated it with any order.
                            "unknown"     - new address was generated but cannot retrieve balance due to blockchain API failure.
*/
function NBR__create_database_tables($nbr_settings)
{
    global $wpdb;

    $nbr_settings = NBR__get_settings();
    $must_update_settings = false;

    $nbr_payments_table_name = $wpdb->prefix . 'nbr_payments';

    if ($wpdb->get_var("SHOW TABLES LIKE '$nbr_payments_table_name'") != $nbr_payments_table_name) {
        $b_first_time = true;
    } else {
        $b_first_time = false;
    }

    //----------------------------------------------------------
    // Create tables
    $query = "CREATE TABLE IF NOT EXISTS `$nbr_payments_table_name` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `nbr_address` char(98) NOT NULL,
    `nbr_payment_id` char(64) NOT NULL,
    `origin_id` char(128) NOT NULL DEFAULT '',
    `index_in_wallet` bigint(20) NOT NULL DEFAULT '0',
    `status` char(16)  NOT NULL DEFAULT 'unknown',
    `last_assigned_to_ip` char(16) NOT NULL DEFAULT '0.0.0.0',
    `assigned_at` bigint(20) NOT NULL DEFAULT '0',
    `total_received_funds` DECIMAL( 16, 8 ) NOT NULL DEFAULT '0.00000000',
    `received_funds_checked_at` bigint(20) NOT NULL DEFAULT '0',
    `address_meta` MEDIUMBLOB NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `nbr_payment_id` (`nbr_payment_id`),
    KEY `index_in_wallet` (`index_in_wallet`),
    KEY `origin_id` (`origin_id`),
    KEY `status` (`status`)
    );";
    $wpdb->query($query);
    //----------------------------------------------------------
}

//===========================================================================

//===========================================================================
// NOTE: Irreversibly deletes all plugin tables and data
function NBR__delete_database_tables()
{
    global $wpdb;

    $nbr_payments_table_name = $wpdb->prefix . 'nbr_nbr_payments';

    $wpdb->query("DROP TABLE IF EXISTS `$nbr_payments_table_name`");
}
//===========================================================================
