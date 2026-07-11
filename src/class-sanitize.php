<?php
/**
 * Functions to ensure that values are safe to tamper with.
 */

use POMFramework\POM_Framework_Disk;
use POMFramework\POM_Framework_Helper;

if (!function_exists('sanitize_pom_framework_background_image')) {
    function sanitize_pom_framework_background_image($value) {
        if (empty($value)) {
            return [];
        }

        if (!is_array($value)) {
            $value = json_decode(stripslashes($value), true);
        }

        if (!is_array($value)) {
            return [];
        }

        $allowed_values = [
            'background-repeat' => ['repeat', 'repeat-x', 'repeat-y', 'prepeat-y', 'no-repeat'],
            'background-attachment' => ['scroll', 'fixed'],
            'custom_horizontal_alignment_unit' => ['%', 'px', 'rem', 'em'],
            'custom_vertical_alignment_unit' => ['%', 'px', 'rem', 'em'],
            'custom_background_size_width_unit' => ['%', 'px', 'rem', 'em'],
            'custom_background_size_height_unit' => ['%', 'px', 'rem', 'em'],
        ];
        $numeric_keys = [
            'custom_horizontal_alignment_number',
            'custom_vertical_alignment_number',
            'custom_background_size_width_number',
            'custom_background_size_height_number',
        ];
        $sanitized = [];

        if (isset($value['background-image'])) {
            $sanitized['background-image'] = esc_url_raw((string) $value['background-image']);
        }

        foreach ($allowed_values as $key => $options) {
            $candidate = isset($value[$key]) ? sanitize_text_field((string) $value[$key]) : '';
            if (in_array($candidate, $options, true)) {
                $sanitized[$key] = $candidate;
            }
        }

        foreach ($numeric_keys as $key) {
            if (!isset($value[$key]) || $value[$key] === '') {
                continue;
            }

            $candidate = filter_var($value[$key], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            if ($candidate !== '' && is_numeric($candidate)) {
                $sanitized[$key] = $candidate;
            }
        }

        $position_pattern = '/^-?(?:\d+|\d*\.\d+)(?:%|px|rem|em)$/';
        $horizontal = isset($value['horizontal_alignment']) ? sanitize_text_field((string) $value['horizontal_alignment']) : '';
        $vertical = isset($value['vertical_alignment']) ? sanitize_text_field((string) $value['vertical_alignment']) : '';

        if (!in_array($horizontal, ['left', 'center', 'right'], true) && !preg_match($position_pattern, $horizontal)) {
            $horizontal = '';
        }
        if (!in_array($vertical, ['top', 'center', 'bottom'], true) && !preg_match($position_pattern, $vertical)) {
            $vertical = '';
        }

        if (($horizontal === '' || $vertical === '') && isset($value['background-position'])) {
            $position_parts = preg_split('/\s+/', trim((string) $value['background-position']));
            if (is_array($position_parts) && count($position_parts) === 2) {
                $legacy_horizontal = sanitize_text_field($position_parts[0]);
                $legacy_vertical = sanitize_text_field($position_parts[1]);
                if ($horizontal === '' && (in_array($legacy_horizontal, ['left', 'center', 'right'], true) || preg_match($position_pattern, $legacy_horizontal))) {
                    $horizontal = $legacy_horizontal;
                }
                if ($vertical === '' && (in_array($legacy_vertical, ['top', 'center', 'bottom'], true) || preg_match($position_pattern, $legacy_vertical))) {
                    $vertical = $legacy_vertical;
                }
            }
        }

        if ($horizontal !== '') {
            $sanitized['horizontal_alignment'] = $horizontal;
        }
        if ($vertical !== '') {
            $sanitized['vertical_alignment'] = $vertical;
        }
        if ($horizontal !== '' && $vertical !== '') {
            $sanitized['background-position'] = $horizontal . ' ' . $vertical;
        }

        $background_size = isset($value['background-size']) ? sanitize_text_field((string) $value['background-size']) : '';
        if (in_array($background_size, ['auto', 'cover', 'contain'], true)) {
            $sanitized['background-size'] = $background_size;
        }
        elseif ($background_size !== '') {
            $size_parts = preg_split('/\s+/', trim($background_size));
            $valid_parts = is_array($size_parts) && count($size_parts) > 0 && count($size_parts) <= 2;
            foreach ((array) $size_parts as $size_part) {
                if ($size_part !== 'auto' && !preg_match($position_pattern, $size_part)) {
                    $valid_parts = false;
                    break;
                }
            }

            if ($valid_parts) {
                $sanitized['background-size'] = implode(' ', $size_parts);
            }
        }

        foreach (['background-width', 'background-height'] as $legacy_size_key) {
            if (!isset($value[$legacy_size_key]) || $value[$legacy_size_key] === '') {
                continue;
            }

            $legacy_size = filter_var($value[$legacy_size_key], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            if ($legacy_size !== '' && is_numeric($legacy_size)) {
                $sanitized[$legacy_size_key] = $legacy_size;
            }
        }

        if (isset($value['lazyload'])) {
            $sanitized['lazyload'] = filter_var($value['lazyload'], FILTER_VALIDATE_BOOLEAN);
        }

        return $sanitized;
    }
}

if (!function_exists('sanitize_pom_framework_button')) {
    function sanitize_pom_framework_button($value) {
        return sanitize_text_field($value);
    }
}

/**
 * Returns 'yes' or 'no' if it is a single checkbox.
 * If it is an array of checkboxes, it returns a value in array format.
 *
 * @param $value
 *
 * @return array|mixed|string
 */
if (!function_exists('sanitize_pom_framework_checkbox')) {
    function sanitize_pom_framework_checkbox($value) {
        if (is_array($value)) {
            return !empty($value) ? $value : [];
        }

        return $value === 'yes' ? 'yes' : 'no';
    }
}

if (!function_exists('sanitize_pom_framework_toggle')) {
    function sanitize_pom_framework_toggle($value): string {
        return $value === 'yes' ? 'yes' : 'no';
    }
}

if (!function_exists('sanitize_pom_framework_code_css')) {
    // TODO: Fix this
    function sanitize_pom_framework_code_css($value, $compression_level = 'default'): string {
/*        $csstidy = new csstidy();
        $csstidy->set_cfg('optimise_shorthands', 2);
        $csstidy->set_cfg('template', $compression_level); // compression level
        $csstidy->parse($value);*/

        return $value;
    }
}

/**
 * Filters text content and strips out disallowed HTML.
 *
 * @param $value
 *
 * @return string
 */
if (!function_exists('sanitize_pom_framework_code_html')) {
    function sanitize_pom_framework_code_html($value): string {
        $allowed_tags = POM_Framework_Helper::get_allowed_html();

        return wp_kses(stripslashes($value), $allowed_tags);
    }
}

if (!function_exists('sanitize_pom_framework_code_js')) {
    function sanitize_pom_framework_code_js($value): string {
        $filtered_js = esc_js($value);

        return stripslashes($filtered_js);
    }
}

if (!function_exists('sanitize_pom_framework_code_json')) {
    function sanitize_pom_framework_code_json($value): string {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return '';
        }

        return $value;
    }
}

if (!function_exists('sanitize_pom_framework_color')) {
    function sanitize_pom_framework_color($value): string {
        return sanitize_hex_color(sanitize_text_field($value));
    }
}

if (!function_exists('sanitize_pom_framework_color_palette')) {
    function sanitize_pom_framework_color_palette($value): string {
        return sanitize_text_field($value);
    }
}

if (!function_exists('sanitize_pom_framework_date')) {
    function sanitize_pom_framework_date($value, $format = 'Y-m-d') {
        $timestamp = strtotime(sanitize_text_field($value));

        return date($format, $timestamp);
    }
}

if (!function_exists('sanitize_pom_framework_datetime')) {
    function sanitize_pom_framework_datetime($value, $format = 'Y-m-d H:i') {
        $timestamp = strtotime(sanitize_text_field($value));

        return date($format, $timestamp);
    }
}

if (!function_exists('sanitize_pom_framework_email')) {
    function sanitize_pom_framework_email($value): string {
        return sanitize_email($value);
    }
}

if (!function_exists('sanitize_pom_framework_file')) {
    function sanitize_pom_framework_file($value) {
        return is_scalar($value) ? sanitize_text_field((string) $value) : '';
    }
}

/**
 * Allow only numbers (from 0 to 9) and comma (,).
 *
 * @param $value
 *
 * @return false|mixed
 */
if (!function_exists('sanitize_pom_framework_gallery')) {
    function sanitize_pom_framework_gallery($value) {
        $validate = preg_match("/^[0-9,]+$/", $value);

        return $validate ? $value : false;
    }
}

if (!function_exists('sanitize_pom_framework_hidden')) {
    function sanitize_pom_framework_hidden($value): string {
        return sanitize_text_field($value);
    }
}

/**
 * Validate that it is a real URL.
 *
 * @param $value
 *
 * @return string
 */
if (!function_exists('sanitize_pom_framework_icon_picker')) {
    function sanitize_pom_framework_icon_picker($value): string {
        return sanitize_url($value);
    }
}

/**
 * Validate that it is a real URL.
 *
 * @param $value
 *
 * @return string
 */
if (!function_exists('sanitize_pom_framework_image_picker')) {
    function sanitize_pom_framework_image_picker($value): string {
        return sanitize_url($value);
    }
}

if (!function_exists('sanitize_pom_framework_image_picker_id')) {
    function sanitize_pom_framework_image_picker_id($value): string {
        $sanitized = absint($value);
        return $sanitized ? (string) $sanitized : '';
    }
}

if (!function_exists('sanitize_pom_framework_number')) {
    function sanitize_pom_framework_number($value) {
        return filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT);
    }
}

if (!function_exists('sanitize_pom_framework_password')) {
    function sanitize_pom_framework_password($value): string {
        return sanitize_text_field($value);
    }
}

if (!function_exists('sanitize_pom_framework_quantity')) {
    function sanitize_pom_framework_quantity($value) {
        return filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT);
    }
}

if (!function_exists('sanitize_pom_framework_radio')) {
    function sanitize_pom_framework_radio($value): string {
        return sanitize_text_field($value);
    }
}

if (!function_exists('sanitize_pom_framework_radio_icons')) {
    function sanitize_pom_framework_radio_icons($value): string {
        return sanitize_text_field($value);
    }
}

if (!function_exists('sanitize_pom_framework_range')) {
    function sanitize_pom_framework_range($value) {
        return filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }
}

if (!function_exists('sanitize_pom_framework_trbl')) {
    function sanitize_pom_framework_trbl($value): array {
        if (is_string($value)) {
            $value = json_decode(stripslashes($value), true);
        }

        if (!is_array($value)) {
            return [];
        }

        $sides = ['top', 'right', 'bottom', 'left'];
        $sanitized = [
            'sync' => (!empty($value['sync']) && $value['sync'] === 'yes') ? 'yes' : 'no'
        ];

        foreach ($sides as $side) {
            $side_value = '';
            $side_unit = '';

            if (!empty($value[$side]) && is_array($value[$side])) {
                $side_value = isset($value[$side]['value']) ? filter_var($value[$side]['value'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : '';
                $side_unit = isset($value[$side]['unit']) ? sanitize_text_field($value[$side]['unit']) : '';
            }

            $sanitized[$side] = [
                'value' => $side_value,
                'unit'  => $side_unit,
            ];
        }

        return $sanitized;
    }
}

if (!function_exists('sanitize_pom_framework_font')) {
    function sanitize_pom_framework_font($value) {
        return sanitize_pom_framework_repeater(
            $value,
            [
                'name' => 'font_families',
                'limit' => 5,
                'fields' => pom_framework_get_font_field_definitions(),
            ]
        );
    }
}

if (!function_exists('sanitize_pom_framework_font_picker')) {
    function sanitize_pom_framework_font_picker($value): array {
        if (is_string($value)) {
            $value = str_replace('&quot;', '"', $value);
            $value = json_decode($value, true);
        }

        if (!is_array($value)) {
            return [];
        }

        $font_extensions = array_keys(POM_Framework_Helper::get_allowed_font_types());
        $sanitized = [];
        foreach ($value as $font_extension => $font_url) {
            if (!in_array($font_extension, $font_extensions, true)) {
                continue;
            }

            $sanitized[$font_extension] = sanitize_url($font_url);
        }

        return $sanitized;
    }
}

if (!function_exists('sanitize_pom_framework_repeater')) {
    function sanitize_pom_framework_repeater($value, $array_settings = [], $settings_dir = 'pom-framework'): array {
        if (is_string($value)) {
            $value = json_decode(stripslashes($value), true);
        }

        if (!empty($value) && is_array($value)) {
            $sanitized_array = [];

            $limit = !empty($array_settings['limit']) ? absint($array_settings['limit']) : 0;
            $field_definitions = pom_framework_get_field_definition_map($array_settings['fields'] ?? []);

            $i = 0;
            foreach ($value as $type => $items) {
                $type = sanitize_key((string) $type);
                if (!in_array($type, ['default', 'new'], true) || !is_array($items)) {
                    continue;
                }

                foreach ($items as $index => $arr_data) {
                    if ($limit > 0 && $i >= $limit) {
                        break;
                    }

                    $i++;
                    if (!is_array($arr_data)) {
                        continue;
                    }

                    $repeater_identifier = '';
                    foreach ($arr_data as $arr_key => $arr_value) {
                        if ($arr_key === 'repeater_identifier') {
                            $sanitized_array[$type][$index][$arr_key] = $repeater_identifier = sanitize_pom_framework_text($arr_value);
                        }
                        elseif ($arr_key === 'default_values') {
                            $sanitized_array[$type][$index][$arr_key] = pom_framework_sanitize_repeater_defaults($arr_value, $field_definitions, $settings_dir);
                        }
                        else {
                            $field_name = str_replace('[]', '', (string) $arr_key);
                            if (!isset($field_definitions[$field_name]) || !is_array($arr_value)) {
                                continue;
                            }

                            $field_definition = $field_definitions[$field_name];
                            $field_type = strtolower((string) ($field_definition['type'] ?? 'text'));

                            if (isset($array_settings['name']) && in_array($field_type, ['code_html', 'code_css', 'code_js', 'code_json'], true)) {
                                $file_name = "{$array_settings['name']}_{$repeater_identifier}_$field_name";
                                $field_value = $arr_value['value'] ?? '';
                                if ($field_type === 'code_html' || $field_type === 'code_json') {
                                    $field_value = pom_framework_sanitize_defined_field($field_value, $field_definition, $settings_dir, $file_name);
                                }

                                $sanitized_array[$type][$index][$field_name]['value'] = POM_Framework_Disk::save_to_file($file_name, $field_value, str_replace('code_', '', $field_type), $settings_dir);
                            }
                            else {
                                $sanitized_array[$type][$index][$field_name]['value'] = pom_framework_sanitize_defined_field($arr_value['value'] ?? '', $field_definition, $settings_dir, $field_name);
                            }

                            $sanitized_array[$type][$index][$field_name]['type'] = $field_type;
                        }
                    }
                }
            }

            return $sanitized_array;
        }

        return [];
    }
}

if (!function_exists('pom_framework_get_field_definition_map')) {
    function pom_framework_get_field_definition_map($fields): array {
        if (!is_array($fields)) {
            return [];
        }

        $definitions = [];
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['name'])) {
                continue;
            }

            $definitions[(string) $field['name']] = $field;
        }

        return $definitions;
    }
}

if (!function_exists('pom_framework_get_font_field_definitions')) {
    /**
     * Return the fixed schema used by the composite Font field.
     *
     * Why: Font renders an internal repeater, so its server-side sanitizer must
     * have the same immutable field names and options without trusting types
     * copied back from the browser.
     */
    function pom_framework_get_font_field_definitions(): array {
        return [
            [
                'type' => 'text',
                'name' => 'font_name',
            ],
            [
                'type' => 'select',
                'name' => 'font_type',
                'options' => [
                    'normal' => 'Normal',
                    'variable' => 'Variable',
                ],
                'default' => 'normal',
            ],
            [
                'type' => 'select',
                'name' => 'font_fallback',
                'options' => [
                    'sans_serif' => 'Sans serif',
                    'serif' => 'Serif',
                    'monospace' => 'Monospace',
                ],
            ],
            [
                'type' => 'repeater',
                'name' => 'font_variant',
                'limit' => 18,
                'fields' => [
                    [
                        'type' => 'text',
                        'name' => 'variant_name',
                    ],
                    [
                        'type' => 'select',
                        'name' => 'font_weight',
                        'options' => [
                            'lighter' => 'Lighter',
                            '300' => '300',
                            '400' => '400',
                            '600' => '600',
                            '700' => '700',
                            'bolder' => 'Bolder',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'name' => 'font_style',
                        'options' => [
                            'normal' => 'Normal',
                            'italic' => 'Italic',
                            'oblique' => 'Oblique',
                        ],
                    ],
                    [
                        'type' => 'font_picker',
                        'name' => 'font_variants',
                    ],
                ],
            ],
            [
                'type' => 'font_picker',
                'name' => 'font_variable_files',
            ],
            [
                'type' => 'text',
                'name' => 'font_variable_weight_range',
            ],
            [
                'type' => 'text',
                'name' => 'font_variable_stretch_range',
            ],
            [
                'type' => 'select',
                'name' => 'font_variable_style',
                'options' => [
                    'normal' => 'Normal',
                    'italic' => 'Italic',
                    'oblique' => 'Oblique',
                ],
                'default' => 'normal',
            ],
            [
                'type' => 'select',
                'name' => 'font_variable_display',
                'options' => [
                    'auto' => 'Auto',
                    'block' => 'Block',
                    'swap' => 'Swap',
                    'fallback' => 'Fallback',
                    'optional' => 'Optional',
                ],
                'default' => 'swap',
            ],
        ];
    }
}

if (!function_exists('pom_framework_get_allowed_option_values')) {
    function pom_framework_get_allowed_option_values($options): array {
        if (!is_array($options)) {
            return [];
        }

        $allowed = [];
        foreach ($options as $key => $label) {
            if (is_array($label) && isset($label['group_options']) && is_array($label['group_options'])) {
                foreach (array_keys($label['group_options']) as $group_key) {
                    $allowed[] = (string) $group_key;
                }
                continue;
            }

            $allowed[] = (string) $key;
        }

        return array_values(array_unique($allowed));
    }
}

if (!function_exists('pom_framework_sanitize_defined_field')) {
    function pom_framework_sanitize_defined_field($value, array $field_definition, string $settings_dir = 'pom-framework', string $field_name = '') {
        $type = strtolower((string) ($field_definition['type'] ?? 'text'));

        if ($type === 'repeater') {
            return sanitize_pom_framework_repeater(
                $value,
                [
                    'name' => $field_name !== '' ? $field_name : ($field_definition['name'] ?? ''),
                    'limit' => $field_definition['limit'] ?? 0,
                    'fields' => $field_definition['fields'] ?? [],
                ],
                $settings_dir
            );
        }

        $sanitize_function_name = "sanitize_pom_framework_$type";
        if (!function_exists($sanitize_function_name)) {
            return is_scalar($value) ? sanitize_text_field((string) $value) : '';
        }

        $allowed = pom_framework_get_allowed_option_values($field_definition['options'] ?? []);
        if ($type === 'checkbox' && !empty($allowed)) {
            $values = is_array($value) ? $value : [$value];
            $values = array_map(static function($item): string {
                return sanitize_text_field((string) $item);
            }, $values);

            return array_values(array_intersect($values, $allowed));
        }

        if ($type === 'select' && !empty($field_definition['multiple']) && !empty($allowed)) {
            $values = is_array($value) ? $value : explode(',', (string) $value);
            $values = array_map(static function($item): string {
                return sanitize_text_field((string) $item);
            }, $values);

            return implode(',', array_values(array_intersect($values, $allowed)));
        }

        $sanitized = $sanitize_function_name($value);

        if (in_array($type, ['select', 'radio', 'radio_icons', 'color_palette'], true) && !empty($allowed)) {
            $sanitized = (string) $sanitized;
            if (!in_array($sanitized, $allowed, true)) {
                $default = isset($field_definition['default']) ? (string) $field_definition['default'] : '';
                return in_array($default, $allowed, true) ? $default : '';
            }
        }

        if (in_array($type, ['number', 'quantity', 'range'], true) && $sanitized !== '' && is_numeric($sanitized)) {
            $numeric_value = (float) $sanitized;
            if (isset($field_definition['min']) && is_numeric($field_definition['min'])) {
                $numeric_value = max((float) $field_definition['min'], $numeric_value);
            }
            if (isset($field_definition['max']) && is_numeric($field_definition['max'])) {
                $numeric_value = min((float) $field_definition['max'], $numeric_value);
            }

            return strpos((string) $sanitized, '.') === false ? (string) (int) $numeric_value : (string) $numeric_value;
        }

        return $sanitized;
    }
}

if (!function_exists('pom_framework_sanitize_repeater_defaults')) {
    function pom_framework_sanitize_repeater_defaults($value, array $field_definitions, string $settings_dir): array {
        if (is_string($value)) {
            $value = json_decode(stripslashes($value), true);
        }

        if (!is_array($value)) {
            return [];
        }

        $sanitized = [];
        foreach ($field_definitions as $field_name => $field_definition) {
            if (!isset($value[$field_name]) || !is_array($value[$field_name])) {
                continue;
            }

            $sanitized[$field_name] = [
                'value' => pom_framework_sanitize_defined_field($value[$field_name]['value'] ?? '', $field_definition, $settings_dir, $field_name),
                'disabled' => !empty($value[$field_name]['disabled']),
            ];
        }

        if (array_key_exists('can_be_removed', $value)) {
            $sanitized['can_be_removed'] = !empty($value['can_be_removed']);
        }

        return $sanitized;
    }
}

if (!function_exists('sanitize_pom_framework_select')) {
    function sanitize_pom_framework_select($value): string {
        if (is_array($value)) {
            return sanitize_text_field(implode(',', $value));
        }

        return sanitize_text_field($value);
    }
}

if (!function_exists('sanitize_pom_framework_signature')) {
    function sanitize_pom_framework_signature($value): string {
        return $value;
    }
}

/**
 * Returns the entered value if it is a valid phone.
 * If not, it returns false.
 *
 * @param $value
 *
 * @return false|mixed
 */
if (!function_exists('sanitize_pom_framework_tel')) {
    function sanitize_pom_framework_tel($value) {
        $validate = preg_match("/^\\+?[1-9][0-9]{7,14}$/", $value);

        return $validate ? $value : false;
    }
}

if (!function_exists('sanitize_pom_framework_text')) {
    function sanitize_pom_framework_text($value): string {
        return sanitize_text_field($value);
    }
}

if (!function_exists('sanitize_pom_framework_textarea')) {
    function sanitize_pom_framework_textarea($value): string {
        return sanitize_textarea_field($value);
    }
}

/**
 * Returns the time in HH:MM format if it passes validation.
 * If not, it returns false.
 *
 * @param $value
 *
 * @return false|mixed
 */
if (!function_exists('sanitize_pom_framework_time')) {
    function sanitize_pom_framework_time($value) {
        $validate = preg_match("/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/", $value);

        return $validate ? $value : false;
    }
}

if (!function_exists('sanitize_pom_framework_tinymce')) {
    function sanitize_pom_framework_tinymce($value): string {
        $allowed_tags = POM_Framework_Helper::get_allowed_html();

        return wp_kses($value, $allowed_tags);
    }
}

if (!function_exists('sanitize_pom_framework_url')) {
    function sanitize_pom_framework_url($value): string {
        $value = trim($value);

        if (strpos($value, '#') === 0) {
            return '#' . sanitize_title($value);
        }

        if ($value === '') {
            return '';
        }

        // Allow relative paths but reject special keywords that would otherwise
        // be coerced into fake hosts such as "http://self".
        if (strpos($value, '/') === 0) {
            return esc_url_raw($value);
        }

        $sanitized = sanitize_url($value);

        if ($sanitized === '') {
            return '';
        }

        $host = wp_parse_url($sanitized, PHP_URL_HOST);

        if (!empty($host) && in_array(strtolower($host), ['self'], true)) {
            return '';
        }

        return $sanitized;
    }
}
