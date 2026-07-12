<?php
/**
 * Licensed v2 fallback updater for products without the common POM client.
 *
 * The common updater remains preferred because it discovers inactive
 * products. This fallback deliberately exposes no metadata without a stored
 * license and always refreshes the one-time token at installation time.
 */

namespace POMFramework\Licensing;

class Private_Updater {

	/** @var array<string, array<string, string>> */
	private static $plugins = array();

	/** @var array<string, array<string, string>> */
	private static $themes = array();

	private static $booted = false;

	/**
	 * Register a plugin fallback updater.
	 *
	 * @param array<string, string> $labels name, author, description.
	 */
	public static function boot_plugin( string $basename, string $product_slug, array $labels = array() ): void {
		if ( class_exists( 'POM_Private_Updates' ) && \POM_Private_Updates::manages_plugin( $basename ) ) {
			return;
		}

		self::$plugins[ $basename ] = array_merge( array( 'product' => $product_slug ), $labels );
		self::boot();
	}

	/**
	 * Register a theme fallback updater.
	 *
	 * @param array<string, string> $labels name, author, description.
	 */
	public static function boot_theme( string $stylesheet, string $product_slug, array $labels = array() ): void {
		if ( class_exists( 'POM_Private_Updates' ) && \POM_Private_Updates::manages_theme( $stylesheet ) ) {
			return;
		}

		self::$themes[ $stylesheet ] = array_merge( array( 'product' => $product_slug ), $labels );
		self::boot();
	}

	private static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'plugin_updates' ) );
		add_filter( 'pre_set_site_transient_update_themes', array( __CLASS__, 'theme_updates' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
		add_filter( 'themes_api', array( __CLASS__, 'theme_information' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
	}

	public static function plugin_updates( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		foreach ( self::$plugins as $basename => $config ) {
			if ( empty( $transient->checked[ $basename ] ) ) {
				continue;
			}

			$data = self::check( 'plugin', $basename, $config, (string) $transient->checked[ $basename ] );
			if ( empty( $data['ok'] ) || empty( $data['new_version'] ) || ! version_compare( (string) $data['new_version'], (string) $transient->checked[ $basename ], '>' ) ) {
				continue;
			}

			$transient->response[ $basename ] = (object) array(
				'slug'         => dirname( $basename ),
				'plugin'       => $basename,
				'new_version'  => (string) $data['new_version'],
				'url'          => (string) ( $data['homepage'] ?? '' ),
				'package'      => self::marker_url( 'plugin', $basename, $config['product'], (string) ( $data['sha256'] ?? '' ) ),
				'tested'       => (string) ( $data['tested'] ?? '' ),
				'requires'     => (string) ( $data['requires'] ?? '' ),
				'requires_php' => (string) ( $data['requires_php'] ?? '' ),
			);
		}

		return $transient;
	}

	public static function theme_updates( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		foreach ( self::$themes as $stylesheet => $config ) {
			if ( empty( $transient->checked[ $stylesheet ] ) ) {
				continue;
			}

			$data = self::check( 'theme', $stylesheet, $config, (string) $transient->checked[ $stylesheet ] );
			if ( empty( $data['ok'] ) || empty( $data['new_version'] ) || ! version_compare( (string) $data['new_version'], (string) $transient->checked[ $stylesheet ], '>' ) ) {
				continue;
			}

			$transient->response[ $stylesheet ] = array(
				'theme'        => $stylesheet,
				'new_version'  => (string) $data['new_version'],
				'url'          => (string) ( $data['homepage'] ?? '' ),
				'package'      => self::marker_url( 'theme', $stylesheet, $config['product'], (string) ( $data['sha256'] ?? '' ) ),
				'tested'       => (string) ( $data['tested'] ?? '' ),
				'requires'     => (string) ( $data['requires'] ?? '' ),
				'requires_php' => (string) ( $data['requires_php'] ?? '' ),
			);
		}

		return $transient;
	}

	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) ) {
			return $result;
		}

		foreach ( self::$plugins as $basename => $config ) {
			if ( dirname( $basename ) !== ( $args->slug ?? '' ) ) {
				continue;
			}

			$data = self::check( 'plugin', $basename, $config, '0' );
			return empty( $data['ok'] ) ? $result : self::information( dirname( $basename ), $config, $data );
		}

		return $result;
	}

	public static function theme_information( $result, $action, $args ) {
		if ( 'theme_information' !== $action || ! is_object( $args ) || empty( self::$themes[ $args->slug ] ) ) {
			return $result;
		}

		$config = self::$themes[ $args->slug ];
		$data   = self::check( 'theme', (string) $args->slug, $config, '0' );

		return empty( $data['ok'] ) ? $result : self::information( (string) $args->slug, $config, $data );
	}

	public static function download( $reply, string $package, $upgrader ) {
		unset( $upgrader );

		if ( false !== $reply
			|| wp_parse_url( License_Client::api_base(), PHP_URL_HOST ) !== wp_parse_url( $package, PHP_URL_HOST )
			|| '/wp-json/pom-lic/v2/updates/download' !== wp_parse_url( $package, PHP_URL_PATH ) ) {
			return $reply;
		}

		parse_str( (string) wp_parse_url( $package, PHP_URL_QUERY ), $query );
		$type     = sanitize_key( $query['type'] ?? '' );
		$artifact = sanitize_text_field( $query['slug'] ?? '' );
		$product  = sanitize_key( $query['product'] ?? '' );
		$config   = 'theme' === $type ? ( self::$themes[ $artifact ] ?? null ) : ( self::$plugins[ $artifact ] ?? null );

		if ( ! is_array( $config ) || $product !== $config['product'] ) {
			return $reply;
		}

		$data = self::check( $type, $artifact, $config, '0' );
		if ( empty( $data['ok'] ) || empty( $data['download_token'] ) || empty( $data['sha256'] ) ) {
			return new \WP_Error( 'pom_update_license_rejected', 'The POM license server rejected this update download.' );
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$url = add_query_arg( 'token', rawurlencode( (string) $data['download_token'] ), License_Client::api_base() . '/updates/download' );
		$tmp = download_url( $url, 300 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$actual = hash_file( 'sha256', $tmp );
		if ( ! is_string( $actual ) || ! hash_equals( strtolower( (string) $data['sha256'] ), strtolower( $actual ) ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'pom_update_bad_hash', 'The POM update package integrity check failed.' );
		}

		return $tmp;
	}

	/** @return array<string, mixed> */
	private static function check( string $type, string $artifact, array $config, string $version ): array {
		$key = License_Registry::get_key( $config['product'] );

		return null === $key ? array() : License_Client::check_update( $config['product'], $key, $type, $artifact, $version );
	}

	private static function marker_url( string $type, string $artifact, string $product, string $sha256 ): string {
		return add_query_arg(
			array( 'type' => $type, 'slug' => $artifact, 'product' => $product, 'sha256' => strtolower( $sha256 ) ),
			License_Client::api_base() . '/updates/download'
		);
	}

	private static function information( string $slug, array $config, array $data ) {
		return (object) array(
			'name'         => (string) ( $config['name'] ?? $slug ),
			'slug'         => $slug,
			'version'      => (string) ( $data['new_version'] ?? '' ),
			'requires'     => (string) ( $data['requires'] ?? '' ),
			'requires_php' => (string) ( $data['requires_php'] ?? '' ),
			'tested'       => (string) ( $data['tested'] ?? '' ),
			'author'       => (string) ( $config['author'] ?? 'POM Design & Development S.L.' ),
			'homepage'     => (string) ( $data['homepage'] ?? '' ),
			'sections'     => array(
				'description' => (string) ( $config['description'] ?? '' ),
				'changelog'   => (string) ( $data['changelog'] ?? '' ),
			),
		);
	}
}
