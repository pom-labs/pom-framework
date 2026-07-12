<?php

namespace POMFramework\Fields;

use POMFramework\POM_Framework_Disk;
use POMFramework\POM_Framework_Helper;

class Code_HTML {

    public static function render_field(array $args): void {
        $data_dependencies = POM_Framework_Helper::get_dependencies_data_attr($args);

        echo '<div class="form-group">';

        if (!empty($args['label'])) {
            echo '<label for="' . $args['id'] . '">' . $args['label'] . '</label><br>';
        }

        if (!empty($args['description']) && $args['description_position'] === 'below_label') {
            echo '<small class="description form-text text-muted">' . $args['description'] . '</small>';
        }

        $value = '';
        if (!empty($args['value_is_content'])) {
            $value = sanitize_pom_framework_code_html($args['value'] ?? '');
        }
        elseif (($managed_contents = POM_Framework_Disk::read_managed_file_path($args['value'] ?? '')) !== null) {
            $value = $managed_contents;
        }
        elseif (!empty($args['default'])) {
            $value = $args['default'];
        }

        ?>

        <textarea aria-label="<?= esc_attr($args['label']) ?>" id="<?= esc_attr(POM_Framework_Helper::generate_random_string(10, false)) ?>" name="<?= esc_attr($args['name']) ?>" class="form-control pom-framework-code-editor-html <?= esc_attr($args['class']) ?>"<?= $data_dependencies ?> data-type="code_html"><?= esc_textarea($value) ?></textarea>

        <?php

        if (!empty($args['description']) && $args['description_position'] === 'under_field') {
            echo '<small class="description form-text text-muted">' . $args['description'] . '</small>';
        }

        echo '</div>';

        POM_Framework_Helper::enqueue_code_editor_assets();
    }

}
