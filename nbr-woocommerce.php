<?php
/*
Plugin Name: NBR for WooCommerce
Plugin URI: https://github.com/niobio-cash/gateway-woocommerce
Description: Niobio Cash for WooCommerce plugin allows you to accept payments in NBR for physical and digital products at your WooCommerce-powered online store.
Version: 1.0
Author: vinyvicente
Author URI: https://github.com/niobio-cash/gateway-woocommerce
License: GPL 2.0
*/

// Include everything
include(dirname(__FILE__) . '/autoload.php');

//---------------------------------------------------------------------------
// Add hooks and filters

// create custom plugin settings menu
add_action('admin_menu', 'NBR_create_menu');

register_activation_hook(__FILE__, 'NBR_activate');
register_deactivation_hook(__FILE__, 'NBR_deactivate');
register_uninstall_hook(__FILE__, 'NBR_uninstall');

add_filter('cron_schedules', 'NBR__add_custom_scheduled_intervals');
add_action('NBR_cron_action', 'NBR_cron_job_worker');     // Multiple functions can be attached to 'NBR_cron_action' action

NBR_set_lang_file();
//---------------------------------------------------------------------------

//===========================================================================
// activating the default values
function NBR_activate()
{
    global  $g_NBR__config_defaults;

    $nbr_default_options = $g_NBR__config_defaults;

    // This will overwrite default options with already existing options but leave new options (in case of upgrading to new version) untouched.
    $nbr_settings = NBR__get_settings();

    foreach ($nbr_settings as $key=>$value) {
        $nbr_default_options[$key] = $value;
    }

    update_option(NBR_SETTINGS_NAME, $nbr_default_options);

    // Re-get new settings.
    $nbr_settings = NBR__get_settings();

    // Create necessary database tables if not already exists...
    NBR__create_database_tables($nbr_settings);
    NBR__SubIns();

    //----------------------------------
    // Setup cron jobs

    if ($nbr_settings['enable_soft_cron_job'] && !wp_next_scheduled('NBR_cron_action')) {
        $cron_job_schedule_name = $nbr_settings['soft_cron_job_schedule_name'];
        wp_schedule_event(time(), $cron_job_schedule_name, 'NBR_cron_action');
    }
    //----------------------------------
}
//---------------------------------------------------------------------------
// Cron Subfunctions
function NBR__add_custom_scheduled_intervals($schedules)
{
    $schedules['seconds_30']     = array('interval'=>30,     'display'=>__('Once every 30 seconds'));
    $schedules['minutes_1']      = array('interval'=>1*60,   'display'=>__('Once every 1 minute'));
    $schedules['minutes_2.5']    = array('interval'=>2.5*60, 'display'=>__('Once every 2.5 minutes'));
    $schedules['minutes_5']      = array('interval'=>5*60,   'display'=>__('Once every 5 minutes'));

    return $schedules;
}
//---------------------------------------------------------------------------
//===========================================================================

//===========================================================================
// deactivating
function NBR_deactivate()
{
    // Do deactivation cleanup. Do not delete previous settings in case user will reactivate plugin again...

    //----------------------------------
    // Clear cron jobs
    wp_clear_scheduled_hook('NBR_cron_action');
    //----------------------------------
}
//===========================================================================

//===========================================================================
// uninstalling
function NBR_uninstall()
{
    $nbr_settings = NBR__get_settings();

    if ($nbr_settings['delete_db_tables_on_uninstall']) {
        // delete all settings.
        delete_option(NBR_SETTINGS_NAME);

        // delete all DB tables and data.
        NBR__delete_database_tables();
    }
}
//===========================================================================

//===========================================================================
function NBR_create_menu()
{

    // create new top-level menu
    // http://www.fileformat.info/info/unicode/char/e3f/index.htm
    add_menu_page(
        __('Woo NBR', NBR_I18N_DOMAIN),                    // Page title
        __('Niobio Cash', NBR_I18N_DOMAIN),                        // Menu Title - lower corner of admin menu
        'administrator',                                        // Capability
        'nbr-settings',                                        // Handle - First submenu's handle must be equal to parent's handle to avoid duplicate menu entry.
        'NBR__render_general_settings_page',                   // Function
        plugins_url('/images/nbr_16x.png', __FILE__)      // Icon URL
        );

    add_submenu_page(
        'nbr-settings',                                        // Parent
        __("WooCommerce Niobio Cash Gateway", NBR_I18N_DOMAIN),                   // Page title
        __("General Settings", NBR_I18N_DOMAIN),               // Menu Title
        'administrator',                                        // Capability
        'nbr-settings',                                        // Handle - First submenu's handle must be equal to parent's handle to avoid duplicate menu entry.
        'NBR__render_general_settings_page'                    // Function
        );
}
//===========================================================================

//===========================================================================
// load language files
function NBR_set_lang_file()
{
    # set the language file
    $currentLocale = get_locale();
    if (!empty($currentLocale)) {
        $moFile = dirname(__FILE__) . "/lang/" . $currentLocale . ".mo";
        if (@file_exists($moFile) && is_readable($moFile)) {
            load_textdomain(NBR_I18N_DOMAIN, $moFile);
        }
    }
}
//===========================================================================
