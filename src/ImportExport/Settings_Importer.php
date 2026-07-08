<?php

namespace POMFramework\ImportExport;

use POMFramework\POM_Framework_Disk;
use RuntimeException;
use ZipArchive;

class Settings_Importer {
    private string $page_slug;
    private string $base_path;
    private string $transient_key;
    private ?array $allowed_files;

    public function __construct(string $page_slug, ?array $allowed_files = null) {
        $this->page_slug = $page_slug;
        $this->base_path = Settings_Transfer_Helper::get_settings_base_path($page_slug);
        $this->transient_key = 'pom_settings_import_preview_' . get_current_user_id() . '_' . md5($page_slug);
        $this->allowed_files = Settings_Transfer_Helper::normalize_settings_file_allowlist($allowed_files);
    }

    /**
     * @return array{token: string, manifest: array, extract_path: string, zip_path: string}
     */
    public function prepare_from_upload(array $uploaded_file): array {
        if (!isset($uploaded_file['tmp_name']) || !is_uploaded_file($uploaded_file['tmp_name'])) {
            throw new RuntimeException(__('Please upload a valid ZIP file.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }

        $handled_upload = wp_handle_upload($uploaded_file, ['test_form' => false]);

        if (!empty($handled_upload['error'])) {
            throw new RuntimeException($handled_upload['error']);
        }

        $zip_path = $handled_upload['file'];

        $preview = $this->build_preview_from_zip($zip_path);

        $this->cleanup_existing_preview();
        set_transient($this->transient_key, $preview, HOUR_IN_SECONDS);

        return $preview;
    }

    public function get_preview(): ?array {
        $preview = get_transient($this->transient_key);

        return is_array($preview) ? $preview : null;
    }

    public function cleanup_existing_preview(): void {
        $existing = $this->get_preview();

        if (empty($existing)) {
            return;
        }

        $this->remove_preview_artifacts($existing);
        delete_transient($this->transient_key);
    }

    /**
     * @param array<int, string> $selected_files
     *
     * @return array{imported: array<int, string>, notices: array<int, string>}
     */
    public function import_selected(array $selected_files): array {
        $preview = $this->get_preview();

        if (empty($preview)) {
            throw new RuntimeException(__('Upload a ZIP file before importing.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }

        $selected_files = array_filter(array_map('sanitize_file_name', $selected_files));

        if (empty($selected_files)) {
            throw new RuntimeException(__('Select at least one settings file to import.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }

        $manifest = $preview['manifest'];

        if (($manifest['page_slug'] ?? '') !== $this->page_slug) {
            throw new RuntimeException(__('The uploaded ZIP does not match the current settings page.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }

        POM_Framework_Disk::create_settings_dir($this->page_slug);

        $destination_domain = Domain_Replacer::normalize_domain(home_url());
        $source_domain = Domain_Replacer::normalize_domain($manifest['source_domain'] ?? '');
        $source_base = Settings_Transfer_Helper::ensure_trailing_slash(Settings_Transfer_Helper::normalize_path($manifest['source_base_path'] ?? $this->base_path));
        $imported = [];
        $notices = [];
        $copied_assets = [];

        foreach ($selected_files as $file_name) {
            if (!$this->is_allowed_file($file_name)) {
                $notices[] = sprintf(__('Skipping settings file outside the allowed transfer scope: %s', 'pom'), esc_html($file_name)); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
                continue;
            }

            $manifest_entry = $this->find_manifest_entry($manifest, $file_name);

            if ($manifest_entry === null) {
                $notices[] = sprintf(__('Skipping unknown settings file: %s', 'pom'), esc_html($file_name)); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
                continue;
            }

            $json_path = $this->get_preview_path($preview, $manifest_entry['json']);

            if (!is_file($json_path)) {
                $notices[] = sprintf(__('Settings payload missing for: %s', 'pom'), esc_html($file_name)); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
                continue;
            }

            $settings_array = json_decode((string) file_get_contents($json_path), true);

            if (!is_array($settings_array)) {
                $notices[] = sprintf(__('Settings payload unreadable for: %s', 'pom'), esc_html($file_name)); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
                continue;
            }

            $assets = $manifest_entry['assets'] ?? [];

            if (!$this->copy_assets($preview, $assets, $source_domain, $destination_domain, $copied_assets)) {
                $notices[] = sprintf(__('Settings file skipped because one or more managed assets could not be restored: %s', 'pom'), esc_html($file_name)); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
                continue;
            }

            $settings_array = Settings_Transfer_Helper::update_asset_paths($settings_array, $assets, $source_base, $this->base_path);
            $settings_array = Domain_Replacer::replace_in_array($settings_array, $source_domain, $destination_domain);

            $disk = new POM_Framework_Disk();
            $file_content = $disk->generate_file_content($settings_array, 'Imported settings file.');
            $destination_file = $this->base_path . $manifest_entry['file'];

            if (!Settings_Transfer_Helper::is_path_inside_directory($destination_file, $this->base_path)) {
                $notices[] = sprintf(__('Skipping unsafe settings file: %s', 'pom'), esc_html($file_name)); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
                continue;
            }

            if (POM_Framework_Disk::write_file($destination_file, $file_content, LOCK_EX) === false) {
                $notices[] = sprintf(__('Settings file could not be written: %s', 'pom'), esc_html($file_name)); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
                continue;
            }

            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($destination_file, true); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }

            $imported[] = $manifest_entry['file'];
        }

        $this->remove_preview_artifacts($preview);
        delete_transient($this->transient_key);

        return [
            'imported' => $imported,
            'notices' => $notices,
        ];
    }

    private function build_preview_from_zip(string $zip_path): array {
        $zip = new ZipArchive();

        if ($zip->open($zip_path) !== true) {
            throw new RuntimeException(__('The uploaded ZIP file could not be opened.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }

        $this->validate_zip_entries($zip);

        $extract_dir = Settings_Transfer_Helper::ensure_trailing_slash(trailingslashit(wp_get_upload_dir()['basedir']) . 'pom-settings-tools/import-' . wp_generate_uuid4());
        wp_mkdir_p($extract_dir);

        if (!$zip->extractTo($extract_dir)) {
            throw new RuntimeException(__('The uploaded ZIP file could not be extracted.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }

        $zip->close();

        $manifest_path = $extract_dir . 'manifest.json';

        if (!is_file($manifest_path)) {
            Settings_Transfer_Helper::delete_directory($extract_dir);
            throw new RuntimeException(__('The uploaded ZIP file is missing its manifest.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }

        $manifest = json_decode((string) file_get_contents($manifest_path), true);
        $manifest = is_array($manifest) ? $this->sanitize_manifest($manifest) : null;

        if (!is_array($manifest) || empty($manifest['settings'])) {
            Settings_Transfer_Helper::delete_directory($extract_dir);
            throw new RuntimeException(__('The uploaded ZIP file has an invalid manifest.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }

        return [
            'token' => wp_generate_uuid4(),
            'manifest' => $manifest,
            'extract_path' => Settings_Transfer_Helper::ensure_trailing_slash(Settings_Transfer_Helper::normalize_path($extract_dir)),
            'zip_path' => Settings_Transfer_Helper::normalize_path($zip_path),
        ];
    }

    private function validate_zip_entries(ZipArchive $zip): void {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry_name = $zip->getNameIndex($i);

            if ($entry_name === false || Settings_Transfer_Helper::sanitize_relative_path($entry_name) === null) {
                throw new RuntimeException(__('The uploaded ZIP file contains invalid paths.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
            }
        }
    }

    private function find_manifest_entry(array $manifest, string $file_name): ?array {
        foreach ($manifest['settings'] as $entry) {
            if (($entry['file'] ?? '') === $file_name) {
                return $entry;
            }
        }

        return null;
    }

    private function get_preview_path(array $preview, string $relative_path): string {
        $clean_relative = Settings_Transfer_Helper::sanitize_relative_path($relative_path);

        if ($clean_relative === null) {
            return '';
        }

        $path = Settings_Transfer_Helper::ensure_trailing_slash($preview['extract_path']) . $clean_relative;

        if (!Settings_Transfer_Helper::is_path_inside_directory($path, $preview['extract_path'])) {
            return '';
        }

        return $path;
    }

    private function copy_assets(array $preview, array $assets, string $source_domain, string $destination_domain, array &$copied_assets): bool {
        $all_copied = true;

        foreach ($assets as $asset_relative) {
            $clean_relative = Settings_Transfer_Helper::sanitize_relative_path((string) $asset_relative);

            if ($clean_relative === null) {
                $all_copied = false;
                continue;
            }

            if (isset($copied_assets[$clean_relative])) {
                continue;
            }

            $source_file = $this->get_preview_path($preview, 'assets/' . $clean_relative);
            $destination_file = $this->base_path . $clean_relative;

            if (!is_file($source_file) || !Settings_Transfer_Helper::is_path_inside_directory($destination_file, $this->base_path)) {
                $all_copied = false;
                continue;
            }

            if (!wp_mkdir_p(dirname($destination_file)) && !is_dir(dirname($destination_file))) {
                $all_copied = false;
                continue;
            }

            POM_Framework_Disk::apply_directory_permissions(dirname($destination_file));

            $contents = file_get_contents($source_file);

            if ($contents === false) {
                $all_copied = false;
                continue;
            }

            $contents = Domain_Replacer::replace_in_string($contents, $source_domain, $destination_domain);

            if (POM_Framework_Disk::write_file($destination_file, $contents, LOCK_EX) === false) {
                $all_copied = false;
                continue;
            }

            $copied_assets[$clean_relative] = true;
        }

        return $all_copied;
    }

    private function sanitize_manifest(array $manifest): ?array {
        if (empty($manifest['settings']) || !is_array($manifest['settings'])) {
            return null;
        }

        $sanitized_settings = [];

        foreach ($manifest['settings'] as $entry) {
            if (!is_array($entry)) {
                return null;
            }

            $file = isset($entry['file']) ? sanitize_file_name((string) $entry['file']) : '';
            $json = isset($entry['json']) ? Settings_Transfer_Helper::sanitize_relative_path((string) $entry['json']) : null;

            if ($file === '' || $file !== ($entry['file'] ?? '') || !str_ends_with($file, '.php')) {
                return null;
            }

            if (!$this->is_allowed_file($file)) {
                continue;
            }

            if ($json === null || !str_starts_with($json, 'settings/') || !str_ends_with($json, '.json')) {
                return null;
            }

            $assets = [];
            foreach (($entry['assets'] ?? []) as $asset) {
                $clean_asset = Settings_Transfer_Helper::sanitize_relative_path((string) $asset);

                if ($clean_asset === null || !preg_match('/\.(html?|css|js)$/i', $clean_asset)) {
                    return null;
                }

                $assets[] = $clean_asset;
            }

            $sanitized_settings[] = [
                'file' => $file,
                'label' => isset($entry['label']) ? sanitize_text_field((string) $entry['label']) : Settings_Transfer_Helper::humanize_setting_label($file),
                'json' => $json,
                'assets' => array_values(array_unique($assets)),
            ];
        }

        $manifest['settings'] = $sanitized_settings;
        $manifest['page_slug'] = isset($manifest['page_slug']) ? sanitize_key((string) $manifest['page_slug']) : '';
        $manifest['source_domain'] = Domain_Replacer::normalize_domain((string) ($manifest['source_domain'] ?? ''));
        $manifest['source_base_path'] = isset($manifest['source_base_path']) ? Settings_Transfer_Helper::normalize_path((string) $manifest['source_base_path']) : '';
        $manifest['generated_at'] = isset($manifest['generated_at']) ? sanitize_text_field((string) $manifest['generated_at']) : '';

        return $manifest;
    }

    private function remove_preview_artifacts(array $preview): void {
        if (!empty($preview['extract_path'])) {
            Settings_Transfer_Helper::delete_directory($preview['extract_path']);
        }

        if (!empty($preview['zip_path']) && file_exists($preview['zip_path'])) {
            unlink($preview['zip_path']);
        }
    }

    private function is_allowed_file(string $file_name): bool {
        return Settings_Transfer_Helper::is_transferable_settings_file($file_name)
            && ($this->allowed_files === null || isset($this->allowed_files[$file_name]));
    }
}
