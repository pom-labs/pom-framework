<?php

namespace POMFramework\Fields;

use POMFramework\POM_Framework_Disk;
use POMFramework\POM_Framework_Helper;

class Tinymce {

    public static function render_field(array $args): void {
        echo '<div class="form-group">';

        if (!empty($args['label'])) {
            echo '<label for="' . $args['id'] . '">' . $args['label'] . '</label>';
        }

        if (!empty($args['description']) && $args['description_position'] === 'below_label') {
            echo '<small class="description form-text text-muted">' . $args['description'] . '</small>';
        }

        $custom_attrs = [];

        if (!empty($args['custom_attrs'])) {
            $custom_attrs = POM_Framework_Helper::convert_html_attributes_to_array($args['custom_attrs']);
        }

        $value = '';
        if (!empty($args['value_is_content'])) {
            $value = sanitize_pom_framework_tinymce($args['value'] ?? '');
        }
        elseif (($managed_contents = POM_Framework_Disk::read_managed_file_path($args['value'] ?? '')) !== null) {
            $value = $managed_contents;
        }
        elseif (!empty($args['default'])) {
            $value = $args['default'];
        }

        $tinymce_args = [
            'textarea_rows' => $custom_attrs['textarea_rows'] ?? 10,
            'teeny' => $custom_attrs['teeny'] ?? false,
            'quicktags' => $custom_attrs['quicktags'] ?? false,
            'wpautop' => $custom_attrs['wpautop'] ?? true,
            'media_buttons' => $custom_attrs['media_buttons'] ?? false
        ];
        wp_editor($value, $args['id'], $tinymce_args);

        if (!empty($args['description']) && $args['description_position'] === 'under_field') {
            echo '<small class="description form-text text-muted">' . $args['description'] . '</small>';
        }

        echo '</div>';
    }

}
