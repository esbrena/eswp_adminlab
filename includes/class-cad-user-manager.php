<?php

if (! defined('ABSPATH')) {
    exit;
}

class CAD_User_Manager {
    /**
     * @var CAD_Access_Control
     */
    private $access_control;

    /**
     * @param CAD_Access_Control $access_control
     */
    public function __construct($access_control) {
        $this->access_control = $access_control;
        add_action('admin_init', array($this, 'handle_admin_requests'));
    }

    /**
     * Handle GET/POST actions from custom users screen.
     */
    public function handle_admin_requests() {
        if (! is_admin() || ! $this->access_control->is_current_user_allowed()) {
            return;
        }

        $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : '';
        if ($page !== 'cad-users') {
            return;
        }

        if (
            isset($_GET['cad_action'], $_GET['user_id'], $_GET['_wpnonce']) &&
            sanitize_key(wp_unslash($_GET['cad_action'])) === 'send_reset'
        ) {
            $this->handle_send_reset_password();
            return;
        }

        if (
            isset($_POST['cad_action']) &&
            sanitize_key(wp_unslash($_POST['cad_action'])) === 'save_user'
        ) {
            $this->handle_save_user();
        }
    }

    /**
     * Render users page (list or edit mode).
     */
    public function render_users_page() {
        if (! $this->access_control->can_manage_users()) {
            wp_die(esc_html__('No tienes permisos para gestionar usuarios.', 'custom-admin-dashboard'));
        }

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;

        if ($action === 'edit' && $user_id > 0) {
            $this->render_user_edit_page($user_id);
            return;
        }

        $this->render_users_list_page();
    }

    /**
     * Render users list table.
     */
    private function render_users_list_page() {
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $role   = isset($_GET['role']) ? sanitize_key(wp_unslash($_GET['role'])) : '';
        $paged  = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $number = 20;

        $args = array(
            'number'  => $number,
            'offset'  => ($paged - 1) * $number,
            'orderby' => 'registered',
            'order'   => 'DESC',
        );

        if ($search !== '') {
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = array('user_login', 'user_email', 'display_name');
        }

        if ($role !== '') {
            $args['role'] = $role;
        }

        $query = new WP_User_Query($args);
        $users = $query->get_results();
        $total = (int) $query->get_total();

        $total_pages = $number > 0 ? (int) ceil($total / $number) : 1;
        $roles       = $this->get_editable_roles();

        ?>
        <div class="wrap cad-wrap">
            <h1><?php esc_html_e('Usuarios', 'custom-admin-dashboard'); ?></h1>
            <?php $this->render_notice(); ?>

            <form method="get" class="cad-filter-form">
                <input type="hidden" name="page" value="cad-users" />
                <p class="search-box">
                    <label class="screen-reader-text" for="cad-search-users"><?php esc_html_e('Buscar usuario', 'custom-admin-dashboard'); ?></label>
                    <input type="search" id="cad-search-users" name="s" value="<?php echo esc_attr($search); ?>" />
                    <select name="role">
                        <option value=""><?php esc_html_e('Todos los roles', 'custom-admin-dashboard'); ?></option>
                        <?php foreach ($roles as $role_key => $role_label) : ?>
                            <option value="<?php echo esc_attr($role_key); ?>" <?php selected($role, $role_key); ?>>
                                <?php echo esc_html($role_label); ?>
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
                        <th><?php esc_html_e('Usuario', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Email', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Rol', 'custom-admin-dashboard'); ?></th>
                        <th><?php esc_html_e('Registro', 'custom-admin-dashboard'); ?></th>
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
                                    'page'    => 'cad-users',
                                    'action'  => 'edit',
                                    'user_id' => $user->ID,
                                ),
                                admin_url('admin.php')
                            );

                            $reset_url = wp_nonce_url(
                                add_query_arg(
                                    array(
                                        'page'       => 'cad-users',
                                        'cad_action' => 'send_reset',
                                        'user_id'    => $user->ID,
                                    ),
                                    admin_url('admin.php')
                                ),
                                'cad_send_reset_' . $user->ID
                            );
                            ?>
                            <tr>
                                <td><?php echo esc_html((string) $user->ID); ?></td>
                                <td>
                                    <strong><?php echo esc_html($user->display_name); ?></strong><br />
                                    <span><?php echo esc_html($user->user_login); ?></span>
                                </td>
                                <td><?php echo esc_html($user->user_email); ?></td>
                                <td><?php echo esc_html(implode(', ', $user->roles)); ?></td>
                                <td><?php echo esc_html($user->user_registered); ?></td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url($edit_url); ?>">
                                        <?php esc_html_e('Editar', 'custom-admin-dashboard'); ?>
                                    </a>
                                    <a class="button button-small" href="<?php echo esc_url($reset_url); ?>">
                                        <?php esc_html_e('Enviar reset password', 'custom-admin-dashboard'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1) : ?>
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
                                    'total'     => $total_pages,
                                    'prev_text' => '&laquo;',
                                    'next_text' => '&raquo;',
                                )
                            )
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render user edit form.
     *
     * @param int $user_id
     */
    private function render_user_edit_page($user_id) {
        $user = get_user_by('ID', $user_id);
        if (! $user instanceof WP_User) {
            ?>
            <div class="wrap cad-wrap">
                <h1><?php esc_html_e('Usuario no encontrado', 'custom-admin-dashboard'); ?></h1>
            </div>
            <?php
            return;
        }

        if ($this->access_control->is_super_admin_user($user_id) && ! $this->access_control->is_super_admin_user()) {
            wp_die(esc_html__('No puedes editar a un superadmin.', 'custom-admin-dashboard'));
        }

        $meta_rows = $this->get_editable_meta_rows($user_id);
        $roles     = $this->get_editable_roles();
        $back_url  = add_query_arg(array('page' => 'cad-users'), admin_url('admin.php'));
        $form_url  = add_query_arg(
            array(
                'page'    => 'cad-users',
                'action'  => 'edit',
                'user_id' => $user_id,
            ),
            admin_url('admin.php')
        );
        ?>
        <div class="wrap cad-wrap">
            <h1>
                <?php
                printf(
                    /* translators: %s is a username */
                    esc_html__('Editar usuario: %s', 'custom-admin-dashboard'),
                    esc_html($user->display_name)
                );
                ?>
            </h1>
            <a href="<?php echo esc_url($back_url); ?>" class="button">
                <?php esc_html_e('Volver al listado', 'custom-admin-dashboard'); ?>
            </a>
            <?php $this->render_notice(); ?>

            <form method="post" action="<?php echo esc_url($form_url); ?>" class="cad-user-form">
                <?php wp_nonce_field('cad_save_user_' . $user_id); ?>
                <input type="hidden" name="cad_action" value="save_user" />
                <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $user_id); ?>" />

                <h2><?php esc_html_e('Datos principales', 'custom-admin-dashboard'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th><label for="cad-user-display-name"><?php esc_html_e('Nombre visible', 'custom-admin-dashboard'); ?></label></th>
                            <td><input type="text" id="cad-user-display-name" class="regular-text" name="display_name" value="<?php echo esc_attr($user->display_name); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="cad-user-email"><?php esc_html_e('Email', 'custom-admin-dashboard'); ?></label></th>
                            <td><input type="email" id="cad-user-email" class="regular-text" name="user_email" value="<?php echo esc_attr($user->user_email); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="cad-user-url"><?php esc_html_e('Web', 'custom-admin-dashboard'); ?></label></th>
                            <td><input type="url" id="cad-user-url" class="regular-text" name="user_url" value="<?php echo esc_attr($user->user_url); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="cad-user-nickname"><?php esc_html_e('Nickname', 'custom-admin-dashboard'); ?></label></th>
                            <td><input type="text" id="cad-user-nickname" class="regular-text" name="nickname" value="<?php echo esc_attr($user->nickname); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="cad-user-first-name"><?php esc_html_e('Nombre', 'custom-admin-dashboard'); ?></label></th>
                            <td><input type="text" id="cad-user-first-name" class="regular-text" name="first_name" value="<?php echo esc_attr(get_user_meta($user_id, 'first_name', true)); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="cad-user-last-name"><?php esc_html_e('Apellidos', 'custom-admin-dashboard'); ?></label></th>
                            <td><input type="text" id="cad-user-last-name" class="regular-text" name="last_name" value="<?php echo esc_attr(get_user_meta($user_id, 'last_name', true)); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="cad-user-description"><?php esc_html_e('Descripcion', 'custom-admin-dashboard'); ?></label></th>
                            <td><textarea id="cad-user-description" class="large-text" rows="4" name="description"><?php echo esc_textarea(get_user_meta($user_id, 'description', true)); ?></textarea></td>
                        </tr>
                        <?php if (current_user_can('promote_users') || $this->access_control->is_super_admin_user()) : ?>
                            <tr>
                                <th><label for="cad-user-role"><?php esc_html_e('Rol', 'custom-admin-dashboard'); ?></label></th>
                                <td>
                                    <select id="cad-user-role" name="role">
                                        <?php foreach ($roles as $role_key => $role_name) : ?>
                                            <option value="<?php echo esc_attr($role_key); ?>" <?php selected(in_array($role_key, (array) $user->roles, true)); ?>>
                                                <?php echo esc_html($role_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Metadatos', 'custom-admin-dashboard'); ?></h2>
                <p><?php esc_html_e('Puedes editar metadatos existentes y agregar nuevos. Si el valor es JSON valido (objeto/array), se guardara como estructura.', 'custom-admin-dashboard'); ?></p>

                <table class="widefat striped cad-table" id="cad-meta-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Clave meta', 'custom-admin-dashboard'); ?></th>
                            <th><?php esc_html_e('Valor', 'custom-admin-dashboard'); ?></th>
                            <th><?php esc_html_e('Eliminar', 'custom-admin-dashboard'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (! empty($meta_rows)) : ?>
                            <?php foreach ($meta_rows as $index => $row) : ?>
                                <tr>
                                    <td>
                                        <input type="text" class="regular-text" name="meta_keys[]" value="<?php echo esc_attr($row['key']); ?>" />
                                    </td>
                                    <td>
                                        <textarea class="large-text code" rows="2" name="meta_values[]"><?php echo esc_textarea($row['value']); ?></textarea>
                                    </td>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="meta_delete[]" value="<?php echo esc_attr((string) $index); ?>" />
                                            <?php esc_html_e('Borrar', 'custom-admin-dashboard'); ?>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr class="cad-meta-empty-row">
                            <td><input type="text" class="regular-text" name="meta_keys[]" value="" /></td>
                            <td><textarea class="large-text code" rows="2" name="meta_values[]"></textarea></td>
                            <td>-</td>
                        </tr>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button" id="cad-add-meta-row"><?php esc_html_e('Agregar fila de metadato', 'custom-admin-dashboard'); ?></button>
                </p>

                <?php submit_button(__('Guardar usuario', 'custom-admin-dashboard')); ?>
            </form>
        </div>
        <script>
            (function() {
                var addButton = document.getElementById('cad-add-meta-row');
                var tableBody = document.querySelector('#cad-meta-table tbody');
                if (!addButton || !tableBody) {
                    return;
                }

                addButton.addEventListener('click', function() {
                    var row = document.createElement('tr');
                    row.innerHTML =
                        '<td><input type="text" class="regular-text" name="meta_keys[]" value="" /></td>' +
                        '<td><textarea class="large-text code" rows="2" name="meta_values[]"></textarea></td>' +
                        '<td>-</td>';
                    tableBody.appendChild(row);
                });
            })();
        </script>
        <?php
    }

    /**
     * Handle POST user save.
     */
    private function handle_save_user() {
        if (! $this->access_control->can_manage_users()) {
            wp_die(esc_html__('No tienes permisos para guardar usuarios.', 'custom-admin-dashboard'));
        }

        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        if ($user_id <= 0) {
            return;
        }

        check_admin_referer('cad_save_user_' . $user_id);

        if ($this->access_control->is_super_admin_user($user_id) && ! $this->access_control->is_super_admin_user()) {
            wp_die(esc_html__('No puedes editar a un superadmin.', 'custom-admin-dashboard'));
        }

        $userdata = array(
            'ID'           => $user_id,
            'display_name' => isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '',
            'user_email'   => isset($_POST['user_email']) ? sanitize_email(wp_unslash($_POST['user_email'])) : '',
            'user_url'     => isset($_POST['user_url']) ? esc_url_raw(wp_unslash($_POST['user_url'])) : '',
            'nickname'     => isset($_POST['nickname']) ? sanitize_text_field(wp_unslash($_POST['nickname'])) : '',
            'first_name'   => isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '',
            'last_name'    => isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '',
            'description'  => isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '',
        );

        if ((current_user_can('promote_users') || $this->access_control->is_super_admin_user()) && isset($_POST['role'])) {
            $role = sanitize_key(wp_unslash($_POST['role']));
            if ($role !== '' && get_role($role) instanceof WP_Role) {
                $userdata['role'] = $role;
            }
        }

        $updated = wp_update_user($userdata);
        if (is_wp_error($updated)) {
            $this->redirect_with_notice(
                array(
                    'action'  => 'edit',
                    'user_id' => $user_id,
                ),
                'user_error'
            );
        }

        $meta_keys   = isset($_POST['meta_keys']) ? (array) wp_unslash($_POST['meta_keys']) : array();
        $meta_values = isset($_POST['meta_values']) ? (array) wp_unslash($_POST['meta_values']) : array();
        $meta_delete = isset($_POST['meta_delete']) ? array_map('intval', (array) wp_unslash($_POST['meta_delete'])) : array();

        $row_count = max(count($meta_keys), count($meta_values));
        for ($i = 0; $i < $row_count; $i++) {
            $key = isset($meta_keys[$i]) ? $this->sanitize_meta_key($meta_keys[$i]) : '';
            if ($key === '') {
                continue;
            }

            if ($this->is_sensitive_meta_key($key)) {
                continue;
            }

            if (in_array($i, $meta_delete, true)) {
                delete_user_meta($user_id, $key);
                continue;
            }

            $raw_value = isset($meta_values[$i]) ? (string) $meta_values[$i] : '';
            $parsed    = $this->parse_meta_value($raw_value);

            update_user_meta($user_id, $key, $parsed);
        }

        $this->redirect_with_notice(
            array(
                'action'  => 'edit',
                'user_id' => $user_id,
            ),
            'user_saved'
        );
    }

    /**
     * Send reset password email to selected user.
     */
    private function handle_send_reset_password() {
        if (! $this->access_control->can_manage_users()) {
            wp_die(esc_html__('No tienes permisos para esta accion.', 'custom-admin-dashboard'));
        }

        $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        if ($user_id <= 0) {
            return;
        }

        check_admin_referer('cad_send_reset_' . $user_id);

        $user = get_user_by('ID', $user_id);
        if (! $user instanceof WP_User) {
            $this->redirect_with_notice(array(), 'user_not_found');
        }

        if ($this->access_control->is_super_admin_user($user_id) && ! $this->access_control->is_super_admin_user()) {
            wp_die(esc_html__('No puedes gestionar a un superadmin.', 'custom-admin-dashboard'));
        }

        $sent = retrieve_password($user->user_login);
        if ($sent === true) {
            $this->redirect_with_notice(array(), 'reset_sent');
        }

        $this->redirect_with_notice(array(), 'reset_error');
    }

    /**
     * @param int $user_id
     *
     * @return array
     */
    private function get_editable_meta_rows($user_id) {
        $meta = get_user_meta($user_id);
        if (! is_array($meta)) {
            return array();
        }

        $rows = array();
        foreach ($meta as $key => $values) {
            if ($this->is_sensitive_meta_key($key)) {
                continue;
            }

            if (! is_array($values) || ! array_key_exists(0, $values)) {
                $display_value = '';
            } elseif (count($values) === 1) {
                $display_value = $this->format_meta_value($values[0]);
            } else {
                $display_value = $this->format_meta_value($values);
            }

            $rows[] = array(
                'key'   => $key,
                'value' => $display_value,
            );
        }

        return $rows;
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    private function format_meta_value($value) {
        $value = maybe_unserialize($value);

        if (is_array($value) || is_object($value)) {
            return wp_json_encode($value, JSON_PRETTY_PRINT);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        return (string) $value;
    }

    /**
     * @param string $value
     *
     * @return mixed
     */
    private function parse_meta_value($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $lower = strtolower($value);
        if ($lower === 'true') {
            return true;
        }

        if ($lower === 'false') {
            return false;
        }

        if ($lower === 'null') {
            return null;
        }

        $first = substr($value, 0, 1);
        if ($first === '{' || $first === '[') {
            $json = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }

        return sanitize_textarea_field($value);
    }

    /**
     * @param string $key
     *
     * @return string
     */
    private function sanitize_meta_key($key) {
        $key = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $key);
        return (string) $key;
    }

    /**
     * @return array
     */
    private function get_editable_roles() {
        if (! function_exists('get_editable_roles')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $editable = function_exists('get_editable_roles') ? get_editable_roles() : array();
        $roles = array();
        foreach ($editable as $key => $data) {
            $roles[$key] = isset($data['name']) ? $data['name'] : $key;
        }
        return $roles;
    }

    /**
     * Avoid direct editing of sensitive permission/session metadata.
     *
     * @param string $key
     *
     * @return bool
     */
    private function is_sensitive_meta_key($key) {
        $key = (string) $key;

        if ($key === 'session_tokens') {
            return true;
        }

        if (substr($key, -13) === '_capabilities') {
            return true;
        }

        if (substr($key, -11) === '_user_level') {
            return true;
        }

        return false;
    }

    /**
     * Render query-string based notices.
     */
    private function render_notice() {
        if (! isset($_GET['cad_notice'])) {
            return;
        }

        $notice = sanitize_key(wp_unslash($_GET['cad_notice']));
        $map = array(
            'user_saved'    => array('success', __('Usuario guardado correctamente.', 'custom-admin-dashboard')),
            'user_error'    => array('error', __('No se pudo actualizar el usuario.', 'custom-admin-dashboard')),
            'reset_sent'    => array('success', __('Email de recuperacion enviado.', 'custom-admin-dashboard')),
            'reset_error'   => array('error', __('No se pudo enviar el email de recuperacion.', 'custom-admin-dashboard')),
            'user_not_found'=> array('error', __('Usuario no encontrado.', 'custom-admin-dashboard')),
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

    /**
     * @param array  $extra_args
     * @param string $notice
     */
    private function redirect_with_notice($extra_args, $notice) {
        $args = wp_parse_args(
            $extra_args,
            array(
                'page' => 'cad-users',
            )
        );

        $args['cad_notice'] = $notice;

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
