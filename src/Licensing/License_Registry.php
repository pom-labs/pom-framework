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
	 * Read the full registry.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( is_multisite() ) {
			$registry = get_site_option( self::OPTION_NAME, array() );
		} else {
			$registry = get_option( self::OPTION_NAME, array() );
		}

		return is_array( $registry ) ? $registry : array();
	}

	/**
	 * Read one product entry.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $product_slug ): ?array {
		$registry = self::all();

		return isset( $registry[ $product_slug ] ) && is_array( $registry[ $product_slug ] ) ? $registry[ $product_slug ] : null;
	}

	/**
	 * Store a license key for a product.
	 *
	 * @return bool|\WP_Error
	 */
	public static function store_key( string $product_slug, string $license_key ) {
		$encrypted = self::encrypt( $license_key );

		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}

		$registry = self::all();

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

		return self::persist( $registry );
	}

	/**
	 * Retrieve the plaintext license key for a product.
	 *
	 * @return string|null Null when absent or undecryptable (reconnect needed).
	 */
	public static function get_key( string $product_slug ): ?string {
		$entry = self::get( $product_slug );

		if ( null === $entry || empty( $entry['key_cipher'] ) || empty( $entry['key_nonce'] ) ) {
			return null;
		}

		$plaintext = self::decrypt( (string) $entry['key_cipher'], (string) $entry['key_nonce'] );

		return is_string( $plaintext ) && '' !== $plaintext ? $plaintext : null;
	}

	/**
	 * Whether the entry exists but can no longer be decrypted (salts changed).
	 */
	public static function needs_reconnect( string $product_slug ): bool {
		$entry = self::get( $product_slug );

		if ( null === $entry || empty( $entry['key_cipher'] ) ) {
			return false;
		}

		return null === self::get_key( $product_slug );
	}

	/**
	 * Cache the last validation result for a product.
	 *
	 * @param array<string, mixed> $result Sanitized validation payload.
	 */
	public static function store_validation( string $product_slug, array $result, int $cache_ttl = DAY_IN_SECONDS ): void {
		$registry = self::all();

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

		self::persist( $registry );
	}

	/**
	 * Cached validation state for admin display and grace decisions.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_validation( string $product_slug ): ?array {
		$entry = self::get( $product_slug );

		return null !== $entry && isset( $entry['last_validation'] ) && is_array( $entry['last_validation'] ) ? $entry['last_validation'] : null;
	}

	/**
	 * Whether the cached state is still usable during an account outage.
	 */
	public static function in_grace( string $product_slug ): bool {
		$entry = self::get( $product_slug );

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
	 * Remove a product entry (unlink).
	 */
	public static function forget( string $product_slug ): void {
		$registry = self::all();

		unset( $registry[ $product_slug ] );

		self::persist( $registry );
	}

	/**
	 * The stable random network/site UUID sent with multisite payloads.
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
	 * @param array<string, array<string, mixed>> $registry Registry payload.
	 */
	private static function persist( array $registry ): bool {
		if ( is_multisite() ) {
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
