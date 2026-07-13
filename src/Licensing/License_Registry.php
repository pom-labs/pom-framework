<?php
/**
 * Shared client-side product license registry.
 *
 * One registry named pom_product_licenses, keyed by canonical product slug.
 * Single-site WordPress stores it as a non-autoloaded option; multisite
 * stores it as a network option so the common updater can read every product
 * key while target plugins are inactive. One product license applies to one
 * WordPress network.
 *
 * Service-scoped exception (POM AI): on multisite, subsites store their own
 * blog-scoped license in a per-blog non-autoloaded option with the same name.
 * Service operations (AI requests and credits) require that blog entry and do
 * not inherit the network entry. Updates always use the network entry only, so
 * the common updater and its read-only registry reader never see blog keys.
 *
 * Key material is encrypted with a key derived from WordPress salts through a
 * versioned KDF. If salts change and decryption fails, the entry requires
 * reconnection; ciphertext is never logged or exposed.
 */

namespace POMFramework\Licensing;

class License_Registry {

	const OPTION_NAME    = 'pom_product_licenses';
	const SCHEMA_VERSION = 1;
	const KDF_VERSION    = 'v1';

	/**
	 * Registry scopes. SCOPE_BLOG exists only on multisite and only for
	 * service-scoped products.
	 */
	const SCOPE_SITE    = 'site';
	const SCOPE_NETWORK = 'network';
	const SCOPE_BLOG    = 'blog';

	/**
	 * Products whose service license is blog-scoped on multisite: each
	 * subsite (which may belong to a third party) can store its own key and
	 * consume its own credits, while plugin updates keep using the
	 * network-scoped entry managed from the network's main site.
	 */
	const SERVICE_SCOPED_PRODUCTS = array( 'pom-ai' );

	/**
	 * Whether a product's service license is per-blog on multisite.
	 */
	public static function is_service_scoped( string $product_slug ): bool {
		return in_array( $product_slug, self::SERVICE_SCOPED_PRODUCTS, true );
	}

	/**
	 * The scope where this product's license is MANAGED on the current site.
	 *
	 * Single site: 'site'. Multisite: 'network', except service-scoped
	 * products on a subsite, which manage their own 'blog' entry. The main
	 * site manages the network entry (it doubles as its service license).
	 */
	public static function management_scope( string $product_slug ): string {
		if ( ! is_multisite() ) {
			return self::SCOPE_SITE;
		}

		if ( self::is_service_scoped( $product_slug ) && ! is_main_site() ) {
			return self::SCOPE_BLOG;
		}

		return self::SCOPE_NETWORK;
	}

	/**
	 * The scope whose key actually serves this product on the current site.
	 *
	 * Service-scoped products deliberately stay in the blog scope on a
	 * multisite subsite. Requiring an explicit local key prevents a network
	 * license from silently granting account-backed services to third parties.
	 */
	public static function effective_scope( string $product_slug ): string {
		return self::management_scope( $product_slug );
	}

	/**
	 * Read the full registry for one scope.
	 *
	 * The default scope is the network registry on multisite (what updaters
	 * read) and the site registry on single site.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all( string $scope = '' ): array {
		if ( '' === $scope ) {
			$scope = is_multisite() ? self::SCOPE_NETWORK : self::SCOPE_SITE;
		}

		return self::read( $scope );
	}

	/**
	 * Read one product entry. Empty scope resolves to the current product's
	 * effective scope; service-scoped subsite reads never inherit network keys.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $product_slug, string $scope = '' ): ?array {
		$scope    = '' !== $scope ? $scope : self::effective_scope( $product_slug );
		$registry = self::read( $scope );

		return isset( $registry[ $product_slug ] ) && is_array( $registry[ $product_slug ] ) ? $registry[ $product_slug ] : null;
	}

	/**
	 * Store a license key for a product.
	 *
	 * Writes default to the management scope: a subsite storing a
	 * service-scoped key never touches the network registry.
	 *
	 * @return bool|\WP_Error
	 */
	public static function store_key( string $product_slug, string $license_key, string $scope = '' ) {
		$scope     = '' !== $scope ? $scope : self::management_scope( $product_slug );
		$encrypted = self::encrypt( $license_key );

		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}

		$registry = self::read( $scope );

		$existing = isset( $registry[ $product_slug ] ) && is_array( $registry[ $product_slug ] ) ? $registry[ $product_slug ] : array();

		$registry[ $product_slug ] = array_merge(
			$existing,
			array(
				'schema'       => self::SCHEMA_VERSION,
				'product'      => $product_slug,
				'key_cipher'   => $encrypted['cipher'],
				'key_nonce'    => $encrypted['nonce'],
				'kdf'          => self::KDF_VERSION,
				'masked'       => self::mask( $license_key ),
				'network_uuid' => $existing['network_uuid'] ?? self::ensure_network_uuid(),
				'stored_at'    => time(),
			)
		);

		return self::persist( $registry, $scope );
	}

	/**
	 * Retrieve the plaintext license key for a product.
	 *
	 * @return string|null Null when absent or undecryptable (reconnect needed).
	 */
	public static function get_key( string $product_slug, string $scope = '' ): ?string {
		$entry = self::get( $product_slug, $scope );

		if ( null === $entry || empty( $entry['key_cipher'] ) || empty( $entry['key_nonce'] ) ) {
			return null;
		}

		$plaintext = self::decrypt( (string) $entry['key_cipher'], (string) $entry['key_nonce'] );

		return is_string( $plaintext ) && '' !== $plaintext ? $plaintext : null;
	}

	/**
	 * Whether the entry exists but can no longer be decrypted (salts changed).
	 */
	public static function needs_reconnect( string $product_slug, string $scope = '' ): bool {
		$entry = self::get( $product_slug, $scope );

		if ( null === $entry || empty( $entry['key_cipher'] ) ) {
			return false;
		}

		return null === self::get_key( $product_slug, $scope );
	}

	/**
	 * Cache the last validation result for a product.
	 *
	 * The cache is stored next to the key that produced it (effective scope),
	 * so a subsite's own validation never overwrites the network entry.
	 *
	 * @param array<string, mixed> $result Sanitized validation payload.
	 */
	public static function store_validation( string $product_slug, array $result, int $cache_ttl = DAY_IN_SECONDS, string $scope = '' ): void {
		$scope    = '' !== $scope ? $scope : self::effective_scope( $product_slug );
		$registry = self::read( $scope );

		if ( ! isset( $registry[ $product_slug ] ) || ! is_array( $registry[ $product_slug ] ) ) {
			return;
		}

		$registry[ $product_slug ]['last_validation'] = array(
			'valid'            => ! empty( $result['valid'] ),
			'multisite_allowed' => ! empty( $result['multisite_allowed'] ),
			'license_status'   => isset( $result['license_status'] ) ? (string) $result['license_status'] : null,
			'plan'             => isset( $result['plan']['slug'] ) ? (string) $result['plan']['slug'] : null,
			'expires_at'       => isset( $result['expires_at'] ) ? (string) $result['expires_at'] : null,
			'allowed_features' => isset( $result['allowed_features'] ) && is_array( $result['allowed_features'] ) ? array_map( 'strval', $result['allowed_features'] ) : array(),
			'domain'           => isset( $result['domain']['normalized'] ) ? (string) $result['domain']['normalized'] : null,
			'error_code'       => isset( $result['error_code'] ) ? (string) $result['error_code'] : null,
			'checked_at'       => time(),
		);

		$registry[ $product_slug ]['next_check_at'] = time() + max( HOUR_IN_SECONDS, $cache_ttl );

		if ( ! empty( $result['valid'] ) ) {
			// Soft grace window for account outages; revoked/blocked results
			// never extend grace because they overwrite this entry.
			$registry[ $product_slug ]['grace_until'] = time() + ( 7 * DAY_IN_SECONDS );
		} elseif ( in_array( $result['error_code'] ?? '', array( 'license_inactive', 'invalid_license_key', 'license_expired', 'product_mismatch' ), true ) ) {
			$registry[ $product_slug ]['grace_until'] = 0;
		}

		self::persist( $registry, $scope );
	}

	/**
	 * Cached validation state for admin display and grace decisions.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_validation( string $product_slug, string $scope = '' ): ?array {
		$entry = self::get( $product_slug, $scope );

		return null !== $entry && isset( $entry['last_validation'] ) && is_array( $entry['last_validation'] ) ? $entry['last_validation'] : null;
	}

	/**
	 * Whether the cached state is still usable during an account outage.
	 */
	public static function in_grace( string $product_slug, string $scope = '' ): bool {
		$entry = self::get( $product_slug, $scope );

		if ( null === $entry ) {
			return false;
		}

		$validation = $entry['last_validation'] ?? null;

		if ( ! is_array( $validation ) || empty( $validation['valid'] ) ) {
			return false;
		}

		return (int) ( $entry['grace_until'] ?? 0 ) > time();
	}

	/**
	 * Remove a product entry (unlink) from its management scope.
	 */
	public static function forget( string $product_slug, string $scope = '' ): void {
		$scope    = '' !== $scope ? $scope : self::management_scope( $product_slug );
		$registry = self::read( $scope );

		unset( $registry[ $product_slug ] );

		self::persist( $registry, $scope );
	}

	/**
	 * The stable random network/site UUID sent with multisite payloads.
	 *
	 * Always network-level: blog-scoped entries share the network UUID.
	 */
	public static function ensure_network_uuid(): string {
		$registry = self::all();

		foreach ( $registry as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['network_uuid'] ) ) {
				return (string) $entry['network_uuid'];
			}
		}

		if ( is_multisite() ) {
			$uuid = get_site_option( 'pom_license_network_uuid' );
			if ( ! is_string( $uuid ) || '' === $uuid ) {
				$uuid = wp_generate_uuid4();
				update_site_option( 'pom_license_network_uuid', $uuid );
			}
		} else {
			$uuid = get_option( 'pom_license_network_uuid' );
			if ( ! is_string( $uuid ) || '' === $uuid ) {
				$uuid = wp_generate_uuid4();
				update_option( 'pom_license_network_uuid', $uuid, false );
			}
		}

		return $uuid;
	}

	/**
	 * Mask a key for UI display after save.
	 */
	public static function mask( string $license_key ): string {
		$length = strlen( $license_key );

		if ( $length <= 16 ) {
			return str_repeat( '*', $length );
		}

		return substr( $license_key, 0, 12 ) . str_repeat( '*', 8 ) . substr( $license_key, -4 );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function read( string $scope ): array {
		if ( self::SCOPE_NETWORK === $scope ) {
			$registry = get_site_option( self::OPTION_NAME, array() );
		} else {
			// 'blog' and 'site' both live in the current blog's options table.
			$registry = get_option( self::OPTION_NAME, array() );
		}

		return is_array( $registry ) ? $registry : array();
	}

	/**
	 * @param array<string, array<string, mixed>> $registry Registry payload.
	 */
	private static function persist( array $registry, string $scope ): bool {
		if ( self::SCOPE_NETWORK === $scope ) {
			update_site_option( self::OPTION_NAME, $registry );

			return true;
		}

		// Non-autoloaded: license state must not load on every request.
		update_option( self::OPTION_NAME, $registry, false );

		return true;
	}

	/**
	 * Derive the client encryption key from WordPress salts (versioned KDF).
	 */
	private static function derived_key(): string {
		$material = wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|pom-product-licenses|' . self::KDF_VERSION;

		return hash( 'sha256', $material, true );
	}

	/**
	 * @return array{cipher: string, nonce: string}|\WP_Error
	 */
	private static function encrypt( string $plaintext ) {
		$key = self::derived_key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			try {
				$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			} catch ( \Throwable $exception ) {
				return new \WP_Error( 'pom_license_encrypt_failed', 'License key encryption failed.' );
			}

			return array(
				'cipher' => 'sodium:' . base64_encode( $cipher ),
				'nonce'  => base64_encode( $nonce ),
			);
		}

		try {
			$nonce = random_bytes( 12 );
		} catch ( \Throwable $exception ) {
			return new \WP_Error( 'pom_license_encrypt_failed', 'License key encryption failed.' );
		}

		$tag    = '';
		$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );

		if ( false === $cipher ) {
			return new \WP_Error( 'pom_license_encrypt_failed', 'License key encryption failed.' );
		}

		return array(
			'cipher' => 'openssl:' . base64_encode( $cipher . $tag ),
			'nonce'  => base64_encode( $nonce ),
		);
	}

	private static function decrypt( string $envelope, string $nonce_base64 ): ?string {
		$key       = self::derived_key();
		$separator = strpos( $envelope, ':' );

		if ( false === $separator ) {
			return null;
		}

		$scheme = substr( $envelope, 0, $separator );
		$cipher = base64_decode( substr( $envelope, $separator + 1 ), true );
		$nonce  = base64_decode( $nonce_base64, true );

		if ( false === $cipher || false === $nonce ) {
			return null;
		}

		if ( 'sodium' === $scheme && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			try {
				$plaintext = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			} catch ( \Throwable $exception ) {
				return null;
			}

			return is_string( $plaintext ) ? $plaintext : null;
		}

		if ( 'openssl' === $scheme ) {
			if ( strlen( $cipher ) <= 16 ) {
				return null;
			}

			$tag       = substr( $cipher, -16 );
			$body      = substr( $cipher, 0, -16 );
			$plaintext = openssl_decrypt( $body, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );

			return is_string( $plaintext ) ? $plaintext : null;
		}

		return null;
	}
}
