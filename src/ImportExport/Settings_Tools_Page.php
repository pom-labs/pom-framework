<?php

namespace POMFramework\ImportExport;

use RuntimeException;

class Settings_Tools_Page {
    private const FLASH_OPTION = 'pom_settings_tools_flash_messages';
    private static array $bootstrapped_pages = [];

    private string $page_slug;
    private Settings_Exporter $exporter;
    private Settings_Importer $importer;
    private array $messages = [];

    public function __construct(string $page_slug, ?array $allowed_files = null) {
        $this->page_slug = $page_slug;
        $this->exporter = new Settings_Exporter($page_slug, $allowed_files);
        $this->importer = new Settings_Importer($page_slug, $allowed_files);
    }

    public static function enqueue_assets(): void {
        wp_register_style('pom-settings-tools', false, [], null);
        wp_enqueue_style('pom-settings-tools');

        $css = '.pom-settings-tools-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;}'
            . '.pom-settings-tools-grid .card{background:#fff;border:1px solid #c3c4c7;padding:16px;box-shadow:0 1px 1px rgba(0,0,0,0.04);}'
            . '.pom-settings-tools-grid h2{margin-top:0;}'
            . '.pom-settings-tools-grid .actions{margin-top:16px;}'
            . '.pom-settings-tools-grid ul{max-height:320px;overflow:auto;padding:0 0 0 18px;}'
            . '.pom-settings-tools-grid .notice-list{margin-top:12px;}';

        wp_add_inline_style('pom-settings-tools', $css);
    }

    public static function bootstrap(string $page_slug, ?array $allowed_files = null): void {
        if (isset(self::$bootstrapped_pages[$page_slug])) {
            return;
        }

        add_action('admin_init', function () use ($page_slug, $allowed_files) {
            if (empty($_POST['pom_settings_tools_action']) || $_POST['pom_settings_tools_action'] !== 'export_settings') {
                return;
            }

            $posted_page = isset($_POST['pom_settings_tools_page']) ? sanitize_text_field(wp_unslash($_POST['pom_settings_tools_page'])) : '';

            if ($posted_page !== $page_slug) {
                return;
            }

            $instance = new self($page_slug, $allowed_files);
            $instance->handle_export(true);
        });

        self::$bootstrapped_pages[$page_slug] = true;
    }

    public function render(): void {
        if (!self::current_user_can_use_tools($this->page_slug)) {
            wp_die(__('You do not have permission to access this page.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }

        $this->messages = array_merge($this->messages, self::consume_flash_messages());
        $this->handle_actions();

        $available_settings = $this->exporter->get_available_settings();
        $preview = $this->importer->get_preview();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Settings transfer', 'pom') . '</h1>'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        echo '<p>' . esc_html__('Only the listed POM Framework settings files and their managed assets are transferred.', 'pom') . '</p>'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        $this->render_messages();
        echo '<div class="pom-settings-tools-grid">';
        $this->render_export_card($available_settings);
        $this->render_import_card($preview);
        echo '</div>';
        echo '</div>';
    }

    private function handle_actions(): void {
        if (!empty($_POST['pom_settings_tools_action'])) {
            $action = sanitize_text_field(wp_unslash($_POST['pom_settings_tools_action']));

            if ($action === 'prepare_import') {
                $this->handle_import_preview();
            }

            if ($action === 'perform_import') {
                $this->handle_import_execution();
            }
        }
    }

    private function handle_export(bool $stream_immediately = false): void {
        check_admin_referer('pom_settings_tools_export', 'pom_settings_tools_nonce');

        if (!self::current_user_can_use_tools($this->page_slug)) {
            wp_die(__('You do not have permission to access this page.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }

        $selected = isset($_POST['settings']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['settings'])) : [];

        try {
            $result = $this->exporter->export($selected);

            if ($stream_immediately) {
                $this->stream_zip($result['path'], $result['name']);
                return;
            }

            $this->messages[] = ['type' => 'success', 'text' => __('Export completed.', 'pom')]; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }
        catch (RuntimeException $exception) {
            if ($stream_immediately) {
                self::flash_message(['type' => 'error', 'text' => $exception->getMessage()]);
                $this->redirect_back();
            }

            $this->messages[] = ['type' => 'error', 'text' => $exception->getMessage()];
        }
    }

    private function handle_import_preview(): void {
        check_admin_referer('pom_settings_tools_import', 'pom_settings_tools_nonce');

        if (!self::current_user_can_use_tools($this->page_slug)) {
            wp_die(__('You do not have permission to access this page.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }

        try {
            $preview = $this->importer->prepare_from_upload($_FILES['settings_zip'] ?? []);
            $this->messages[] = ['type' => 'success', 'text' => __('ZIP uploaded. Select the settings you want to import.', 'pom')]; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }
        catch (RuntimeException $exception) {
            $this->messages[] = ['type' => 'error', 'text' => $exception->getMessage()];
        }
    }

    private function handle_import_execution(): void {
        check_admin_referer('pom_settings_tools_execute', 'pom_settings_tools_nonce_execute');

        if (!self::current_user_can_use_tools($this->page_slug)) {
            wp_die(__('You do not have permission to access this page.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }

        $selected = isset($_POST['import_settings']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['import_settings'])) : [];

        try {
            $result = $this->importer->import_selected($selected);

            if (!empty($result['imported'])) {
                $this->messages[] = ['type' => 'success', 'text' => sprintf(__('Imported: %s', 'pom'), esc_html(implode(', ', $result['imported'])))]; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
            }

            foreach ($result['notices'] as $notice) {
                $this->messages[] = ['type' => 'warning', 'text' => $notice];
            }
        }
        catch (RuntimeException $exception) {
            $this->messages[] = ['type' => 'error', 'text' => $exception->getMessage()];
        }
    }

    private function render_messages(): void {
        foreach ($this->messages as $message) {
            $class = isset($message['type']) && $message['type'] === 'success' ? 'notice notice-success' : 'notice notice-error';

            if (isset($message['type']) && $message['type'] === 'warning') {
                $class = 'notice notice-warning';
            }

            echo '<div class="' . esc_attr($class) . '"><p>' . wp_kses_post($message['text']) . '</p></div>';
        }
    }

    private function render_export_card(array $available_settings): void {
        echo '<div class="card">';
        echo '<h2>' . esc_html__('Export settings', 'pom') . '</h2>'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        echo '<p>' . esc_html__('Choose which settings to export into a ZIP archive.', 'pom') . '</p>'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        echo '<form method="post">';
        wp_nonce_field('pom_settings_tools_export', 'pom_settings_tools_nonce');
        echo '<input type="hidden" name="pom_settings_tools_action" value="export_settings" />';
        echo '<input type="hidden" name="pom_settings_tools_page" value="' . esc_attr($this->page_slug) . '" />';

        if (empty($available_settings)) {
            echo '<p>' . esc_html__('No settings files were found in the settings directory.', 'pom') . '</p>'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }
        else {
            echo '<ul class="notice-list">';
            foreach ($available_settings as $file_name => $label) {
                echo '<li><label><input type="checkbox" name="settings[]" value="' . esc_attr($file_name) . '"> ' . esc_html($label) . ' (' . esc_html($file_name) . ')</label></li>';
            }
            echo '</ul>';
        }

        echo '<div class="actions">';
        echo '<button type="submit" class="button button-primary"' . (empty($available_settings) ? ' disabled' : '') . '>' . esc_html__('Export selected', 'pom') . '</button>'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        echo '</div>';
        echo '</form>';
        echo '</div>';
    }

    private function render_import_card(?array $preview): void {
        echo '<div class="card">';
        echo '<h2>' . esc_html__('Import settings', 'pom') . '</h2>'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        echo '<p>' . esc_html__('Upload a ZIP created by the exporter to restore settings.', 'pom') . '</p>'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch

        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('pom_settings_tools_import', 'pom_settings_tools_nonce');
        echo '<input type="hidden" name="pom_settings_tools_action" value="prepare_import" />';
        echo '<p><input type="file" name="settings_zip" accept="application/zip" required /></p>';
        echo '<p class="actions"><button type="submit" class="button">' . esc_html__('Upload & preview', 'pom') . '</button></p>'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        echo '</form>';

        if (!empty($preview)) {
            $this->render_import_preview($preview);
        }

        echo '</div>';
    }

    private function render_import_preview(array $preview): void {
        $manifest = $preview['manifest'];
        echo '<hr />';
        echo '<h3>' . esc_html__('ZIP contents', 'pom') . '</h3>'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        echo '<p>' . esc_html(sprintf(__('Source domain: %s', 'pom'), $manifest['source_domain'] ?? __('Unknown', 'pom'))) . '</p>'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        echo '<p>' . esc_html(sprintf(__('Generated at: %s', 'pom'), $manifest['generated_at'] ?? __('Unknown', 'pom'))) . '</p>'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch

        echo '<form method="post">';
        wp_nonce_field('pom_settings_tools_execute', 'pom_settings_tools_nonce_execute');
        echo '<input type="hidden" name="pom_settings_tools_action" value="perform_import" />';

        echo '<ul class="notice-list">';
        foreach ($manifest['settings'] as $entry) {
            $file = $entry['file'] ?? '';
            $label = $entry['label'] ?? $file;
            echo '<li><label><input type="checkbox" name="import_settings[]" value="' . esc_attr($file) . '" checked> ' . esc_html($label) . ' (' . esc_html($file) . ')</label></li>';
        }
        echo '</ul>';

        echo '<p class="actions"><button type="submit" class="button button-primary">' . esc_html__('Import selected', 'pom') . '</button></p>'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        echo '</form>';
    }

    private function stream_zip(string $file_path, string $file_name): void {
        if (!is_file($file_path)) {
            $this->messages[] = ['type' => 'error', 'text' => __('Export failed: ZIP not found.', 'pom')]; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
            return;
        }

        if (ob_get_length()) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($file_name) . '"');
        header('Content-Length: ' . (string) filesize($file_path));
        readfile($file_path);
        unlink($file_path);
        exit;
    }

    private static function flash_message(array $message): void {
        set_transient(self::FLASH_OPTION, $message, MINUTE_IN_SECONDS);
    }

    private static function consume_flash_messages(): array {
        $message = get_transient(self::FLASH_OPTION);

        if (!empty($message)) {
            delete_transient(self::FLASH_OPTION);
            return [$message];
        }

        return [];
    }

    private static function current_user_can_use_tools(string $page_slug): bool {
        $capability = (string) apply_filters('pom_settings_tools_capability', 'edit_posts', $page_slug);

        return $capability === '' || current_user_can($capability);
    }

    private function redirect_back(): void {
        $referer = wp_get_referer();
        $fallback = admin_url('options-general.php?page=' . $this->page_slug);
        wp_safe_redirect($referer ?: $fallback);
        exit;
    }
}
