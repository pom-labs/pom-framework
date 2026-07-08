<?php

namespace POMFramework\ImportExport;

use POMFramework\POM_Framework_Disk;

class Settings_Transfer_Helper {
    private const NON_TRANSFERABLE_SETTINGS_FILES = [
        'fields_save_as.php',
    ];

    /**
     * Internal storage metadata is not a standalone settings payload.
     *
     * Why: transferring this map without also transferring its external option or
     * theme-mod values is incomplete and can change how unrelated fields are read.
     */
    public static function is_transferable_settings_file(string $file_name): bool {
        return !in_array($file_name, self::NON_TRANSFERABLE_SETTINGS_FILES, true);
    }

    /**
     * Normalize an optional settings-file allowlist used to constrain a transfer.
     *
     * Why: consumers can expose import/export beside legacy settings systems, so the
     * transfer boundary must be explicit rather than trusting submitted filenames.
     */
    public static function normalize_settings_file_allowlist(?array $file_names): ?array {
        if ($file_names === null) {
            return null;
        }

        $normalized = [];

        foreach ($file_names as $file_name) {
            $file_name = sanitize_file_name((string) $file_name);

            if (
                $file_name === ''
                || !str_ends_with($file_name, '.php')
                || !self::is_transferable_settings_file($file_name)
            ) {
                continue;
            }

            $normalized[$file_name] = true;
        }

        return $normalized;
    }

    public static function normalize_path(string $path): string {
        return wp_normalize_path($path);
    }

    public static function ensure_trailing_slash(string $path): string {
        return rtrim($path, '/') . '/';
    }

    public static function sanitize_relative_path(string $path): ?string {
        $normalized = self::normalize_path($path);

        if (
            $normalized === '' ||
            str_starts_with($normalized, '/') ||
            str_contains($normalized, "\0") ||
            str_contains($normalized, '..') ||
            str_contains($normalized, '://') ||
            preg_match('/^[A-Za-z]:/', $normalized)
        ) {
            return null;
        }

        return ltrim($normalized, '/');
    }

    public static function is_path_inside_directory(string $path, string $directory): bool {
        $normalized_directory = self::ensure_trailing_slash(self::normalize_path($directory));
        $normalized_path = self::normalize_path($path);

        return $normalized_path === rtrim($normalized_directory, '/') || strpos($normalized_path, $normalized_directory) === 0;
    }

    public static function detect_domain_from_file(string $file_path): string {
        if (!is_readable($file_path)) {
            return '';
        }

        $handle = fopen($file_path, 'r');

        if ($handle === false) {
            return '';
        }

        $domain = '';
        $line_number = 0;

        while (!feof($handle) && $line_number < 10) {
            $line = fgets($handle);
            $line_number++;

            if ($line === false) {
                continue;
            }

            if (strpos($line, '@site') !== false) {
                preg_match('#@site\s+([^\s]+)#', $line, $matches);

                if (!empty($matches[1])) {
                    $domain = Domain_Replacer::normalize_domain($matches[1]);
                    break;
                }
            }
        }

        fclose($handle);

        return $domain;
    }

    public static function relative_asset_path(string $candidate, string $base_path): ?string {
        $normalized_base = self::ensure_trailing_slash(self::normalize_path($base_path));
        $normalized_candidate = self::normalize_path($candidate);

        if (strpos($normalized_candidate, $normalized_base) === 0) {
            return self::sanitize_relative_path(ltrim(substr($normalized_candidate, strlen($normalized_base)), '/'));
        }

        $candidate_without_leading_slash = ltrim($normalized_candidate, '/');
        $absolute_candidate = $normalized_base . $candidate_without_leading_slash;

        if (file_exists($absolute_candidate)) {
            return self::sanitize_relative_path($candidate_without_leading_slash);
        }

        return null;
    }

    public static function collect_asset_paths($data, string $base_path): array {
        $assets = [];

        if (is_array($data)) {
            foreach ($data as $value) {
                $assets = array_merge($assets, self::collect_asset_paths($value, $base_path));
            }

            return array_values(array_unique($assets));
        }

        if (is_string($data) && preg_match('/\.(html?|css|js)$/i', $data)) {
            $relative_path = self::relative_asset_path($data, $base_path);

            if ($relative_path !== null) {
                $assets[] = $relative_path;
            }
        }

        return array_values(array_unique($assets));
    }

    public static function update_asset_paths($data, array $asset_relatives, string $source_base, string $destination_base) {
        if (empty($asset_relatives)) {
            return $data;
        }

        $normalized_source = self::ensure_trailing_slash(self::normalize_path($source_base));
        $normalized_destination = self::ensure_trailing_slash(self::normalize_path($destination_base));
        $asset_lookup = [];

        foreach ($asset_relatives as $asset_relative) {
            $clean_relative = ltrim(self::normalize_path($asset_relative), '/');
            $asset_lookup[$clean_relative] = $normalized_destination . $clean_relative;
        }

        return self::replace_asset_paths_recursively($data, $asset_lookup, $normalized_source, $normalized_destination);
    }

    public static function delete_directory(string $path): void {
        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            }
            else {
                unlink($file->getRealPath());
            }
        }

        rmdir($path);
    }

    public static function humanize_setting_label(string $file_name): string {
        $label = pathinfo($file_name, PATHINFO_FILENAME);
        $label = str_replace('_', ' ', $label);

        return ucwords($label);
    }

    public static function get_settings_base_path(string $page_slug): string {
        $disk = new POM_Framework_Disk();

        return self::ensure_trailing_slash(self::normalize_path($disk->get_settings_path($page_slug)));
    }

    private static function replace_asset_paths_recursively($value, array $asset_lookup, string $source_base, string $destination_base) {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::replace_asset_paths_recursively($item, $asset_lookup, $source_base, $destination_base);
            }

            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        $normalized_value = self::normalize_path($value);

        foreach ($asset_lookup as $relative_path => $destination_path) {
            $source_absolute = $source_base . $relative_path;

            if ($normalized_value === $source_absolute || $normalized_value === $relative_path || $normalized_value === '/' . $relative_path) {
                return $destination_path;
            }

            if (strpos($normalized_value, $source_base) === 0 && strpos($normalized_value, $relative_path) !== false) {
                return $destination_path;
            }
        }

        return $value;
    }
}
