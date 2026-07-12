<?php
/**
 *
 */

namespace POMFramework;

class POM_Framework_Translations {
    /**
     * TODO: When a field that has already been translatable is no longer translatable, it does not disappear from the translatable strings.
     */

    private $settings_dir;

    public function __construct($settings_dir) {
        $this->settings_dir = $settings_dir;

        add_action('admin_init', [$this, 'register_translatable_strings']);
    }

    public function register_translatable_strings(): void {
        if (!function_exists('pll_register_string')) {
            return;
        }

        $settings_path = (new POM_Framework_Disk)->get_settings_path($this->settings_dir);

        $strings = [];
        if (file_exists("{$settings_path}translatable_strings.php")) {
            $strings = include "{$settings_path}translatable_strings.php";
        }

        if (!empty($strings) && is_array($strings)) {
            foreach ($strings as $name => $data) {
                $string = POM_Framework_Settings::get_setting_value($this->settings_dir, $data['filename'], $name, $data['type']);
                $type = strtolower($data['type']);

                if (in_array($type, ['code_html', 'tinymce'], true)) {
                    $managed_contents = POM_Framework_Disk::read_managed_file_path($string);
                    if ($managed_contents !== null) {
                        $string = $managed_contents;
                    }
                }

                pll_register_string($name, $string, 'POM Framework', $data['multiline']);
            }
        }
    }

    /**
     * Saves in a file all the information needed to register the translatable strings.
     */
    public static function register($translatables, $settings_dir): void {
        if (empty($translatables)) {
            return;
        }

        $saved_strings = POM_Framework_Disk::read_file('translatable_strings.php', $settings_dir, 'array');
        $saved_strings = !empty($saved_strings) && is_array($saved_strings) ? $saved_strings : [];

        foreach ($translatables as $translatable_key => $translatable_data) {
            $saved_strings[$translatable_key] = $translatable_data;
        }

        $content = (new POM_Framework_Disk())->generate_file_content($saved_strings, 'String translations.');
        POM_Framework_Disk::save_to_file('translatable_strings', $content, 'php', $settings_dir);
    }

    /**
     * If Polylang is active, translate a previously registered string.
     *
     * @param $string
     *
     * @return string
     */
    public static function translate($string): string {
        return function_exists('pll__') ? pll__($string) : $string;
    }

}
