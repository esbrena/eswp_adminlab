<?php

if (! defined('ABSPATH')) {
    exit;
}

class CAD_Access_Control {
    const OPTION_KEY = 'cad_settings';

    const CAP_ACCESS_DASHBOARD = 'cad_access_dashboard';
    const CAP_MANAGE_USERS     = 'cad_manage_users';
    const CAP_MANAGE_COURSES   = 'cad_manage_courses';
    const CAP_MANAGE_BOOKINGS  = 'cad_manage_bookings';

    /**
     * @var array|null
     */
    private $settings = null;

    /**
     * @return array
     */
    public static function get_default_settings() {
        return array(
            'allowed_roles'  => array('administrator', 'admin'),
            'force_redirect' => 1,
            'hide_menus'     => 1,
            'ui'             => array(
                'show_users_section'        => 1,
                'show_posts_section'        => 1,
                'allowed_post_types'        => array('post'),
                'show_plugins_section'      => 1,
                'allowed_plugin_menus'      => array(),
                'extra_visible_top_menus'   => array(),
                'hidden_top_menus'          => array(),
                'extra_visible_submenus'    => array(),
                'hidden_submenus'           => array(),
                'extra_capabilities'        => array(),
                'show_profile_menu'         => 1,
                'hide_wp_dashboard_widgets' => 1,
                'hide_admin_bar_items'      => 1,
                'hide_wp_notices'           => 1,
            ),
            'integrations'   => array(
                'course_post_types'       => array('sfwd-courses', 'lp_course', 'tutor_course', 'course'),
                'booking_post_types'      => array('wc_booking', 'bookly_appointment', 'booking'),
                'user_relation_meta_keys' => array('user_id', 'customer_id', 'student_id', 'attendee_id', '_customer_user'),
            ),
            'branding'       => array(
                'logo_url'              => '',
                'header_title'          => 'Panel operativo',
                'header_subtitle'       => 'Entorno simplificado para equipo de administracion',
                'primary_color'         => '#2271b1',
                'accent_color'          => '#135e96',
                'background_color'      => '#f6f7f7',
                'card_background_color' => '#ffffff',
                'custom_css'            => '',
            ),
        );
    }

    /**
     * @param mixed $settings
     *
     * @return array
     */
    public static function normalize_settings($settings) {
        $defaults = self::get_default_settings();

        if (! is_array($settings)) {
            $settings = array();
        }

        $normalized = wp_parse_args($settings, $defaults);

        $normalized['allowed_roles'] = self::sanitize_role_list($normalized['allowed_roles']);
        if (empty($normalized['allowed_roles'])) {
            $normalized['allowed_roles'] = array('administrator');
        }

        $normalized['force_redirect'] = ! empty($normalized['force_redirect']) ? 1 : 0;
        $normalized['hide_menus']     = ! empty($normalized['hide_menus']) ? 1 : 0;

        $normalized['ui'] = self::sanitize_ui_settings(
            isset($normalized['ui']) ? $normalized['ui'] : array()
        );
        $normalized['integrations'] = self::sanitize_integration_settings(
            isset($normalized['integrations']) ? $normalized['integrations'] : array()
        );
        $normalized['branding'] = self::sanitize_branding_settings(
            isset($normalized['branding']) ? $normalized['branding'] : array()
        );

        return $normalized;
    }

    /**
     * @return array
     */
    public function get_settings() {
        if (null === $this->settings) {
            $saved          = get_option(self::OPTION_KEY, array());
            $this->settings = self::normalize_settings($saved);
        }

        return $this->settings;
    }

    /**
     * @return array
     */
    public function get_ui_settings() {
        $settings = $this->get_settings();
        return isset($settings['ui']) && is_array($settings['ui']) ? $settings['ui'] : array();
    }

    /**
     * @return array
     */
    public function get_branding_settings() {
        $settings = $this->get_settings();
        return isset($settings['branding']) && is_array($settings['branding']) ? $settings['branding'] : array();
    }

    /**
     * @return array
     */
    public function get_integration_settings() {
        $settings = $this->get_settings();
        return isset($settings['integrations']) && is_array($settings['integrations']) ? $settings['integrations'] : array();
    }

    /**
     * @return bool
     */
    public function is_current_user_allowed() {
        if (! is_user_logged_in()) {
            return false;
        }

        $user = wp_get_current_user();
        if (! ($user instanceof WP_User) || empty($user->ID)) {
            return false;
        }

        if (user_can($user, 'manage_options')) {
            return true;
        }

        if ($this->is_super_admin_user($user->ID)) {
            return true;
        }

        if (user_can($user, self::CAP_ACCESS_DASHBOARD)) {
            return true;
        }

        $settings      = $this->get_settings();
        $allowed_roles = self::sanitize_role_list($settings['allowed_roles']);
        $intersect     = array_intersect((array) $user->roles, $allowed_roles);

        $allowed = ! empty($intersect);

        /**
         * Filter if current user can access custom dashboard.
         *
         * @param bool    $allowed
         * @param WP_User $user
         * @param array   $settings
         */
        return (bool) apply_filters('cad_user_is_allowed', $allowed, $user, $settings);
    }

    /**
     * @return bool
     */
    public function is_current_user_operational_admin() {
        if (! $this->is_current_user_allowed()) {
            return false;
        }

        return ! $this->is_super_admin_user(get_current_user_id());
    }

    /**
     * @return bool
     */
    public function can_manage_users() {
        if (! $this->is_current_user_allowed()) {
            return false;
        }

        if ($this->is_super_admin_user(get_current_user_id())) {
            return true;
        }

        $ui = $this->get_ui_settings();
        if (empty($ui['show_users_section'])) {
            return false;
        }

        if (current_user_can(self::CAP_MANAGE_USERS)) {
            return true;
        }

        return current_user_can('list_users');
    }

    /**
     * @param string $post_type
     *
     * @return bool
     */
    public function can_access_post_type($post_type) {
        if (! $this->is_current_user_allowed()) {
            return false;
        }

        if ($this->is_super_admin_user(get_current_user_id())) {
            return true;
        }

        $ui = $this->get_ui_settings();
        if (empty($ui['show_posts_section'])) {
            return false;
        }

        $post_type = sanitize_key((string) $post_type);
        $allowed   = self::sanitize_post_type_list($ui['allowed_post_types']);

        if (! in_array($post_type, $allowed, true)) {
            return false;
        }

        return current_user_can('edit_posts');
    }

    /**
     * Legacy capability helper kept for compatibility.
     *
     * @return bool
     */
    public function can_manage_courses() {
        if (! $this->is_current_user_allowed()) {
            return false;
        }

        if ($this->is_super_admin_user(get_current_user_id())) {
            return true;
        }

        if (current_user_can(self::CAP_MANAGE_COURSES)) {
            return true;
        }

        return current_user_can('edit_posts');
    }

    /**
     * Legacy capability helper kept for compatibility.
     *
     * @return bool
     */
    public function can_manage_bookings() {
        if (! $this->is_current_user_allowed()) {
            return false;
        }

        if ($this->is_super_admin_user(get_current_user_id())) {
            return true;
        }

        if (current_user_can(self::CAP_MANAGE_BOOKINGS)) {
            return true;
        }

        return current_user_can('edit_posts');
    }

    /**
     * @param int $user_id
     *
     * @return bool
     */
    public function is_super_admin_user($user_id = 0) {
        if (! function_exists('is_super_admin')) {
            return false;
        }

        // Only multisite has true superadmin semantics.
        if (! is_multisite()) {
            return false;
        }

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            $user_id = get_current_user_id();
        }

        return $user_id > 0 && is_super_admin($user_id);
    }

    /**
     * @param array $settings
     */
    public static function sync_role_caps($settings = array()) {
        $settings = self::normalize_settings($settings);
        $roles    = self::sanitize_role_list($settings['allowed_roles']);

        if (! in_array('administrator', $roles, true)) {
            $roles[] = 'administrator';
        }

        $caps = array(
            self::CAP_ACCESS_DASHBOARD,
            self::CAP_MANAGE_USERS,
            self::CAP_MANAGE_COURSES,
            self::CAP_MANAGE_BOOKINGS,
        );

        $ui = isset($settings['ui']) && is_array($settings['ui']) ? $settings['ui'] : array();
        $extra_caps = isset($ui['extra_capabilities']) ? self::sanitize_capability_list($ui['extra_capabilities']) : array();
        $caps = array_values(array_unique(array_merge($caps, $extra_caps)));

        foreach ($roles as $role_key) {
            $role = get_role($role_key);
            if (! $role instanceof WP_Role) {
                continue;
            }

            foreach ($caps as $cap) {
                $role->add_cap($cap);
            }
        }
    }

    /**
     * @param mixed $roles
     *
     * @return array
     */
    public static function sanitize_role_list($roles) {
        if (! is_array($roles)) {
            $roles = array();
        }

        $sanitized = array();
        $wp_roles  = wp_roles();

        foreach ($roles as $role) {
            $role = sanitize_key((string) $role);
            if ($role === '') {
                continue;
            }

            if ($wp_roles instanceof WP_Roles && $wp_roles->is_role($role)) {
                $sanitized[] = $role;
            }
        }

        return array_values(array_unique($sanitized));
    }

    /**
     * @param mixed $post_types
     *
     * @return array
     */
    public static function sanitize_post_type_list($post_types) {
        if (! is_array($post_types)) {
            $post_types = array();
        }

        $sanitized = array();
        foreach ($post_types as $post_type) {
            $post_type = sanitize_key((string) $post_type);
            if ($post_type === '') {
                continue;
            }
            $sanitized[] = $post_type;
        }

        return array_values(array_unique($sanitized));
    }

    /**
     * @param mixed $slugs
     *
     * @return array
     */
    public static function sanitize_menu_slug_list($slugs) {
        if (! is_array($slugs)) {
            $slugs = array();
        }

        $sanitized = array();
        foreach ($slugs as $slug) {
            $slug = sanitize_text_field((string) $slug);
            $slug = preg_replace('/[^a-zA-Z0-9_\-\.\?=\/&]/', '', $slug);
            $slug = trim((string) $slug);
            if ($slug === '') {
                continue;
            }
            $sanitized[] = $slug;
        }

        return array_values(array_unique($sanitized));
    }

    /**
     * @param mixed $ui
     *
     * @return array
     */
    public static function sanitize_ui_settings($ui) {
        $defaults = self::get_default_settings();
        $defaults = isset($defaults['ui']) ? $defaults['ui'] : array();

        if (! is_array($ui)) {
            $ui = array();
        }

        $ui = wp_parse_args($ui, $defaults);

        $flags = array(
            'show_users_section',
            'show_posts_section',
            'show_plugins_section',
            'show_profile_menu',
            'hide_wp_dashboard_widgets',
            'hide_admin_bar_items',
            'hide_wp_notices',
        );

        foreach ($flags as $flag) {
            $ui[$flag] = ! empty($ui[$flag]) ? 1 : 0;
        }

        $ui['allowed_post_types']   = self::sanitize_post_type_list($ui['allowed_post_types']);
        $ui['allowed_plugin_menus'] = self::sanitize_menu_slug_list($ui['allowed_plugin_menus']);
        $ui['extra_visible_top_menus'] = self::sanitize_menu_slug_list($ui['extra_visible_top_menus']);
        $ui['hidden_top_menus'] = self::sanitize_menu_slug_list($ui['hidden_top_menus']);
        $ui['extra_visible_submenus'] = self::sanitize_submenu_id_list($ui['extra_visible_submenus']);
        $ui['hidden_submenus'] = self::sanitize_submenu_id_list($ui['hidden_submenus']);
        $ui['extra_capabilities'] = self::sanitize_capability_list($ui['extra_capabilities']);

        if (! empty($ui['show_posts_section']) && empty($ui['allowed_post_types'])) {
            $ui['allowed_post_types'] = array('post');
        }

        return $ui;
    }

    /**
     * @param mixed $integration
     *
     * @return array
     */
    public static function sanitize_integration_settings($integration) {
        $defaults = self::get_default_settings();
        $defaults = isset($defaults['integrations']) ? $defaults['integrations'] : array();

        if (! is_array($integration)) {
            $integration = array();
        }

        $integration = wp_parse_args($integration, $defaults);

        $integration['course_post_types'] = self::sanitize_post_type_list($integration['course_post_types']);
        $integration['booking_post_types'] = self::sanitize_post_type_list($integration['booking_post_types']);
        $integration['user_relation_meta_keys'] = self::sanitize_meta_key_list($integration['user_relation_meta_keys']);

        if (empty($integration['user_relation_meta_keys'])) {
            $integration['user_relation_meta_keys'] = $defaults['user_relation_meta_keys'];
        }

        return $integration;
    }

    /**
     * @param mixed $branding
     *
     * @return array
     */
    public static function sanitize_branding_settings($branding) {
        $defaults = self::get_default_settings();
        $defaults = isset($defaults['branding']) ? $defaults['branding'] : array();

        if (! is_array($branding)) {
            $branding = array();
        }

        $branding = wp_parse_args($branding, $defaults);

        $clean = array(
            'logo_url'              => esc_url_raw((string) $branding['logo_url']),
            'header_title'          => sanitize_text_field((string) $branding['header_title']),
            'header_subtitle'       => sanitize_text_field((string) $branding['header_subtitle']),
            'primary_color'         => self::sanitize_hex_color_with_default(
                $branding['primary_color'],
                $defaults['primary_color']
            ),
            'accent_color'          => self::sanitize_hex_color_with_default(
                $branding['accent_color'],
                $defaults['accent_color']
            ),
            'background_color'      => self::sanitize_hex_color_with_default(
                $branding['background_color'],
                $defaults['background_color']
            ),
            'card_background_color' => self::sanitize_hex_color_with_default(
                $branding['card_background_color'],
                $defaults['card_background_color']
            ),
            'custom_css'            => '',
        );

        if ($clean['header_title'] === '') {
            $clean['header_title'] = (string) $defaults['header_title'];
        }

        $custom_css = isset($branding['custom_css']) ? (string) $branding['custom_css'] : '';
        $custom_css = str_replace(array('</style', '<style', '<?', '?>'), '', $custom_css);
        $clean['custom_css'] = trim($custom_css);

        return $clean;
    }

    /**
     * @param mixed  $color
     * @param string $default
     *
     * @return string
     */
    private static function sanitize_hex_color_with_default($color, $default) {
        $sanitized = sanitize_hex_color((string) $color);
        if (! $sanitized) {
            $sanitized = sanitize_hex_color((string) $default);
        }

        return $sanitized ? $sanitized : '#2271b1';
    }

    /**
     * @param mixed $keys
     *
     * @return array
     */
    public static function sanitize_meta_key_list($keys) {
        if (! is_array($keys)) {
            $keys = array();
        }

        $sanitized = array();
        foreach ($keys as $key) {
            $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $key);
            $clean = trim((string) $clean);
            if ($clean !== '') {
                $sanitized[] = $clean;
            }
        }

        return array_values(array_unique($sanitized));
    }

    /**
     * @param mixed $submenu_ids
     *
     * @return array
     */
    public static function sanitize_submenu_id_list($submenu_ids) {
        if (! is_array($submenu_ids)) {
            $submenu_ids = array();
        }

        $sanitized = array();
        foreach ($submenu_ids as $submenu_id) {
            $submenu_id = sanitize_text_field((string) $submenu_id);
            $submenu_id = preg_replace('/[^a-zA-Z0-9_\-\.\?=\/&:]/', '', (string) $submenu_id);
            $submenu_id = trim((string) $submenu_id);
            if ($submenu_id === '' || strpos($submenu_id, '::') === false) {
                continue;
            }
            $sanitized[] = $submenu_id;
        }

        return array_values(array_unique($sanitized));
    }

    /**
     * @param mixed $caps
     *
     * @return array
     */
    public static function sanitize_capability_list($caps) {
        if (! is_array($caps)) {
            $caps = array();
        }

        $sanitized = array();
        foreach ($caps as $cap) {
            $cap = sanitize_key((string) $cap);
            if ($cap === '') {
                continue;
            }

            $sanitized[] = $cap;
        }

        return array_values(array_unique($sanitized));
    }

    /**
     * Refresh settings cache after updates.
     */
    public function flush_settings_cache() {
        $this->settings = null;
    }
}
