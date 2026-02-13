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
     * @var int
     */
    const RELATION_LIMIT = 50;

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
    }

    /**
     * Register user management page under Tools.
     */
    public function register_user_management_page() {
        if (! current_user_can('list_users')) {
            return;
        }

        add_management_page(
            __('Gestion de usuarios CAD', 'custom-admin-dashboard'),
            __('Gestion usuarios CAD', 'custom-admin-dashboard'),
            'list_users',
            'cad-user-management',
            array($this, 'render_user_management_page')
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
                admin_url('tools.php')
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
        $users  = $this->get_target_users($search);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Gestion usuarios CAD', 'custom-admin-dashboard'); ?></h1>
            <p class="description">
                <?php esc_html_e('Solo se muestran usuarios tipo cie_user y cie_new_user.', 'custom-admin-dashboard'); ?>
            </p>

            <form method="get" action="<?php echo esc_url(admin_url('tools.php')); ?>">
                <input type="hidden" name="page" value="cad-user-management" />
                <p class="search-box">
                    <label class="screen-reader-text" for="cad-user-search"><?php esc_html_e('Buscar usuarios', 'custom-admin-dashboard'); ?></label>
                    <input type="search" id="cad-user-search" name="s" value="<?php echo esc_attr($search); ?>" />
                    <?php submit_button(__('Buscar', 'custom-admin-dashboard'), 'secondary', '', false); ?>
                </p>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Tipo', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Nombre', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Email', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Acciones', 'custom-admin-dashboard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)) : ?>
                        <tr>
                            <td colspan="5"><?php esc_html_e('No hay usuarios que coincidan.', 'custom-admin-dashboard'); ?></td>
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
                            $edit_url = add_query_arg(
                                array(
                                    'page'    => 'cad-user-management',
                                    'action'  => 'edit',
                                    'user_id' => (int) $user->ID,
                                ),
                                admin_url('tools.php')
                            );
                            ?>
                            <tr>
                                <td><?php echo esc_html((string) $user->ID); ?></td>
                                <td><code><?php echo esc_html($type_label); ?></code></td>
                                <td><?php echo esc_html($name_value); ?></td>
                                <td><?php echo esc_html($email_value); ?></td>
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

        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

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
            <?php $this->render_notice(); ?>

            <p>
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'cad-user-management'), admin_url('tools.php'))); ?>">
                    &larr; <?php esc_html_e('Volver al listado', 'custom-admin-dashboard'); ?>
                </a>
            </p>

            <form method="post" action="<?php echo esc_url(add_query_arg(array('page' => 'cad-user-management'), admin_url('tools.php'))); ?>">
                <?php wp_nonce_field('cad_save_cie_user'); ?>
                <input type="hidden" name="cad_action" value="save_cie_user" />
                <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $user_id); ?>" />

                <table class="form-table" role="presentation">
                    <tbody>
                        <?php foreach ($this->get_profile_fields() as $field) : ?>
                            <?php $this->render_profile_field_row($user_id, $field); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Relaciones del usuario', 'custom-admin-dashboard'); ?></h2>
                <?php $this->render_user_relations($user_id); ?>

                <?php submit_button(__('Guardar perfil', 'custom-admin-dashboard')); ?>
            </form>
        </div>
        <?php

        $this->render_media_picker_script();
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
                'label'   => 'Foto de perfil',
                'meta_key' => 'profile_pic',
                'acf_key' => 'field_683f15de2203c',
                'type'    => 'image',
            ),
            array(
                'label'   => 'Nombre y Apellidos',
                'meta_key' => 'name',
                'acf_key' => 'field_683f14a72202f',
                'type'    => 'text',
            ),
            array(
                'label'   => 'Fecha de nacimiento',
                'meta_key' => 'birthdate',
                'acf_key' => 'field_67f61aae9c8a8',
                'type'    => 'text',
            ),
            array(
                'label'   => 'Email',
                'meta_key' => 'email',
                'acf_key' => 'field_683f14b222030',
                'type'    => 'email',
            ),
            array(
                'label'   => 'Telefono',
                'meta_key' => 'phone',
                'acf_key' => 'field_683f14d622031',
                'type'    => 'text',
            ),
            array(
                'label'   => 'Universidad de Adscripcion',
                'meta_key' => 'adscription_university',
                'acf_key' => 'field_67f61a8d9c8a7',
                'type'    => 'text',
            ),
            array(
                'label'   => 'Rol en la Universidad',
                'meta_key' => 'university_role',
                'acf_key' => 'field_683f152522032',
                'type'    => 'text',
            ),
            array(
                'label'   => 'Direccion',
                'meta_key' => 'address',
                'acf_key' => 'field_683f153922033',
                'type'    => 'text',
            ),
            array(
                'label'   => 'Direccion de trabajo',
                'meta_key' => 'job_address',
                'acf_key' => 'field_683f154122034',
                'type'    => 'text',
            ),
            array(
                'label'   => 'Proyecto experimental',
                'meta_key' => 'experimental_project',
                'acf_key' => 'field_683f155422035',
                'type'    => 'text',
            ),
            array(
                'label'   => 'Necesidad de uso',
                'meta_key' => 'use_needs',
                'acf_key' => 'field_683f156922036',
                'type'    => 'text',
            ),
            array(
                'label'   => 'Equipos previstos',
                'meta_key' => 'planned_equipment',
                'acf_key' => 'field_683f158a22038',
                'type'    => 'text',
            ),
            array(
                'label'   => 'Periodo de uso',
                'meta_key' => 'use_period',
                'acf_key' => 'field_683f157722037',
                'type'    => 'text',
            ),
            array(
                'label'   => 'User Type',
                'meta_key' => 'user_type',
                'acf_key' => 'field_683f15c022039',
                'type'    => 'text',
            ),
            array(
                'label'   => 'Nombre del Aval',
                'meta_key' => 'aval_name',
                'acf_key' => 'field_683f15c92203a',
                'type'    => 'text',
            ),
            array(
                'label'   => 'Email del Aval',
                'meta_key' => 'aval_mail',
                'acf_key' => 'field_683f15d32203b',
                'type'    => 'email',
            ),
            array(
                'label'   => 'Progreso cursos',
                'meta_key' => 'courses_progress',
                'acf_key' => 'field_696816747c641',
                'type'    => 'textarea',
            ),
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

        $meta_type = (string) get_user_meta($user->ID, 'user_type', true);
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

        $target_types = $this->get_target_user_types();
        $roles = array_values(array_intersect((array) $user->roles, $target_types));
        if (! empty($roles)) {
            return (string) $roles[0];
        }

        return (string) get_user_meta($user->ID, 'user_type', true);
    }

    /**
     * @param string $search
     *
     * @return array
     */
    private function get_target_users($search = '') {
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
                function ($user) {
                    return $this->user_matches_target_type($user);
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

        $label = isset($field['label']) ? (string) $field['label'] : $meta_key;
        $acf_key = isset($field['acf_key']) ? (string) $field['acf_key'] : '';
        $type = isset($field['type']) ? (string) $field['type'] : 'text';
        $value = get_user_meta($user_id, $meta_key, true);
        $value = is_scalar($value) ? (string) $value : wp_json_encode($value);
        ?>
        <tr>
            <th scope="row">
                <label for="cad-field-<?php echo esc_attr($meta_key); ?>">
                    <?php echo esc_html($label); ?>
                </label>
                <p><code><?php echo esc_html($meta_key); ?></code></p>
                <?php if ($acf_key !== '') : ?>
                    <p><code><?php echo esc_html($acf_key); ?></code></p>
                <?php endif; ?>
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
                <?php else : ?>
                    <input
                        type="<?php echo esc_attr($type === 'email' ? 'email' : 'text'); ?>"
                        id="cad-field-<?php echo esc_attr($meta_key); ?>"
                        class="regular-text"
                        name="cie_fields[<?php echo esc_attr($meta_key); ?>]"
                        value="<?php echo esc_attr($value); ?>"
                    />
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * @param string $meta_key
     * @param string $value
     */
    private function render_image_field($meta_key, $value) {
        $preview_html = '';
        if ($value !== '') {
            if (ctype_digit($value)) {
                $attachment_id = (int) $value;
                $img = wp_get_attachment_image($attachment_id, 'thumbnail');
                if ($img) {
                    $preview_html = $img;
                }
            } elseif (filter_var($value, FILTER_VALIDATE_URL)) {
                $preview_html = sprintf(
                    '<img src="%s" alt="" style="max-width:120px;height:auto;" />',
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
            value="<?php echo esc_attr($value); ?>"
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
        <?php
    }

    /**
     * Save configured custom fields for selected user.
     *
     * @param int $user_id
     */
    private function save_profile_fields($user_id) {
        $fields_input = isset($_POST['cie_fields']) ? (array) wp_unslash($_POST['cie_fields']) : array();

        foreach ($this->get_profile_fields() as $field) {
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

        if ($type === 'image') {
            if (ctype_digit($value)) {
                return (string) absint($value);
            }
            return esc_url_raw($value);
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
     * Render relationship panels.
     *
     * @param int $user_id
     */
    private function render_user_relations($user_id) {
        $relation_settings = $this->get_relation_settings();
        $relation_meta_keys = isset($relation_settings['user_relation_meta_keys'])
            ? (array) $relation_settings['user_relation_meta_keys']
            : array();
        $groups = $this->get_relation_groups();
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;">
            <?php foreach ($groups as $group) : ?>
                <?php
                $posts = $this->get_related_posts_for_user(
                    $user_id,
                    isset($group['post_types']) ? (array) $group['post_types'] : array(),
                    $relation_meta_keys,
                    self::RELATION_LIMIT
                );
                ?>
                <div style="background:#fff;border:1px solid #dcdcde;padding:12px;">
                    <h3 style="margin-top:0;"><?php echo esc_html(isset($group['label']) ? (string) $group['label'] : ''); ?></h3>
                    <?php $this->render_related_posts_table($posts); ?>
                </div>
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
    private function get_related_posts_for_user($user_id, $post_types, $relation_meta_keys = array(), $limit = 50) {
        $user_id = (int) $user_id;
        $limit   = (int) $limit;

        $post_types = array_values(
            array_filter(
                array_map(
                    static function ($post_type) {
                        $post_type = sanitize_key((string) $post_type);
                        return post_type_exists($post_type) ? $post_type : '';
                    },
                    (array) $post_types
                )
            )
        );

        if ($user_id <= 0 || empty($post_types)) {
            return array();
        }

        $ids = array();

        $by_author = new WP_Query(
            array(
                'post_type'              => $post_types,
                'post_status'            => 'any',
                'author'                 => $user_id,
                'posts_per_page'         => $limit,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );

        if (! empty($by_author->posts)) {
            $ids = array_merge($ids, array_map('intval', (array) $by_author->posts));
        }

        $meta_keys = CAD_Access_Control::sanitize_meta_key_list((array) $relation_meta_keys);
        if (empty($meta_keys)) {
            $meta_keys = CAD_Access_Control::sanitize_meta_key_list(
                array(
                    'user_id',
                    'customer_id',
                    'student_id',
                    'attendee_id',
                    '_customer_user',
                )
            );
        }

        $meta_query = array('relation' => 'OR');
        foreach ($meta_keys as $meta_key) {
            $meta_query[] = array(
                'key'     => $meta_key,
                'value'   => (string) $user_id,
                'compare' => '=',
            );
        }

        $by_meta = new WP_Query(
            array(
                'post_type'              => $post_types,
                'post_status'            => 'any',
                'posts_per_page'         => $limit,
                'fields'                 => 'ids',
                'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );

        if (! empty($by_meta->posts)) {
            $ids = array_merge($ids, array_map('intval', (array) $by_meta->posts));
        }

        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return array();
        }

        $posts = get_posts(
            array(
                'post_type'      => $post_types,
                'post_status'    => 'any',
                'post__in'       => $ids,
                'posts_per_page' => $limit,
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );

        return is_array($posts) ? $posts : array();
    }

    /**
     * @param array $posts
     */
    private function render_related_posts_table($posts) {
        $posts = is_array($posts) ? $posts : array();
        ?>
        <table class="widefat striped">
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

    /**
     * @return array
     */
    private function get_relation_settings() {
        if ($this->access_control instanceof CAD_Access_Control) {
            return $this->access_control->get_relation_settings();
        }

        $defaults = CAD_Access_Control::get_default_settings();
        return CAD_Access_Control::sanitize_relation_settings(
            isset($defaults['relations']) ? $defaults['relations'] : array()
        );
    }

    /**
     * Print media picker inline script.
     */
    private function render_media_picker_script() {
        ?>
        <script>
        (function($){
            if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
                return;
            }

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
            }

            $(document).on('click', '.cad-image-upload', function(e){
                e.preventDefault();
                var $button = $(this);
                var target = $button.data('target');
                var preview = $button.data('preview');
                var frame = wp.media({
                    title: 'Seleccionar imagen',
                    library: { type: 'image' },
                    button: { text: 'Usar imagen' },
                    multiple: false
                });

                frame.on('select', function(){
                    var attachment = frame.state().get('selection').first().toJSON();
                    var value = attachment.id ? String(attachment.id) : (attachment.url || '');
                    $(target).val(value);
                    var previewUrl = (attachment.sizes && attachment.sizes.thumbnail)
                        ? attachment.sizes.thumbnail.url
                        : (attachment.url || '');
                    setPreview(preview, previewUrl);
                });

                frame.open();
            });

            $(document).on('click', '.cad-image-clear', function(e){
                e.preventDefault();
                var $button = $(this);
                var target = $button.data('target');
                var preview = $button.data('preview');
                $(target).val('');
                setPreview(preview, '');
            });
        })(jQuery);
        </script>
        <?php
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
