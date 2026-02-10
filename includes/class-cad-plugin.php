<?php

if (! defined('ABSPATH')) {
    exit;
}

class CAD_Plugin {
    /**
     * @var CAD_Plugin|null
     */
    private static $instance = null;

    /**
     * @var CAD_Access_Control
     */
    private $access_control;

    /**
     * @var CAD_User_Manager
     */
    private $user_manager;

    /**
     * @var CAD_Admin_Panel
     */
    private $admin_panel;

    /**
     * @return CAD_Plugin
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
    }

    public function init() {
        $this->load_dependencies();
        $this->load_textdomain();

        $this->access_control = new CAD_Access_Control();
        $this->user_manager   = new CAD_User_Manager($this->access_control);
        $this->admin_panel    = new CAD_Admin_Panel($this->access_control, $this->user_manager);
    }

    private function load_dependencies() {
        require_once CAD_PLUGIN_DIR . 'includes/class-cad-access-control.php';
        require_once CAD_PLUGIN_DIR . 'includes/class-cad-user-manager.php';
        require_once CAD_PLUGIN_DIR . 'includes/class-cad-admin-panel.php';
    }

    private function load_textdomain() {
        load_plugin_textdomain(
            'custom-admin-dashboard',
            false,
            dirname(plugin_basename(CAD_PLUGIN_FILE)) . '/languages'
        );
    }

    public static function activate() {
        require_once CAD_PLUGIN_DIR . 'includes/class-cad-access-control.php';

        $current = get_option(CAD_Access_Control::OPTION_KEY, array());
        $merged  = CAD_Access_Control::normalize_settings($current);

        update_option(CAD_Access_Control::OPTION_KEY, $merged);
        CAD_Access_Control::sync_role_caps($merged);
    }

    public static function deactivate() {
        // Keep configuration and capabilities for future re-activation.
    }
}
