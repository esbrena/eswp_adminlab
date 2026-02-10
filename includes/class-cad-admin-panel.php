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
     * @var CAD_User_Manager
     */
    private $user_manager;

    /**
     * @param CAD_Access_Control $access_control
     * @param CAD_User_Manager   $user_manager
     */
    public function __construct($access_control, $user_manager) {
        $this->access_control = $access_control;
        $this->user_manager   = $user_manager;

        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_menu', array($this, 'cleanup_admin_menu'), 999);
        add_action('admin_init', array($this, 'handle_admin_requests'));
        add_action('admin_init', array($this, 'maybe_force_redirect_to_custom_dashboard'), 5);
        add_action('wp_dashboard_setup', array($this, 'remove_dashboard_widgets'), 99);
        add_action('admin_bar_menu', array($this, 'prune_admin_bar'), 999);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_head', array($this, 'output_admin_customizations'));
        add_filter('login_redirect', array($this, 'maybe_redirect_after_login'), 20, 3);
    }

    /**
     * Register custom admin pages.
     */
    public function register_menu() {
        if (! $this->access_control->is_current_user_allowed()) {
            return;
        }

        add_menu_page(
            __('Panel operativo', 'custom-admin-dashboard'),
            __('Panel operativo', 'custom-admin-dashboard'),
            'read',
            'cad-dashboard',
            array($this, 'render_dashboard_page'),
            'dashicons-screenoptions',
            2
        );

        add_submenu_page(
            'cad-dashboard',
            __('Resumen', 'custom-admin-dashboard'),
            __('Resumen', 'custom-admin-dashboard'),
            'read',
            'cad-dashboard',
            array($this, 'render_dashboard_page')
        );

        if ($this->access_control->can_manage_users()) {
            add_submenu_page(
                'cad-dashboard',
                __('Usuarios', 'custom-admin-dashboard'),
                __('Usuarios', 'custom-admin-dashboard'),
                'read',
                'cad-users',
                array($this->user_manager, 'render_users_page')
            );
        }

        if ($this->can_manage_settings()) {
            add_submenu_page(
                'cad-dashboard',
                __('Configuracion de visibilidad', 'custom-admin-dashboard'),
                __('Configuracion', 'custom-admin-dashboard'),
                'read',
                'cad-settings',
                array($this, 'render_settings_page')
            );
        }
    }

    /**
     * Hide menu items not allowed for operational admins.
     */
    public function cleanup_admin_menu() {
        if (! $this->access_control->is_current_user_operational_admin()) {
            return;
        }

        $settings = $this->access_control->get_settings();
        if (empty($settings['hide_menus'])) {
            return;
        }

        global $menu, $submenu;
        if (! is_array($menu)) {
            return;
        }

        $allowed_top = $this->get_allowed_top_level_menu_slugs_for_operational_admin();
        $allowed_sub = $this->get_allowed_submenu_slugs_for_operational_admin($allowed_top);

        foreach ($menu as $index => $item) {
            $slug = isset($item[2]) ? (string) $item[2] : '';
            if ($slug === '' || strpos($slug, 'separator') === 0) {
                continue;
            }

            if (! in_array($slug, $allowed_top, true)) {
                unset($menu[$index]);
            }
        }

        if (! is_array($submenu)) {
            return;
        }

        foreach ($submenu as $parent_slug => $items) {
            $parent_slug = (string) $parent_slug;
            if (! in_array($parent_slug, $allowed_top, true)) {
                unset($submenu[$parent_slug]);
                continue;
            }

            foreach ($items as $item_index => $submenu_item) {
                $submenu_slug = isset($submenu_item[2]) ? (string) $submenu_item[2] : '';
                if ($submenu_slug === '') {
                    continue;
                }

                if (! in_array($submenu_slug, $allowed_sub, true)) {
                    unset($submenu[$parent_slug][$item_index]);
                }
            }
        }
    }

    /**
     * Persist plugin settings from custom form.
     */
    public function handle_admin_requests() {
        if (! is_admin()) {
            return;
        }

        $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : '';
        if ($page !== 'cad-settings') {
            return;
        }

        if (
            ! isset($_POST['cad_action']) ||
            sanitize_key(wp_unslash($_POST['cad_action'])) !== 'save_settings'
        ) {
            return;
        }

        if (! $this->can_manage_settings()) {
            wp_die(esc_html__('Solo el superadmin puede guardar esta configuracion.', 'custom-admin-dashboard'));
        }

        check_admin_referer('cad_save_settings');

        $allowed_roles = isset($_POST['allowed_roles']) ? (array) wp_unslash($_POST['allowed_roles']) : array();
        $allowed_roles = CAD_Access_Control::sanitize_role_list($allowed_roles);
        if (empty($allowed_roles)) {
            $allowed_roles = array('administrator');
        }

        $ui_input = array(
            'show_users_section'        => isset($_POST['ui_show_users_section']) ? 1 : 0,
            'show_posts_section'        => isset($_POST['ui_show_posts_section']) ? 1 : 0,
            'allowed_post_types'        => isset($_POST['ui_allowed_post_types']) ? (array) wp_unslash($_POST['ui_allowed_post_types']) : array(),
            'show_plugins_section'      => isset($_POST['ui_show_plugins_section']) ? 1 : 0,
            'allowed_plugin_menus'      => isset($_POST['ui_allowed_plugin_menus']) ? (array) wp_unslash($_POST['ui_allowed_plugin_menus']) : array(),
            'show_profile_menu'         => isset($_POST['ui_show_profile_menu']) ? 1 : 0,
            'hide_wp_dashboard_widgets' => isset($_POST['ui_hide_wp_dashboard_widgets']) ? 1 : 0,
            'hide_admin_bar_items'      => isset($_POST['ui_hide_admin_bar_items']) ? 1 : 0,
            'hide_wp_notices'           => isset($_POST['ui_hide_wp_notices']) ? 1 : 0,
        );
        $ui_input = CAD_Access_Control::sanitize_ui_settings($ui_input);

        $branding_input = array(
            'logo_url'              => isset($_POST['branding_logo_url']) ? wp_unslash($_POST['branding_logo_url']) : '',
            'header_title'          => isset($_POST['branding_header_title']) ? wp_unslash($_POST['branding_header_title']) : '',
            'header_subtitle'       => isset($_POST['branding_header_subtitle']) ? wp_unslash($_POST['branding_header_subtitle']) : '',
            'primary_color'         => isset($_POST['branding_primary_color']) ? wp_unslash($_POST['branding_primary_color']) : '',
            'accent_color'          => isset($_POST['branding_accent_color']) ? wp_unslash($_POST['branding_accent_color']) : '',
            'background_color'      => isset($_POST['branding_background_color']) ? wp_unslash($_POST['branding_background_color']) : '',
            'card_background_color' => isset($_POST['branding_card_background_color']) ? wp_unslash($_POST['branding_card_background_color']) : '',
            'custom_css'            => isset($_POST['branding_custom_css']) ? wp_unslash($_POST['branding_custom_css']) : '',
        );
        $branding_input = CAD_Access_Control::sanitize_branding_settings($branding_input);

        $settings = $this->access_control->get_settings();
        $settings['allowed_roles']  = $allowed_roles;
        $settings['force_redirect'] = isset($_POST['force_redirect']) ? 1 : 0;
        $settings['hide_menus']     = isset($_POST['hide_menus']) ? 1 : 0;
        $settings['ui']             = $ui_input;
        $settings['branding']       = $branding_input;
        $settings                   = CAD_Access_Control::normalize_settings($settings);

        update_option(CAD_Access_Control::OPTION_KEY, $settings);
        CAD_Access_Control::sync_role_caps($settings);
        $this->access_control->flush_settings_cache();

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'       => 'cad-settings',
                    'cad_notice' => 'settings_saved',
                ),
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Redirect restricted admins away from disallowed screens.
     */
    public function maybe_force_redirect_to_custom_dashboard() {
        if (! is_admin() || ! $this->access_control->is_current_user_operational_admin()) {
            return;
        }

        $settings = $this->access_control->get_settings();
        if (empty($settings['force_redirect'])) {
            return;
        }

        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        if ($this->is_current_request_allowed_for_operational_admin()) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=cad-dashboard'));
        exit;
    }

    /**
     * Redirect allowed users to custom dashboard after login.
     *
     * @param string           $redirect_to
     * @param string           $requested_redirect_to
     * @param WP_User|WP_Error $user
     *
     * @return string
     */
    public function maybe_redirect_after_login($redirect_to, $requested_redirect_to, $user) {
        if (! $user instanceof WP_User) {
            return $redirect_to;
        }

        $settings = $this->access_control->get_settings();
        if (empty($settings['force_redirect'])) {
            return $redirect_to;
        }

        if ($this->access_control->is_super_admin_user($user->ID)) {
            return admin_url('admin.php?page=cad-dashboard');
        }

        $allowed_roles = CAD_Access_Control::sanitize_role_list($settings['allowed_roles']);
        if (! empty(array_intersect((array) $user->roles, $allowed_roles))) {
            return admin_url('admin.php?page=cad-dashboard');
        }

        if (! empty($user->allcaps[CAD_Access_Control::CAP_ACCESS_DASHBOARD])) {
            return admin_url('admin.php?page=cad-dashboard');
        }

        return $redirect_to;
    }

    /**
     * Hide dashboard widgets for operational admins when configured.
     */
    public function remove_dashboard_widgets() {
        if (! $this->access_control->is_current_user_operational_admin()) {
            return;
        }

        $ui = $this->access_control->get_ui_settings();
        if (empty($ui['hide_wp_dashboard_widgets'])) {
            return;
        }

        global $wp_meta_boxes;
        if (isset($wp_meta_boxes['dashboard'])) {
            $wp_meta_boxes['dashboard'] = array();
        }
    }

    /**
     * Remove noisy admin bar nodes for operational admins.
     *
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public function prune_admin_bar($wp_admin_bar) {
        if (! $this->access_control->is_current_user_operational_admin()) {
            return;
        }

        $ui = $this->access_control->get_ui_settings();
        if (empty($ui['hide_admin_bar_items'])) {
            return;
        }

        if (! $wp_admin_bar instanceof WP_Admin_Bar) {
            return;
        }

        $remove_nodes = array(
            'wp-logo',
            'about',
            'wporg',
            'documentation',
            'support-forums',
            'feedback',
            'site-name',
            'view-site',
            'comments',
            'new-content',
            'updates',
            'customize',
            'search',
        );

        foreach ($remove_nodes as $node_id) {
            $wp_admin_bar->remove_node($node_id);
        }
    }

    /**
     * Load base styles and inject branding variables.
     */
    public function enqueue_assets() {
        if (! $this->access_control->is_current_user_allowed()) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $is_cad_page = $page !== '' && strpos($page, 'cad-') === 0;
        $is_operational = $this->access_control->is_current_user_operational_admin();

        if (! $is_cad_page && ! $is_operational) {
            return;
        }

        wp_enqueue_style(
            'cad-admin-style',
            CAD_PLUGIN_URL . 'assets/admin.css',
            array(),
            CAD_VERSION
        );

        $branding = $this->access_control->get_branding_settings();
        wp_add_inline_style('cad-admin-style', $this->build_dynamic_css($branding));
    }

    /**
     * Hide extra WP elements for operational admins.
     */
    public function output_admin_customizations() {
        if (! $this->access_control->is_current_user_operational_admin()) {
            return;
        }

        $ui = $this->access_control->get_ui_settings();
        $css = '';

        if (! empty($ui['hide_wp_notices'])) {
            $css .= '.notice:not(.cad-keep-notice), .update-nag { display: none !important; }';
            $css .= '#wpfooter { display: none !important; }';
            $css .= '#contextual-help-link-wrap, #screen-options-link-wrap { display: none !important; }';
        }

        if ($css !== '') {
            printf('<style id="cad-operational-ui">%s</style>', $css);
        }
    }

    /**
     * Render dashboard landing page.
     */
    public function render_dashboard_page() {
        if (! $this->access_control->is_current_user_allowed()) {
            wp_die(esc_html__('No tienes permisos para acceder al panel.', 'custom-admin-dashboard'));
        }

        $ui = $this->access_control->get_ui_settings();
        $users = count_users();
        $allowed_post_types = ! empty($ui['show_posts_section']) ? CAD_Access_Control::sanitize_post_type_list($ui['allowed_post_types']) : array();
        $posts_total = $this->count_posts_by_types($allowed_post_types);

        $plugin_candidates = $this->get_plugin_menu_candidates();
        $enabled_plugin_menus = ! empty($ui['show_plugins_section']) ? CAD_Access_Control::sanitize_menu_slug_list($ui['allowed_plugin_menus']) : array();
        ?>
        <div class="wrap cad-wrap">
            <?php $this->render_brand_header(__('Resumen operativo', 'custom-admin-dashboard')); ?>
            <?php $this->render_notice(); ?>

            <div class="cad-grid">
                <?php if ($this->access_control->can_manage_users()) : ?>
                    <div class="cad-card">
                        <h2><?php esc_html_e('Gestion de usuarios', 'custom-admin-dashboard'); ?></h2>
                        <p class="cad-count"><?php echo esc_html((string) $users['total_users']); ?></p>
                        <p><?php esc_html_e('Acceso a formulario simplificado para editar datos y metadatos.', 'custom-admin-dashboard'); ?></p>
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=cad-users')); ?>">
                            <?php esc_html_e('Abrir usuarios', 'custom-admin-dashboard'); ?>
                        </a>
                    </div>
                <?php endif; ?>

                <?php if (! empty($ui['show_posts_section'])) : ?>
                    <div class="cad-card">
                        <h2><?php esc_html_e('Seccion de posts', 'custom-admin-dashboard'); ?></h2>
                        <p class="cad-count"><?php echo esc_html((string) $posts_total); ?></p>
                        <p><?php esc_html_e('Tipos de contenido permitidos para admins operativos:', 'custom-admin-dashboard'); ?></p>
                        <ul class="cad-list-inline">
                            <?php foreach ($allowed_post_types as $post_type) : ?>
                                <li>
                                    <a href="<?php echo esc_url($post_type === 'post' ? admin_url('edit.php') : admin_url('edit.php?post_type=' . $post_type)); ?>">
                                        <?php echo esc_html($this->get_post_type_label($post_type)); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (! empty($ui['show_plugins_section'])) : ?>
                    <div class="cad-card">
                        <h2><?php esc_html_e('Plugins visibles', 'custom-admin-dashboard'); ?></h2>
                        <p><?php esc_html_e('Menus de plugins permitidos por el superadmin:', 'custom-admin-dashboard'); ?></p>
                        <?php if (empty($enabled_plugin_menus)) : ?>
                            <p><?php esc_html_e('No hay plugins habilitados para admins operativos.', 'custom-admin-dashboard'); ?></p>
                        <?php else : ?>
                            <ul class="cad-list-inline">
                                <?php foreach ($enabled_plugin_menus as $menu_slug) : ?>
                                    <?php
                                    $label = isset($plugin_candidates[$menu_slug]) ? $plugin_candidates[$menu_slug]['label'] : $menu_slug;
                                    $url = isset($plugin_candidates[$menu_slug]) ? $plugin_candidates[$menu_slug]['url'] : admin_url('admin.php?page=' . rawurlencode($menu_slug));
                                    ?>
                                    <li><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <h2><?php esc_html_e('Que controla esta configuracion', 'custom-admin-dashboard'); ?></h2>
            <ul class="cad-quick-links">
                <li><?php esc_html_e('Que menus y pantallas ven los admins no-superadmin.', 'custom-admin-dashboard'); ?></li>
                <li><?php esc_html_e('Si pueden acceder a usuarios, posts y menus de plugins instalados.', 'custom-admin-dashboard'); ?></li>
                <li><?php esc_html_e('Nivel de limpieza de UI: widgets, barra admin y avisos no necesarios.', 'custom-admin-dashboard'); ?></li>
                <li><?php esc_html_e('Branding visual: logo, colores y CSS custom para tu panel.', 'custom-admin-dashboard'); ?></li>
            </ul>

            <?php if ($this->can_manage_settings()) : ?>
                <p>
                    <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=cad-settings')); ?>">
                        <?php esc_html_e('Abrir configuracion avanzada', 'custom-admin-dashboard'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render settings page for superadmin.
     */
    public function render_settings_page() {
        if (! $this->can_manage_settings()) {
            wp_die(esc_html__('Solo el superadmin puede gestionar esta configuracion.', 'custom-admin-dashboard'));
        }

        $settings = $this->access_control->get_settings();
        $ui = $this->access_control->get_ui_settings();
        $branding = $this->access_control->get_branding_settings();

        $roles = wp_roles();
        $all_roles = $roles instanceof WP_Roles ? $roles->roles : array();

        $available_post_types = $this->get_available_post_type_options();
        $plugin_candidates = $this->get_plugin_menu_candidates();
        $selected_plugin_menus = CAD_Access_Control::sanitize_menu_slug_list($ui['allowed_plugin_menus']);
        $unknown_plugin_menus = array_diff($selected_plugin_menus, array_keys($plugin_candidates));
        ?>
        <div class="wrap cad-wrap">
            <?php $this->render_brand_header(__('Configuracion de visibilidad y branding', 'custom-admin-dashboard')); ?>
            <?php $this->render_notice(); ?>

            <p>
                <?php esc_html_e('Como superadmin defines aqui exactamente que ve el resto de admins operativos en wp-admin.', 'custom-admin-dashboard'); ?>
            </p>

            <form method="post" action="<?php echo esc_url(add_query_arg(array('page' => 'cad-settings'), admin_url('admin.php'))); ?>">
                <?php wp_nonce_field('cad_save_settings'); ?>
                <input type="hidden" name="cad_action" value="save_settings" />

                <h2><?php esc_html_e('1) Roles objetivo', 'custom-admin-dashboard'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Roles administrados', 'custom-admin-dashboard'); ?></th>
                            <td>
                                <fieldset class="cad-checkbox-grid">
                                    <?php foreach ($all_roles as $role_key => $role_data) : ?>
                                        <label>
                                            <input
                                                type="checkbox"
                                                name="allowed_roles[]"
                                                value="<?php echo esc_attr($role_key); ?>"
                                                <?php checked(in_array($role_key, (array) $settings['allowed_roles'], true)); ?>
                                            />
                                            <?php echo esc_html(isset($role_data['name']) ? $role_data['name'] : $role_key); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>
                                <p class="description">
                                    <?php esc_html_e('Incluye aqui tu rol custom "admin" para aplicarle estas reglas.', 'custom-admin-dashboard'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Aplicar restricciones de menu', 'custom-admin-dashboard'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="hide_menus" value="1" <?php checked(! empty($settings['hide_menus'])); ?> />
                                    <?php esc_html_e('Ocultar menus no permitidos y limpiar elementos de WordPress innecesarios.', 'custom-admin-dashboard'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Redireccion inteligente', 'custom-admin-dashboard'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="force_redirect" value="1" <?php checked(! empty($settings['force_redirect'])); ?> />
                                    <?php esc_html_e('Si acceden a una pantalla no permitida, volver al dashboard custom.', 'custom-admin-dashboard'); ?>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('2) Que secciones puede ver el admin operativo', 'custom-admin-dashboard'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Gestion de usuarios', 'custom-admin-dashboard'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ui_show_users_section" value="1" <?php checked(! empty($ui['show_users_section'])); ?> />
                                    <?php esc_html_e('Permitir la seccion de usuarios (formulario simplificado).', 'custom-admin-dashboard'); ?>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e('Seccion de posts', 'custom-admin-dashboard'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ui_show_posts_section" value="1" <?php checked(! empty($ui['show_posts_section'])); ?> />
                                    <?php esc_html_e('Permitir acceso a la seccion de posts.', 'custom-admin-dashboard'); ?>
                                </label>
                                <fieldset class="cad-checkbox-grid cad-child-fieldset">
                                    <?php foreach ($available_post_types as $post_type => $post_type_label) : ?>
                                        <label>
                                            <input
                                                type="checkbox"
                                                name="ui_allowed_post_types[]"
                                                value="<?php echo esc_attr($post_type); ?>"
                                                <?php checked(in_array($post_type, (array) $ui['allowed_post_types'], true)); ?>
                                            />
                                            <?php echo esc_html($post_type_label); ?>
                                            <code><?php echo esc_html($post_type); ?></code>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>
                                <p class="description">
                                    <?php esc_html_e('Selecciona exactamente que tipos de contenido pueden gestionar.', 'custom-admin-dashboard'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e('Menus de plugins instalados', 'custom-admin-dashboard'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ui_show_plugins_section" value="1" <?php checked(! empty($ui['show_plugins_section'])); ?> />
                                    <?php esc_html_e('Permitir acceso a plugins concretos.', 'custom-admin-dashboard'); ?>
                                </label>

                                <?php if (empty($plugin_candidates)) : ?>
                                    <p><?php esc_html_e('No se detectan menus de plugins en este momento.', 'custom-admin-dashboard'); ?></p>
                                <?php else : ?>
                                    <fieldset class="cad-checkbox-grid cad-child-fieldset">
                                        <?php foreach ($plugin_candidates as $plugin_menu_slug => $plugin_menu_data) : ?>
                                            <label>
                                                <input
                                                    type="checkbox"
                                                    name="ui_allowed_plugin_menus[]"
                                                    value="<?php echo esc_attr($plugin_menu_slug); ?>"
                                                    <?php checked(in_array($plugin_menu_slug, (array) $selected_plugin_menus, true)); ?>
                                                />
                                                <?php echo esc_html($plugin_menu_data['label']); ?>
                                                <code><?php echo esc_html($plugin_menu_slug); ?></code>
                                            </label>
                                        <?php endforeach; ?>
                                    </fieldset>
                                <?php endif; ?>

                                <?php if (! empty($unknown_plugin_menus)) : ?>
                                    <p class="description">
                                        <?php esc_html_e('Menus guardados que ahora no estan disponibles:', 'custom-admin-dashboard'); ?>
                                        <code><?php echo esc_html(implode(', ', $unknown_plugin_menus)); ?></code>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e('Perfil propio', 'custom-admin-dashboard'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ui_show_profile_menu" value="1" <?php checked(! empty($ui['show_profile_menu'])); ?> />
                                    <?php esc_html_e('Permitir que cada admin operativo vea/edite su perfil.', 'custom-admin-dashboard'); ?>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('3) Limpieza de contenido innecesario de WordPress', 'custom-admin-dashboard'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Widgets del dashboard', 'custom-admin-dashboard'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ui_hide_wp_dashboard_widgets" value="1" <?php checked(! empty($ui['hide_wp_dashboard_widgets'])); ?> />
                                    <?php esc_html_e('Ocultar widgets nativos de la portada de WordPress.', 'custom-admin-dashboard'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Barra de admin', 'custom-admin-dashboard'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ui_hide_admin_bar_items" value="1" <?php checked(! empty($ui['hide_admin_bar_items'])); ?> />
                                    <?php esc_html_e('Quitar accesos de la barra superior no necesarios.', 'custom-admin-dashboard'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Avisos y ayudas', 'custom-admin-dashboard'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ui_hide_wp_notices" value="1" <?php checked(! empty($ui['hide_wp_notices'])); ?> />
                                    <?php esc_html_e('Ocultar notices de WordPress/plugins para simplificar la UI operativa.', 'custom-admin-dashboard'); ?>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('4) Branding y personalizacion visual', 'custom-admin-dashboard'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="cad-branding-logo-url"><?php esc_html_e('Logo de cabecera (URL)', 'custom-admin-dashboard'); ?></label></th>
                            <td>
                                <input type="url" id="cad-branding-logo-url" class="regular-text" name="branding_logo_url" value="<?php echo esc_attr($branding['logo_url']); ?>" />
                                <p class="description"><?php esc_html_e('Puedes pegar URL de la imagen del logo.', 'custom-admin-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cad-branding-header-title"><?php esc_html_e('Titulo de cabecera', 'custom-admin-dashboard'); ?></label></th>
                            <td>
                                <input type="text" id="cad-branding-header-title" class="regular-text" name="branding_header_title" value="<?php echo esc_attr($branding['header_title']); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cad-branding-header-subtitle"><?php esc_html_e('Subtitulo de cabecera', 'custom-admin-dashboard'); ?></label></th>
                            <td>
                                <input type="text" id="cad-branding-header-subtitle" class="regular-text" name="branding_header_subtitle" value="<?php echo esc_attr($branding['header_subtitle']); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Colores del panel', 'custom-admin-dashboard'); ?></th>
                            <td class="cad-color-grid">
                                <label>
                                    <?php esc_html_e('Color primario', 'custom-admin-dashboard'); ?><br />
                                    <input type="color" name="branding_primary_color" value="<?php echo esc_attr($branding['primary_color']); ?>" />
                                </label>
                                <label>
                                    <?php esc_html_e('Color acento', 'custom-admin-dashboard'); ?><br />
                                    <input type="color" name="branding_accent_color" value="<?php echo esc_attr($branding['accent_color']); ?>" />
                                </label>
                                <label>
                                    <?php esc_html_e('Fondo panel', 'custom-admin-dashboard'); ?><br />
                                    <input type="color" name="branding_background_color" value="<?php echo esc_attr($branding['background_color']); ?>" />
                                </label>
                                <label>
                                    <?php esc_html_e('Fondo tarjetas', 'custom-admin-dashboard'); ?><br />
                                    <input type="color" name="branding_card_background_color" value="<?php echo esc_attr($branding['card_background_color']); ?>" />
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cad-branding-custom-css"><?php esc_html_e('CSS custom', 'custom-admin-dashboard'); ?></label></th>
                            <td>
                                <textarea id="cad-branding-custom-css" class="large-text code" rows="8" name="branding_custom_css"><?php echo esc_textarea($branding['custom_css']); ?></textarea>
                                <p class="description"><?php esc_html_e('Opcional: CSS adicional para terminar de personalizar el panel.', 'custom-admin-dashboard'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Guardar configuracion', 'custom-admin-dashboard')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * @return bool
     */
    private function can_manage_settings() {
        if (is_multisite()) {
            return $this->access_control->is_super_admin_user();
        }

        return current_user_can('manage_options');
    }

    /**
     * @return array
     */
    private function get_allowed_top_level_menu_slugs_for_operational_admin() {
        $ui = $this->access_control->get_ui_settings();

        $allowed = array(
            'cad-dashboard',
        );

        if ($this->access_control->can_manage_users()) {
            $allowed[] = 'cad-users';
        }

        if (! empty($ui['show_profile_menu'])) {
            $allowed[] = 'profile.php';
        }

        if (! empty($ui['show_posts_section'])) {
            $post_types = CAD_Access_Control::sanitize_post_type_list($ui['allowed_post_types']);
            foreach ($post_types as $post_type) {
                $allowed[] = $post_type === 'post'
                    ? 'edit.php'
                    : 'edit.php?post_type=' . $post_type;
            }
        }

        if (! empty($ui['show_plugins_section'])) {
            $allowed = array_merge(
                $allowed,
                CAD_Access_Control::sanitize_menu_slug_list($ui['allowed_plugin_menus'])
            );
        }

        $allowed = array_values(array_unique($allowed));

        /**
         * Filter allowed top-level menu slugs for operational admins.
         *
         * @param array           $allowed
         * @param array           $ui
         * @param CAD_Admin_Panel $panel
         */
        return (array) apply_filters('cad_allowed_menu_slugs', $allowed, $ui, $this);
    }

    /**
     * @param array $allowed_top
     *
     * @return array
     */
    private function get_allowed_submenu_slugs_for_operational_admin($allowed_top) {
        $ui = $this->access_control->get_ui_settings();
        $allowed = array(
            'cad-dashboard',
        );

        if ($this->access_control->can_manage_users() && in_array('cad-users', $allowed_top, true)) {
            $allowed[] = 'cad-users';
        }

        if (! empty($ui['show_profile_menu'])) {
            $allowed[] = 'profile.php';
        }

        if (! empty($ui['show_posts_section'])) {
            $post_types = CAD_Access_Control::sanitize_post_type_list($ui['allowed_post_types']);
            foreach ($post_types as $post_type) {
                if ($post_type === 'post') {
                    $allowed[] = 'edit.php';
                    $allowed[] = 'post-new.php';
                } else {
                    $allowed[] = 'edit.php?post_type=' . $post_type;
                    $allowed[] = 'post-new.php?post_type=' . $post_type;
                }
            }
        }

        if (! empty($ui['show_plugins_section'])) {
            $plugin_top = CAD_Access_Control::sanitize_menu_slug_list($ui['allowed_plugin_menus']);
            $allowed = array_merge($allowed, $this->get_allowed_plugin_submenu_slugs($plugin_top));
        }

        if ($this->can_manage_settings()) {
            $allowed[] = 'cad-settings';
        }

        return array_values(array_unique($allowed));
    }

    /**
     * @param array $allowed_plugin_menus
     *
     * @return array
     */
    private function get_allowed_plugin_submenu_slugs($allowed_plugin_menus) {
        global $submenu;

        $allowed = array();
        $allowed_plugin_menus = CAD_Access_Control::sanitize_menu_slug_list($allowed_plugin_menus);

        foreach ($allowed_plugin_menus as $menu_slug) {
            $allowed[] = $menu_slug;
            $allowed[] = 'admin.php?page=' . $menu_slug;

            if (! is_array($submenu) || ! isset($submenu[$menu_slug]) || ! is_array($submenu[$menu_slug])) {
                continue;
            }

            foreach ($submenu[$menu_slug] as $submenu_item) {
                $sub_slug = isset($submenu_item[2]) ? (string) $submenu_item[2] : '';
                if ($sub_slug !== '') {
                    $allowed[] = $sub_slug;
                }
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * @return bool
     */
    private function is_current_request_allowed_for_operational_admin() {
        global $pagenow;

        $ui = $this->access_control->get_ui_settings();
        $current_page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

        if ($current_page !== '' && strpos($current_page, 'cad-') === 0) {
            return true;
        }

        if (in_array($pagenow, array('admin-ajax.php', 'async-upload.php', 'update.php'), true)) {
            return true;
        }

        if ($pagenow === 'profile.php') {
            return ! empty($ui['show_profile_menu']);
        }

        if (in_array($pagenow, array('users.php', 'user-edit.php', 'user-new.php'), true)) {
            return $this->access_control->can_manage_users();
        }

        if (in_array($pagenow, array('edit.php', 'post.php', 'post-new.php'), true)) {
            if (empty($ui['show_posts_section'])) {
                return false;
            }

            $post_type = $this->get_request_post_type($pagenow);
            return $this->access_control->can_access_post_type($post_type);
        }

        if ($this->is_allowed_plugin_request($pagenow, $ui)) {
            return true;
        }

        return false;
    }

    /**
     * @param string $pagenow
     * @param array  $ui
     *
     * @return bool
     */
    private function is_allowed_plugin_request($pagenow, $ui) {
        if (empty($ui['show_plugins_section'])) {
            return false;
        }

        $allowed_plugin_menus = CAD_Access_Control::sanitize_menu_slug_list($ui['allowed_plugin_menus']);
        if (empty($allowed_plugin_menus)) {
            return false;
        }

        if (in_array($pagenow, $allowed_plugin_menus, true)) {
            return true;
        }

        $allowed_plugin_submenus = $this->get_allowed_plugin_submenu_slugs($allowed_plugin_menus);
        if (in_array($pagenow, $allowed_plugin_submenus, true)) {
            return true;
        }

        if ($pagenow !== 'admin.php') {
            return false;
        }

        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page === '') {
            return false;
        }

        if (in_array($page, $allowed_plugin_menus, true)) {
            return true;
        }

        foreach ($allowed_plugin_submenus as $submenu_slug) {
            if ($submenu_slug === $page) {
                return true;
            }

            if (strpos($submenu_slug, 'admin.php?page=') === 0) {
                $submenu_page = substr($submenu_slug, strlen('admin.php?page='));
                if ($submenu_page === $page) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param string $pagenow
     *
     * @return string
     */
    private function get_request_post_type($pagenow) {
        if ($pagenow === 'post.php' && isset($_GET['post'])) {
            $post_id = absint($_GET['post']);
            if ($post_id > 0) {
                $post_type = get_post_type($post_id);
                return $post_type ? (string) $post_type : 'post';
            }
        }

        if (isset($_GET['post_type'])) {
            return sanitize_key(wp_unslash($_GET['post_type']));
        }

        return 'post';
    }

    /**
     * @param array $post_types
     *
     * @return int
     */
    private function count_posts_by_types($post_types) {
        $post_types = CAD_Access_Control::sanitize_post_type_list($post_types);
        $existing_post_types = array();

        foreach ($post_types as $post_type) {
            if (post_type_exists($post_type)) {
                $existing_post_types[] = $post_type;
            }
        }

        if (empty($existing_post_types)) {
            return 0;
        }

        $query = new WP_Query(
            array(
                'post_type'              => $existing_post_types,
                'post_status'            => 'any',
                'posts_per_page'         => 1,
                'fields'                 => 'ids',
                'no_found_rows'          => false,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );

        return (int) $query->found_posts;
    }

    /**
     * @param string $post_type
     *
     * @return string
     */
    private function get_post_type_label($post_type) {
        $obj = get_post_type_object($post_type);
        if (! $obj) {
            return $post_type;
        }

        if (! empty($obj->labels->name)) {
            return (string) $obj->labels->name;
        }

        if (! empty($obj->label)) {
            return (string) $obj->label;
        }

        return $post_type;
    }

    /**
     * @return array
     */
    private function get_available_post_type_options() {
        $post_types = get_post_types(array('show_ui' => true), 'objects');
        $excluded = array(
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_navigation',
            'wp_global_styles',
        );

        $options = array();
        foreach ($post_types as $post_type => $obj) {
            if (in_array($post_type, $excluded, true)) {
                continue;
            }

            if (! $obj instanceof WP_Post_Type) {
                continue;
            }

            $label = ! empty($obj->labels->name) ? (string) $obj->labels->name : $post_type;
            $options[$post_type] = $label;
        }

        asort($options);
        return $options;
    }

    /**
     * @return array
     */
    private function get_plugin_menu_candidates() {
        global $menu, $submenu;

        if (! is_array($menu)) {
            return array();
        }

        $core_slugs = array(
            'index.php',
            'edit.php',
            'upload.php',
            'edit.php?post_type=page',
            'edit-comments.php',
            'themes.php',
            'plugins.php',
            'users.php',
            'profile.php',
            'tools.php',
            'options-general.php',
            'separator1',
            'separator2',
            'separator-last',
            'cad-dashboard',
            'cad-settings',
            'cad-users',
        );

        $candidates = array();
        foreach ($menu as $item) {
            $slug = isset($item[2]) ? (string) $item[2] : '';
            if ($slug === '' || strpos($slug, 'separator') === 0) {
                continue;
            }

            if (in_array($slug, $core_slugs, true)) {
                continue;
            }

            if (strpos($slug, 'edit.php?post_type=') === 0) {
                continue;
            }

            $label = $this->clean_menu_label(isset($item[0]) ? (string) $item[0] : $slug);
            $link_slug = $slug;
            if (is_array($submenu) && isset($submenu[$slug][0][2]) && ! empty($submenu[$slug][0][2])) {
                $link_slug = (string) $submenu[$slug][0][2];
            }

            $candidates[$slug] = array(
                'label' => $label !== '' ? $label : $slug,
                'slug'  => $slug,
                'url'   => $this->menu_slug_to_url($link_slug),
            );
        }

        ksort($candidates);
        return $candidates;
    }

    /**
     * @param string $slug
     *
     * @return string
     */
    private function menu_slug_to_url($slug) {
        $slug = (string) $slug;
        if ($slug === '') {
            return admin_url();
        }

        if (strpos($slug, 'http://') === 0 || strpos($slug, 'https://') === 0) {
            return $slug;
        }

        if (strpos($slug, '.php') !== false || strpos($slug, '?') !== false) {
            return admin_url($slug);
        }

        return admin_url('admin.php?page=' . rawurlencode($slug));
    }

    /**
     * @param string $label
     *
     * @return string
     */
    private function clean_menu_label($label) {
        $label = preg_replace('/<span class="update-plugins[^>]*>.*?<\/span>/i', '', $label);
        return trim((string) wp_strip_all_tags((string) $label));
    }

    /**
     * @param array $branding
     *
     * @return string
     */
    private function build_dynamic_css($branding) {
        $branding = CAD_Access_Control::sanitize_branding_settings($branding);

        $css  = ':root{';
        $css .= '--cad-primary:' . $branding['primary_color'] . ';';
        $css .= '--cad-accent:' . $branding['accent_color'] . ';';
        $css .= '--cad-bg:' . $branding['background_color'] . ';';
        $css .= '--cad-card-bg:' . $branding['card_background_color'] . ';';
        $css .= '}';

        if (! empty($branding['custom_css'])) {
            $css .= "\n" . (string) $branding['custom_css'];
        }

        return $css;
    }

    /**
     * @param string $page_title
     */
    private function render_brand_header($page_title) {
        $branding = $this->access_control->get_branding_settings();
        $logo_url = isset($branding['logo_url']) ? (string) $branding['logo_url'] : '';
        $app_title = isset($branding['header_title']) ? (string) $branding['header_title'] : '';
        $subtitle = isset($branding['header_subtitle']) ? (string) $branding['header_subtitle'] : '';
        ?>
        <div class="cad-brand-header">
            <?php if ($logo_url !== '') : ?>
                <div class="cad-brand-logo-wrap">
                    <img src="<?php echo esc_url($logo_url); ?>" class="cad-brand-logo" alt="<?php echo esc_attr($app_title !== '' ? $app_title : 'CAD'); ?>" />
                </div>
            <?php endif; ?>
            <div class="cad-brand-copy">
                <?php if ($app_title !== '') : ?>
                    <p class="cad-brand-app-title"><?php echo esc_html($app_title); ?></p>
                <?php endif; ?>
                <h1 class="cad-brand-page-title"><?php echo esc_html($page_title); ?></h1>
                <?php if ($subtitle !== '') : ?>
                    <p class="cad-brand-subtitle"><?php echo esc_html($subtitle); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render plugin notices.
     */
    private function render_notice() {
        if (! isset($_GET['cad_notice'])) {
            return;
        }

        $notice = sanitize_key(wp_unslash($_GET['cad_notice']));
        $map = array(
            'settings_saved' => array('success', __('Configuracion guardada correctamente.', 'custom-admin-dashboard')),
        );

        if (! isset($map[$notice])) {
            return;
        }

        $data = $map[$notice];
        printf(
            '<div class="notice notice-%1$s is-dismissible cad-keep-notice"><p>%2$s</p></div>',
            esc_attr($data[0]),
            esc_html($data[1])
        );
    }
}
