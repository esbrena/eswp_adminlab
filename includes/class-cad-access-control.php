<?php

if (! defined('ABSPATH')) {
    exit;
}

class CAD_Access_Control {
    const OPTION_KEY = 'cad_settings';
    const ROLE_ADMIN = 'admin';
    const ROLE_ADMIN_ALIAS = 'administrator';
    const ROLE_ADMIN_LABORATORY = 'admin_laboratorio';

    /**
     * @var array|null
     */
    private $settings = null;

    /**
     * @return array
     */
    public static function get_default_settings() {
        return array(
            'role_css' => array(),
        );
    }

    /**
     * Editable role map in settings page.
     *
     * @return array
     */
    public static function get_editable_roles() {
        return array(
            self::ROLE_ADMIN => __('Admin', 'custom-admin-dashboard'),
            self::ROLE_ADMIN_LABORATORY => __('Admin laboratorio', 'custom-admin-dashboard'),
        );
    }

    /**
     * @param mixed $settings
     *
     * @return array
     */
    public static function normalize_settings($settings) {
        if (! is_array($settings)) {
            $settings = array();
        }

        return array(
            'role_css' => self::sanitize_role_css_map(
                isset($settings['role_css']) ? $settings['role_css'] : array()
            ),
        );
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
    public function get_role_css_map() {
        $settings = $this->get_settings();
        return isset($settings['role_css']) && is_array($settings['role_css'])
            ? $settings['role_css']
            : array();
    }

    /**
     * @param mixed $role_css
     *
     * @return array
     */
    public static function sanitize_role_css_map($role_css) {
        if (! is_array($role_css)) {
            return array();
        }

        $valid_roles = array_keys(self::get_editable_roles());
        $sanitized   = array();

        foreach ($role_css as $role_key => $raw_css) {
            $role_key = self::normalize_role_key($role_key);
            if (! in_array($role_key, $valid_roles, true)) {
                continue;
            }

            $clean   = self::sanitize_css($raw_css);

            if ($clean !== '') {
                $sanitized[$role_key] = $clean;
            }
        }

        return $sanitized;
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

        $editable_roles = array_keys(self::get_editable_roles());
        $sanitized = array();

        foreach ($roles as $role) {
            $role = self::normalize_role_key($role);
            if ($role === '') {
                continue;
            }

            if (in_array($role, $editable_roles, true)) {
                $sanitized[] = $role;
            }
        }

        return array_values(array_unique($sanitized));
    }

    /**
     * @param mixed $css
     *
     * @return string
     */
    public static function sanitize_css($css) {
        $css = (string) $css;
        $css = str_replace(array('<?', '?>', '<style', '</style'), '', $css);
        $css = trim($css);

        return $css;
    }

    private static function normalize_role_key($role) {
        $role = sanitize_key((string) $role);
        if ($role === self::ROLE_ADMIN_ALIAS) {
            return self::ROLE_ADMIN;
        }

        return $role;
    }

    /**
     * @param array $roles
     *
     * @return string
     */
    public function get_css_for_roles($roles) {
        $roles   = self::sanitize_role_list((array) $roles);
        $role_css = $this->get_role_css_map();

        if (empty($roles) || empty($role_css)) {
            return '';
        }

        $chunks = array();
        foreach ($roles as $role_key) {
            if (! isset($role_css[$role_key]) || $role_css[$role_key] === '') {
                continue;
            }

            $chunks[] = "/* role: {$role_key} */\n" . $role_css[$role_key];
        }

        return trim(implode("\n\n", $chunks));
    }

    /**
     * @return string
     */
    public function get_css_for_current_user() {
        if (! is_user_logged_in()) {
            return '';
        }

        $user = wp_get_current_user();
        if (! $user instanceof WP_User || empty($user->roles)) {
            return '';
        }

        return $this->get_css_for_roles((array) $user->roles);
    }

    /**
     * Refresh settings cache after updates.
     */
    public function flush_settings_cache() {
        $this->settings = null;
    }
}
