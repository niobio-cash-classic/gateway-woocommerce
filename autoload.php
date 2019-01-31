<?php

/**
 * NBR for WooCommerce
 * @see https://github.com/niobio-cash/gateway-woocommerce
 * @license GPL 2.0
 * @author Vinicius V. de Oliveira <vinyvicente@gmail.com>
 */

if (!defined('NBR_PLUGIN_NAME')) {
    define('NBR_VERSION', '1.1');

    define('NBR_EDITION', 'Standard');

    define('NBR_SETTINGS_NAME', 'NBR-Settings');
    define('NBR_PLUGIN_NAME', 'NBR for WooCommerce');

    define('NBR_I18N_DOMAIN', 'nbr');
}

if (defined('NBR_MUST_LOAD_WP') && !defined('WP_USE_THEMES') && !defined('ABSPATH')) {
    $g_blog_dir = preg_replace('|(/+[^/]+){4}$|', '', str_replace('\\', '/', __FILE__)); // For love of the art of regex-ing
    define('WP_USE_THEMES', false);
    require_once($g_blog_dir . '/wp-blog-header.php');

    // Force-elimination of header 404 for non-wordpress pages.
    header('HTTP/1.1 200 OK');
    header('Status: 200 OK');

    require_once($g_blog_dir . '/wp-admin/includes/admin.php');
}

define('NBR_BASE_URL', dirname(__FILE__));

require_once(NBR_BASE_URL . '/src/forkNoteAPI.php');
require_once(NBR_BASE_URL . '/src/nbr-cron.php');
require_once(NBR_BASE_URL . '/src/nbr-utils.php');
require_once(NBR_BASE_URL . '/src/nbr-admin.php');
require_once(NBR_BASE_URL . '/src/nbr-render-settings.php');
require_once(NBR_BASE_URL . '/src/nbr-gateway.php');
