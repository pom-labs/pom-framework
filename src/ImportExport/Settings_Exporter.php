<?php

namespace POMFramework\ImportExport;

use POMFramework\POM_Framework_Disk;
use RuntimeException;
use ZipArchive;

class Settings_Exporter {
    private string $page_slug;
    private string $base_path;
    private ?array $allowed_files;

    public function __construct(string $page_slug, ?array $allowed_files = null) {
        $this->page_slug = $page_slug;
        $this->base_path = Settings_Transfer_Helper::get_settings_base_path($page_slug);
        $this->allowed_files = Settings_Transfer_Helper::normalize_settings_file_allowlist($allowed_files);
    }

    public function get_available_settings(): array {
        $files = glob($this->base_path . '*.php');

        if ($files === false) {
            return [];
        }

        $settings = [];

        foreach ($files as $file) {
            $file_name = basename($file);

            if (!$this->is_allowed_file($file_name)) {
                continue;
            }

            $settings[$file_name] = Settings_Transfer_Helper::humanize_setting_label($file_name);
        }

        ksort($settings);

        return $settings;
    }

    /**
     * @return array{path: string, name: string, notices: array<int, string>, source_domain: string}
     */
    public function export(array $selected_files): array {
        $selected_files = array_filter(array_map('sanitize_file_name', $selected_files));

        if (empty($selected_files)) {
            throw new RuntimeException(__('Select at least one settings file to export.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }

        $disk = new POM_Framework_Disk();
        POM_Framework_Disk::create_settings_dir($this->page_slug);

        $upload_dir = wp_get_upload_dir();
        $export_dir = trailingslashit($upload_dir['basedir']) . 'pom-settings-tools/';
        wp_mkdir_p($export_dir);

        $source_domain = '';
        $manifest_settings = [];
        $zip = new ZipArchive();
        $zip_filename = $this->build_zip_filename();
        $zip_path = $export_dir . $zip_filename;

        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(__('Unable to create the ZIP file for export.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }

        $notices = [];

        foreach ($selected_files as $file_name) {
            if (!$this->is_allowed_file($file_name)) {
                $notices[] = sprintf(__('Skipped settings file outside the allowed transfer scope: %s', 'pom'), esc_html($file_name)); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
                continue;
            }

            $full_path = $this->base_path . $file_name;

            if (!is_file($full_path)) {
                $notices[] = sprintf(__('Skipped missing settings file: %s', 'pom'), esc_html($file_name)); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
                continue;
            }

            $source_domain = $source_domain ?: Settings_Transfer_Helper::detect_domain_from_file($full_path);
            $setting_array = POM_Framework_Disk::read_file($file_name, $this->page_slug, 'array');

            if (!is_array($setting_array)) {
                $notices[] = sprintf(__('Skipped unreadable settings file: %s', 'pom'), esc_html($file_name)); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
                continue;
            }

            $json_file = 'settings/' . pathinfo($file_name, PATHINFO_FILENAME) . '.json';
            $json_content = wp_json_encode($setting_array, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($json_content === false) {
                $notices[] = sprintf(__('Could not encode settings file: %s', 'pom'), esc_html($file_name)); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
                continue;
            }

            $zip->addFromString($json_file, $json_content);

            $assets = Settings_Transfer_Helper::collect_asset_paths($setting_array, $this->base_path);

            foreach ($assets as $asset_relative) {
                $asset_full_path = $this->base_path . $asset_relative;

                if (!is_file($asset_full_path)) {
                    $notices[] = sprintf(__('Referenced asset missing and skipped: %s', 'pom'), esc_html($asset_relative)); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
                    continue;
                }

                $zip->addFile($asset_full_path, 'assets/' . $asset_relative);
            }

            $manifest_settings[] = [
                'file' => $file_name,
                'label' => Settings_Transfer_Helper::humanize_setting_label($file_name),
                'json' => $json_file,
                'assets' => $assets,
            ];
        }

        if (empty($manifest_settings)) {
            $zip->close();

            if (is_file($zip_path)) {
                unlink($zip_path);
            }

            throw new RuntimeException(__('No valid settings files were available to export.', 'pom')); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
        }

        $source_domain = $source_domain ?: Domain_Replacer::normalize_domain(get_site_url());

        $manifest = [
            'version' => 1,
            'page_slug' => $this->page_slug,
            'source_domain' => $source_domain,
            'source_base_path' => Settings_Transfer_Helper::normalize_path($this->base_path),
            'generated_at' => current_time('mysql', true),
            'settings' => $manifest_settings,
        ];

        $zip->addFromString('manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return [
            'path' => $zip_path,
            'name' => $zip_filename,
            'notices' => $notices,
            'source_domain' => $source_domain,
        ];
    }

    private function build_zip_filename(): string {
        $domain = Domain_Replacer::normalize_domain(get_site_url());
        $timestamp = gmdate('Ymd-His');

        return sprintf('%s-settings-%s.zip', $domain ?: 'site', $timestamp);
    }

    private function is_allowed_file(string $file_name): bool {
        return $this->allowed_files === null || isset($this->allowed_files[$file_name]);
    }
}
