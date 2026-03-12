<?php

if (! defined('ABSPATH')) {
    exit;
}

class CAD_User_Manager {
    /**
     * @var int
     */
    const LIST_LIMIT = 200;

    /**
     * @var string
     */
    const USE_PERIOD_SEPARATOR = ' — ';

    /**
     * @var CAD_Access_Control
     */
    private $access_control;

    /**
     * @param CAD_Access_Control $access_control
     */
    public function __construct($access_control) {
        $this->access_control = $access_control;

        add_action('admin_menu', array($this, 'register_user_management_page'));
        add_action('admin_init', array($this, 'handle_admin_requests'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Enqueue scripts/styles only for CIE user edit page.
     *
     * @param string $hook_suffix
     */
    public function enqueue_admin_assets($hook_suffix) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'cad-user-management') {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        if ($action !== 'edit') {
            return;
        }

        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        wp_enqueue_script('jquery');
        wp_enqueue_style(
            'cad-flatpickr-style',
            'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css',
            array(),
            '4.6.13'
        );
        wp_enqueue_script(
            'cad-flatpickr-script',
            'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js',
            array(),
            '4.6.13',
            false
        );
    }

    /**
     * Register user management page under Tools.
     */
    public function register_user_management_page() {
        if (! current_user_can('list_users')) {
            return;
        }

        add_menu_page(
            __('CIE - Usuarios', 'custom-admin-dashboard'),
            __('CIE - Usuarios', 'custom-admin-dashboard'),
            'list_users',
            'cad-user-management',
            array($this, 'render_user_management_page'),
            'dashicons-groups',
            3
        );
    }

    /**
     * Handle custom user save requests.
     */
    public function handle_admin_requests() {
        if (! is_admin()) {
            return;
        }

        $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : '';
        if ($page !== 'cad-user-management') {
            return;
        }

        if (
            ! isset($_POST['cad_action']) ||
            sanitize_key(wp_unslash($_POST['cad_action'])) !== 'save_cie_user'
        ) {
            return;
        }

        if (! current_user_can('list_users')) {
            wp_die(esc_html__('No tienes permisos para gestionar usuarios.', 'custom-admin-dashboard'));
        }

        check_admin_referer('cad_save_cie_user');

        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        if ($user_id <= 0) {
            wp_die(esc_html__('Usuario invalido.', 'custom-admin-dashboard'));
        }

        $user = get_userdata($user_id);
        if (! $user instanceof WP_User) {
            wp_die(esc_html__('No se ha encontrado el usuario.', 'custom-admin-dashboard'));
        }

        if (! $this->user_matches_target_type($user)) {
            wp_die(esc_html__('Este usuario no pertenece a los tipos permitidos.', 'custom-admin-dashboard'));
        }

        if (! current_user_can('edit_user', $user_id)) {
            wp_die(esc_html__('No tienes permisos para editar este usuario.', 'custom-admin-dashboard'));
        }

        $this->save_profile_fields($user_id);

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'            => 'cad-user-management',
                    'action'          => 'edit',
                    'user_id'         => $user_id,
                    'cad_user_notice' => 'saved',
                ),
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Render entry page (list or edit).
     */
    public function render_user_management_page() {
        if (! current_user_can('list_users')) {
            wp_die(esc_html__('No tienes permisos para gestionar usuarios.', 'custom-admin-dashboard'));
        }

        $action  = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;

        if ($action === 'edit' && $user_id > 0) {
            $this->render_user_edit_page($user_id);
            return;
        }

        $this->render_user_list_page();
    }

    /**
     * Render users list page.
     */
    private function render_user_list_page() {
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $status_filter = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'all';
        $type_filter = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : 'all';

        $status_filter = in_array($status_filter, array('all', 'active', 'inactive'), true) ? $status_filter : 'all';
        $type_filter = in_array($type_filter, array('all', 'internal', 'external'), true) ? $type_filter : 'all';

        $users  = $this->get_target_users($search, $status_filter, $type_filter);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('CIE - Usuarios', 'custom-admin-dashboard'); ?></h1>
            <?php $this->render_user_manager_styles(); ?>
            <p class="description">
                <?php esc_html_e('Solo se muestran usuarios tipo cie_user y cie_new_user.', 'custom-admin-dashboard'); ?>
            </p>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="cad-user-list-toolbar">
                <input type="hidden" name="page" value="cad-user-management" />
                <p class="search-box cad-user-list-toolbar__search">
                    <label class="screen-reader-text" for="cad-user-search"><?php esc_html_e('Buscar usuarios', 'custom-admin-dashboard'); ?></label>
                    <input type="search" id="cad-user-search" name="s" value="<?php echo esc_attr($search); ?>" />
                </p>

                <div class="cad-user-list-toolbar__filters">
                    <label for="cad-user-status-filter"><?php esc_html_e('Estado', 'custom-admin-dashboard'); ?></label>
                    <select id="cad-user-status-filter" name="status">
                        <option value="all" <?php selected($status_filter, 'all'); ?>><?php esc_html_e('Todos', 'custom-admin-dashboard'); ?></option>
                        <option value="active" <?php selected($status_filter, 'active'); ?>><?php esc_html_e('Activos', 'custom-admin-dashboard'); ?></option>
                        <option value="inactive" <?php selected($status_filter, 'inactive'); ?>><?php esc_html_e('No activos', 'custom-admin-dashboard'); ?></option>
                    </select>

                    <label for="cad-user-type-filter"><?php esc_html_e('Tipo', 'custom-admin-dashboard'); ?></label>
                    <select id="cad-user-type-filter" name="type">
                        <option value="all" <?php selected($type_filter, 'all'); ?>><?php esc_html_e('Todos', 'custom-admin-dashboard'); ?></option>
                        <option value="internal" <?php selected($type_filter, 'internal'); ?>><?php esc_html_e('Internos', 'custom-admin-dashboard'); ?></option>
                        <option value="external" <?php selected($type_filter, 'external'); ?>><?php esc_html_e('No internos', 'custom-admin-dashboard'); ?></option>
                    </select>
                </div>

                <div class="cad-user-list-toolbar__actions">
                    <?php submit_button(__('Buscar y filtrar', 'custom-admin-dashboard'), 'secondary', '', false); ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'cad-user-management'), admin_url('admin.php'))); ?>">
                        <?php esc_html_e('Limpiar', 'custom-admin-dashboard'); ?>
                    </a>
                </div>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Tipo', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Nombre', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Email', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Periodo de uso', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Acciones', 'custom-admin-dashboard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e('No hay usuarios que coincidan.', 'custom-admin-dashboard'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($users as $user) : ?>
                            <?php
                            $type_label = $this->get_user_type_label($user);
                            $name_value = (string) get_user_meta($user->ID, 'name', true);
                            if ($name_value === '') {
                                $name_value = (string) $user->display_name;
                            }
                            $email_value = (string) get_user_meta($user->ID, 'email', true);
                            if ($email_value === '') {
                                $email_value = (string) $user->user_email;
                            }
                            $use_period_value = $this->normalize_use_period_value(
                                $this->normalize_meta_to_string(get_user_meta($user->ID, 'use_period', true))
                            );
                            $use_period_status = $this->get_use_period_status_key($use_period_value);
                            $edit_url = add_query_arg(
                                array(
                                    'page'    => 'cad-user-management',
                                    'action'  => 'edit',
                                    'user_id' => (int) $user->ID,
                                ),
                                admin_url('admin.php')
                            );
                            ?>
                            <tr>
                                <td><?php echo esc_html((string) $user->ID); ?></td>
                                <td><code><?php echo esc_html($type_label); ?></code></td>
                                <td><?php echo esc_html($name_value); ?></td>
                                <td><?php echo esc_html($email_value); ?></td>
                                <td>
                                    <?php if ($use_period_value === '') : ?>
                                        &mdash;
                                    <?php else : ?>
                                        <div><?php echo esc_html($use_period_value); ?></div>
                                        <?php if ($use_period_status !== '') : ?>
                                            <?php
                                            $status_label = $use_period_status === 'active'
                                                ? __('Activo', 'custom-admin-dashboard')
                                                : __('Caducado', 'custom-admin-dashboard');
                                            $status_style = $use_period_status === 'active'
                                                ? 'background:#d1e7dd;color:#0f5132;'
                                                : 'background:#f8d7da;color:#842029;';
                                            ?>
                                            <span style="display:inline-block;margin-top:6px;padding:2px 8px;border-radius:999px;font-size:12px;<?php echo esc_attr($status_style); ?>">
                                                <?php echo esc_html($status_label); ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="button button-primary" href="<?php echo esc_url($edit_url); ?>">
                                        <?php esc_html_e('Editar perfil visual', 'custom-admin-dashboard'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render single user edit page.
     *
     * @param int $user_id
     */
    private function render_user_edit_page($user_id) {
        $user_id = (int) $user_id;
        $user = get_userdata($user_id);
        if (! $user instanceof WP_User) {
            wp_die(esc_html__('No se ha encontrado el usuario.', 'custom-admin-dashboard'));
        }

        if (! $this->user_matches_target_type($user)) {
            wp_die(esc_html__('Este usuario no pertenece a los tipos permitidos.', 'custom-admin-dashboard'));
        }

        if (! current_user_can('edit_user', $user_id)) {
            wp_die(esc_html__('No tienes permisos para editar este usuario.', 'custom-admin-dashboard'));
        }

        $user_type_label = $this->get_user_type_label($user);
        $user_type_value = $this->get_user_type_value($user);
        $visible_fields  = $this->get_visible_profile_fields($user_type_value);
        $editable_fields = array_values(
            array_filter(
                $visible_fields,
                static function ($field) {
                    $meta_key = isset($field['meta_key']) ? (string) $field['meta_key'] : '';
                    return $meta_key !== 'profile_pic';
                }
            )
        );
        $name_value = $this->get_user_name_value($user);
        $email_value = $this->get_user_email_value($user);

        ?>
        <div class="wrap">
            <h1>
                <?php
                printf(
                    /* translators: %s: username */
                    esc_html__('Perfil visual de usuario: %s', 'custom-admin-dashboard'),
                    esc_html((string) $user->user_login)
                );
                ?>
            </h1>
            <?php $this->render_user_manager_styles(); ?>
            <?php $this->render_notice(); ?>

            <p>
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'cad-user-management'), admin_url('admin.php'))); ?>">
                    &larr; <?php esc_html_e('Volver al listado', 'custom-admin-dashboard'); ?>
                </a>
            </p>

            <form method="post" action="<?php echo esc_url(add_query_arg(array('page' => 'cad-user-management'), admin_url('admin.php'))); ?>">
                <?php wp_nonce_field('cad_save_cie_user'); ?>
                <input type="hidden" name="cad_action" value="save_cie_user" />
                <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $user_id); ?>" />

                <div class="cad-user-edit-layout">
                    <section class="cad-user-edit-layout__left">
                        <?php $this->render_profile_photo_editor($user_id); ?>
                        <h2 id="cad-user-summary-name" class="cad-user-summary-name"><?php echo esc_html($name_value !== '' ? $name_value : '-'); ?></h2>
                        <p id="cad-user-summary-email" class="cad-user-summary-email"><?php echo esc_html($email_value !== '' ? $email_value : '-'); ?></p>
                        <p class="cad-user-summary-type">
                            <strong><?php esc_html_e('User Type:', 'custom-admin-dashboard'); ?></strong>
                            <span class="cad-user-type-badge">
                                <?php echo esc_html($user_type_label !== '' ? $user_type_label : __('No definido', 'custom-admin-dashboard')); ?>
                            </span>
                        </p>
                    </section>

                    <section class="cad-user-edit-layout__right">
                        <div class="cad-user-fields-grid">
                            <?php foreach ($editable_fields as $field) : ?>
                                <?php $this->render_profile_field_control($user_id, $field); ?>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>

                <?php submit_button(__('Guardar perfil', 'custom-admin-dashboard')); ?>
            </form>
        </div>
        <?php

        $this->render_admin_inline_script();
    }

    /**
     * @return array
     */
    private function get_target_user_types() {
        return array('cie_user', 'cie_new_user');
    }

    /**
     * @return array
     */
    private function get_profile_fields() {
        return array(
            array(
                'label'    => 'Foto de perfil',
                'meta_key' => 'profile_pic',
                'acf_key'  => 'field_683f15de2203c',
                'type'     => 'image',
            ),
            array(
                'label'    => 'Nombre y Apellidos',
                'meta_key' => 'name',
                'acf_key'  => 'field_683f14a72202f',
                'type'     => 'text',
            ),
            array(
                'label'    => 'Fecha de nacimiento',
                'meta_key' => 'birthdate',
                'acf_key'  => 'field_67f61aae9c8a8',
                'type'     => 'birthdate',
            ),
            array(
                'label'    => 'Email',
                'meta_key' => 'email',
                'acf_key'  => 'field_683f14b222030',
                'type'     => 'email',
            ),
            array(
                'label'    => 'Telefono',
                'meta_key' => 'phone',
                'acf_key'  => 'field_683f14d622031',
                'type'     => 'text',
            ),
            array(
                'label'    => 'Universidad de Adscripcion',
                'meta_key' => 'adscription_university',
                'acf_key'  => 'field_67f61a8d9c8a7',
                'type'     => 'text',
            ),
            array(
                'label'    => 'Rol en la Universidad',
                'meta_key' => 'university_role',
                'acf_key'  => 'field_683f152522032',
                'type'     => 'text',
            ),
            array(
                'label'    => 'Direccion',
                'meta_key' => 'address',
                'acf_key'  => 'field_683f153922033',
                'type'     => 'text',
            ),
            array(
                'label'    => 'Direccion de trabajo',
                'meta_key' => 'job_address',
                'acf_key'  => 'field_683f154122034',
                'type'     => 'text',
            ),
            array(
                'label'    => 'Proyecto experimental',
                'meta_key' => 'experimental_project',
                'acf_key'  => 'field_683f155422035',
                'type'     => 'text',
            ),
            array(
                'label'    => 'Necesidad de uso',
                'meta_key' => 'use_needs',
                'acf_key'  => 'field_683f156922036',
                'type'     => 'text',
            ),
            array(
                'label'    => 'Equipos previstos',
                'meta_key' => 'planned_equipment',
                'acf_key'  => 'field_683f158a22038',
                'type'     => 'text',
            ),
            array(
                'label'    => 'Periodo de uso',
                'meta_key' => 'use_period',
                'acf_key'  => 'field_683f157722037',
                'type'     => 'use_period',
            ),
            array(
                'label'    => 'Nombre del Aval',
                'meta_key' => 'aval_name',
                'acf_key'  => 'field_683f15c92203a',
                'type'     => 'text',
            ),
            array(
                'label'    => 'Email del Aval',
                'meta_key' => 'aval_mail',
                'acf_key'  => 'field_683f15d32203b',
                'type'     => 'email',
            ),
        );
    }

    /**
     * @param string $user_type_value
     *
     * @return array
     */
    private function get_visible_profile_fields($user_type_value) {
        $fields = $this->get_profile_fields();
        if (! $this->is_internal_user_type($user_type_value)) {
            return $fields;
        }

        $hidden_for_internal = array('aval_name', 'aval_mail');

        return array_values(
            array_filter(
                $fields,
                static function ($field) use ($hidden_for_internal) {
                    $meta_key = isset($field['meta_key']) ? (string) $field['meta_key'] : '';
                    return ! in_array($meta_key, $hidden_for_internal, true);
                }
            )
        );
    }

    /**
     * @param WP_User $user
     *
     * @return bool
     */
    private function user_matches_target_type($user) {
        if (! $user instanceof WP_User) {
            return false;
        }

        $target_types = $this->get_target_user_types();
        $role_match = ! empty(array_intersect((array) $user->roles, $target_types));
        if ($role_match) {
            return true;
        }

        $meta_type = $this->normalize_meta_to_string(get_user_meta($user->ID, 'user_type', true));
        return in_array($meta_type, $target_types, true);
    }

    /**
     * @param WP_User $user
     *
     * @return string
     */
    private function get_user_type_label($user) {
        if (! $user instanceof WP_User) {
            return '';
        }

        $meta_type = $this->normalize_meta_to_string(get_user_meta($user->ID, 'user_type', true));
        if ($meta_type !== '') {
            return $meta_type;
        }

        $target_types = $this->get_target_user_types();
        $roles = array_values(array_intersect((array) $user->roles, $target_types));
        if (! empty($roles)) {
            return (string) $roles[0];
        }

        return '';
    }

    /**
     * @param WP_User|int $user
     *
     * @return string
     */
    private function get_user_type_value($user) {
        $user_id = 0;
        if ($user instanceof WP_User) {
            $user_id = (int) $user->ID;
        } else {
            $user_id = (int) $user;
        }

        if ($user_id <= 0) {
            return '';
        }

        $meta_type = $this->normalize_meta_to_string(get_user_meta($user_id, 'user_type', true));
        if ($meta_type !== '') {
            return strtolower(remove_accents($meta_type));
        }

        $user_obj = get_userdata($user_id);
        if ($user_obj instanceof WP_User) {
            return strtolower(remove_accents($this->get_user_type_label($user_obj)));
        }

        return '';
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    private function normalize_meta_to_string($value) {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * @param string $user_type_value
     *
     * @return bool
     */
    private function is_internal_user_type($user_type_value) {
        $user_type_value = strtolower(remove_accents(trim((string) $user_type_value)));
        if ($user_type_value === '') {
            return false;
        }

        return strpos($user_type_value, 'intern') !== false;
    }

    /**
     * @param WP_User $user
     *
     * @return bool
     */
    private function is_user_internal($user) {
        if (! $user instanceof WP_User) {
            return false;
        }

        return $this->is_internal_user_type($this->get_user_type_value($user));
    }

    /**
     * @param WP_User $user
     *
     * @return bool
     */
    private function is_user_active($user) {
        if (! $user instanceof WP_User) {
            return false;
        }

        if (is_multisite()) {
            if (function_exists('is_user_spammy') && is_user_spammy($user->ID)) {
                return false;
            }

            $deleted = get_user_option('deleted', $user->ID);
            if ((string) $deleted === '1') {
                return false;
            }
        }

        return (int) $user->user_status === 0;
    }

    /**
     * @param WP_User $user
     *
     * @return string
     */
    private function get_user_name_value($user) {
        if (! $user instanceof WP_User) {
            return '';
        }

        $name_value = (string) get_user_meta($user->ID, 'name', true);
        if ($name_value !== '') {
            return $name_value;
        }

        return (string) $user->display_name;
    }

    /**
     * @param WP_User $user
     *
     * @return string
     */
    private function get_user_email_value($user) {
        if (! $user instanceof WP_User) {
            return '';
        }

        $email_value = (string) get_user_meta($user->ID, 'email', true);
        if ($email_value !== '') {
            return $email_value;
        }

        return (string) $user->user_email;
    }

    /**
     * @param string $value
     * @param string $size
     *
     * @return string
     */
    private function get_image_preview_url($value, $size = 'thumbnail') {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (ctype_digit($value)) {
            $image_data = wp_get_attachment_image_src((int) $value, $size);
            if (is_array($image_data) && isset($image_data[0])) {
                return (string) $image_data[0];
            }
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        return '';
    }

    /**
     * @param int $user_id
     * @param int $size
     *
     * @return string
     */
    private function get_user_photo_url($user_id, $size = 96) {
        $user_id = (int) $user_id;
        $size = max(24, (int) $size);
        $profile_pic = (string) get_user_meta($user_id, 'profile_pic', true);
        $preview_url = $this->get_image_preview_url($profile_pic, $size >= 160 ? 'medium' : 'thumbnail');
        if ($preview_url !== '') {
            return $preview_url;
        }

        $avatar_url = get_avatar_url($user_id, array('size' => $size));
        return is_string($avatar_url) ? $avatar_url : '';
    }

    /**
     * @param WP_User $user
     * @param string  $status_filter
     * @param string  $type_filter
     *
     * @return bool
     */
    private function user_matches_list_filters($user, $status_filter, $type_filter) {
        if (! $this->user_matches_target_type($user)) {
            return false;
        }

        $is_active = $this->is_user_active($user);
        if ($status_filter === 'active' && ! $is_active) {
            return false;
        }
        if ($status_filter === 'inactive' && $is_active) {
            return false;
        }

        $is_internal = $this->is_user_internal($user);
        if ($type_filter === 'internal' && ! $is_internal) {
            return false;
        }
        if ($type_filter === 'external' && $is_internal) {
            return false;
        }

        return true;
    }

    /**
     * @param string $search
     * @param string $status_filter
     * @param string $type_filter
     *
     * @return array
     */
    private function get_target_users($search = '', $status_filter = 'all', $type_filter = 'all') {
        $search = sanitize_text_field((string) $search);
        $target_types = $this->get_target_user_types();
        $ids = array();

        $base_args = array(
            'number' => self::LIST_LIMIT,
            'fields' => 'ID',
        );

        if ($search !== '') {
            $base_args['search'] = '*' . $search . '*';
            $base_args['search_columns'] = array('user_login', 'user_email', 'display_name');
        }

        $query_by_role = new WP_User_Query(
            array_merge(
                $base_args,
                array(
                    'role__in' => $target_types,
                    'orderby'  => 'ID',
                    'order'    => 'DESC',
                )
            )
        );
        if (! empty($query_by_role->results)) {
            $ids = array_merge($ids, array_map('intval', (array) $query_by_role->results));
        }

        $query_by_meta = new WP_User_Query(
            array_merge(
                $base_args,
                array(
                    'meta_query' => array(
                        array(
                            'key'     => 'user_type',
                            'value'   => $target_types,
                            'compare' => 'IN',
                        ),
                    ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    'orderby'    => 'ID',
                    'order'      => 'DESC',
                )
            )
        );
        if (! empty($query_by_meta->results)) {
            $ids = array_merge($ids, array_map('intval', (array) $query_by_meta->results));
        }

        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return array();
        }

        $users_query = new WP_User_Query(
            array(
                'include' => $ids,
                'number'  => self::LIST_LIMIT,
                'orderby' => 'ID',
                'order'   => 'DESC',
            )
        );

        $users = $users_query->get_results();
        if (! is_array($users)) {
            return array();
        }

        return array_values(
            array_filter(
                $users,
                function ($user) use ($status_filter, $type_filter) {
                    return $this->user_matches_list_filters($user, $status_filter, $type_filter);
                }
            )
        );
    }

    /**
     * @param int   $user_id
     * @param array $field
     */
    private function render_profile_field_row($user_id, $field) {
        $meta_key = isset($field['meta_key']) ? (string) $field['meta_key'] : '';
        if ($meta_key === '') {
            return;
        }
        ?>
        <tr>
            <th scope="row">
                <label for="cad-field-<?php echo esc_attr($meta_key); ?>">
                    <?php echo esc_html(isset($field['label']) ? (string) $field['label'] : $meta_key); ?>
                </label>
            </th>
            <td>
                <?php $this->render_profile_field_control($user_id, $field, false); ?>
            </td>
        </tr>
        <?php
    }

    /**
     * @param int   $user_id
     * @param array $field
     * @param bool  $with_wrapper
     */
    private function render_profile_field_control($user_id, $field, $with_wrapper = true) {
        $meta_key = isset($field['meta_key']) ? (string) $field['meta_key'] : '';
        if ($meta_key === '') {
            return;
        }

        $label = isset($field['label']) ? (string) $field['label'] : $meta_key;
        $type = isset($field['type']) ? (string) $field['type'] : 'text';
        $value = get_user_meta($user_id, $meta_key, true);
        $value = is_scalar($value) ? (string) $value : wp_json_encode($value);

        if ($type === 'birthdate') {
            $value = $this->normalize_birthdate_value($value);
        } elseif ($type === 'use_period') {
            $value = $this->normalize_use_period_value($value);
        }

        if ($with_wrapper) :
            ?>
            <div class="cad-user-field">
                <label class="cad-user-field__label" for="cad-field-<?php echo esc_attr($meta_key); ?>">
                    <?php echo esc_html($label); ?>
                </label>
            </th>
            <td>
                <?php if ($type === 'textarea') : ?>
                    <textarea
                        id="cad-field-<?php echo esc_attr($meta_key); ?>"
                        name="cie_fields[<?php echo esc_attr($meta_key); ?>]"
                        rows="4"
                        class="large-text"
                    ><?php echo esc_textarea($value); ?></textarea>
                <?php elseif ($type === 'image') : ?>
                    <?php $this->render_image_field($meta_key, $value); ?>
                <?php elseif ($type === 'birthdate') : ?>
                    <input
                        type="text"
                        id="cad-field-<?php echo esc_attr($meta_key); ?>"
                        class="regular-text cad-flatpickr-date"
                        name="cie_fields[<?php echo esc_attr($meta_key); ?>]"
                        value="<?php echo esc_attr($value); ?>"
                        placeholder="<?php esc_attr_e('dd/mm/aaaa', 'custom-admin-dashboard'); ?>"
                    />
                <?php elseif ($type === 'use_period') : ?>
                    <input
                        type="text"
                        id="cad-field-<?php echo esc_attr($meta_key); ?>"
                        class="regular-text cad-flatpickr-range"
                        name="cie_fields[<?php echo esc_attr($meta_key); ?>]"
                        value="<?php echo esc_attr($value); ?>"
                        placeholder="<?php esc_attr_e('dd/mm/aaaa — dd/mm/aaaa', 'custom-admin-dashboard'); ?>"
                    />
                <?php else : ?>
                    <input
                        type="<?php echo esc_attr($type === 'email' ? 'email' : 'text'); ?>"
                        id="cad-field-<?php echo esc_attr($meta_key); ?>"
                        class="regular-text"
                        name="cie_fields[<?php echo esc_attr($meta_key); ?>]"
                        value="<?php echo esc_attr($value); ?>"
                    />
            <?php
        endif;

        if ($type === 'textarea') :
            ?>
            <textarea
                id="cad-field-<?php echo esc_attr($meta_key); ?>"
                name="cie_fields[<?php echo esc_attr($meta_key); ?>]"
                rows="4"
                class="large-text cad-user-field__input"
            ><?php echo esc_textarea($value); ?></textarea>
            <?php
        elseif ($type === 'image') :
            $this->render_image_field($meta_key, $value);
        else :
            ?>
            <input
                type="<?php echo esc_attr($type === 'email' ? 'email' : ($type === 'date' ? 'date' : 'text')); ?>"
                id="cad-field-<?php echo esc_attr($meta_key); ?>"
                class="regular-text cad-user-field__input"
                name="cie_fields[<?php echo esc_attr($meta_key); ?>]"
                value="<?php echo esc_attr($value); ?>"
            />
            <?php
        endif;

        if ($with_wrapper) :
            ?>
            </div>
            <?php
        endif;
    }

    /**
     * @param int $user_id
     */
    private function render_profile_photo_editor($user_id) {
        $meta_key = 'profile_pic';
        $value = (string) get_user_meta($user_id, $meta_key, true);
        $preview_url = $this->get_image_preview_url($value, 'medium');
        if ($preview_url === '') {
            $preview_url = $this->get_user_photo_url($user_id, 180);
        }
        ?>
        <div class="cad-user-photo-editor">
            <input
                type="hidden"
                id="cad-field-<?php echo esc_attr($meta_key); ?>"
                class="cad-image-value"
                name="cie_fields[<?php echo esc_attr($meta_key); ?>]"
                value="<?php echo esc_attr($value); ?>"
            />
            <div id="cad-image-preview-<?php echo esc_attr($meta_key); ?>" class="cad-user-photo-editor__preview">
                <?php if ($preview_url !== '') : ?>
                    <img src="<?php echo esc_url($preview_url); ?>" alt="" />
                <?php endif; ?>
            </div>

            <div class="cad-user-photo-editor__actions">
                <button
                    type="button"
                    class="button button-secondary cad-image-upload"
                    data-target="#cad-field-<?php echo esc_attr($meta_key); ?>"
                    data-preview="#cad-image-preview-<?php echo esc_attr($meta_key); ?>"
                >
                    <?php esc_html_e('Editar foto', 'custom-admin-dashboard'); ?>
                </button>
                <button
                    type="button"
                    class="button cad-image-clear"
                    data-target="#cad-field-<?php echo esc_attr($meta_key); ?>"
                    data-preview="#cad-image-preview-<?php echo esc_attr($meta_key); ?>"
                >
                    <?php esc_html_e('Eliminar foto', 'custom-admin-dashboard'); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * @param string $meta_key
     * @param string $value
     */
    private function render_image_field($meta_key, $value) {
        $raw_value = trim((string) $value);
        $attachment_id = $this->normalize_image_attachment_id($raw_value);
        $image_url = $this->get_image_preview_url($raw_value);
        $field_value = $attachment_id !== '' ? $attachment_id : $raw_value;
        $preview_html = '';

        if ($image_url !== '') {
            $preview_html = sprintf(
                '<img src="%s" alt="" style="max-width:120px;height:auto;" />',
                esc_url($image_url)
            );
        if ($value !== '') {
            if (ctype_digit($value)) {
                $attachment_id = (int) $value;
                $img = wp_get_attachment_image($attachment_id, 'thumbnail');
                if ($img) {
                    $preview_html = $img;
                }
            } elseif (filter_var($value, FILTER_VALIDATE_URL)) {
                $preview_html = sprintf(
                    '<img src="%s" alt="" />',
                    esc_url($value)
                );
            }
        }
        ?>
        <input
            type="text"
            id="cad-field-<?php echo esc_attr($meta_key); ?>"
            class="regular-text cad-image-value"
            name="cie_fields[<?php echo esc_attr($meta_key); ?>]"
            value="<?php echo esc_attr($field_value); ?>"
        />
        <button
            type="button"
            class="button cad-image-upload"
            data-target="#cad-field-<?php echo esc_attr($meta_key); ?>"
            data-preview="#cad-image-preview-<?php echo esc_attr($meta_key); ?>"
        >
            <?php esc_html_e('Seleccionar imagen', 'custom-admin-dashboard'); ?>
        </button>
        <button
            type="button"
            class="button cad-image-clear"
            data-target="#cad-field-<?php echo esc_attr($meta_key); ?>"
            data-preview="#cad-image-preview-<?php echo esc_attr($meta_key); ?>"
        >
            <?php esc_html_e('Quitar', 'custom-admin-dashboard'); ?>
        </button>
        <div id="cad-image-preview-<?php echo esc_attr($meta_key); ?>" style="margin-top:10px;">
            <?php echo $preview_html ? wp_kses_post($preview_html) : ''; ?>
        </div>
        <p class="description"><?php esc_html_e('Este campo guarda el ID de la imagen en ACF (profile_pic).', 'custom-admin-dashboard'); ?></p>
        <?php
    }

    /**
     * Save configured custom fields for selected user.
     *
     * @param int $user_id
     */
    private function save_profile_fields($user_id) {
        $fields_input = isset($_POST['cie_fields']) ? (array) wp_unslash($_POST['cie_fields']) : array();
        $user_type_value = $this->get_user_type_value($user_id);
        $visible_fields = $this->get_visible_profile_fields($user_type_value);

        foreach ($visible_fields as $field) {
            $meta_key = isset($field['meta_key']) ? (string) $field['meta_key'] : '';
            if ($meta_key === '') {
                continue;
            }

            $raw_value = isset($fields_input[$meta_key]) ? $fields_input[$meta_key] : '';
            if (is_array($raw_value)) {
                continue;
            }

            $value = $this->sanitize_field_value($field, (string) $raw_value);
            if ($value === '') {
                delete_user_meta($user_id, $meta_key);
            } else {
                update_user_meta($user_id, $meta_key, $value);
            }

            $acf_key = isset($field['acf_key']) ? (string) $field['acf_key'] : '';
            if ($acf_key !== '') {
                if ($value === '') {
                    delete_user_meta($user_id, '_' . $meta_key);
                } else {
                    update_user_meta($user_id, '_' . $meta_key, $acf_key);
                }
            }

            if ($meta_key === 'email' && $value !== '') {
                $this->sync_wp_user_email($user_id, $value);
            }

            if ($meta_key === 'profile_pic') {
                $this->sync_wp_user_profile_picture($user_id, $value);
            }
        }

        if ($this->is_internal_user_type($user_type_value)) {
            delete_user_meta($user_id, 'aval_name');
            delete_user_meta($user_id, '_aval_name');
            delete_user_meta($user_id, 'aval_mail');
            delete_user_meta($user_id, '_aval_mail');
        }
    }

    /**
     * @param array  $field
     * @param string $value
     *
     * @return string
     */
    private function sanitize_field_value($field, $value) {
        $type  = isset($field['type']) ? (string) $field['type'] : 'text';
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if ($type === 'textarea') {
            return sanitize_textarea_field($value);
        }

        if ($type === 'email') {
            $email = sanitize_email($value);
            if ($email === '') {
                wp_die(esc_html__('Uno de los emails introducidos no es valido.', 'custom-admin-dashboard'));
            }
            return $email;
        }

        if ($type === 'birthdate') {
            return $this->normalize_birthdate_value($value);
        }

        if ($type === 'use_period') {
            return $this->normalize_use_period_value($value);
        }

        if ($type === 'image') {
            return $this->sanitize_image_field_value($value);
        }

        return sanitize_text_field($value);
    }

    /**
     * @param int    $user_id
     * @param string $email
     */
    private function sync_wp_user_email($user_id, $email) {
        $email = sanitize_email($email);
        if ($email === '') {
            return;
        }

        $result = wp_update_user(
            array(
                'ID'         => (int) $user_id,
                'user_email' => $email,
            )
        );

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }
    }

    /**
     * Sync custom profile picture with common WordPress avatar meta keys.
     *
     * @param int    $user_id
     * @param string $value
     * @param int $user_id
     */
    private function render_user_relations($user_id) {
        $relation_settings = $this->get_relation_settings();
        $relation_meta_keys = isset($relation_settings['user_relation_meta_keys'])
            ? (array) $relation_settings['user_relation_meta_keys']
            : array();
        $groups = $this->get_relation_groups();
        ?>
        <div class="cad-user-related-tabs" data-cad-tabs>
            <div class="cad-user-related-tabs__nav" role="tablist" aria-label="<?php esc_attr_e('Contenido relacionado', 'custom-admin-dashboard'); ?>">
                <?php foreach ($groups as $index => $group) : ?>
                    <?php $tab_id = 'cad-user-tab-' . (int) $user_id . '-' . (int) $index; ?>
                    <button
                        type="button"
                        class="cad-user-related-tabs__button<?php echo $index === 0 ? ' is-active' : ''; ?>"
                        id="<?php echo esc_attr($tab_id . '-button'); ?>"
                        data-tab-target="<?php echo esc_attr($tab_id); ?>"
                        role="tab"
                        aria-controls="<?php echo esc_attr($tab_id); ?>"
                        aria-selected="<?php echo $index === 0 ? 'true' : 'false'; ?>"
                    >
                        <?php echo esc_html(isset($group['label']) ? (string) $group['label'] : ''); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($groups as $index => $group) : ?>
                <?php
                $posts = $this->get_related_posts_for_user(
                    $user_id,
                    isset($group['post_types']) ? (array) $group['post_types'] : array(),
                    $relation_meta_keys,
                    self::RELATION_LIMIT
                );
                $tab_id = 'cad-user-tab-' . (int) $user_id . '-' . (int) $index;
                ?>
                <section
                    class="cad-user-related-tabs__panel<?php echo $index === 0 ? ' is-active' : ''; ?>"
                    id="<?php echo esc_attr($tab_id); ?>"
                    role="tabpanel"
                    aria-labelledby="<?php echo esc_attr($tab_id . '-button'); ?>"
                    <?php echo $index === 0 ? '' : 'hidden'; ?>
                >
                    <?php $this->render_related_posts_table($posts); ?>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * @return array
     */
    private function get_relation_groups() {
        $relation_settings = $this->get_relation_settings();

        return array(
            array(
                'label'      => __('Cursos', 'custom-admin-dashboard'),
                'post_types' => isset($relation_settings['course_post_types']) ? (array) $relation_settings['course_post_types'] : array(),
            ),
            array(
                'label'      => __('Lecciones', 'custom-admin-dashboard'),
                'post_types' => isset($relation_settings['lesson_post_types']) ? (array) $relation_settings['lesson_post_types'] : array(),
            ),
            array(
                'label'      => __('Examenes', 'custom-admin-dashboard'),
                'post_types' => isset($relation_settings['exam_post_types']) ? (array) $relation_settings['exam_post_types'] : array(),
            ),
            array(
                'label'      => __('Reservas', 'custom-admin-dashboard'),
                'post_types' => isset($relation_settings['booking_post_types']) ? (array) $relation_settings['booking_post_types'] : array(),
            ),
        );
    }

    /**
     * @param int   $user_id
     * @param array $post_types
     * @param array $relation_meta_keys
     * @param int   $limit
     *
     * @return array
     */
    private function sync_wp_user_profile_picture($user_id, $value) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }

        $attachment_id = absint($this->normalize_image_attachment_id($value));
        $avatar_meta_keys = array(
            'wp_user_avatar',
            'wp_user_avatar_id',
            'profile_picture',
        );

        foreach ($avatar_meta_keys as $meta_key) {
            if ($attachment_id > 0) {
                update_user_meta($user_id, $meta_key, $attachment_id);
                continue;
            }
        return is_array($posts) ? $posts : array();
    }

    /**
     * @param array $posts
     */
    private function render_related_posts_table($posts) {
        $posts = is_array($posts) ? $posts : array();
        ?>
        <table class="widefat striped cad-related-posts-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Titulo', 'custom-admin-dashboard'); ?></th>
                    <th><?php esc_html_e('Tipo', 'custom-admin-dashboard'); ?></th>
                    <th><?php esc_html_e('Estado', 'custom-admin-dashboard'); ?></th>
                    <th><?php esc_html_e('Fecha', 'custom-admin-dashboard'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($posts)) : ?>
                    <tr>
                        <td colspan="4"><?php esc_html_e('Sin resultados.', 'custom-admin-dashboard'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($posts as $post_item) : ?>
                        <?php
                        $edit_link = get_edit_post_link($post_item->ID);
                        $title = get_the_title($post_item->ID);
                        ?>
                        <tr>
                            <td>
                                <?php if ($edit_link) : ?>
                                    <a href="<?php echo esc_url($edit_link); ?>">
                                        <?php echo esc_html($title !== '' ? $title : ('#' . $post_item->ID)); ?>
                                    </a>
                                <?php else : ?>
                                    <?php echo esc_html($title !== '' ? $title : ('#' . $post_item->ID)); ?>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html((string) $post_item->post_type); ?></code></td>
                            <td><?php echo esc_html((string) $post_item->post_status); ?></td>
                            <td><?php echo esc_html(mysql2date('Y-m-d H:i', (string) $post_item->post_date)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

            delete_user_meta($user_id, $meta_key);
        }
    }

    /**
     * Print media picker and flatpickr initializer inline script.
     * Print shared styles for user manager screens.
     */
    private function render_user_manager_styles() {
        ?>
        <style id="cad-user-manager-styles">
            .cad-user-list-toolbar {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                align-items: flex-end;
                margin: 16px 0;
            }
            .cad-user-list-toolbar .search-box {
                float: none;
                margin: 0;
                flex: 1 1 260px;
            }
            .cad-user-list-toolbar .search-box input[type="search"] {
                width: 100%;
                max-width: 100%;
            }
            .cad-user-list-toolbar__filters {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                align-items: center;
            }
            .cad-user-list-toolbar__actions {
                display: flex;
                gap: 8px;
                align-items: center;
            }
            .cad-users-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 16px;
                margin-top: 16px;
            }
            .cad-user-card {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 16px;
            }
            .cad-user-card__header {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .cad-user-card__photo {
                width: 56px;
                height: 56px;
                border-radius: 50%;
                object-fit: cover;
                flex-shrink: 0;
            }
            .cad-user-card__name {
                margin: 0;
                font-size: 16px;
                line-height: 1.2;
            }
            .cad-user-card__email {
                margin: 4px 0 0;
                color: #646970;
                word-break: break-word;
            }
            .cad-user-card__meta {
                margin-top: 14px;
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            .cad-user-pill {
                display: inline-flex;
                align-items: center;
                padding: 4px 10px;
                border-radius: 999px;
                font-size: 12px;
                font-weight: 600;
                line-height: 1.4;
                background: #f0f0f1;
            }
            .cad-user-pill.is-active {
                background: #e7f7ed;
                color: #1f7a3b;
            }
            .cad-user-pill.is-inactive {
                background: #fbeaea;
                color: #8a2424;
            }
            .cad-user-pill.is-internal {
                background: #e8f0fe;
                color: #1f4db8;
            }
            .cad-user-pill.is-external {
                background: #fff4d9;
                color: #6f4b00;
            }
            .cad-user-card__type {
                margin: 12px 0 0;
            }
            .cad-user-card__actions {
                margin-top: 14px;
            }

            .cad-user-edit-layout {
                display: grid;
                grid-template-columns: minmax(260px, 320px) minmax(0, 1fr);
                gap: 20px;
                margin-top: 12px;
                align-items: start;
            }
            .cad-user-edit-layout__left,
            .cad-user-edit-layout__right {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 16px;
            }
            .cad-user-photo-editor__preview {
                width: 180px;
                height: 180px;
                border-radius: 12px;
                overflow: hidden;
                background: #f6f7f7;
                margin-bottom: 12px;
                border: 1px solid #dcdcde;
            }
            .cad-user-photo-editor__preview img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                display: block;
            }
            .cad-user-photo-editor__actions {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }
            .cad-user-summary-name {
                margin: 16px 0 4px;
                font-size: 28px;
                line-height: 1.2;
            }
            .cad-user-summary-email {
                margin: 0;
                color: #646970;
                word-break: break-word;
            }
            .cad-user-summary-type {
                margin: 12px 0 0;
            }
            .cad-user-type-badge {
                display: inline-block;
                margin-left: 6px;
                padding: 4px 10px;
                border-radius: 999px;
                background: #2271b1;
                color: #fff;
                font-size: 12px;
                line-height: 1.4;
            }
            .cad-user-fields-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                gap: 12px;
            }
            .cad-user-field {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }
            .cad-user-field__label {
                font-weight: 600;
            }
            .cad-user-field__input,
            .cad-user-fields-grid input[type="text"],
            .cad-user-fields-grid input[type="email"],
            .cad-user-fields-grid input[type="date"],
            .cad-user-fields-grid textarea {
                width: 100%;
                max-width: 100%;
            }

            .cad-user-related-section {
                margin-top: 20px;
            }
            .cad-user-related-tabs {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 12px;
            }
            .cad-user-related-tabs__nav {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-bottom: 12px;
            }
            .cad-user-related-tabs__button {
                border: 1px solid #c3c4c7;
                background: #f6f7f7;
                border-radius: 6px;
                padding: 6px 12px;
                cursor: pointer;
            }
            .cad-user-related-tabs__button.is-active {
                border-color: #2271b1;
                background: #edf5ff;
                color: #1d4f92;
            }
            .cad-user-related-tabs__panel {
                display: none;
            }
            .cad-user-related-tabs__panel.is-active {
                display: block;
            }
            .cad-related-posts-table {
                margin-top: 0;
            }

            @media (max-width: 960px) {
                .cad-user-edit-layout {
                    grid-template-columns: 1fr;
                }
                .cad-user-photo-editor__preview {
                    width: 140px;
                    height: 140px;
                }
            }
        </style>
        <?php
    }

    /**
     * Print media picker inline script.
     */
    private function render_admin_inline_script() {
        ?>
        <script>
        (function($){
            function setPreview(previewSelector, url) {
                var $preview = $(previewSelector);
                if (!$preview.length) {
                    return;
                }

                if (!url) {
                    $preview.html('');
                    return;
                }

                $preview.html('<img src="' + url + '" alt="" style="max-width:120px;height:auto;" />');
                $preview.html('<img src="' + url + '" alt="" />');
            }

            $(document).on('click', '.cad-image-upload', function(e){
                e.preventDefault();
                if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
                    return;
                }

                var $button = $(this);
                var target = $button.data('target');
                var preview = $button.data('preview');
                if (!target) {
                    return;
                }

                var frame = wp.media({
                    title: 'Seleccionar imagen',
                    library: { type: 'image' },
                    button: { text: 'Usar imagen' },
                    multiple: false
                });

                frame.on('select', function(){
                    var selection = frame.state().get('selection');
                    if (!selection || !selection.first()) {
                        return;
                    }

                    var attachment = selection.first().toJSON();
                    var imageUrl = attachment.url || '';
                    var attachmentId = attachment.id ? String(attachment.id) : '';
                    if (!imageUrl && attachment.sizes && attachment.sizes.full) {
                        imageUrl = attachment.sizes.full.url || '';
                    }
                    if (!imageUrl && attachment.sizes && attachment.sizes.thumbnail) {
                        imageUrl = attachment.sizes.thumbnail.url || '';
                    }

                    $(target).val(attachmentId);
                    setPreview(preview, imageUrl);
                });

                frame.open();
            });

            $(document).on('click', '.cad-image-clear', function(e){
                e.preventDefault();
                var $button = $(this);
                var target = $button.data('target');
                var preview = $button.data('preview');
                if (!target) {
                    return;
                }

                $(target).val('');
                setPreview(preview, '');
            });

            function extractRangeParts(value) {
                var raw = String(value || '').trim();
                if (!raw) {
                    return null;
                }

                var parts = null;
                var separators = [' — ', ' – ', ' to ', ' al ', ' - '];

                for (var i = 0; i < separators.length; i++) {
                    var delimiter = separators[i];
                    if (raw.indexOf(delimiter) === -1) {
                        continue;
                    }

                    var splitParts = raw.split(delimiter);
                    if (splitParts.length >= 2) {
                        parts = [
                            String(splitParts[0] || '').trim(),
                            String(splitParts.slice(1).join(delimiter) || '').trim()
                        ];
                        break;
                    }
                }

                if (!parts) {
                    var regexMatch = raw.match(/^(.+?)\s*(?:—|–|-)\s*(.+)$/);
                    if (regexMatch && regexMatch.length === 3) {
                        parts = [String(regexMatch[1]).trim(), String(regexMatch[2]).trim()];
                    }
                }

                if (!parts || !parts[0] || !parts[1]) {
                    return null;
                }

                return parts;
            }

            if (typeof flatpickr !== 'undefined') {
                $('.cad-flatpickr-date').each(function(){
                    if (this._flatpickr) {
                        return;
                    }

                    flatpickr(this, {
                        dateFormat: 'd/m/Y',
                        allowInput: true
                    });
                });

                $('.cad-flatpickr-range').each(function(){
                    if (this._flatpickr) {
                        return;
                    }

                    var defaultDates = null;
                    var parts = extractRangeParts(this.value);
                    if (parts) {
                        this.value = parts[0] + ' — ' + parts[1];
                        defaultDates = [parts[0], parts[1]];
                    }

                    var config = {
                        mode: 'range',
                        dateFormat: 'd/m/Y',
                        locale: {
                            rangeSeparator: ' — '
                        },
                        allowInput: true
                    };

                    if (defaultDates) {
                        config.defaultDate = defaultDates;
                    }

                    flatpickr(this, config);
                });
            }
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Normalize birthdate values to d/m/Y while preserving unparseable text.
     *
     * @param string $value
     *
     * @return string
     */
    private function normalize_birthdate_value($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $date = $this->parse_date_value($value);
        if (! $date instanceof DateTimeImmutable) {
            return sanitize_text_field($value);
        }

        return $this->format_date_value($date);
    }

    /**
     * Normalize use period values to "d/m/Y — d/m/Y".
     * Print user edit interactions (tabs + summary sync).
     */
    private function render_user_edit_script() {
        ?>
        <script>
        (function(){
            var tabContainers = document.querySelectorAll('[data-cad-tabs]');
            if (tabContainers.length) {
                tabContainers.forEach(function(container) {
                    var buttons = container.querySelectorAll('.cad-user-related-tabs__button');
                    var panels = container.querySelectorAll('.cad-user-related-tabs__panel');

                    var activateTab = function(targetId) {
                        buttons.forEach(function(button) {
                            var isActive = button.getAttribute('data-tab-target') === targetId;
                            button.classList.toggle('is-active', isActive);
                            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
                        });

                        panels.forEach(function(panel) {
                            var isActive = panel.id === targetId;
                            panel.classList.toggle('is-active', isActive);
                            if (isActive) {
                                panel.removeAttribute('hidden');
                            } else {
                                panel.setAttribute('hidden', 'hidden');
                            }
                        });
                    };

                    buttons.forEach(function(button) {
                        button.addEventListener('click', function() {
                            var targetId = button.getAttribute('data-tab-target');
                            if (!targetId) {
                                return;
                            }
                            activateTab(targetId);
                        });
                    });
                });
            }

            var nameInput = document.getElementById('cad-field-name');
            var emailInput = document.getElementById('cad-field-email');
            var nameOutput = document.getElementById('cad-user-summary-name');
            var emailOutput = document.getElementById('cad-user-summary-email');

            var syncSummary = function() {
                if (nameInput && nameOutput) {
                    var nextName = nameInput.value.trim();
                    nameOutput.textContent = nextName !== '' ? nextName : '-';
                }
                if (emailInput && emailOutput) {
                    var nextEmail = emailInput.value.trim();
                    emailOutput.textContent = nextEmail !== '' ? nextEmail : '-';
                }
            };

            if (nameInput && nameOutput) {
                nameInput.addEventListener('input', syncSummary);
            }
            if (emailInput && emailOutput) {
                emailInput.addEventListener('input', syncSummary);
            }
        })();
        </script>
        <?php
    }

    /**
     * Normalize date values to HTML date format.
     *
     * @param string $value
     *
     * @return string
     */
    private function normalize_use_period_value($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $range = $this->parse_use_period_range($value);
        if (empty($range)) {
            return sanitize_text_field($value);
        }

        return $range['start'] . self::USE_PERIOD_SEPARATOR . $range['end'];
    }

    /**
     * @param string $value
     *
     * @return array
     */
    private function parse_use_period_range($value) {
        $parts = $this->extract_use_period_parts($value);
        if (count($parts) !== 2) {
            return array();
        }

        $start = $this->parse_date_value($parts[0]);
        $end = $this->parse_date_value($parts[1]);

        if (! $start instanceof DateTimeImmutable || ! $end instanceof DateTimeImmutable) {
            return array();
        }

        if ($start > $end) {
            $tmp = $start;
            $start = $end;
            $end = $tmp;
        }

        return array(
            'start' => $this->format_date_value($start),
            'end'   => $this->format_date_value($end),
        );
    }

    /**
     * @param string $value
     *
     * @return array
     */
    private function extract_use_period_parts($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return array();
        }

        $delimiters = array(
            self::USE_PERIOD_SEPARATOR,
            ' – ',
            ' to ',
            ' al ',
            ' - ',
        );

        foreach ($delimiters as $delimiter) {
            $parts = explode($delimiter, $value, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $start = trim((string) $parts[0]);
            $end = trim((string) $parts[1]);
            if ($start !== '' && $end !== '') {
                return array($start, $end);
            }
        }

        if (preg_match('/^(.+?)\s*[—–-]\s*(.+)$/u', $value, $matches)) {
            $start = trim((string) $matches[1]);
            $end = trim((string) $matches[2]);
            if ($start !== '' && $end !== '') {
                return array($start, $end);
            }
        }

        if (preg_match('/^(.+?)\s+to\s+(.+)$/i', $value, $matches)) {
            $start = trim((string) $matches[1]);
            $end = trim((string) $matches[2]);
            if ($start !== '' && $end !== '') {
                return array($start, $end);
            }
        }

        return array();
    }

    /**
     * @param string $value
     *
     * @return DateTimeImmutable|null
     */
    private function parse_date_value($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timezone = wp_timezone();
        $formats = array('d/m/Y', 'Y-m-d', 'd-m-Y', 'd.m.Y');

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $timezone);
            if (! $date instanceof DateTimeImmutable) {
                continue;
            }

            $errors = DateTimeImmutable::getLastErrors();
            if (
                $errors === false ||
                (
                    isset($errors['warning_count'], $errors['error_count']) &&
                    (int) $errors['warning_count'] === 0 &&
                    (int) $errors['error_count'] === 0
                )
            ) {
                return $date;
            }
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
    }

    /**
     * @param DateTimeImmutable $date
     *
     * @return string
     */
    private function format_date_value($date) {
        return $date->format('d/m/Y');
    }

    /**
     * @param string $use_period_value
     *
     * @return string
     */
    private function get_use_period_status_key($use_period_value) {
        $range = $this->parse_use_period_range($use_period_value);
        if (empty($range) || ! isset($range['end'])) {
            return '';
        }

        $end_date = $this->parse_date_value($range['end']);
        if (! $end_date instanceof DateTimeImmutable) {
            return '';
        }

        $today = new DateTimeImmutable('today', wp_timezone());
        if ($today <= $end_date) {
            return 'active';
        }

        return 'expired';
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function sanitize_image_field_value($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $attachment_id = $this->normalize_image_attachment_id($value);
        if ($attachment_id !== '') {
            return $attachment_id;
        }

        // Keep compatibility with legacy URL values if the ID cannot be resolved.
        $url = esc_url_raw($value);
        if ($url !== '') {
            return $url;
        }

        return '';
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function normalize_image_attachment_id($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (ctype_digit($value)) {
            $attachment_id = absint($value);
            if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
                return '';
            }

            $mime_type = get_post_mime_type($attachment_id);
            if (! is_string($mime_type) || strpos($mime_type, 'image/') !== 0) {
                return '';
            }

            return (string) $attachment_id;
        }

        $url = esc_url_raw($value);
        if ($url === '' || ! function_exists('attachment_url_to_postid')) {
            return '';
        }

        $attachment_id = absint(attachment_url_to_postid($url));
        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
            return '';
        }

        $mime_type = get_post_mime_type($attachment_id);
        if (! is_string($mime_type) || strpos($mime_type, 'image/') !== 0) {
            return '';
        }

        return (string) $attachment_id;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function get_image_preview_url($value) {
        $attachment_id = $this->normalize_image_attachment_id($value);
        if ($attachment_id !== '') {
            $url = wp_get_attachment_image_url((int) $attachment_id, 'thumbnail');
            if (! is_string($url) || $url === '') {
                $url = wp_get_attachment_url((int) $attachment_id);
            }

            return is_string($url) ? esc_url($url) : '';
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return esc_url($value);
        }

        return '';
    }

    /**
     * Render save notice.
     */
    private function render_notice() {
        if (! isset($_GET['cad_user_notice'])) {
            return;
        }

        $notice = sanitize_key(wp_unslash($_GET['cad_user_notice']));
        if ($notice !== 'saved') {
            return;
        }

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html__('Usuario guardado correctamente.', 'custom-admin-dashboard')
        );
    }
}
