<?php

if (! defined('ABSPATH')) {
    exit;
}

class CAD_Admin_Panel {
    /**
     * @var CAD_Access_Control
     */
    private $access_control;

    /**
     * @param CAD_Access_Control $access_control
     */
    public function __construct($access_control) {
        $this->access_control = $access_control;

        add_action('admin_menu', array($this, 'register_settings_page'));
        add_action('admin_init', array($this, 'handle_admin_requests'));
        add_action('admin_head', array($this, 'output_role_css_for_current_user'));
    }

    /**
     * Register simple settings page under "Settings".
     */
    public function register_settings_page() {
        if (! current_user_can('manage_options')) {
            return;
        }

        add_options_page(
            __('CSS por rol', 'custom-admin-dashboard'),
            __('CSS por rol', 'custom-admin-dashboard'),
            'manage_options',
            'cad-role-css',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Persist role-css settings.
     */
    public function handle_admin_requests() {
        if (! is_admin()) {
            return;
        }

        $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : '';
        if ($page !== 'cad-role-css') {
            return;
        }

        if (
            ! isset($_POST['cad_action']) ||
            sanitize_key(wp_unslash($_POST['cad_action'])) !== 'save_role_css'
        ) {
            return;
        }

        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para guardar esta configuracion.', 'custom-admin-dashboard'));
        }

        check_admin_referer('cad_save_role_css');

        $role_css = isset($_POST['role_css']) ? (array) wp_unslash($_POST['role_css']) : array();
        $settings = $this->access_control->get_settings();
        $settings['role_css'] = CAD_Access_Control::sanitize_role_css_map($role_css);
        $settings = CAD_Access_Control::normalize_settings($settings);

        update_option(CAD_Access_Control::OPTION_KEY, $settings);
        $this->access_control->flush_settings_cache();

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'       => 'cad-role-css',
                    'cad_notice' => 'settings_saved',
                ),
                admin_url('options-general.php')
            )
        );
        exit;
    }

    /**
     * Render settings page with CSS textarea per role.
     */
    public function render_settings_page() {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta pantalla.', 'custom-admin-dashboard'));
        }

        $roles = wp_roles();
        $all_roles = $roles instanceof WP_Roles ? $roles->roles : array();
        $role_css = $this->access_control->get_role_css_map();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('CSS personalizado por rol', 'custom-admin-dashboard'); ?></h1>
            <?php $this->render_notice(); ?>

            <p>
                <?php esc_html_e('Define CSS para cada rol. El CSS solo se aplica en wp-admin a usuarios que tengan ese rol.', 'custom-admin-dashboard'); ?>
            </p>

            <form method="post" action="<?php echo esc_url(add_query_arg(array('page' => 'cad-role-css'), admin_url('options-general.php'))); ?>">
                <?php wp_nonce_field('cad_save_role_css'); ?>
                <input type="hidden" name="cad_action" value="save_role_css" />

                <table class="form-table" role="presentation">
                    <tbody>
                        <?php foreach ($all_roles as $role_key => $role_data) : ?>
                            <?php
                            $label = isset($role_data['name']) ? (string) $role_data['name'] : (string) $role_key;
                            $css_value = isset($role_css[$role_key]) ? (string) $role_css[$role_key] : '';
                            ?>
                            <tr>
                                <th scope="row">
                                    <?php echo esc_html($label); ?>
                                    <br />
                                    <code><?php echo esc_html($role_key); ?></code>
                                </th>
                                <td>
                                    <textarea
                                        name="role_css[<?php echo esc_attr($role_key); ?>]"
                                        rows="8"
                                        class="large-text code"
                                    ><?php echo esc_textarea($css_value); ?></textarea>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button(__('Guardar CSS por rol', 'custom-admin-dashboard')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Print CSS for current user roles in admin area.
     */
    public function output_role_css_for_current_user() {
        if (! is_admin()) {
            return;
        }

        $css = $this->access_control->get_css_for_current_user();
        if ($css === '') {
            return;
        }

        echo '<style id="cad-role-css">' . "\n";
        echo $css . "\n";
        echo '</style>';
    }

    /**
     * Render save notice.
     */
    private function render_notice() {
        if (! isset($_GET['cad_notice'])) {
            return;
        }

        $notice = sanitize_key(wp_unslash($_GET['cad_notice']));
        if ($notice !== 'settings_saved') {
            return;
        }

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html__('Configuracion guardada correctamente.', 'custom-admin-dashboard')
        );
    }
}
