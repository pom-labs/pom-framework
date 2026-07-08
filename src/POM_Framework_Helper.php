<?php

namespace POMFramework;

class POM_Framework_Helper {

    public static function get_path(): string {
        return __DIR__;
    }

    public static function get_current_user_role() {
        $current_user = wp_get_current_user();

        if (is_null($current_user)) {
            return null;
        }

        $user_roles = $current_user->roles;

        if (!empty($user_roles)) {
            return $user_roles[0];
        }

        return null;
    }

    public static function get_dependencies_data_attr($args): string {
        $data_dependencies = '';
        $dependencies = !empty($args['dependency']) ? $args['dependency'] : [];

        if (!empty($dependencies) && is_array($dependencies)) {
            $jsonString = json_encode($dependencies, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS);
            $data_dependencies = ' data-dependencies="' . esc_attr($jsonString) . '"';
        }

        return $data_dependencies;
    }

    /**
     * Enqueue the WordPress code editor and the POM Framework initializer.
     *
     * Why: wp_enqueue_code_editor() returns false when syntax highlighting is
     * disabled for the current user. Loading the initializer in that state
     * would call an undefined wp.codeEditor API instead of leaving a usable
     * plain textarea.
     *
     * @return bool Whether the code editor assets were enqueued.
     */
    public static function enqueue_code_editor_assets(): bool {
        $codemirror_settings = wp_enqueue_code_editor([]);

        if (!is_array($codemirror_settings) || empty($codemirror_settings['codemirror'])) {
            return false;
        }

        wp_enqueue_style(
            'pom-framework-code-style',
            POM_FRAMEWORK_SRC_URI . '/dist/css/code.min.css',
            ['code-editor']
        );
        wp_enqueue_script(
            'pom-framework-code',
            POM_FRAMEWORK_SRC_URI . '/dist/js/code' . POM_FRAMEWORK_MIN . '.js',
            ['jquery', 'code-editor'],
            null,
            true
        );
        wp_localize_script(
            'pom-framework-code',
            'settings',
            [
                'codeMirrorSettings' => $codemirror_settings,
            ]
        );

        return true;
    }

    /**
     * Determine whether a field should be persisted between requests.
     *
     * Why: some fields only expose transient admin UI state, such as the
     * current tab used by dependency rules, and should not be read from or
     * written to the generated settings payload.
     *
     * @param array $field Field definition array.
     * @return bool
     */
    public static function field_should_persist(array $field): bool {
        if (!array_key_exists('persist', $field)) {
            return true;
        }

        $persist = $field['persist'];

        if (is_bool($persist)) {
            return $persist;
        }

        if (is_numeric($persist)) {
            return (int) $persist !== 0;
        }

        if (is_string($persist)) {
            return !in_array(strtolower(trim($persist)), ['0', 'false', 'no', 'off'], true);
        }

        return (bool) $persist;
    }

    /**
     * Sanitize one or more CSS class names while preserving class separators.
     *
     * Why: WordPress sanitize_html_class() accepts a single class token. Passing
     * a full class list to it removes spaces and merges valid classes together.
     *
     * @param mixed $classes Class string or array of class strings.
     */
    public static function sanitize_html_classes($classes): string {
        if (empty($classes)) {
            return '';
        }

        $tokens = is_array($classes) ? $classes : preg_split('/\s+/', (string) $classes);
        if (!is_array($tokens)) {
            return '';
        }

        $sanitized = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }

            $class = sanitize_html_class($token);
            if ($class !== '') {
                $sanitized[] = $class;
            }
        }

        return implode(' ', array_unique($sanitized));
    }

    /**
     * Convert an associative array into valid HTML attributes
     */
    public static function convert_array_to_html_attributes($field_args): string {
        return implode(' ', array_map(static function ($key) use ($field_args) {
            if (is_bool($field_args[$key])) {
                return $field_args[$key] ? $key : '';
            }

            return $key . '="' . $field_args[$key] . '"';
        }, array_keys($field_args)));
    }

    public static function convert_html_attributes_to_array($string): array {
        $array = [];

        $asArr = explode(' ', $string);
        foreach ($asArr as $val) {
            $tmp = explode('=', str_replace(['"', "'"], '', $val));
            $array[$tmp[0]] = $tmp[1];
        }

        return $array;
    }

    public static function path_to_url($path = ''): string {
        $url = str_replace(
            wp_normalize_path(untrailingslashit(ABSPATH)),
            site_url(),
            wp_normalize_path($path)
        );

        return esc_url_raw($url);
    }

    public static function get_icon_libraries(): array {
        /**
         * Libraries are added as associative arrays.
         * The primary key has to match the name of the
         * folder that contains the icons.
         * Then the array contains the name and the path.
         */
        $libraries['all'] = [
            'name' => __('All', 'pom-framework'),
            'path' => ''
        ];

        return apply_filters('pom_framework_icon_libraries', $libraries);
    }

    /**
     * https://stackoverflow.com/questions/4356289/php-random-string-generator
     * @param int $length
     * @param bool $numbers
     *
     * @return string
     */
    public static function generate_random_string(int $length = 10, bool $numbers = true): string {
        $number_string = $numbers ? '0123456789' : '';
        $characters = "{$number_string}abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[mt_rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    /**
     * Write to the WordPress debug.log file.
     *
     * @param $log
     *
     * @return void
     */
    public static function write_log($log): void {
        if (true === WP_DEBUG) {
            if (is_array($log) || is_object($log)) {
                error_log(print_r($log, true));
            }
            else {
                error_log($log);
            }
        }
    }

    /**
     * Get the array of settings config from a tab/subsection.
     *
     * @param $settings_array
     * @param $tab
     * @param $subsection
     *
     * @return mixed
     */
    public static function get_settings($settings_array, $tab, $subsection) {
        return $settings_array[$tab]['tab'][$subsection]['settings'];
    }

    public static function get_allowed_html(): array {
        $allowed_html = [
            'h1' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'h2' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'h3' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'h4' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'h5' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'h6' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'hr' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'form' => [
                'id' => true,
                'class' => true,
                'style' => true,
                'action' => true,
                'method' => true
            ],
            'iframe' => [
                'id' => true,
                'class' => true,
                'style' => true,
                'src' => true,
                'width' => true,
                'height' => true,
                'frameborder' => true,
                'allowfullscreen' => true,
                'aria-hidden' => true,
                'tabindex' => true
            ],
            'button' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'blockquote' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'article' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'section' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'div' => [
                'id' => true,
                'class' => true,
                'style' => true,
            ],
            'span' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'a' => [
                'href' => true,
                'title' => true,
                'target' => true,
                'rel' => true,
                'class' => true
            ],
            'i' => true,
            'u' => true,
            'ol' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'ul' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'li' => [
                'id' => true,
                'class' => true,
                'style' => true,
            ],
            'img' => [
                'id' => true,
                'class' => true,
                'title' => true,
                'src' => true,
                'alt' => true,
                'width' => true,
                'height' => true
            ],
            'b' => true,
            'br' => true,
            'em' => true,
            's' => true,
            'strong' => [
                'class' => true
            ],
            'select' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'option' => [
                'class' => true
            ],
            'small' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'p' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'label' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'input' => [
                'id' => true,
                'class' => true,
                'style' => true,
                'type' => true,
                'name' => true,
                'value' => true,
                'placeholder' => true
            ],
            'ins' => [
                'class' => true,
                'data-*' => true
            ],
            'table' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'thead' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'tbody' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'tfoot' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'th' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'tr' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'td' => [
                'id' => true,
                'class' => true,
                'style' => true
            ],
            'style' => true,
            'del' => true,
            'map' => [
                'id' => true,
                'class' => true,
                'style' => true,
                'name' => true
            ],
            'area' => [
                'coords' => true,
                'shape' => true,
                'href' => true
            ],
            'pre' => true
        ];

        return apply_filters('pom_framework_allowed_html', $allowed_html);
    }

    /**
     * Get allowed font types.
     *
     * @return string[]
     */
    public static function get_allowed_font_types(): array {
        return [
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
        ];
    }

}
