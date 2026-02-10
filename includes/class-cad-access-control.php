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
            'allowed_roles'      => array('administrator', 'admin'),
            'force_redirect'     => 1,
            'hide_menus'         => 1,
            'courses_post_types' => array('sfwd-courses', 'lp_course', 'tutor_course', 'course'),
            'bookings_post_types'=> array('wc_booking', 'bookly_appointment', 'booking'),
        );
    }

    /**
     * @return array
     */
    public function get_settings() {
        if (null === $this->settings) {
            $saved          = get_option(self::OPTION_KEY, array());
            $this->settings = wp_parse_args($saved, self::get_default_settings());
        }

        return $this->settings;
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
     * Operational admins are allowed users excluding superadmins.
     *
     * @return bool
     */
    public function is_current_user_operational_admin() {
        if (! $this->is_current_user_allowed()) {
            return false;
        }

        $user_id = get_current_user_id();
        return ! $this->is_super_admin_user($user_id);
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

        if (current_user_can(self::CAP_MANAGE_USERS)) {
            return true;
        }

        return current_user_can('list_users');
    }

    /**
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
        $defaults = self::get_default_settings();
        $settings = wp_parse_args($settings, $defaults);
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
     * Refresh settings cache after updates.
     */
    public function flush_settings_cache() {
        $this->settings = null;
    }
}
