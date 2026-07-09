<?php
/**
 *
 */

namespace POMFramework;

class POM_Framework_Settings {

    public static function get_current_tab($settings_array) {
        /**
         * Skip first array key (fist key should be 'config').
         */
        if (isset($_GET['section'])) {
            $section = sanitize_key(wp_unslash($_GET['section']));

            if ($section !== 'config' && isset($settings_array[$section])) {
                return $section;
            }
        }

        $first_key = array_key_first($settings_array);

        return $first_key === 'config' ? array_key_first(array_slice($settings_array, 1)) : array_key_first($settings_array);
    }

    public static function get_current_subsection($settings_array) {
        $current_tab = self::get_current_tab($settings_array);
        $tabs = isset($settings_array[$current_tab]['tab']) && is_array($settings_array[$current_tab]['tab'])
            ? $settings_array[$current_tab]['tab']
            : [];

        if (isset($_GET['tab'])) {
            $tab = sanitize_key(wp_unslash($_GET['tab']));

            if (isset($tabs[$tab])) {
                return $tab;
            }
        }

        if (!empty($tabs)) {
            return array_key_first($tabs);
        }

        return null;
    }

    public static function read_fields($settings_dir, $setting_name) {
        $settings_dir = rtrim($settings_dir, '/');

        if (file_exists("$settings_dir/$setting_name/fields.php")) {
            return include "$settings_dir/$setting_name/fields.php";
        }

        return [];
    }

    public static function get_setting_value($page_slug, $setting_name, $field_name, $type = '') {
        $storage_metadata = self::get_field_storage_metadata($page_slug, $setting_name, $field_name);

        if (!empty($storage_metadata)) {
            $default_value = $storage_metadata['default'] ?? '';

            if (($storage_metadata['storage'] ?? '') === 'theme_mod') {
                $value = get_theme_mod($field_name, $default_value);
            }
            elseif (($storage_metadata['storage'] ?? '') === 'option') {
                $value = get_option($field_name, $default_value);
            }
            else {
                $values = POM_Framework_Disk::read_file("$setting_name.php", $page_slug, 'array');
                $value = is_array($values) && isset($values[$field_name]) ? $values[$field_name] : '';
            }
        }
        else {
            $values = POM_Framework_Disk::read_file("$setting_name.php", $page_slug, 'array');
            $value = is_array($values) && isset($values[$field_name]) ? $values[$field_name] : '';
        }

        if (!empty($type)) {
            $sanitize_function_name = "sanitize_pom_framework_$type";

            if (function_exists($sanitize_function_name)) {
                $value = $sanitize_function_name($value);
            }
        }

        if ($type === 'font' && is_array($value)) {
            $value = self::add_font_face_to_fonts($value);
        }

        return $value;
    }

    /**
     * Append a computed @font-face block to each font repeater item.
     *
     * @param array $fonts
     *
     * @return array
     */
    public static function add_font_face_to_fonts(array $fonts): array {
        foreach ($fonts as $repeater_type => $repeater_elements) {
            if (!is_array($repeater_elements)) {
                continue;
            }

            foreach ($repeater_elements as $index => $repeater_item) {
                if (!is_array($repeater_item)) {
                    continue;
                }

                $fonts[$repeater_type][$index]['font_face'] = [
                    'value' => self::build_font_face_css($repeater_item),
                    'type' => 'font_face',
                ];
            }
        }

        return $fonts;
    }

    private static function build_font_face_css(array $font_item): string {
        $font_family = self::get_font_field_value($font_item, 'font_name', '');
        $font_family = trim((string)$font_family);

        if ($font_family === '') {
            return '';
        }

        $font_family = str_replace('"', '\"', $font_family);
        $font_type = strtolower((string)self::get_font_field_value($font_item, 'font_type', 'normal'));

        if ($font_type === 'variable') {
            return self::build_variable_font_face($font_item, $font_family);
        }

        return self::build_static_font_faces($font_item, $font_family);
    }

    private static function build_variable_font_face(array $font_item, string $font_family): string {
        $files = self::get_font_field_value($font_item, 'font_variable_files', []);
        $files = is_array($files) ? $files : [];
        $src_parts = self::build_font_src_parts($files, ['woff2', 'woff'], [
            'woff2' => 'woff2',
            'woff' => 'woff',
        ]);

        if (empty($src_parts)) {
            return '';
        }

        $weight_range = trim((string)self::get_font_field_value($font_item, 'font_variable_weight_range', ''));
        if ($weight_range === '') {
            $weight_range = '100 900';
        }

        $stretch_range = trim((string)self::get_font_field_value($font_item, 'font_variable_stretch_range', ''));
        $font_style = trim((string)self::get_font_field_value($font_item, 'font_variable_style', 'normal'));
        $font_display = trim((string)self::get_font_field_value($font_item, 'font_variable_display', 'swap'));

        if ($font_style === '') {
            $font_style = 'normal';
        }

        if ($font_display === '') {
            $font_display = 'swap';
        }

        $properties = [
            'font-weight' => $weight_range,
        ];

        if ($stretch_range !== '') {
            $properties['font-stretch'] = $stretch_range;
        }

        $properties['font-style'] = $font_style;
        $properties['font-display'] = $font_display;

        return self::build_font_face_block($font_family, $src_parts, $properties);
    }

    private static function build_static_font_faces(array $font_item, string $font_family): string {
        $variants = self::get_font_field_value($font_item, 'font_variant', []);
        $variants = is_array($variants) ? $variants : [];
        $blocks = [];

        foreach ($variants as $variant_group) {
            if (!is_array($variant_group)) {
                continue;
            }

            foreach ($variant_group as $variant) {
                if (!is_array($variant)) {
                    continue;
                }

                $files = $variant['font_variants']['value'] ?? [];
                $files = is_array($files) ? $files : [];

                $src_parts = self::build_font_src_parts($files, ['woff2', 'woff', 'ttf'], [
                    'woff2' => 'woff2',
                    'woff' => 'woff',
                    'ttf' => 'truetype',
                ]);

                if (empty($src_parts)) {
                    continue;
                }

                $weight = trim((string)self::get_font_field_value($variant, 'font_weight', '400'));
                $style = trim((string)self::get_font_field_value($variant, 'font_style', 'normal'));

                if ($weight === '') {
                    $weight = '400';
                }

                if ($style === '') {
                    $style = 'normal';
                }

                $blocks[] = self::build_font_face_block($font_family, $src_parts, [
                    'font-weight' => $weight,
                    'font-style' => $style,
                    'font-display' => 'swap',
                ]);
            }
        }

        $blocks = array_filter($blocks);

        if (empty($blocks)) {
            return '';
        }

        return implode("\n", $blocks);
    }

    private static function build_font_face_block(string $font_family, array $src_parts, array $properties): string {
        if (empty($src_parts)) {
            return '';
        }

        $lines = [
            '@font-face {',
            '  font-family: "' . $font_family . '";',
            '  src:',
            '    ' . implode(",\n    ", $src_parts) . ';',
        ];

        foreach ($properties as $property => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $lines[] = '  ' . $property . ': ' . $value . ';';
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    private static function build_font_src_parts(array $files, array $order, array $format_map): array {
        $src_parts = [];

        foreach ($order as $extension) {
            if (empty($files[$extension])) {
                continue;
            }

            $url = trim((string)$files[$extension]);
            if ($url === '') {
                continue;
            }

            $format = $format_map[$extension] ?? $extension;
            $src_parts[] = 'url("' . $url . '") format("' . $format . '")';
        }

        return $src_parts;
    }

    private static function get_font_field_value(array $font_item, string $field_name, $default = '') {
        if (!isset($font_item[$field_name]) || !is_array($font_item[$field_name])) {
            return $default;
        }

        if (!array_key_exists('value', $font_item[$field_name])) {
            return $default;
        }

        $value = $font_item[$field_name]['value'];

        return ($value === '' || $value === null) ? $default : $value;
    }

    private static function get_field_storage_metadata(string $page_slug, string $setting_name, string $field_name): array {
        $map = self::get_fields_save_as_map($page_slug);

        if (
            isset($map[$setting_name]) &&
            is_array($map[$setting_name]) &&
            isset($map[$setting_name][$field_name]) &&
            is_array($map[$setting_name][$field_name])
        ) {
            return $map[$setting_name][$field_name];
        }

        return [];
    }

    private static function get_fields_save_as_map(string $page_slug): array {
        static $cache = [];

        if (!isset($cache[$page_slug])) {
            $map = POM_Framework_Disk::read_file('fields_save_as.php', $page_slug, 'array');
            $cache[$page_slug] = is_array($map) ? $map : [];
        }

        return $cache[$page_slug];
    }

    public static function is_setting_enabled($setting_name, $page_slug, array $settings_array = []): bool {
        if (!empty($settings_array)) {
            $enabled_settings = self::get_effective_enabled_settings($page_slug, $settings_array);
        }
        else {
            $enabled_settings = POM_Framework_Disk::read_file('enabled_settings.php', $page_slug, 'array');
            $enabled_settings = is_array($enabled_settings) ? $enabled_settings : [];
        }

        return isset($enabled_settings[$setting_name]) && $enabled_settings[$setting_name] === '1';
    }

    public static function get_effective_enabled_settings(string $page_slug, array $settings_array): array {
        POM_Framework_Disk::create_settings_dir($page_slug);

        $enabled_settings = POM_Framework_Disk::read_file('enabled_settings.php', $page_slug, 'array');
        $enabled_settings = is_array($enabled_settings) ? $enabled_settings : [];
        $definitions = self::flatten_settings_definitions($settings_array);
        $needs_write = false;

        foreach ($definitions as $setting_key => $setting_definition) {
            if (isset($setting_definition['requires_initialization']) && $setting_definition['requires_initialization'] === false) {
                if (!isset($enabled_settings[$setting_key]) || $enabled_settings[$setting_key] !== '1') {
                    $enabled_settings[$setting_key] = '1';
                    $needs_write = true;
                }
            }
        }

        if ($needs_write) {
            ksort($enabled_settings);

            $disk = new POM_Framework_Disk();
            $settings_path = $disk->get_settings_path($page_slug);
            $settings_content = $disk->generate_file_content($enabled_settings, 'Enabled settings array file.');

            POM_Framework_Disk::write_file($settings_path . 'enabled_settings.php', $settings_content, LOCK_EX);

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($settings_path . 'enabled_settings.php', true);
            }
        }

        return $enabled_settings;
    }

    private static function flatten_settings_definitions(array $settings_array): array {
        $settings = [];

        foreach ($settings_array as $tab_key => $tab_definition) {
            if ($tab_key === 'config' || !isset($tab_definition['tab']) || !is_array($tab_definition['tab'])) {
                continue;
            }

            foreach ($tab_definition['tab'] as $subsection_definition) {
                if (!isset($subsection_definition['settings']) || !is_array($subsection_definition['settings'])) {
                    continue;
                }

                foreach ($subsection_definition['settings'] as $setting_key => $setting_definition) {
                    $settings[$setting_key] = $setting_definition;
                }
            }
        }

        return $settings;
    }

    public static function render($page_slug, $settings_file_path): void {
        POM_Framework_Save::save_settings($page_slug, $settings_file_path);

        ?>

        <div id="<?= $page_slug ?>-settings-page" class="wrap">
            
            <h1></h1> <!-- WP core will move notices here -->

            <?php

            //(new self)->render_tabs($page_slug, $settings_file_path);
            do_action('pom_framework_before_render_content', $page_slug, $settings_file_path);
            (new self)->render_content($page_slug, $settings_file_path);

            ?>
        </div>

        <?php
    }

    public static function render_tabs($page_slug, $settings_array = []): void {
        global $pagenow;

        if (empty($page_slug) || empty($settings_array)) {
            return;
        }

        $current_user_role = POM_Framework_Helper::get_current_user_role();
        $current_tab = self::get_current_tab($settings_array);
        $current_subsection = self::get_current_subsection($settings_array);

        ?>

        <div class="pom-framework-settings-nav-heading"><?php // TODO: add class .is-scrolled with js when it is scrolled. ?>

            <h1><?= esc_html($settings_array[$current_tab]['tab'][$current_subsection]['title'] ?? '') ?></h1>

        </div>

        <nav class="nav-tab-wrapper">
            <?php

            foreach ($settings_array as $tab_key => $tab_data) {
                if ($tab_key === 'config') {
                    continue;
                }

                if (isset($tab_data['allowed_roles'])) {
                    if (is_super_admin(get_current_user_id())) {
                        // Allow everything
                    }
                    elseif (!in_array($current_user_role, $tab_data['allowed_roles'], true)) {
                        continue;
                    }
                }

                $active = $tab_key === $current_tab ? ' nav-tab-active' : '';

                ?>

                <a class="nav-tab<?= esc_attr($active) ?>" href="<?= esc_url(admin_url($pagenow . '?page=' . rawurlencode($page_slug) . '&section=' . rawurlencode($tab_key))) ?>">
                    <?= esc_html($tab_data['title']) ?>
                </a>

                <?php
            }

            ?>
        </nav>

        <?php

        if (!self::is_allowed_role($settings_array)) {
            return;
        }

        $tabs = $settings_array[$current_tab]['tab'];
        if (!empty($tabs)) {
            ?>

            <ul class="subsubsub">
                <?php

                foreach ($tabs as $subsection_key => $subsection_data) {
                    $tab_url = add_query_arg(
                        [
                            'page' => $page_slug,
                            'section' => $current_tab,
                            'tab' => $subsection_key,
                        ],
                        get_admin_url() . $pagenow
                    );
                    $current_class = $current_subsection === $subsection_key ? ' class="current"' : '';
                    $next = next($tabs) ? ' | ' : '';

                    ?>

                    <li>
	                        <a href="<?= esc_url($tab_url) ?>"<?= $current_class ?>>
	                            <?= esc_html($subsection_data['title']) ?>
	                        </a>
	                        <?= esc_html($next) ?>
                    </li>

                    <?php
                }

                ?>
            </ul>

            <?php
        }
        ?>



        <?php
    }

    private function render_content($page_slug, $settings_array = []): void {
        $current_tab = self::get_current_tab($settings_array);
        $current_subsection = self::get_current_subsection($settings_array);

        if (!$this->is_allowed_role($settings_array)) {
            (new self)->render_error_page($settings_array);

            return;
        }

        /**
         * A subsection can delegate its whole content to a callable instead of
         * the standard settings form (used by the shared license tab, and
         * available to any custom subsection).
         */
        $render_callback = $settings_array[$current_tab]['tab'][$current_subsection]['render_callback'] ?? null;

        if (is_callable($render_callback)) {
            call_user_func($render_callback, $page_slug, $current_tab, $current_subsection);

            return;
        }

        $action_url = add_query_arg(
            [
                'page' => $page_slug,
                'section' => $current_tab,
                'tab' => $current_subsection,
            ],
            admin_url('options-general.php')
        );







        $description = $settings_array[$current_tab]['tab'][$current_subsection]['description'] ?? '';

        if (!empty($description)) {
            echo '<p>' . wp_kses_post($description) . '</p>';
        }

        do_action('pom_framework_after_description', $current_tab, $current_subsection);

        ?>

        <form method="POST" class="pom-framework-settings-form" action="<?= esc_url($action_url) ?>">
            <?php

            wp_nonce_field('pom_framework_save_settings', 'pom_framework_security_check');

            $settings = POM_Framework_Helper::get_settings($settings_array, $current_tab, $current_subsection);
            $enabled_settings = self::get_effective_enabled_settings($page_slug, $settings_array);

            foreach ($settings as $setting_key => $setting) {

                $wrapper_is_div = false;
                if(!empty($setting['wrapper']) && $setting['wrapper'] === 'div') {
                    $wrapper_is_div = true;
                }

                if ($wrapper_is_div) { ?>
                    <div class="pom-framework-setting">
                <?php }

                if (!empty($setting['img'])) {
                    ?>

	                    <img src="<?= esc_url($setting['img']) ?>"/>

                    <?php
                }
                ?>

                <h2 class="title"><?= esc_html($setting['title'] ?? '') ?></h2>

                <?php

                if (!empty($setting['description'])) {
                    echo '<p>' . wp_kses_post($setting['description']) . '</p>';
                }

                $requires_initialization = isset($setting['requires_initialization']) && $setting['requires_initialization'] === true;

                if ($requires_initialization) {

                    if (!$wrapper_is_div) { ?>
                        <table class="form-table">
                        <tbody>
                        <tr>
                        <th scope="row">
                    <?php } ?>


                            <label class="main-label" for="<?= esc_attr("$setting_key-enabled") ?>">
                                <?php

                                if (!empty($setting['heading_checkbox'])) {
	                                    echo wp_kses_post($setting['heading_checkbox']);
                                } else {
                                    _e('Enable', 'pom-framework');
                                }

                                ?>
                            </label><br>
                    <?php if (!$wrapper_is_div) { ?>
                        </th>
                        <td>
                    <?php } ?>

                            <input type="hidden" name="<?= esc_attr("{$setting_key}_enabled") ?>" value="no">
                            <input name="<?= esc_attr("{$setting_key}_enabled") ?>" id="<?= esc_attr("$setting_key-enabled") ?>" type="checkbox" value="yes" <?= isset($enabled_settings[$setting_key]) && $enabled_settings[$setting_key] === '1' ? 'checked' : '' ?>>
                            <label for="<?= esc_attr("$setting_key-enabled") ?>">
                                <?php

                                if (!empty($setting['label_checkbox'])) {
                                    ?>

	                                    <?= wp_kses_post($setting['label_checkbox']) ?>

                                    <?php
                                } else {
                                    _e('Check to enable this setting.', 'pom-framework');
                                }



                                ?>

                            </label>





                            <?php

                            if (!empty($setting['description_checkbox'])) {
                                ?>

                                <p class="description"><?= wp_kses_post($setting['description_checkbox']) ?></p>

                                <?php
                            }

                            ?>
                <?php if (!$wrapper_is_div) { ?>

                        </td>
                        </tr>

                    </tbody>
                    </table>
                <?php } ?>


                    <?php
                }
                else {
                    ?>
	                    <input type="hidden" name="<?= esc_attr("{$setting_key}_enabled") ?>" value="yes">
                    <?php

                    $has_checkbox_copy = !empty($setting['heading_checkbox']) || !empty($setting['label_checkbox']) || !empty($setting['description_checkbox']);

                    if ($has_checkbox_copy) {
                        if (!$wrapper_is_div) { ?>
                            <table class="form-table">
                            <tbody>
                            <tr>
                            <th scope="row">
                        <?php } ?>

                        <span class="main-label">
                            <?= !empty($setting['heading_checkbox']) ? wp_kses_post($setting['heading_checkbox']) : esc_html__('Enable', 'pom-framework') ?>
                        </span><br>
                        <?php

                        if (!$wrapper_is_div) { ?>
                            </th>
                            <td>
                        <?php } ?>

                        <div class="pom-framework-setting__auto-enabled-text">
	                            <?= !empty($setting['label_checkbox']) ? wp_kses_post($setting['label_checkbox']) : esc_html__('Check to enable this setting.', 'pom-framework') ?>
                        </div>
                        <?php

                        if (!empty($setting['description_checkbox'])) {
                            ?>
                            <p class="description"><?= wp_kses_post($setting['description_checkbox']) ?></p>
                            <?php
                        }

                        if (!$wrapper_is_div) { ?>
                            </td>
                            </tr>
                            </tbody>
                            </table>
                        <?php }
                    }
                }

                if ($wrapper_is_div) { ?>
                    </div>
                <?php }

                if (
                    (!$requires_initialization) ||
                    (isset($enabled_settings[$setting_key]) && $enabled_settings[$setting_key] === '1')
                ) {
                    $settings_dir = (
                            isset($settings_array[$current_tab]['tab']) &&
                            isset($settings_array[$current_tab]['tab'][$current_subsection]['settings_dir']) &&
                            is_dir($settings_array[$current_tab]['tab'][$current_subsection]['settings_dir'])
	                    ) ? $settings_array[$current_tab]['tab'][$current_subsection]['settings_dir'] : '';

                    if (empty($settings_dir)) {
                        $settings_dir = isset($settings_array[$current_tab]['settings_dir']) && is_dir($settings_array[$current_tab]['settings_dir']) ? $settings_array[$current_tab]['settings_dir'] : $settings_array['config']['settings_dir'];
                    } 

                    $fields = self::read_fields($settings_dir, $setting_key);

                    if(count($fields) > 0) { ?>

                    <table class="form-table">
                    <tbody>
                    <?php

                    }



                    foreach ($fields as $field) {
                        if ($field['type'] === 'Separator') {

                            ?>

                            </tbody>
                            </table>

                            <?php

                            echo (new POM_Framework())::add_field($field);

                            ?>

                            <table class="form-table">
                            <tbody>
                            <?php

                            continue;
                        }

                        $data_dependencies = POM_Framework_Helper::get_dependencies_data_attr($field);

                        ?>

                        <tr<?= $data_dependencies ?>>
                            <th scope="row">
	                                <label for="<?= esc_attr($field['name'] ?? '') ?>">
	                                    <?= wp_kses_post($field['label'] ?? '') ?>
	                                </label>
                            </th>
                            <td>
                                <?php

                                $description = $field['description'] ?? '';
                                unset($field['label'], $field['description']);

                                $original_field_name = $field['name'];
                                $field_should_persist = POM_Framework_Helper::field_should_persist($field);
                                $value = $field_should_persist ? self::get_setting_value($page_slug, $setting_key, $original_field_name) : ($field['value'] ?? '');
                                $storage_metadata = $field_should_persist ? self::get_field_storage_metadata($page_slug, $setting_key, $original_field_name) : [];
                                $field['name'] = $setting_key . '_' . $original_field_name;

                                if ($field['type'] === 'checkbox' && isset($field['value']) && $field['value'] === true) {
                                    $field['value'] = 'yes';
                                }
                                elseif ($field['type'] === 'code_html' || $field['type'] === 'code_css' || $field['type'] === 'code_js') {
                                    $source_value = $value;

                                    if ($field_should_persist && empty($storage_metadata)) {
                                        $source_value = POM_Framework_Disk::read_file($field['name'] . '.' . str_replace('code_', '', $field['type']), $page_slug);
                                    }

                                    $sanitize_function_name = "sanitize_pom_framework_{$field['type']}";
                                    $field['value'] = function_exists($sanitize_function_name) ? $sanitize_function_name($source_value) : $source_value;
                                }
                                else {
                                    $sanitize_function_name = "sanitize_pom_framework_{$field['type']}";
                                    $field['value'] = function_exists($sanitize_function_name) ? $sanitize_function_name($value) : $value;
                                }

                                echo (new POM_Framework())::add_field($field);

                                ?>
                                <p class="description" id="<?= esc_attr($field['name']) ?>"><?= wp_kses_post($description) ?></p>
                            </td>
                        </tr>

                        <?php
                    }

                    if(count($fields) > 0) { ?>

                    </table>
                    </tbody>
                    <?php

                    }
                }

                 ?>

                


                <?php if (!$wrapper_is_div) { ?>

                    <hr>

                <?php }

            }

            ?>

            <input type="hidden" name="save_pom_framework_fields" value="1">

            <?php submit_button(__('Save', 'pom-framework')) ?>
        </form>

        <?php
    }

    public static function is_allowed_role($settings_array): bool {
        $current_tab = self::get_current_tab($settings_array);
        $current_user_role = POM_Framework_Helper::get_current_user_role();
        $allowed_roles = $settings_array[$current_tab]['allowed_roles'] ?? [];
        $is_super_admin = is_super_admin(get_current_user_id());

        if (!$is_super_admin && !in_array($current_user_role, $allowed_roles, true)) {
            return false;
        }

        return true;
    }

    private function render_error_page($settings_array): void {
        if (!$this->is_allowed_role($settings_array)) {
            echo '<h2>' . esc_html__('You do not have access to this page', 'pom-framework') . '</h2>';
        }
    }

}
