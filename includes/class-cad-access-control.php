<?php

if (! defined('ABSPATH')) {
    exit;
}

class CAD_Access_Control {
    const OPTION_KEY = 'cad_settings';

    /**
     * @var array|null
     */
    private $settings = null;

    /**
     * @return array
     */
    public static function get_default_settings() {
        return array(
            'role_css'   => array(),
            'relations'  => array(
                'course_post_types'      => array('course', 'courses', 'sfwd-courses', 'lp_course', 'tutor_course'),
                'lesson_post_types'      => array('lesson', 'lessons', 'sfwd-lessons', 'lp_lesson', 'tutor_lesson'),
                'exam_post_types'        => array('exam', 'exams', 'quiz', 'sfwd-quiz', 'tutor_quiz'),
                'booking_post_types'     => array('booking', 'bookings', 'wc_booking', 'bookly_appointment'),
                'user_relation_meta_keys' => array(
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
                ),
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

        return array(
            'role_css'  => self::sanitize_role_css_map(
                isset($settings['role_css']) ? $settings['role_css'] : array()
            ),
            'relations' => self::sanitize_relation_settings(
                isset($settings['relations']) ? $settings['relations'] : $defaults['relations']
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
     * @return array
     */
    public function get_relation_settings() {
        $settings = $this->get_settings();
        return isset($settings['relations']) && is_array($settings['relations'])
            ? self::sanitize_relation_settings($settings['relations'])
            : self::sanitize_relation_settings(array());
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

        $valid_roles = self::sanitize_role_list(array_keys($role_css));
        $sanitized   = array();

        foreach ($valid_roles as $role_key) {
            $raw_css = isset($role_css[$role_key]) ? $role_css[$role_key] : '';
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

    /**
     * @param mixed $relation_settings
     *
     * @return array
     */
    public static function sanitize_relation_settings($relation_settings) {
        $defaults = self::get_default_settings();
        $defaults = isset($defaults['relations']) ? $defaults['relations'] : array();

        if (! is_array($relation_settings)) {
            $relation_settings = array();
        }

        $relation_settings = wp_parse_args($relation_settings, $defaults);

        return array(
            'course_post_types'       => self::sanitize_post_type_list(
                isset($relation_settings['course_post_types']) ? $relation_settings['course_post_types'] : array()
            ),
            'lesson_post_types'       => self::sanitize_post_type_list(
                isset($relation_settings['lesson_post_types']) ? $relation_settings['lesson_post_types'] : array()
            ),
            'exam_post_types'         => self::sanitize_post_type_list(
                isset($relation_settings['exam_post_types']) ? $relation_settings['exam_post_types'] : array()
            ),
            'booking_post_types'      => self::sanitize_post_type_list(
                isset($relation_settings['booking_post_types']) ? $relation_settings['booking_post_types'] : array()
            ),
            'user_relation_meta_keys' => self::sanitize_meta_key_list(
                isset($relation_settings['user_relation_meta_keys']) ? $relation_settings['user_relation_meta_keys'] : array()
            ),
        );
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
            $key = sanitize_text_field((string) $key);
            $key = preg_replace('/[^a-zA-Z0-9_\-]/', '', $key);
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $sanitized[] = $key;
        }

        return array_values(array_unique($sanitized));
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
