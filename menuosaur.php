<?php
/**
 * Plugin Name: Menuosaur
 * Plugin URI: https://example.com/
 * Description: Build WordPress menu shortcodes from cached Square catalog categories, items, variations, and prices.
 * Version: 1.0.13
 * Author: Alex Burgess
 * License: GPLv2 or later
 * Text Domain: menuosaur
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MENUOSAUR_VERSION', '1.0.13');
define('MENUOSAUR_DB_VERSION', '1');
define('MENUOSAUR_PLUGIN_FILE', __FILE__);
define('MENUOSAUR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MENUOSAUR_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once MENUOSAUR_PLUGIN_DIR . 'includes/class-menuosaur-manager.php';
require_once MENUOSAUR_PLUGIN_DIR . 'includes/class-menuosaur-plugin.php';

function menuosaur_plugin() {
    return Menuosaur_Plugin::instance();
}

register_activation_hook(MENUOSAUR_PLUGIN_FILE, array('Menuosaur_Plugin', 'activate'));
register_deactivation_hook(MENUOSAUR_PLUGIN_FILE, array('Menuosaur_Plugin', 'deactivate'));

add_action('plugins_loaded', 'menuosaur_plugin');
