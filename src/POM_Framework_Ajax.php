<?php

namespace POMFramework;

class POM_Framework_Ajax {
    public const AJAX_NONCE_ACTION = 'pom_framework_ajax';

    /**
     * Sign an exact repeater payload rendered by the server.
     *
     * Why: a nonce proves request origin but does not make browser-supplied field
     * definitions trustworthy. The signature prevents editors from replacing a
     * legitimate repeater schema with fields that read arbitrary server files.
     */
    public static function sign_repeater_payload(string ...$parts): string {
        return hash_hmac('sha256', implode("\0", $parts), wp_salt('auth'));
    }

    public static function verify_repeater_payload_signature(string $signature, string ...$parts): bool {
        return $signature !== '' && hash_equals(self::sign_repeater_payload(...$parts), $signature);
    }

    public function __construct() {
        add_action('wp_ajax_pom_framework_get_icon_library_icons', [$this, 'get_library_icons']);
        add_action('wp_ajax_pom_framework_get_icon_by_name', [$this, 'get_icon_by_name']);
        add_action('wp_ajax_pom_framework_get_repeater_item_html', [$this, 'get_repeater_item_html']);
        add_action('wp_ajax_pom_framework_restore_repeater_defaults', [$this, 'restore_repeater_defaults']);
    }

    public function get_library_icons(): void {
        $this->verify_ajax_request();

        $library = isset($_REQUEST['library']) ? sanitize_key(wp_unslash($_REQUEST['library'])) : 'all';
        $limit = 55;
        $current_offset = isset($_REQUEST['offset']) ? absint(wp_unslash($_REQUEST['offset'])) : 0;

        if (empty($library)) {
            wp_die('<span class="centered-text">' . esc_html__('Choose a library from the menu to see its icons or do a global search on all icon libraries.', 'pom-framework') . '</span>');
        }


        $icons = POM_Framework_Helper::get_icon_libraries();
        if ($library !== 'all' && !isset($icons[$library])) {
            wp_die('<span class="centered-text">' . esc_html__('No icons found that match the search criteria.', 'pom-framework') . '</span>');
        }

        $glob = [];

        ob_start();

        echo '<ul class="attachments">';

        if ($library === 'all') {
            foreach ($icons as $library_index => $data) {
                $icons_array = glob("{$data['path']}$library_index/*.svg");
                $icons_array = is_array($icons_array) ? $icons_array : [];
                $glob = array_merge($glob, $icons_array);

                /**
                 * As long as there is no offset, it is not necessary to load all the icons.
                 * Only the first until reaching the limit.
                 */
                if ($current_offset === 0 && count($glob) > $limit) {
                    break;
                }
            }

            if (!empty($glob)) {
                foreach (array_slice($glob, $current_offset, $limit) as $file) {
                    echo $this->get_icon_attachment_html($file);
                }
            }
        }
        else {
            $glob = glob("{$icons[$library]['path']}$library/*.svg");
            $glob = is_array($glob) ? $glob : [];
            if (!empty($glob)) {
                foreach (array_slice($glob, $current_offset, $limit) as $file) {
                    echo $this->get_icon_attachment_html($file);
                }
            }
        }

        echo '</ul>';

        $glob_count = count($glob);
        if ($glob_count > ($current_offset + $limit)) {
            ?>

            <div class="load-more-icons">
                <button class="button button-secondary" data-total="<?= esc_attr($glob_count) ?>" data-offset="<?= esc_attr($current_offset) ?>"><?php esc_html_e('Load more', 'pom-framework') ?></button>
                <img class="icon-picker-spinner" src="<?= esc_url(admin_url('images/loading.gif')) ?>" style="display: none; padding-top: 7px;" alt="Spinner">
            </div>

            <?php
        }

        wp_die(ob_get_clean());
    }

    public function get_icon_by_name(): void {
        $this->verify_ajax_request();

        $search = isset($_REQUEST['search']) ? $this->sanitize_icon_search(wp_unslash($_REQUEST['search'])) : '';
        $library = isset($_REQUEST['library']) ? sanitize_key(wp_unslash($_REQUEST['library'])) : 'all';

        /**
         * If the search is empty all icons are returned.
         */
        if (empty($search)) {
            $this->get_library_icons();
        }

        $icons = POM_Framework_Helper::get_icon_libraries();
        if ($library !== 'all' && !isset($icons[$library])) {
            wp_die('<span class="centered-text">' . esc_html__('No icons found that match the search criteria.', 'pom-framework') . '</span>');
        }

        $found_files = 0;

        ob_start();

        echo '<ul class="attachments">';

        if ($library === 'all') {
            foreach ($icons as $icon_library => $data) {
                $files = glob("{$data['path']}$icon_library/*$search*.svg");
                $files = is_array($files) ? $files : [];
                foreach ($files as $file) {
                    echo $this->get_icon_attachment_html($file);
                    $found_files++;
                }
            }
        }
        else {
            $library_data = $icons[$library];
            $files = glob("{$library_data['path']}$library/*$search*.svg");
            $files = is_array($files) ? $files : [];
            foreach ($files as $file) {
                echo $this->get_icon_attachment_html($file);
                $found_files++;
            }
        }

        echo '</ul>';

        if ($found_files === 0) {
            ob_get_clean();
            wp_die('<span class="centered-text">' . esc_html__('No icons found that match the search criteria.', 'pom-framework') . '</span>');
        }

        wp_die(ob_get_clean());
    }

    private function get_icon_attachment_html($file) {
        ob_start();

        ?>

        <li class="attachment" style="width: 9%;">
            <div class="attachment-preview landscape">
                <div class="thumbnail">
                    <div class="centered">
                        <img alt="" src="<?= esc_url(POM_Framework_Helper::path_to_url($file)) ?>">
                        <span class="icon-name"><?= esc_html(pathinfo($file, PATHINFO_FILENAME)) ?></span>
                    </div>
                </div>
            </div>
        </li>

        <?php

        return ob_get_clean();
    }

    public function get_repeater_item_html(): void {
        $this->verify_ajax_request();

        $config = isset($_REQUEST['config']) && is_scalar($_REQUEST['config']) ? sanitize_text_field(wp_unslash($_REQUEST['config'])) : '';
        $signature = isset($_REQUEST['config_signature']) && is_scalar($_REQUEST['config_signature']) ? sanitize_text_field(wp_unslash($_REQUEST['config_signature'])) : '';
        $items = isset($_REQUEST['items']) ? absint(wp_unslash($_REQUEST['items'])) : 0;

        if ($config === '' || !self::verify_repeater_payload_signature($signature, $config)) {
            wp_die('', '', ['response' => 403]);
        }

        $decoded_config = base64_decode($config, true);
        $config = is_string($decoded_config) ? json_decode($decoded_config, true) : null;

        if (!is_array($config) || empty($config['fields']) || !is_array($config['fields'])) {
            wp_die('', '', ['response' => 400]);
        }

        // Avoid a new item if the limit has been reached.
        if (!empty($items) && !empty($config['limit']) && $items >= (int)$config['limit']) {
            wp_die();
        }

        ob_start();

        ?>

        <?php $repeater_identifier = POM_Framework_Helper::generate_random_string(10, false); ?>

            <div class="repeater new">
            <div class="title"><strong><?= wp_kses_post($config['title'] ?? '') ?></strong><span></span><span class="repeater-identifier"> - ID: <?= esc_html($repeater_identifier) ?></span></div>
            <div class="repeater-fields">
                <input type="hidden" name="repeater_identifier" value="<?= esc_attr($repeater_identifier) ?>">

                <?php

                foreach ($config['fields'] as $field) {
                    echo (new POM_Framework())::add_field($field);
                }

                ?>

                <div class="repeater-action-row">

                    <?php

                    if (isset($config['cloneable']) && $config['cloneable'] === true) {
                        ?>

                        <span class="clone-repeater"><?php esc_html_e('Clone', 'pom-framework') ?></span>

                        <?php
                    }

                    ?>

                    <span class="delete"><?php esc_html_e('Delete', 'pom-framework') ?></span>
                </div>
            </div>
        </div>

        <?php

        wp_die(ob_get_clean());
    }

    public function restore_repeater_defaults(): void {
        $this->verify_ajax_request();

        $defaults = isset($_REQUEST['defaults']) && is_scalar($_REQUEST['defaults']) ? sanitize_text_field(wp_unslash($_REQUEST['defaults'])) : '';
        $fields = isset($_REQUEST['fields']) && is_scalar($_REQUEST['fields']) ? sanitize_text_field(wp_unslash($_REQUEST['fields'])) : '';
        $raw_title = isset($_REQUEST['title']) && is_scalar($_REQUEST['title']) ? (string) wp_unslash($_REQUEST['title']) : '';
        $title = wp_kses_post($raw_title);
        $signature = isset($_REQUEST['payload_signature']) && is_scalar($_REQUEST['payload_signature']) ? sanitize_text_field(wp_unslash($_REQUEST['payload_signature'])) : '';

        if ($defaults === '' || !self::verify_repeater_payload_signature($signature, $defaults, $fields, $raw_title)) {
            wp_die('', '', ['response' => 403]);
        }

        ob_start();

        $decoded_defaults = base64_decode($defaults, true);
        $decoded_fields = base64_decode($fields, true);
        $defaults = is_string($decoded_defaults) ? json_decode($decoded_defaults, true) : null;
        $fields = is_string($decoded_fields) ? json_decode($decoded_fields, true) : null;

        if (!is_array($defaults) || !is_array($fields)) {
            wp_die('', '', ['response' => 400]);
        }

        foreach ($defaults as $default) {
            $default_json = (string) wp_json_encode($default);
            $defaults_identifier = POM_Framework_Helper::generate_random_string(10, false);

            ?>

            <div class="repeater default closed">
                <div class="title">
                    <strong><?= $title ?></strong><span></span><span class="repeater-identifier"> - ID: <?= esc_html($defaults_identifier) ?></span>
                </div>
                <div class="repeater-fields">
                    <input type="hidden" name="repeater_identifier" value="<?= esc_attr($defaults_identifier) ?>">
                    <input type="hidden" name="default_values" value="<?= esc_attr($default_json) ?>">

                    <?php

                    foreach ($fields as $field) {
                        $field['value'] = $default[$field['name']]['value'] ?? '';
                        $field['disabled'] = $default[$field['name']]['disabled'] ?? false;
                        echo (new POM_Framework())::add_field($field);
                    }

                    ?>

                    <div class="repeater-action-row">
                        <span class="restore-default"><?php esc_html_e('Restore default', 'pom-framework') ?></span>

                        <?php

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

        wp_die(ob_get_clean());
    }

    private function verify_ajax_request(): void {
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';

        if (!wp_verify_nonce($nonce, self::AJAX_NONCE_ACTION)) {
            wp_die('', '', ['response' => 403]);
        }

        $capability = (string) apply_filters('pom_framework_ajax_capability', 'edit_posts');

        if ($capability !== '' && !current_user_can($capability)) {
            wp_die('', '', ['response' => 403]);
        }
    }

    private function sanitize_icon_search($search): string {
        $search = sanitize_text_field((string) $search);

        return preg_replace('/[^A-Za-z0-9_-]/', '', $search) ?: '';
    }

}
new POM_Framework_Ajax();
