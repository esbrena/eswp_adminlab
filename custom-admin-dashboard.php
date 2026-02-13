<?php
/**
 * Plugin Name: Custom Admin Dashboard
 * Description: CSS personalizado por rol y gestion avanzada de usuarios en wp-admin.
 * Version: 2.3.1
 * Author: AdminLab
 * Text Domain: custom-admin-dashboard
 */

if (! defined('ABSPATH')) {
    exit;
}

define('CAD_VERSION', '2.3.1');
define('CAD_PLUGIN_FILE', __FILE__);
define('CAD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CAD_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once CAD_PLUGIN_DIR . 'includes/class-cad-plugin.php';

/**
 * Boot plugin singleton.
 *
 * @return CAD_Plugin
 */
function cad_plugin() {
    return CAD_Plugin::instance();
}

register_activation_hook(__FILE__, array('CAD_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('CAD_Plugin', 'deactivate'));

cad_plugin();
