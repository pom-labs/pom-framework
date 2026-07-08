<?php

namespace POMFramework\Fields;

use POMFramework\POM_Framework_Helper;

class Code_CSS {

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
        if (file_exists($args['value'])) {
            $value = file_get_contents($args['value']);
        }
        elseif (!empty($args['default'])) {
            $value = $args['default'];
        }

        ?>

        <textarea aria-label="<?= $args['label'] ?>" id="<?= $args['id'] ?>" name="<?= $args['name'] ?>" class="form-control pom-framework-code-editor-css <?= $args['class'] ?>"<?= $data_dependencies ?> data-type="code_css"><?= $value ?></textarea>

        <?php

        if (!empty($args['description']) && $args['description_position'] === 'under_field') {
            echo '<small class="description form-text text-muted">' . $args['description'] . '</small>';
        }

        echo '</div>';

        POM_Framework_Helper::enqueue_code_editor_assets();
    }

}
