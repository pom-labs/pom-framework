<?php

namespace POMFramework;

const POM_FRAMEWORK_VERSION = '0.1.0';

define('POM_FRAMEWORK_MIN', defined('WP_DEBUG') && WP_DEBUG !== true ? '.min' : '');
define('POM_FRAMEWORK_SRC_PATH', __DIR__);
define('POM_FRAMEWORK_SRC_URI', str_replace(realpath($_SERVER['DOCUMENT_ROOT']), '', realpath(POM_FRAMEWORK_SRC_PATH)));

class POM_Framework {
    private static $settings_page_hooks = [];

    public function __construct() {
        require_once 'class-sanitize.php';
        require_once 'cli/init-cli.php';

        $classes = [
            POM_Framework_Helper::class => 'POM_Framework_Helper.php',
            POM_Framework_Disk::class => 'POM_Framework_Disk.php',
            POM_Framework_Settings::class => 'POM_Framework_Settings.php',
            POM_Framework_Ajax::class => 'POM_Framework_Ajax.php',
            POM_Framework_Save::class => 'POM_Framework_Save.php',
            POM_Framework_Translations::class => 'POM_Framework_Translations.php',
            POM_Framework_Merge_Tags::class => 'POM_Framework_Merge_Tags.php',
        ];

        foreach ($classes as $class => $file) {
            if (!class_exists($class)) {
                require_once POM_FRAMEWORK_SRC_PATH . '/' . $file;
            }
        }

        // Disk hooks are registered explicitly once instead of as a side effect of every file operation.
        new POM_Framework_Disk();

        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public static function register_settings_page(string $hook_suffix): void {
        if (!in_array($hook_suffix, self::$settings_page_hooks, true)) {
            self::$settings_page_hooks[] = $hook_suffix;
        }
    }

    public function enqueue_scripts($hook): void {
        if (in_array($hook, self::$settings_page_hooks, true)) {
            wp_enqueue_style('pom-framework-settings', POM_FRAMEWORK_SRC_URI . '/dist/css/admin.min.css');
            wp_enqueue_script('pom-framework-dependencies', POM_FRAMEWORK_SRC_URI . '/dist/js/dependencies' . POM_FRAMEWORK_MIN . '.js', ['jquery'], null, true);
        }
    }

    /**
     * Analyze the values before rendering the field
     * and set default values if they are not set.
     *
     * Allow custom attributes. These are not analyzed.
     *
     * @param array $args
     *
     * @return array
     */
    private static function parse_args(array $args): array {
        $field_args = wp_parse_args($args);

        // Make sure the basic attributes have valid values.
        $field_args['type'] = $field_args['type'] ?? 'text';
        $field_args['label'] = $field_args['label'] ?? '';
        $field_args['description'] = $field_args['description'] ?? '';
        $field_args['name'] = isset($field_args['name']) ? sanitize_title($field_args['name']) : '';
        $field_args['id'] = $field_args['id'] ?? $field_args['name'];
        $field_args['value'] = $field_args['value'] ?? '';
        $field_args['class'] = isset($field_args['class']) ? POM_Framework_Helper::sanitize_html_classes($field_args['class']) : '';
        $field_args['description_position'] = $field_args['description_position'] ?? 'under_field';
        $field_args['options'] = $field_args['options'] ?? [];
        $field_args['prefix'] = $field_args['prefix'] ?? '';
        $field_args['suffix'] = $field_args['suffix'] ?? '';
        $field_args['default'] = $field_args['default'] ?? '';

        return $field_args;
    }

    /**
     * Returns the HTML of the field.
     *
     * @param array $args
     *
     * @return string
     */
    public static function add_field(array $args): string {
        $type = self::resolve_field_type((string) ($args['type'] ?? 'text'));

        if ($type === '') {
            return '';
        }

        if (!file_exists($filename = POM_Framework_Helper::get_path() . "/Fields/$type.php")) {
            return '';
        }

        include_once $filename;

        $class = "POMFramework\\Fields\\$type";
        $field_class = new $class();

        $field_args = self::parse_args($args);

        ob_start();

        $field_class::render_field($field_args);

        /**
         * If the field is of type repeater and has a code editor inside,
         * it is necessary to do the enqueue from the beginning.
         * Otherwise, the field is not rendered.
         */
        if (strtolower((string) $field_args['type']) === 'repeater' && !empty($field_args['fields'])) {
            foreach ($field_args['fields'] as $repeater_field) {
                $repeater_field_type = strtolower((string) ($repeater_field['type'] ?? ''));

                if (in_array($repeater_field_type, ['code_html', 'code_css', 'code_js', 'code_json'], true)) {
                    POM_Framework_Helper::enqueue_code_editor_assets();
                }

                if ($repeater_field_type === 'select') {
                    wp_enqueue_script('pom-framework-select', POM_FRAMEWORK_SRC_URI . '/dist/js/select' . POM_FRAMEWORK_MIN . '.js', ['jquery'], null, true);
                }

                if ($repeater_field_type === 'color') {
                    wp_enqueue_style('wp-color-picker');
                    wp_enqueue_script('pom-framework-color', POM_FRAMEWORK_SRC_URI . '/dist/js/color' . POM_FRAMEWORK_MIN . '.js', ['wp-color-picker'], null, true);
                }

                if ($repeater_field_type === 'font_picker') {
                    $mime_types = array_values(POM_Framework_Helper::get_allowed_font_types());
                    $mime_types = array_merge(
                        $mime_types,
                        [
                            'application/x-font-ttf',
                            'application/x-font-woff',
                            'application/font-woff',
                            'application/font-woff2',
                            'application/x-font-truetype',
                        ]
                    );
                    $mime_types = array_values(array_unique($mime_types));

                    wp_enqueue_media();
                    wp_enqueue_style('pom-framework-font_picker', POM_FRAMEWORK_SRC_URI . '/dist/css/font-picker.min.css');
                    wp_enqueue_script('pom-framework-font_picker', POM_FRAMEWORK_SRC_URI . '/dist/js/font_picker' . POM_FRAMEWORK_MIN . '.js', ['jquery'], null, true);
                    wp_localize_script(
                        'pom-framework-font_picker',
                        'pom_framework_font_picker',
                        [
                            'title' => __('Choose Font', 'pom-framework'),
                            'button' => __('Choose Font', 'pom-framework'),
                            'mime_types' => $mime_types,
                        ]
                    );
                }
            }
        }

        return ob_get_clean();
    }

    private static function resolve_field_type(string $type): string {
        $type = trim($type);

        if ($type === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $type)) {
            return '';
        }

        static $field_types = null;

        if ($field_types === null) {
            $field_types = [];
            $files = glob(POM_Framework_Helper::get_path() . '/Fields/*.php');

            if (is_array($files)) {
                foreach ($files as $file) {
                    $field_type = pathinfo($file, PATHINFO_FILENAME);
                    $field_types[strtolower($field_type)] = $field_type;
                }
            }
        }

        return $field_types[strtolower($type)] ?? ucfirst($type);
    }

}

new POM_Framework();
