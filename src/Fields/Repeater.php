<?php
/**
 * New repeater elements are added via ajax.
 * If changes are made to the HTML of a repeater element,
 * update it in POM_Framework_Ajax.php as well --> get_repeater_item_html().
 */

namespace POMFramework\Fields;

use POMFramework\POM_Framework_Helper;
use POMFramework\POM_Framework;
use POMFramework\POM_Framework_Ajax;
use POMFramework\POM_Framework_Disk;

class Repeater {

    public static function render_field(array $args): void {
        echo '<div class="form-group">';

        if (!empty($args['label'])) {
            echo '<label for="' . esc_attr($args['id']) . '">' . wp_kses_post($args['label']) . '</label>';
        }

        if (!empty($args['description']) && $args['description_position'] === 'below_label') {
            echo '<small class="description">' . wp_kses_post($args['description']) . '</small>';
        }

        $repeater_config = [
            'title' => $args['title'] ?? '',
            'fields' => $args['fields'] ?? []
        ];

        if (isset($args['limit'])) {
            $repeater_config['limit'] = $args['limit'];
        }

        if (isset($args['cloneable'])) {
            $repeater_config['cloneable'] = $args['cloneable'];
        }

        $repeater_config = base64_encode((string) wp_json_encode($repeater_config));
        $repeater_config_signature = POM_Framework_Ajax::sign_repeater_payload($repeater_config);

        //$json = json_decode(htmlspecialchars_decode($args['value']), true);
        $json = $args['value'];

        $sortable = isset($args['sortable']) && $args['sortable'] === true ? ' sortable' : '';

        $repeater_identifier = POM_Framework_Helper::generate_random_string(10, false);

        $limit = isset($args['limit']) ? ' data-limit="' . (int)$args['limit'] . '"' : '';
        $data_dependencies = POM_Framework_Helper::get_dependencies_data_attr($args);

        ?>

        <div class="repeater-wrapper<?= $sortable ?>"<?= $limit ?><?= $data_dependencies ?>>
            <div class="repeater-bulk-actions">
                <button type="button" class="button button-secondary open-all-repeaters"><?php esc_html_e('Open all', 'pom-framework') ?></button>
                <button type="button" class="button button-secondary close-all-repeaters"><?php esc_html_e('Close all', 'pom-framework') ?></button>
            </div>

            <?php

            // Render defaults if set
            if (empty($json['default']) && !empty($args['defaults'])) {
                $defaults_identifier = POM_Framework_Helper::generate_random_string(10, false);

                foreach ($args['defaults'] as $default) {
                    $default_json = (string) wp_json_encode($default);

                    ?>

                    <div class="repeater default closed">
                        <div class="title">
	                            <strong><?= wp_kses_post($args['title']) ?></strong><span></span><span class="repeater-identifier"> - ID: <?= esc_html($defaults_identifier) ?></span>
	                        </div>
	                        <div class="repeater-fields">
	                            <input type="hidden" name="repeater_identifier" value="<?= esc_attr($defaults_identifier) ?>">
	                            <input type="hidden" name="default_values" value="<?= esc_attr($default_json) ?>">

                            <?php

                            foreach ($args['fields'] as $field) {
                                $field['value'] = $default[$field['name']]['value'] ?? '';
                                $field['disabled'] = $default[$field['name']]['disabled'] ?? false;
                                echo (new POM_Framework())::add_field($field);
                            }

                            ?>

                            <div class="repeater-action-row">
	                                <span class="restore-default"><?php esc_html_e('Restore default', 'pom-framework') ?></span>

                                <?php

                                if (isset($args['cloneable']) && $args['cloneable'] === true) {
                                    ?>

	                                    <span class="clone-repeater"><?php esc_html_e('Clone', 'pom-framework') ?></span>

                                    <?php
                                }

                                if (isset($default['can_be_removed']) && $default['can_be_removed']) {
                                    ?>

	                            <span class="delete"><?php esc_html_e('Delete', 'pom-framework') ?></span>

                                    <?php
                                }

                                ?>

                            </div>
                        </div>
                    </div>

                    <?php
                }
            }

            // Render the saved values
            if (!empty($json)) {
                /**
                 * If it is an inner repeater the value arrives as JSON
                 */
                if (is_string($json)) {
                    $json = str_replace('&quot;', '"', $json);
                    $json = json_decode($json, true);
                }

                foreach ($json as $repeater_type => $repeater_elements) {
                    if (!empty($repeater_elements) && count($repeater_elements) > 0) {
                        foreach ($repeater_elements as $repeater_item) {
                            ?>

                            <?php $item_identifier = $repeater_item['repeater_identifier'] ?? $repeater_identifier; ?>

	                            <div class="repeater <?= esc_attr(sanitize_html_class($repeater_type)) ?> closed">
	                                <div class="title"><strong><?= wp_kses_post($args['title']) ?></strong><span></span><span class="repeater-identifier"> - ID: <?= esc_html($item_identifier) ?></span></div>
	                                <div class="repeater-fields">
	                                    <input type="hidden" name="repeater_identifier" value="<?= esc_attr($item_identifier) ?>">

                                    <?php

                                    if ($repeater_type === 'default') {
                                        ?>

	                                        <input type="hidden" name="default_values" value="<?= esc_attr((string) wp_json_encode($repeater_item['default_values'])) ?>">

                                        <?php
                                    }

                                    foreach ($args['fields'] as $field) {
                                        if (isset($field['name']) && array_key_exists($field['name'], $repeater_item)) {
                                            if ($field['type'] === 'repeater') {
                                                $field['value'] = htmlspecialchars(json_encode($repeater_item[$field['name']]['value']), ENT_QUOTES, 'UTF-8');
                                            }
                                            else {
                                                if (is_array($repeater_item[$field['name']]['value'])) {
                                                    $field_type = strtolower($field['type']);

                                                    // Complex field types such as TRBL and multi-checkbox fields expect arrays to remain prefilled.
                                                    if ($field_type === 'trbl' || $field_type === 'checkbox') {
                                                        $field['value'] = $repeater_item[$field['name']]['value'];
                                                    }
                                                    else {
                                                        $field['value'] = htmlspecialchars(json_encode($repeater_item[$field['name']]['value']), ENT_QUOTES, 'UTF-8');
                                                    }
                                                }
                                                else {
                                                    $field['value'] = html_entity_decode(htmlspecialchars($repeater_item[$field['name']]['value'], ENT_QUOTES, 'UTF-8'), ENT_HTML5);
                                                }
                                            }
                                        }

                                        if ($repeater_type === 'default' && (isset($repeater_item['default_values'][$field['name']]['disabled']) && $repeater_item['default_values'][$field['name']]['disabled'])) {
                                            $field['disabled'] = true;
                                        }

                                        echo (new POM_Framework())::add_field($field);
                                    }

                                    echo '<div class="repeater-action-row">';

                                    if ($repeater_type === 'default' && !empty($repeater_item['default_values'])) {
                                        ?>

	                                        <span class="restore-default"><?php esc_html_e('Restore default', 'pom-framework') ?></span>

                                        <?php
                                    }

                                    if (isset($args['cloneable']) && $args['cloneable'] === true) {
                                        ?>

	                                        <span class="clone-repeater"><?php esc_html_e('Clone', 'pom-framework') ?></span>

                                        <?php
                                    }

                                    if ($repeater_type === 'default' && isset($repeater_item['default_values']['can_be_removed']) && $repeater_item['default_values']['can_be_removed']) {
                                        ?>

	                                        <span class="delete"><?php esc_html_e('Delete', 'pom-framework') ?></span>

                                        <?php
                                    }
                                    elseif ($repeater_type === 'new') {
                                        ?>

	                                        <span class="delete"><?php esc_html_e('Delete', 'pom-framework') ?></span>

                                        <?php
                                    }

                                    echo '</div>';

                                    ?>
                                </div>
                            </div>

                            <?php
                        }
                    }
                }
            }

            if (!isset($args['disable_new']) || $args['disable_new'] !== true) {
                ?>

	                <button class="button add-new-repeater-item"><?php esc_html_e('Add new', 'pom-framework') ?></button>
	                <img class="repeater-spinner" src="<?= esc_url(admin_url('images/loading.gif')) ?>" alt="Spinner">

                <?php
            }

            if (!empty($args['defaults'])) {
                $defaults_payload = base64_encode((string) wp_json_encode($args['defaults']));
                $fields_payload = base64_encode((string) wp_json_encode($args['fields']));
                $restore_title = (string) ($args['title'] ?? '');
                $restore_signature = POM_Framework_Ajax::sign_repeater_payload($defaults_payload, $fields_payload, $restore_title);
                ?>

	                <button class="button button-secondary right restore-repeater-defaults" data-title="<?= esc_attr($restore_title) ?>" data-fields="<?= esc_attr($fields_payload) ?>" data-defaults="<?= esc_attr($defaults_payload) ?>" data-payload-signature="<?= esc_attr($restore_signature) ?>"><?php esc_html_e('Restore defaults', 'pom-framework') ?></button>

                <?php
            }

            ?>

	            <input type="hidden" name="config" value="<?= esc_attr($repeater_config) ?>">
	            <input type="hidden" name="config_signature" value="<?= esc_attr($repeater_config_signature) ?>">

            <?php

            /**
             * Update hidden input value path to value.
             *
             * By default, when using a Codemirror in a repeater,
             * the value of the input is the path to the file that is generated.
             *
             * With this loop we replace the path with the real value (content of the field).
             */
            if (!empty($args['value'])) {
                /**
                 * If it is an inner repeater the value arrives as JSON.
                 */
                if (is_string($args['value'])) {
                    $args['value'] = str_replace('&quot;', '"', $args['value']);
                    $args['value'] = json_decode($args['value'], true);
                }

                foreach ($args['value'] as $repeater) {
                    foreach ($repeater as $repeater_key => $repeater_value) {
                        foreach ($repeater_value as $repeater_item_key => $repeater_item_value) {
                            if (is_array($repeater_item_value)) {
                                foreach ($repeater_item_value as $repeater_item_arr_key => $repeater_item_arr_value) {
                                    if ($repeater_item_arr_key === 'type') {
                                        if ($repeater_item_arr_value === 'code_html' || $repeater_item_arr_value === 'code_css' || $repeater_item_arr_value === 'code_js' || $repeater_item_arr_value === 'code_json') {
                                            $managed_contents = POM_Framework_Disk::read_managed_file_path($repeater_item_value['value'] ?? '');
                                            if ($managed_contents !== null) {
	                                            $args['value']['new'][$repeater_key][$repeater_item_key]['value'] = $managed_contents;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            ?>

	            <input type="hidden" name="<?= esc_attr($args['name']) ?>" value="<?= esc_attr((string) wp_json_encode($args['value'])) ?>" class="repeater-value" data-type="repeater">
        </div>

        <?php

        if (!empty($args['description']) && $args['description_position'] === 'under_field') {
            echo '<small class="description">' . wp_kses_post($args['description']) . '</small>';
        }

        echo '</div>';

        wp_enqueue_style('pom-framework-repeater', POM_FRAMEWORK_SRC_URI . '/dist/css/repeater.min.css');
        wp_enqueue_script(
            'pom-framework-repeater',
            POM_FRAMEWORK_SRC_URI . '/dist/js/repeater' . POM_FRAMEWORK_MIN . '.js',
            ['jquery', 'jquery-ui-sortable', 'pom-framework-dependencies'],
            null,
            true
        );
        wp_localize_script(
            'pom-framework-repeater',
            'pom_framework_repeater',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
                'limit' => __('Element limit reached', 'pom-framework'),
                'restore_msg' => __('Are you sure you want to reset the repeaters?', 'pom-framework'),
                'delete_repeater' => __('Are you sure you want to delete this repeater?', 'pom-framework'),
                'nonce' => wp_create_nonce(POM_Framework_Ajax::AJAX_NONCE_ACTION),
            ]
        );
    }

}
