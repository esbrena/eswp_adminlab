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
        add_filter('login_redirect', array($this, 'maybe_redirect_after_login'), 20, 3);
    }

    /**
     * Register custom admin menu and pages.
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

        add_submenu_page(
            'cad-dashboard',
            __('Usuarios', 'custom-admin-dashboard'),
            __('Usuarios', 'custom-admin-dashboard'),
            'read',
            'cad-users',
            array($this->user_manager, 'render_users_page')
        );

        add_submenu_page(
            'cad-dashboard',
            __('Cursos', 'custom-admin-dashboard'),
            __('Cursos', 'custom-admin-dashboard'),
            'read',
            'cad-courses',
            array($this, 'render_courses_page')
        );

        add_submenu_page(
            'cad-dashboard',
            __('Reservas', 'custom-admin-dashboard'),
            __('Reservas', 'custom-admin-dashboard'),
            'read',
            'cad-bookings',
            array($this, 'render_bookings_page')
        );

        add_submenu_page(
            'cad-dashboard',
            __('Ajustes', 'custom-admin-dashboard'),
            __('Ajustes', 'custom-admin-dashboard'),
            'read',
            'cad-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Hide default wp-admin menus for operational admins.
     */
    public function cleanup_admin_menu() {
        if (! $this->access_control->is_current_user_operational_admin()) {
            return;
        }

        $settings = $this->access_control->get_settings();
        if (empty($settings['hide_menus'])) {
            return;
        }

        global $menu;
        if (! is_array($menu)) {
            return;
        }

        $allowed_slugs = array(
            'cad-dashboard',
            'cad-users',
            'cad-courses',
            'cad-bookings',
            'cad-settings',
            'profile.php',
        );

        /**
         * Filter allowed top-level slugs in wp-admin menu for operational admins.
         *
         * @param array $allowed_slugs
         */
        $allowed_slugs = (array) apply_filters('cad_allowed_menu_slugs', $allowed_slugs);

        foreach ($menu as $index => $item) {
            $slug = isset($item[2]) ? (string) $item[2] : '';
            if ($slug === '') {
                continue;
            }

            if (in_array($slug, $allowed_slugs, true)) {
                continue;
            }

            unset($menu[$index]);
        }
    }

    /**
     * Handle settings form submit.
     */
    public function handle_admin_requests() {
        if (! is_admin() || ! $this->access_control->is_current_user_allowed()) {
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
            wp_die(esc_html__('No tienes permisos para guardar ajustes.', 'custom-admin-dashboard'));
        }

        check_admin_referer('cad_save_settings');

        $allowed_roles = isset($_POST['allowed_roles']) ? (array) wp_unslash($_POST['allowed_roles']) : array();
        $allowed_roles = CAD_Access_Control::sanitize_role_list($allowed_roles);
        if (empty($allowed_roles)) {
            $allowed_roles = array('administrator');
        }

        $courses_post_types = isset($_POST['courses_post_types']) ? sanitize_text_field(wp_unslash($_POST['courses_post_types'])) : '';
        $bookings_post_types = isset($_POST['bookings_post_types']) ? sanitize_text_field(wp_unslash($_POST['bookings_post_types'])) : '';

        $courses_post_types  = CAD_Access_Control::sanitize_post_type_list(explode(',', $courses_post_types));
        $bookings_post_types = CAD_Access_Control::sanitize_post_type_list(explode(',', $bookings_post_types));

        if (empty($courses_post_types)) {
            $courses_post_types = CAD_Access_Control::get_default_settings()['courses_post_types'];
        }

        if (empty($bookings_post_types)) {
            $bookings_post_types = CAD_Access_Control::get_default_settings()['bookings_post_types'];
        }

        $settings = array(
            'allowed_roles'      => $allowed_roles,
            'force_redirect'     => isset($_POST['force_redirect']) ? 1 : 0,
            'hide_menus'         => isset($_POST['hide_menus']) ? 1 : 0,
            'courses_post_types' => $courses_post_types,
            'bookings_post_types'=> $bookings_post_types,
        );

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
     * Optional redirect to custom dashboard for operational admins.
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

        global $pagenow;
        $current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if ($current_page !== '' && strpos($current_page, 'cad-') === 0) {
            return;
        }

        if (in_array($pagenow, array('admin-ajax.php', 'async-upload.php', 'profile.php', 'update.php'), true)) {
            return;
        }

        if (in_array($pagenow, array('edit.php', 'post.php', 'post-new.php'), true) && $this->is_allowed_post_type_screen($pagenow)) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=cad-dashboard'));
        exit;
    }

    /**
     * Redirect selected users to custom dashboard after login.
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

        if ($this->access_control->is_super_admin_user($user->ID)) {
            return admin_url('admin.php?page=cad-dashboard');
        }

        $settings      = $this->access_control->get_settings();
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
     * Remove wp dashboard widgets for operational admins.
     */
    public function remove_dashboard_widgets() {
        if (! $this->access_control->is_current_user_operational_admin()) {
            return;
        }

        global $wp_meta_boxes;
        if (isset($wp_meta_boxes['dashboard'])) {
            $wp_meta_boxes['dashboard'] = array();
        }
    }

    /**
     * Remove noisy admin bar nodes.
     *
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public function prune_admin_bar($wp_admin_bar) {
        if (! $this->access_control->is_current_user_operational_admin()) {
            return;
        }

        $settings = $this->access_control->get_settings();
        if (empty($settings['hide_menus'])) {
            return;
        }

        if (! $wp_admin_bar instanceof WP_Admin_Bar) {
            return;
        }

        $keep_nodes = array(
            'my-account',
            'user-actions',
        );

        $keep_nodes = (array) apply_filters('cad_admin_bar_keep_nodes', $keep_nodes);
        $nodes      = $wp_admin_bar->get_nodes();

        if (empty($nodes)) {
            return;
        }

        foreach ($nodes as $node) {
            if (! isset($node->id)) {
                continue;
            }

            if (in_array($node->id, $keep_nodes, true)) {
                continue;
            }

            $wp_admin_bar->remove_node($node->id);
        }
    }

    /**
     * Enqueue styles on custom plugin pages.
     */
    public function enqueue_assets() {
        if (! $this->access_control->is_current_user_allowed()) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === '' || strpos($page, 'cad-') !== 0) {
            return;
        }

        wp_enqueue_style(
            'cad-admin-style',
            CAD_PLUGIN_URL . 'assets/admin.css',
            array(),
            CAD_VERSION
        );
    }

    /**
     * Render dashboard summary.
     */
    public function render_dashboard_page() {
        if (! $this->access_control->is_current_user_allowed()) {
            wp_die(esc_html__('No tienes permisos para acceder al panel.', 'custom-admin-dashboard'));
        }

        $users = count_users();
        $courses_total = $this->count_posts_by_types($this->get_active_course_post_types());
        $bookings_total = $this->count_posts_by_types($this->get_active_booking_post_types());
        ?>
        <div class="wrap cad-wrap">
            <h1><?php esc_html_e('Panel operativo', 'custom-admin-dashboard'); ?></h1>
            <?php $this->render_notice(); ?>

            <div class="cad-grid">
                <div class="cad-card">
                    <h2><?php esc_html_e('Usuarios', 'custom-admin-dashboard'); ?></h2>
                    <p class="cad-count"><?php echo esc_html((string) $users['total_users']); ?></p>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=cad-users')); ?>">
                        <?php esc_html_e('Gestionar usuarios', 'custom-admin-dashboard'); ?>
                    </a>
                </div>
                <div class="cad-card">
                    <h2><?php esc_html_e('Cursos', 'custom-admin-dashboard'); ?></h2>
                    <p class="cad-count"><?php echo esc_html((string) $courses_total); ?></p>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=cad-courses')); ?>">
                        <?php esc_html_e('Ver cursos', 'custom-admin-dashboard'); ?>
                    </a>
                </div>
                <div class="cad-card">
                    <h2><?php esc_html_e('Reservas', 'custom-admin-dashboard'); ?></h2>
                    <p class="cad-count"><?php echo esc_html((string) $bookings_total); ?></p>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=cad-bookings')); ?>">
                        <?php esc_html_e('Ver reservas', 'custom-admin-dashboard'); ?>
                    </a>
                </div>
            </div>

            <h2><?php esc_html_e('Acciones rapidas', 'custom-admin-dashboard'); ?></h2>
            <ul class="cad-quick-links">
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=cad-users')); ?>"><?php esc_html_e('Editar usuarios y metadatos', 'custom-admin-dashboard'); ?></a></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=cad-courses')); ?>"><?php esc_html_e('Gestion de cursos desde plugins conectados', 'custom-admin-dashboard'); ?></a></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=cad-bookings')); ?>"><?php esc_html_e('Gestion de reservas desde plugins conectados', 'custom-admin-dashboard'); ?></a></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=cad-settings')); ?>"><?php esc_html_e('Configurar roles, post types y visibilidad', 'custom-admin-dashboard'); ?></a></li>
            </ul>

            <?php
            /**
             * Hook for extra custom dashboard widgets.
             *
             * @param CAD_Admin_Panel $panel
             */
            do_action('cad_render_dashboard_widgets', $this);
            ?>
        </div>
        <?php
    }

    /**
     * Render courses list page.
     */
    public function render_courses_page() {
        if (! $this->access_control->can_manage_courses()) {
            wp_die(esc_html__('No tienes permisos para gestionar cursos.', 'custom-admin-dashboard'));
        }

        $this->render_content_list(
            'courses',
            $this->get_active_course_post_types(),
            __('Cursos', 'custom-admin-dashboard')
        );
    }

    /**
     * Render bookings list page.
     */
    public function render_bookings_page() {
        if (! $this->access_control->can_manage_bookings()) {
            wp_die(esc_html__('No tienes permisos para gestionar reservas.', 'custom-admin-dashboard'));
        }

        $this->render_content_list(
            'bookings',
            $this->get_active_booking_post_types(),
            __('Reservas', 'custom-admin-dashboard')
        );
    }

    /**
     * Render plugin settings page.
     */
    public function render_settings_page() {
        if (! $this->can_manage_settings()) {
            wp_die(esc_html__('No tienes permisos para gestionar ajustes.', 'custom-admin-dashboard'));
        }

        $settings = $this->access_control->get_settings();
        $roles = wp_roles();
        $all_roles = $roles instanceof WP_Roles ? $roles->roles : array();

        $courses_post_types = implode(', ', (array) $settings['courses_post_types']);
        $bookings_post_types = implode(', ', (array) $settings['bookings_post_types']);
        ?>
        <div class="wrap cad-wrap">
            <h1><?php esc_html_e('Ajustes del panel operativo', 'custom-admin-dashboard'); ?></h1>
            <?php $this->render_notice(); ?>

            <form method="post" action="<?php echo esc_url(add_query_arg(array('page' => 'cad-settings'), admin_url('admin.php'))); ?>">
                <?php wp_nonce_field('cad_save_settings'); ?>
                <input type="hidden" name="cad_action" value="save_settings" />

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Roles permitidos', 'custom-admin-dashboard'); ?></th>
                            <td>
                                <fieldset>
                                    <?php foreach ($all_roles as $role_key => $role_data) : ?>
                                        <label style="display:block;margin-bottom:6px;">
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
                                    <?php esc_html_e('Incluye el rol "admin" si es tu rol operativo custom.', 'custom-admin-dashboard'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Forzar acceso al dashboard custom', 'custom-admin-dashboard'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="force_redirect" value="1" <?php checked(! empty($settings['force_redirect'])); ?> />
                                    <?php esc_html_e('Redirigir a admins operativos al panel custom al entrar en wp-admin.', 'custom-admin-dashboard'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Ocultar menus nativos', 'custom-admin-dashboard'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="hide_menus" value="1" <?php checked(! empty($settings['hide_menus'])); ?> />
                                    <?php esc_html_e('Esconder menus, widgets y barra superior no necesarios.', 'custom-admin-dashboard'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cad-courses-post-types"><?php esc_html_e('Post types de cursos', 'custom-admin-dashboard'); ?></label></th>
                            <td>
                                <input type="text" id="cad-courses-post-types" class="regular-text" name="courses_post_types" value="<?php echo esc_attr($courses_post_types); ?>" />
                                <p class="description">
                                    <?php esc_html_e('Separados por coma. Ejemplo: sfwd-courses, lp_course, tutor_course', 'custom-admin-dashboard'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cad-bookings-post-types"><?php esc_html_e('Post types de reservas', 'custom-admin-dashboard'); ?></label></th>
                            <td>
                                <input type="text" id="cad-bookings-post-types" class="regular-text" name="bookings_post_types" value="<?php echo esc_attr($bookings_post_types); ?>" />
                                <p class="description">
                                    <?php esc_html_e('Separados por coma. Ejemplo: wc_booking, booking, bookly_appointment', 'custom-admin-dashboard'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Guardar ajustes', 'custom-admin-dashboard')); ?>
            </form>

            <h2><?php esc_html_e('Integraciones', 'custom-admin-dashboard'); ?></h2>
            <p><?php esc_html_e('Puedes extender estas vistas con hooks para mostrar datos de plugins de cursos o reservas.', 'custom-admin-dashboard'); ?></p>
            <code>cad_render_dashboard_widgets</code>,
            <code>cad_render_courses_panel_after_table</code>,
            <code>cad_render_bookings_panel_after_table</code>
        </div>
        <?php
    }

    /**
     * Render shared content table for courses/bookings.
     *
     * @param string $context
     * @param array  $post_types
     * @param string $title
     */
    private function render_content_list($context, $post_types, $title) {
        $post_types = $this->resolve_existing_post_types($post_types);

        if (empty($post_types)) {
            ?>
            <div class="wrap cad-wrap">
                <h1><?php echo esc_html($title); ?></h1>
                <p><?php esc_html_e('No hay post types configurados o activos para esta vista.', 'custom-admin-dashboard'); ?></p>
                <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=cad-settings')); ?>">
                    <?php esc_html_e('Ir a ajustes', 'custom-admin-dashboard'); ?>
                </a>
            </div>
            <?php
            return;
        }

        $page_slug = $context === 'courses' ? 'cad-courses' : 'cad-bookings';
        $search    = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $status    = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'any';
        $type      = isset($_GET['post_type_filter']) ? sanitize_key(wp_unslash($_GET['post_type_filter'])) : '';
        $paged     = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

        $filtered_types = $post_types;
        if ($type !== '' && in_array($type, $post_types, true)) {
            $filtered_types = array($type);
        }

        $query_args = array(
            'post_type'      => $filtered_types,
            'post_status'    => $status === 'any' ? 'any' : $status,
            'posts_per_page' => 20,
            'paged'          => $paged,
            's'              => $search,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        $query = new WP_Query($query_args);
        $statuses = get_post_statuses();
        ?>
        <div class="wrap cad-wrap">
            <h1><?php echo esc_html($title); ?></h1>

            <form method="get" class="cad-filter-form">
                <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>" />
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Buscar...', 'custom-admin-dashboard'); ?>" />
                    <select name="post_type_filter">
                        <option value=""><?php esc_html_e('Todos los tipos', 'custom-admin-dashboard'); ?></option>
                        <?php foreach ($post_types as $post_type) : ?>
                            <option value="<?php echo esc_attr($post_type); ?>" <?php selected($type, $post_type); ?>>
                                <?php echo esc_html($this->get_post_type_label($post_type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status">
                        <option value="any" <?php selected($status, 'any'); ?>><?php esc_html_e('Todos los estados', 'custom-admin-dashboard'); ?></option>
                        <?php foreach ($statuses as $status_key => $status_label) : ?>
                            <option value="<?php echo esc_attr($status_key); ?>" <?php selected($status, $status_key); ?>>
                                <?php echo esc_html($status_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button"><?php esc_html_e('Filtrar', 'custom-admin-dashboard'); ?></button>
                </p>
            </form>

            <table class="widefat striped cad-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Titulo', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Tipo', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Estado', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Autor', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Fecha', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Acciones', 'custom-admin-dashboard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (! $query->have_posts()) : ?>
                        <tr>
                            <td colspan="7"><?php esc_html_e('No hay resultados.', 'custom-admin-dashboard'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php while ($query->have_posts()) : $query->the_post(); ?>
                            <?php
                            $post_id = get_the_ID();
                            $post_type = get_post_type($post_id);
                            $author_id = (int) get_post_field('post_author', $post_id);
                            $author = get_user_by('ID', $author_id);
                            $edit_link = get_edit_post_link($post_id, 'raw');
                            $view_link = get_permalink($post_id);
                            ?>
                            <tr>
                                <td><?php echo esc_html((string) $post_id); ?></td>
                                <td><?php echo esc_html(get_the_title($post_id)); ?></td>
                                <td><?php echo esc_html($this->get_post_type_label($post_type)); ?></td>
                                <td><?php echo esc_html((string) get_post_status($post_id)); ?></td>
                                <td><?php echo esc_html($author instanceof WP_User ? $author->display_name : '-'); ?></td>
                                <td><?php echo esc_html((string) get_the_date('Y-m-d H:i', $post_id)); ?></td>
                                <td>
                                    <?php if ($edit_link) : ?>
                                        <a class="button button-small" href="<?php echo esc_url($edit_link); ?>"><?php esc_html_e('Editar', 'custom-admin-dashboard'); ?></a>
                                    <?php endif; ?>
                                    <?php if ($view_link) : ?>
                                        <a class="button button-small" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($view_link); ?>"><?php esc_html_e('Ver', 'custom-admin-dashboard'); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        <?php wp_reset_postdata(); ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($query->max_num_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo wp_kses_post(
                            paginate_links(
                                array(
                                    'base'      => add_query_arg(
                                        array(
                                            'paged' => '%#%',
                                        )
                                    ),
                                    'format'    => '',
                                    'current'   => $paged,
                                    'total'     => (int) $query->max_num_pages,
                                    'prev_text' => '&laquo;',
                                    'next_text' => '&raquo;',
                                )
                            )
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php
            if ($context === 'courses') {
                do_action('cad_render_courses_panel_after_table', $query, $filtered_types);
            } else {
                do_action('cad_render_bookings_panel_after_table', $query, $filtered_types);
            }
            ?>
        </div>
        <?php
    }

    /**
     * @return bool
     */
    private function can_manage_settings() {
        if ($this->access_control->is_super_admin_user()) {
            return true;
        }

        if (current_user_can('manage_options')) {
            return true;
        }

        return $this->access_control->can_manage_users();
    }

    /**
     * @return array
     */
    private function get_active_course_post_types() {
        $settings = $this->access_control->get_settings();
        $types = isset($settings['courses_post_types']) ? (array) $settings['courses_post_types'] : array();
        return CAD_Access_Control::sanitize_post_type_list($types);
    }

    /**
     * @return array
     */
    private function get_active_booking_post_types() {
        $settings = $this->access_control->get_settings();
        $types = isset($settings['bookings_post_types']) ? (array) $settings['bookings_post_types'] : array();
        return CAD_Access_Control::sanitize_post_type_list($types);
    }

    /**
     * @param array $post_types
     *
     * @return array
     */
    private function resolve_existing_post_types($post_types) {
        $result = array();
        foreach ((array) $post_types as $post_type) {
            $post_type = sanitize_key((string) $post_type);
            if ($post_type === '') {
                continue;
            }
            if (post_type_exists($post_type)) {
                $result[] = $post_type;
            }
        }
        return array_values(array_unique($result));
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

        if (! empty($obj->labels->singular_name)) {
            return $obj->labels->singular_name;
        }

        if (! empty($obj->label)) {
            return $obj->label;
        }

        return $post_type;
    }

    /**
     * @param array $post_types
     *
     * @return int
     */
    private function count_posts_by_types($post_types) {
        $post_types = $this->resolve_existing_post_types($post_types);
        if (empty($post_types)) {
            return 0;
        }

        $query = new WP_Query(
            array(
                'post_type'              => $post_types,
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
     * @param string $pagenow
     *
     * @return bool
     */
    private function is_allowed_post_type_screen($pagenow) {
        $allowed_types = array_merge(
            $this->resolve_existing_post_types($this->get_active_course_post_types()),
            $this->resolve_existing_post_types($this->get_active_booking_post_types())
        );

        if (empty($allowed_types)) {
            return false;
        }

        $post_type = '';
        if ($pagenow === 'post.php' && isset($_GET['post'])) {
            $post_id = absint($_GET['post']);
            if ($post_id > 0) {
                $post_type = (string) get_post_type($post_id);
            }
        } elseif (isset($_GET['post_type'])) {
            $post_type = sanitize_key(wp_unslash($_GET['post_type']));
        } else {
            $post_type = 'post';
        }

        return in_array($post_type, $allowed_types, true);
    }

    /**
     * Render simple plugin notices.
     */
    private function render_notice() {
        if (! isset($_GET['cad_notice'])) {
            return;
        }

        $notice = sanitize_key(wp_unslash($_GET['cad_notice']));
        $map = array(
            'settings_saved' => array('success', __('Ajustes guardados correctamente.', 'custom-admin-dashboard')),
        );

        if (! isset($map[$notice])) {
            return;
        }

        $data = $map[$notice];
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($data[0]),
            esc_html($data[1])
        );
    }
}
