<?php

if (! defined('ABSPATH')) {
    exit;
}

class CAD_User_Manager {
    /**
     * @var int
     */
    const LIST_LIMIT = 100;

    /**
     * @var int
     */
    const RELATION_LIMIT = 50;

    public function __construct() {
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
     * Handle user save requests.
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
            sanitize_key(wp_unslash($_POST['cad_action'])) !== 'save_user'
        ) {
            return;
        }

        if (! current_user_can('list_users')) {
            wp_die(esc_html__('No tienes permisos para gestionar usuarios.', 'custom-admin-dashboard'));
        }

        check_admin_referer('cad_save_user');

        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        if ($user_id <= 0) {
            wp_die(esc_html__('Usuario invalido.', 'custom-admin-dashboard'));
        }

        $user = get_userdata($user_id);
        if (! $user instanceof WP_User) {
            wp_die(esc_html__('No se ha encontrado el usuario.', 'custom-admin-dashboard'));
        }

        if (! current_user_can('edit_user', $user_id)) {
            wp_die(esc_html__('No tienes permisos para editar este usuario.', 'custom-admin-dashboard'));
        }

        $this->save_user_basic_data($user_id);
        $this->save_user_role($user_id);
        $this->save_user_meta_values($user_id);

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
     * Render users list.
     */
    private function render_user_list_page() {
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $role_filter = isset($_GET['role']) ? sanitize_key(wp_unslash($_GET['role'])) : '';

        $query_args = array(
            'number'  => self::LIST_LIMIT,
            'orderby' => 'ID',
            'order'   => 'DESC',
            'fields'  => array('ID', 'user_login', 'user_email', 'display_name', 'roles'),
        );

        if ($search !== '') {
            $query_args['search'] = '*' . $search . '*';
            $query_args['search_columns'] = array('user_login', 'user_email', 'display_name');
        }

        if ($role_filter !== '') {
            $query_args['role'] = $role_filter;
        }

        $users_query = new WP_User_Query($query_args);
        $users = $users_query->get_results();

        $wp_roles = wp_roles();
        $all_roles = $wp_roles instanceof WP_Roles ? $wp_roles->roles : array();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Gestion de usuarios CAD', 'custom-admin-dashboard'); ?></h1>

            <form method="get" action="<?php echo esc_url(admin_url('tools.php')); ?>">
                <input type="hidden" name="page" value="cad-user-management" />
                <p class="search-box">
                    <label class="screen-reader-text" for="cad-user-search"><?php esc_html_e('Buscar usuarios', 'custom-admin-dashboard'); ?></label>
                    <input type="search" id="cad-user-search" name="s" value="<?php echo esc_attr($search); ?>" />
                    <select name="role">
                        <option value=""><?php esc_html_e('Todos los roles', 'custom-admin-dashboard'); ?></option>
                        <?php foreach ($all_roles as $role_key => $role_data) : ?>
                            <option value="<?php echo esc_attr($role_key); ?>" <?php selected($role_filter, $role_key); ?>>
                                <?php echo esc_html(isset($role_data['name']) ? (string) $role_data['name'] : (string) $role_key); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php submit_button(__('Filtrar', 'custom-admin-dashboard'), 'secondary', '', false); ?>
                </p>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Usuario', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Email', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Nombre mostrado', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Roles', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Acciones', 'custom-admin-dashboard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e('No se han encontrado usuarios.', 'custom-admin-dashboard'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($users as $user) : ?>
                            <?php
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
                                <td><?php echo esc_html((string) $user->user_login); ?></td>
                                <td><?php echo esc_html((string) $user->user_email); ?></td>
                                <td><?php echo esc_html((string) $user->display_name); ?></td>
                                <td><?php echo esc_html(implode(', ', (array) $user->roles)); ?></td>
                                <td>
                                    <a class="button button-primary" href="<?php echo esc_url($edit_url); ?>">
                                        <?php esc_html_e('Editar', 'custom-admin-dashboard'); ?>
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
     * Render user edition page.
     *
     * @param int $user_id
     */
    private function render_user_edit_page($user_id) {
        $user_id = (int) $user_id;
        $user = get_userdata($user_id);
        if (! $user instanceof WP_User) {
            wp_die(esc_html__('No se ha encontrado el usuario.', 'custom-admin-dashboard'));
        }

        if (! current_user_can('edit_user', $user_id)) {
            wp_die(esc_html__('No tienes permisos para editar este usuario.', 'custom-admin-dashboard'));
        }

        $all_meta = get_user_meta($user_id);
        $meta_keys = array_keys((array) $all_meta);
        sort($meta_keys, SORT_STRING);

        $role_options = wp_roles();
        $role_options = $role_options instanceof WP_Roles ? $role_options->roles : array();
        $user_primary_role = ! empty($user->roles) ? (string) $user->roles[0] : '';
        ?>
        <div class="wrap">
            <h1>
                <?php
                printf(
                    /* translators: %s: username */
                    esc_html__('Editar usuario: %s', 'custom-admin-dashboard'),
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
                <?php wp_nonce_field('cad_save_user'); ?>
                <input type="hidden" name="cad_action" value="save_user" />
                <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $user_id); ?>" />

                <h2><?php esc_html_e('Datos base del usuario', 'custom-admin-dashboard'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Usuario (login)', 'custom-admin-dashboard'); ?></th>
                            <td><code><?php echo esc_html((string) $user->user_login); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cad-user-email"><?php esc_html_e('Email', 'custom-admin-dashboard'); ?></label></th>
                            <td><input type="email" id="cad-user-email" class="regular-text" name="user_email" value="<?php echo esc_attr((string) $user->user_email); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cad-user-first-name"><?php esc_html_e('Nombre', 'custom-admin-dashboard'); ?></label></th>
                            <td><input type="text" id="cad-user-first-name" class="regular-text" name="first_name" value="<?php echo esc_attr((string) get_user_meta($user_id, 'first_name', true)); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cad-user-last-name"><?php esc_html_e('Apellidos', 'custom-admin-dashboard'); ?></label></th>
                            <td><input type="text" id="cad-user-last-name" class="regular-text" name="last_name" value="<?php echo esc_attr((string) get_user_meta($user_id, 'last_name', true)); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cad-user-nickname"><?php esc_html_e('Nickname', 'custom-admin-dashboard'); ?></label></th>
                            <td><input type="text" id="cad-user-nickname" class="regular-text" name="nickname" value="<?php echo esc_attr((string) $user->nickname); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cad-user-display-name"><?php esc_html_e('Nombre a mostrar', 'custom-admin-dashboard'); ?></label></th>
                            <td><input type="text" id="cad-user-display-name" class="regular-text" name="display_name" value="<?php echo esc_attr((string) $user->display_name); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cad-user-url"><?php esc_html_e('Web', 'custom-admin-dashboard'); ?></label></th>
                            <td><input type="url" id="cad-user-url" class="regular-text" name="user_url" value="<?php echo esc_attr((string) $user->user_url); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cad-user-description"><?php esc_html_e('Biografia', 'custom-admin-dashboard'); ?></label></th>
                            <td><textarea id="cad-user-description" class="large-text" rows="4" name="description"><?php echo esc_textarea((string) $user->description); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cad-user-role"><?php esc_html_e('Rol principal', 'custom-admin-dashboard'); ?></label></th>
                            <td>
                                <select id="cad-user-role" name="primary_role">
                                    <option value=""><?php esc_html_e('Sin cambios', 'custom-admin-dashboard'); ?></option>
                                    <?php foreach ($role_options as $role_key => $role_data) : ?>
                                        <option value="<?php echo esc_attr($role_key); ?>" <?php selected($user_primary_role, $role_key); ?>>
                                            <?php echo esc_html(isset($role_data['name']) ? (string) $role_data['name'] : (string) $role_key); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Se actualiza solo si tienes permiso para promocionar usuarios.', 'custom-admin-dashboard'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cad-user-password"><?php esc_html_e('Nueva contrasena', 'custom-admin-dashboard'); ?></label></th>
                            <td>
                                <input type="password" id="cad-user-password" class="regular-text" name="user_pass" value="" autocomplete="new-password" />
                                <p class="description"><?php esc_html_e('Opcional. Si lo dejas vacio, no cambia la contrasena.', 'custom-admin-dashboard'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Metadatos del usuario (incluye ACF)', 'custom-admin-dashboard'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Puedes editar todos los metadatos. Los pares ACF se muestran marcados cuando se detectan claves field_.', 'custom-admin-dashboard'); ?>
                </p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width: 280px;"><?php esc_html_e('Meta key', 'custom-admin-dashboard'); ?></th>
                            <th><?php esc_html_e('Valor', 'custom-admin-dashboard'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($meta_keys)) : ?>
                            <tr>
                                <td colspan="2"><?php esc_html_e('Este usuario no tiene metadatos.', 'custom-admin-dashboard'); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($meta_keys as $meta_index => $meta_key) : ?>
                                <?php
                                $display_value = $this->get_displayable_meta_value($all_meta, $meta_key);
                                $is_acf = $this->is_acf_meta_key($all_meta, $meta_key);
                                ?>
                                <tr>
                                    <td>
                                        <code><?php echo esc_html($meta_key); ?></code>
                                        <?php if ($is_acf) : ?>
                                            <span style="display:inline-block;margin-left:6px;padding:2px 6px;background:#2271b1;color:#fff;border-radius:3px;font-size:11px;">ACF</span>
                                        <?php endif; ?>
                                        <input type="hidden" name="meta_keys[<?php echo esc_attr((string) $meta_index); ?>]" value="<?php echo esc_attr($meta_key); ?>" />
                                    </td>
                                    <td>
                                        <textarea class="large-text code" rows="3" name="meta_values[<?php echo esc_attr((string) $meta_index); ?>]"><?php echo esc_textarea($display_value); ?></textarea>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Relaciones del usuario', 'custom-admin-dashboard'); ?></h2>
                <?php $this->render_user_relations($user_id); ?>

                <?php submit_button(__('Guardar usuario', 'custom-admin-dashboard')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Save core WP user fields.
     *
     * @param int $user_id
     */
    private function save_user_basic_data($user_id) {
        $current_user = get_userdata($user_id);
        if (! $current_user instanceof WP_User) {
            return;
        }

        $raw_email = isset($_POST['user_email']) ? (string) wp_unslash($_POST['user_email']) : '';
        $email = sanitize_email($raw_email);
        if (trim($raw_email) !== '' && $email === '') {
            wp_die(esc_html__('El email introducido no es valido.', 'custom-admin-dashboard'));
        }

        if ($email === '') {
            $email = (string) $current_user->user_email;
        }

        $userdata = array(
            'ID'           => (int) $user_id,
            'user_email'   => $email,
            'first_name'   => isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '',
            'last_name'    => isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '',
            'nickname'     => isset($_POST['nickname']) ? sanitize_text_field(wp_unslash($_POST['nickname'])) : '',
            'display_name' => isset($_POST['display_name']) && trim((string) wp_unslash($_POST['display_name'])) !== ''
                ? sanitize_text_field(wp_unslash($_POST['display_name']))
                : (string) $current_user->display_name,
            'user_url'     => isset($_POST['user_url']) ? esc_url_raw(wp_unslash($_POST['user_url'])) : '',
            'description'  => isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '',
        );

        if (isset($_POST['user_pass']) && trim((string) wp_unslash($_POST['user_pass'])) !== '') {
            $userdata['user_pass'] = (string) wp_unslash($_POST['user_pass']);
        }

        $result = wp_update_user($userdata);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }
    }

    /**
     * Save primary role when allowed.
     *
     * @param int $user_id
     */
    private function save_user_role($user_id) {
        if (! current_user_can('promote_users')) {
            return;
        }

        $new_role = isset($_POST['primary_role']) ? sanitize_key(wp_unslash($_POST['primary_role'])) : '';
        if ($new_role === '') {
            return;
        }

        $wp_roles = wp_roles();
        if (! $wp_roles instanceof WP_Roles || ! $wp_roles->is_role($new_role)) {
            return;
        }

        $user = get_userdata($user_id);
        if (! $user instanceof WP_User) {
            return;
        }

        $user->set_role($new_role);
    }

    /**
     * Save meta values from the form.
     *
     * @param int $user_id
     */
    private function save_user_meta_values($user_id) {
        $meta_keys = isset($_POST['meta_keys']) ? (array) wp_unslash($_POST['meta_keys']) : array();
        $meta_values = isset($_POST['meta_values']) ? (array) wp_unslash($_POST['meta_values']) : array();

        foreach ($meta_keys as $meta_index => $raw_key) {
            $meta_key = $this->sanitize_meta_key($raw_key);
            if ($meta_key === '') {
                continue;
            }

            $meta_index = (string) $meta_index;
            $raw_value = isset($meta_values[$meta_index]) ? $meta_values[$meta_index] : '';
            if (is_array($raw_value)) {
                continue;
            }

            $value = $this->parse_meta_input((string) $raw_value);
            if ($value === '') {
                delete_user_meta($user_id, $meta_key);
                continue;
            }

            update_user_meta($user_id, $meta_key, $value);
        }
    }

    /**
     * @param string $raw_value
     *
     * @return mixed
     */
    private function parse_meta_input($raw_value) {
        $raw_value = trim((string) $raw_value);
        if ($raw_value === '') {
            return '';
        }

        $first_char = substr($raw_value, 0, 1);
        if ($first_char === '{' || $first_char === '[') {
            $decoded = json_decode($raw_value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $raw_value;
    }

    /**
     * @param array  $all_meta
     * @param string $meta_key
     *
     * @return string
     */
    private function get_displayable_meta_value($all_meta, $meta_key) {
        if (! isset($all_meta[$meta_key])) {
            return '';
        }

        $values = is_array($all_meta[$meta_key]) ? $all_meta[$meta_key] : array($all_meta[$meta_key]);
        $value = count($values) > 1 ? $values : reset($values);

        if (is_array($value)) {
            return wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_object($value)) {
            return wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /**
     * @param array  $all_meta
     * @param string $meta_key
     *
     * @return bool
     */
    private function is_acf_meta_key($all_meta, $meta_key) {
        $meta_key = (string) $meta_key;
        if ($meta_key === '') {
            return false;
        }

        if (strpos($meta_key, '_') === 0) {
            $raw_values = isset($all_meta[$meta_key]) ? (array) $all_meta[$meta_key] : array();
            $first = isset($raw_values[0]) ? (string) $raw_values[0] : '';
            return strpos($first, 'field_') === 0;
        }

        $reference_key = '_' . $meta_key;
        if (! isset($all_meta[$reference_key])) {
            return false;
        }

        $raw_values = (array) $all_meta[$reference_key];
        $first = isset($raw_values[0]) ? (string) $raw_values[0] : '';
        return strpos($first, 'field_') === 0;
    }

    /**
     * Render relationship panels.
     *
     * @param int $user_id
     */
    private function render_user_relations($user_id) {
        $groups = $this->get_relation_groups();
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;">
            <?php foreach ($groups as $group) : ?>
                <?php
                $posts = $this->get_related_posts_for_user(
                    $user_id,
                    isset($group['post_types']) ? (array) $group['post_types'] : array(),
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
        return array(
            array(
                'label'      => __('Cursos', 'custom-admin-dashboard'),
                'post_types' => array('course', 'courses', 'sfwd-courses', 'lp_course', 'tutor_course'),
            ),
            array(
                'label'      => __('Lecciones', 'custom-admin-dashboard'),
                'post_types' => array('lesson', 'lessons', 'sfwd-lessons', 'lp_lesson', 'tutor_lesson'),
            ),
            array(
                'label'      => __('Examenes', 'custom-admin-dashboard'),
                'post_types' => array('exam', 'exams', 'quiz', 'sfwd-quiz', 'tutor_quiz'),
            ),
            array(
                'label'      => __('Reservas', 'custom-admin-dashboard'),
                'post_types' => array('booking', 'bookings', 'wc_booking', 'bookly_appointment'),
            ),
        );
    }

    /**
     * @param int   $user_id
     * @param array $post_types
     * @param int   $limit
     *
     * @return array
     */
    private function get_related_posts_for_user($user_id, $post_types, $limit = 50) {
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

        $meta_keys = array(
            'user_id',
            'customer_id',
            'student_id',
            'attendee_id',
            '_customer_user',
            'author_id',
            'client_id',
            'booking_user',
            'owner_id',
            'instructor_id',
            'teacher_id',
        );

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
     * @param string $meta_key
     *
     * @return string
     */
    private function sanitize_meta_key($meta_key) {
        $meta_key = sanitize_text_field((string) $meta_key);
        return trim($meta_key);
    }

    /**
     * Render notices for user save.
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
