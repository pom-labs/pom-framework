<?php

namespace POMFramework\Fields;

use POMFramework\POM_Framework_Helper;

class Text {

    public static function render_field(array $args): void {
        $disabled = isset($args['disabled']) && $args['disabled'] === true ? ' disabled' : '';
        $data_dependencies = POM_Framework_Helper::get_dependencies_data_attr($args);

        echo '<div class="form-group">';

            if (!empty($args['label'])) {
                echo '<label for="' . $args['id'] . '">' . $args['label'] . '</label><br>';
            }

            if (!empty($args['description']) && $args['description_position'] === 'below_label') {
                echo '<small class="description form-text text-muted">' . $args['description'] . '</small>';
            }

            // Repeater integration
            $used_for_title = !empty($args['used_for_title']) ? ' use-for-title' : '';

            $value = '';
            if (isset($args['value']) && $args['value'] !== '') {
                $value = $args['value'];
            }
            elseif (!empty($args['default'])) {
                $value = $args['default'];
            }

            ?>

            <input aria-label="<?= esc_attr($args['label'] ?? '') ?>" placeholder="<?= esc_attr($args['placeholder'] ?? '') ?>" type="text" id="<?= esc_attr($args['id']) ?>" name="<?= esc_attr($args['name']) ?>" value="<?= esc_attr($value) ?>" class="form-control<?= esc_attr($used_for_title) ?> <?= esc_attr($args['class'] ?? '') ?>"<?= $data_dependencies ?> data-type="text"<?= $disabled ?>>

            <?php

            if (!empty($args['description']) && $args['description_position'] === 'under_field') {
                echo '<small class="description form-text text-muted">' . $args['description'] . '</small>';
            }

        echo '</div>';
    }

}
