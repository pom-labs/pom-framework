<?php
/**
 * Class used for handling disk-related operations.
 */

namespace POMFramework;

class POM_Framework_Disk {

    /**
     * Prevent utility instances from registering the same global WordPress hooks repeatedly.
     */
    private static $hooks_registered = false;

    public function __construct() {
        if (self::$hooks_registered) {
            return;
        }

        self::$hooks_registered = true;

        /**
         * Set allowed font mime types and save the fonts in a custom directory.
         */
        add_filter('upload_dir', [$this, 'set_fonts_upload_dir']);
        add_filter('upload_mimes', [$this, 'add_allowed_font_mimes_to_upload_types']);
        add_filter('ajax_query_attachments_args', [$this, 'filter_media_library_attachment_query']);
        add_filter('rest_attachment_query', [$this, 'filter_rest_attachment_query'], 10, 2);
        add_action('pre_get_posts', [$this, 'filter_media_library_list_query']);
        add_filter('posts_where', [$this, 'filter_media_library_guid_constraint'], 10, 2);
    }

    public function filter_media_library_attachment_query(array $args): array {
        if ($this->is_font_picker_request()) {
            $args = $this->remove_mime_type_filters($args);
            return $this->add_font_directory_inclusion_to_args($args);
        }

        return $this->add_font_directory_exclusion_to_args($args);
    }

    public function filter_rest_attachment_query(array $args, $request): array {
        if ($this->is_font_picker_request($request)) {
            $args = $this->remove_mime_type_filters($args);
            return $this->add_font_directory_inclusion_to_args($args);
        }

        return $this->add_font_directory_exclusion_to_args($args);
    }

    public function filter_media_library_list_query($query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        global $pagenow;

        if ($pagenow !== 'upload.php') {
            return;
        }

        $this->add_font_directory_exclusion_to_query($query);
    }

    private function add_font_directory_exclusion_to_args(array $args): array {
        $args['pom_framework_font_filter'] = 'exclude';

        return $args;
    }

    private function add_font_directory_exclusion_to_query($query): void {
        if (!is_a($query, 'WP_Query')) {
            return;
        }

        $query->set('pom_framework_font_filter', 'exclude');
    }

    private function add_font_directory_inclusion_to_args(array $args): array {
        $args['pom_framework_font_filter'] = 'include';

        return $args;
    }

    public function filter_media_library_guid_constraint(string $where, $query): string {
        if (!is_a($query, 'WP_Query')) {
            return $where;
        }

        $filter = $query->get('pom_framework_font_filter');
        if (!$filter && isset($query->query['pom_framework_font_filter'])) {
            $filter = $query->query['pom_framework_font_filter'];
        }
        if (!$filter && $this->is_font_picker_request()) {
            $filter = 'include';
        }
        if ($filter !== 'include' && $filter !== 'exclude') {
            return $where;
        }

        $post_type = $query->get('post_type');
        if (!is_string($post_type) && !is_array($post_type)) {
            return $where;
        }

        if (is_string($post_type) && $post_type !== '' && $post_type !== 'attachment') {
            return $where;
        }

        if (is_array($post_type) && !in_array('attachment', $post_type, true)) {
            return $where;
        }

        global $wpdb;
        $like = '%' . $wpdb->esc_like('/fonts/') . '%';

        if ($filter === 'include') {
            $where .= $wpdb->prepare(" AND {$wpdb->posts}.guid LIKE %s", $like);
        }
        else {
            $where .= $wpdb->prepare(" AND {$wpdb->posts}.guid NOT LIKE %s", $like);
        }

        return $where;
    }

    private function remove_mime_type_filters(array $args): array {
        unset($args['post_mime_type'], $args['post_mime_type__in'], $args['post_mime_type__not_in']);

        return $args;
    }

    private function is_font_picker_request($request = null): bool {
        $allowed_font_mimes = array_values(POM_Framework_Helper::get_allowed_font_types());
        $allowed_font_mimes = array_merge(
            $allowed_font_mimes,
            [
                'application/font-woff2',
                'application/x-font-woff',
                'application/font-woff',
                'application/x-font-ttf',
                'application/x-font-truetype',
            ]
        );
        $allowed_font_mimes = array_values(array_unique($allowed_font_mimes));

        $value = null;

        if (is_object($request) && method_exists($request, 'get_param')) {
            $value = $request->get_param('pom_framework_font_picker');
        }

        if ($value === null && isset($_REQUEST['pom_framework_font_picker'])) {
            $value = $_REQUEST['pom_framework_font_picker'];
        }

        if ($value === null && isset($_REQUEST['query'])) {
            $query = $_REQUEST['query'];
            if (is_string($query)) {
                $decoded = json_decode($query, true);
                if (is_array($decoded) && array_key_exists('pom_framework_font_picker', $decoded)) {
                    $value = $decoded['pom_framework_font_picker'];
                }
            }
            elseif (is_array($query) && array_key_exists('pom_framework_font_picker', $query)) {
                $value = $query['pom_framework_font_picker'];
            }
        }

        if ($value !== null) {
            if (is_bool($value)) {
                return $value;
            }

            $is_font_picker = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($is_font_picker === true) {
                return true;
            }
        }

        $post_mime_type = null;

        if (is_object($request) && method_exists($request, 'get_param')) {
            $post_mime_type = $request->get_param('post_mime_type');
            if ($post_mime_type === null) {
                $query = $request->get_param('query');
                if (is_array($query) && array_key_exists('post_mime_type', $query)) {
                    $post_mime_type = $query['post_mime_type'];
                }
            }
        }

        if ($post_mime_type === null && isset($_REQUEST['post_mime_type'])) {
            $post_mime_type = $_REQUEST['post_mime_type'];
        }

        if ($post_mime_type === null && isset($_REQUEST['query'])) {
            $query = $_REQUEST['query'];
            if (is_string($query)) {
                $decoded = json_decode($query, true);
                if (is_array($decoded) && array_key_exists('post_mime_type', $decoded)) {
                    $post_mime_type = $decoded['post_mime_type'];
                }
            }
            elseif (is_array($query) && array_key_exists('post_mime_type', $query)) {
                $post_mime_type = $query['post_mime_type'];
            }
        }

        if ($post_mime_type === null) {
            return false;
        }

        if (is_string($post_mime_type)) {
            $post_mime_type = array_filter(array_map('trim', explode(',', $post_mime_type)));
        }

        if (!is_array($post_mime_type)) {
            return false;
        }

        return count(array_intersect($allowed_font_mimes, $post_mime_type)) > 0;
    }

    private function get_uploaded_file_name(): string {
        if (!empty($_FILES) && is_array($_FILES)) {
            foreach ($_FILES as $file) {
                if (!is_array($file) || empty($file['name'])) {
                    continue;
                }

                $name = is_array($file['name']) ? reset($file['name']) : $file['name'];

                if (is_string($name) && $name !== '') {
                    return $name;
                }
            }
        }

        if (isset($_POST['name']) && is_string($_POST['name']) && $_POST['name'] !== '') {
            return $_POST['name'];
        }

        return '';
    }

    public function set_fonts_upload_dir($path) {
        if (!empty($path['error'])) {
            return $path;
        }

        $file_name = $this->get_uploaded_file_name();

        if ($file_name === '') {
            return $path;
        }

        $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if ($extension === '') {
            return $path;
        }

        $font_extensions = array_keys(POM_Framework_Helper::get_allowed_font_types());

        if (!in_array($extension, $font_extensions, true)) {
            return $path;
        }

        $custom_dir = '/fonts';
        $path['path'] = str_replace($path['subdir'], '', $path['path']); //remove default subdir (year/month)
        $path['url'] = str_replace($path['subdir'], '', $path['url']);
        $path['subdir'] = $custom_dir;
        $path['path'] .= $custom_dir;
        $path['url'] .= $custom_dir;

        return $path;
    }

    public function add_allowed_font_mimes_to_upload_types($mimes) {
        $allowed_fonts = POM_Framework_Helper::get_allowed_font_types();

        foreach ($allowed_fonts as $font => $mime) {
            $mimes[$font] = $mime;
        }

        return $mimes;
    }

    /**
     * Generate .htaccess file on dir creation for security purposes.
     *
     * @return void
     */
    public function create_htaccess_file(): void {
        $htaccess = WP_CONTENT_DIR . "/settings/.htaccess";

        if (!is_file($htaccess)) {
            $htaccess_content = <<<'HTACCESS'
# Default blocking
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule mod_access_compat.c>
    Order deny,allow
    Deny from all
</IfModule>

# Allow ONLY CSS/JS (and sourcemaps) y and ONLY with GET/HEAD/OPTIONS
<FilesMatch "\.(?:css|js|map)$">
    <IfModule mod_authz_core.c>
        Require all granted
        <LimitExcept GET HEAD OPTIONS>
            Require all denied
        </LimitExcept>
    </IfModule>
    <IfModule mod_access_compat.c>
        Order allow,deny
        Allow from all
        <LimitExcept GET HEAD OPTIONS>
            Deny from all
        </LimitExcept>
    </IfModule>
</FilesMatch>

# Never execute server-side on this directory
Options -Indexes -ExecCGI

# Deactivate PHP / CGI / scripts (force plaintext if someone hits)
<FilesMatch "\.(?:php[0-9]?|phtml|phps|shtml|cgi|pl|py|jsp|asp)$">
    ForceType text/plain
    RemoveHandler .php .phtml .phps .shtml .cgi .pl .py .jsp .asp
    RemoveType .php .phtml .phps .shtml .cgi .pl .py .jsp .asp
</FilesMatch>

# Block dotfiles (.env, .git, etc.)
<FilesMatch "^\.(.*)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule mod_access_compat.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>

# Harden headers for static files
<IfModule mod_headers.c>
    <FilesMatch "\.(?:css|js|map)$">
        Header set X-Content-Type-Options "nosniff"
        Header set Referrer-Policy "strict-origin-when-cross-origin"
        Header set Cache-Control "public, max-age=2592000, immutable"
    </FilesMatch>
</IfModule>

# Cache expires
<IfModule mod_expires.c>
    ExpiresActive On
    <FilesMatch "\.(?:css|js|map)$">
        ExpiresDefault "access plus 30 days"
    </FilesMatch>
</IfModule>
HTACCESS;
            self::write_file($htaccess, trim($htaccess_content));
        }
    }

    public static function write_file(string $path, string $content, int $flags = 0) {
        $written = file_put_contents($path, $content, $flags);

        if (false !== $written) {
            self::apply_file_permissions($path);
        }

        return $written;
    }

    public static function apply_file_permissions(string $path): void {
        self::apply_permissions($path, false);
    }

    public static function apply_directory_permissions(string $path): void {
        self::apply_permissions(rtrim($path, '/\\'), true);
    }

    private static function apply_permissions(string $path, bool $is_dir): void {
        if ($path === '' || !file_exists($path)) {
            return;
        }

        $permissions = self::resolve_permissions($path, $is_dir);

        if (null === $permissions) {
            return;
        }

        chmod($path, $permissions);
    }

    private static function resolve_permissions(string $path, bool $is_dir): ?int {
        if ($is_dir && defined('FS_CHMOD_DIR')) {
            return FS_CHMOD_DIR;
        }

        if (!$is_dir && defined('FS_CHMOD_FILE')) {
            return FS_CHMOD_FILE;
        }

        $reference_path = dirname($path);
        $stats = @stat($reference_path);

        if (is_array($stats) && isset($stats['mode'])) {
            return $stats['mode'] & ($is_dir ? 0007777 : 0000666);
        }

        return $is_dir ? 0755 : 0644;
    }

    public function save_signature_image($data) {
        $multisite_path = is_multisite() && !is_main_site() ? 'sites/' . get_current_blog_id() . '/' : '';
        $uploads_array = wp_get_upload_dir();
        $path = trailingslashit($uploads_array['basedir']) . $multisite_path . 'signatures/';

        if (!is_dir($path)) {
            if (!wp_mkdir_p($path) && !is_dir($path)) {
                return false;
            }

            self::apply_directory_permissions($path);
            $htaccessContent = "deny from all";
            $htaccessPath = $path . '/.htaccess';
            self::write_file($htaccessPath, $htaccessContent);
        }

        $filename = POM_Framework_Helper::generate_random_string(20, false) . '.png';
        $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $data));

        if ($data === false) {
            return false;
        }

        $saved = self::write_file($path . $filename, $data);

        return $saved ? $path . $filename : false;
    }

    public function get_signature_image($path): string {
        if (!file_exists($path) || !is_readable($path)) {
            return '';
        }

        $image_data = file_get_contents($path);
        if ($image_data === false) {
            return '';
        }

        $image_base64 = base64_encode($image_data);

        return 'data:image/png;base64,' . $image_base64;
    }

    /**
     * Establish the path in which the actions related to files are executed.
     *
     * @param string $settings_dir
     *
     * @return string
     */
    public static function get_settings_path(string $settings_dir = 'pom-framework'): string {
        $multisite_path = is_multisite() ? 'sites/' . get_current_blog_id() . '/' : '';

        return WP_CONTENT_DIR . "/settings/pom-framework/$multisite_path$settings_dir/";
    }

    /**
     * Read a file only when it belongs to the current site's managed settings tree.
     *
     * Why: code and TinyMCE field values are stored as file paths, but a path sent
     * through a forged field definition must never become an arbitrary file read.
     *
     * @return string|null Null when the path is outside the managed tree or unreadable.
     */
    public static function read_managed_file_path($path): ?string {
        if (!is_scalar($path) || (string) $path === '') {
            return null;
        }

        $real_path = realpath((string) $path);
        $settings_root = realpath(self::get_settings_path(''));
        if ($real_path === false || $settings_root === false || !is_file($real_path) || !is_readable($real_path)) {
            return null;
        }

        $settings_prefix = trailingslashit($settings_root);
        if (!str_starts_with($real_path, $settings_prefix)) {
            return null;
        }

        $contents = file_get_contents($real_path);

        return is_string($contents) ? $contents : null;
    }

    /**
     * Creates the directory in which the configuration files are saved.
     * If a multisite, it is subdivided into /sites/blog_id/.
     *
     * @param string $settings_dir
     *
     * @return void
     */
    public static function create_settings_dir(string $settings_dir = 'pom-framework'): void {
        $settings_path = self::get_settings_path($settings_dir);
        if (!is_dir($settings_path)) {
            $created = wp_mkdir_p($settings_path);

            if (!$created) {
                POM_Framework_Helper::write_log('Error creating tweaks settings dir.');
            }
            else {
                self::apply_directory_permissions($settings_path);
                $disk = new self();
                $disk->create_enabled_settings_file($settings_dir);
                $disk->create_htaccess_file();
                POM_Framework_Helper::write_log('Created tweaks settings dir.');
            }
        }
    }

    private function create_enabled_settings_file(string $settings_dir): void {
        $settings_content = $this->generate_file_content([], 'File responsible for saving active settings info.');
        self::write_file($this->get_settings_path($settings_dir) . 'enabled_settings.php', $settings_content, LOCK_EX);
    }

    /**
     * Generates the file content by converting the data to an array.
     *
     * @param $data
     * @param string $description
     *
     * @return string
     */
    public function generate_file_content($data, string $description = ''): string {
        if (empty($data) && empty($description)) {
            return '';
        }

        $data = empty($data) ? [] : $data;

        $file_content = '<?php' . PHP_EOL;
        $file_content .= '/**' . PHP_EOL;
        $file_content .= !empty($description) ? ' * ' . $description . PHP_EOL : '';
        $file_content .= ' *' . PHP_EOL;
        $file_content .= ' * This file is automatically created.' . PHP_EOL;
        $file_content .= ' *' . PHP_EOL;
        $file_content .= ' * @site ' . get_site_url() . PHP_EOL;
        $file_content .= ' * @time ' . current_time('D, d M Y H:i:s', true) . ' GMT' . PHP_EOL;
        $file_content .= ' */' . PHP_EOL;
        $file_content .= PHP_EOL;
        $file_content .= 'return ' . var_export($data, true) . ';';

        return $file_content;
    }

    /**
     * Writes the literal value to a file.
     *
     * Empty editor values remove the previous managed file so a later settings render cannot restore stale content.
     * Identical content keeps the current file in place so filemtime-based asset versions only change after real edits.
     *
     * @param $file_name
     * @param $content
     * @param string $file_extension
     * @param string $settings_dir
     *
     * @return string Written file path.
     */
    public static function save_to_file($file_name, $content, string $file_extension = 'txt', string $settings_dir = 'pom-framework'): string {
        if (empty($file_name)) {
            return '';
        }

        $file_name = sanitize_file_name((string) $file_name);
        $file_extension = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $file_extension));

        if ($file_name === '' || $file_extension === '') {
            return '';
        }

        $settings_path = self::get_settings_path($settings_dir);
        $filename = $file_name . '.' . $file_extension;

        if (empty($content)) {
            self::delete_file($filename, $settings_dir);

            return '';
        }

        $path = $settings_path . $filename;
        $content = (string) $content;

        if (is_file($path)) {
            $existing_content = file_get_contents($path);

            if (is_string($existing_content) && $existing_content === $content) {
                return $path;
            }
        }

        self::write_file($path, $content, LOCK_EX);

        return $path;
    }

    /**
     * Read the content of a file.
     */
    public static function read_file($filename, $settings_dir = 'pom-framework', $return = 'default') {
        $settings_path = self::get_settings_path($settings_dir);
        $path = $settings_path . $filename;

        if (file_exists($path)) {
            $content = $return === 'array' ? include $path : file_get_contents($path);

            if ($return === 'array') {
                return is_array($content) ? $content : [];
            }

            return $content;
        }

        return $return === 'array' ? [] : '';
    }

    /**
     * Deletes selected file.
     *
     * @param $filename
     * @param string $settings_dir
     *
     * @return bool
     */
    public static function delete_file($filename, string $settings_dir = 'pom-framework'): bool {
        $settings_path = self::get_settings_path($settings_dir);
        $filename = $settings_path . $filename;

        return file_exists($filename) && unlink($filename);
    }

}
