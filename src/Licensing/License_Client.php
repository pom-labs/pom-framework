<?php
/**
 * HTTP client for the account.pom.es pom-lic/v2 licensing API.
 *
 * Product plugins never build licensing requests themselves; they call this
 * client through their thin adapters so payload composition, domain
 * normalization, and error handling stay identical across products.
 */

namespace POMFramework\Licensing;

class License_Client {

	const API_BASE = 'https://account.pom.es/wp-json/pom-lic/v2';

	/**
	 * Resolve the API base, overridable for local/staging account servers.
	 */
	public static function api_base(): string {
		$base = apply_filters( 'pom_license_api_base', self::API_BASE );

		return is_string( $base ) && '' !== $base ? untrailingslashit( $base ) : self::API_BASE;
	}

	/**
	 * Current site payload context shared by every licensing call.
	 *
	 * @return array<string, string>
	 */
	public static function site_context(): array {
		$home = is_multisite() ? network_home_url( '/' ) : home_url( '/' );
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return array(
			'domain'       => is_string( $host ) ? $host : '',
			'site_url'     => home_url( '/' ),
			'network_url'  => $home,
			'network_uuid' => License_Registry::ensure_network_uuid(),
			'multisite'    => is_multisite() ? '1' : '0',
			'environment'  => self::detect_environment(),
			'wp_version'   => get_bloginfo( 'version' ),
			'php_version'  => PHP_VERSION,
		);
	}

	/**
	 * Validate a stored license and optionally activate the current domain.
	 *
	 * @return array<string, mixed> Decoded response; error_code network_error on transport failure.
	 */
	public static function validate( string $product_slug, string $license_key, bool $activate_domain = false, string $version = '' ): array {
		$payload = array_merge(
			self::site_context(),
			array(
				'license_key' => $license_key,
				'product'     => $product_slug,
				'version'     => $version,
			)
		);

		$route = $activate_domain ? '/domains/activate' : '/license/validate';

		return self::post( $route, $payload );
	}

	/**
	 * Full status including authorized domains (admin screens).
	 *
	 * @return array<string, mixed>
	 */
	public static function status( string $product_slug, string $license_key ): array {
		return self::post(
			'/license/status',
			array_merge(
				self::site_context(),
				array(
					'license_key' => $license_key,
					'product'     => $product_slug,
				)
			)
		);
	}

	/**
	 * Deactivate the current domain (unlink flow).
	 *
	 * @return array<string, mixed>
	 */
	public static function deactivate_domain( string $product_slug, string $license_key ): array {
		return self::post(
			'/domains/deactivate',
			array_merge(
				self::site_context(),
				array(
					'license_key' => $license_key,
					'product'     => $product_slug,
				)
			)
		);
	}

	/**
	 * Request licensed private-update metadata and a short-lived token.
	 *
	 * @return array<string, mixed>
	 */
	public static function check_update( string $product_slug, string $license_key, string $artifact_type, string $artifact_slug, string $current_version ): array {
		return self::post(
			'/updates/check',
			array_merge(
				self::site_context(),
				array(
					'license_key'     => $license_key,
					'product'         => $product_slug,
					'artifact_type'   => $artifact_type,
					'artifact_slug'   => $artifact_slug,
					'current_version' => $current_version,
				)
			)
		);
	}

	/**
	 * Validate, cache the result in the shared registry, and return it.
	 *
	 * @return array<string, mixed>
	 */
	public static function validate_and_cache( string $product_slug, bool $activate_domain = false, string $version = '' ): array {
		$license_key = License_Registry::get_key( $product_slug );

		if ( null === $license_key ) {
			return array(
				'valid'      => false,
				'error_code' => License_Registry::needs_reconnect( $product_slug ) ? 'reconnect_required' : 'missing_license_key',
			);
		}

		$result = self::validate( $product_slug, $license_key, $activate_domain, $version );

		if ( 'network_error' !== ( $result['error_code'] ?? '' ) ) {
			License_Registry::store_validation( $product_slug, $result, (int) ( $result['cache_ttl'] ?? DAY_IN_SECONDS ) );
		}

		return $result;
	}

	/**
	 * Cached-first validation for non-critical admin display.
	 *
	 * Never blocks on the network when a fresh-enough cached result exists.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function cached_or_revalidate( string $product_slug ): ?array {
		$entry = License_Registry::get( $product_slug );

		if ( null === $entry ) {
			return null;
		}

		$validation = License_Registry::get_validation( $product_slug );
		$next_check = (int) ( $entry['next_check_at'] ?? 0 );

		if ( is_array( $validation ) && $next_check > time() ) {
			return $validation;
		}

		$result = self::validate_and_cache( $product_slug );

		if ( 'network_error' === ( $result['error_code'] ?? '' ) && is_array( $validation ) && License_Registry::in_grace( $product_slug ) ) {
			// Account outage: keep the previously known valid state within grace.
			return $validation;
		}

		return License_Registry::get_validation( $product_slug );
	}

	/**
	 * POST JSON with bounded timeout and safe decoding.
	 *
	 * @param array<string, mixed> $payload Request payload.
	 * @return array<string, mixed>
	 */
	private static function post( string $route, array $payload ): array {
		$body        = wp_json_encode( $payload );
		$license_key = isset( $payload['license_key'] ) ? (string) $payload['license_key'] : '';
		$headers     = array( 'Content-Type' => 'application/json' );

		if ( '' !== $license_key ) {
			$signed = self::signed_headers( 'POST', '/pom-lic/v2' . $route, (string) $body, $license_key, $headers );
			if ( is_wp_error( $signed ) ) {
				return array(
					'valid'      => false,
					'error_code' => 'signing_error',
					'message'    => $signed->get_error_message(),
				);
			}

			$headers = $signed;
		}

		$response = wp_remote_post(
			self::api_base() . $route,
			array(
				'timeout' => 15,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'valid'      => false,
				'error_code' => 'network_error',
				'message'    => $response->get_error_message(),
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return array(
				'valid'      => false,
				'error_code' => 'network_error',
				'message'    => 'Invalid response from the license server.',
			);
		}

		return $data;
	}

	/**
	 * Sign an exact HTTP body for a POM REST route.
	 *
	 * @param array<string, string> $headers Existing headers to preserve.
	 * @return array<string, string>|\WP_Error
	 */
	public static function signed_headers( string $method, string $route, string $body, string $license_key, array $headers = array() ) {
		try {
			$nonce = rtrim( strtr( base64_encode( random_bytes( 24 ) ), '+/', '-_' ), '=' );
		} catch ( \Throwable $exception ) {
			return new \WP_Error( 'pom_license_signing_failed', 'The request nonce could not be generated.' );
		}

		$timestamp = (string) time();
		$route     = '/' . ltrim( $route, '/' );
		$base      = strtoupper( $method ) . "\n" . $route . "\n" . $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', $body );

		$headers['X-POM-License-Key'] = $license_key;
		$headers['X-POM-Timestamp']   = $timestamp;
		$headers['X-POM-Nonce']       = $nonce;
		$headers['X-POM-Signature']   = hash_hmac( 'sha256', $base, $license_key );

		return $headers;
	}

	/**
	 * Conservative client-side environment hint.
	 */
	private static function detect_environment(): string {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			$type = wp_get_environment_type();

			if ( in_array( $type, array( 'local', 'development', 'staging', 'production' ), true ) ) {
				return $type;
			}
		}

		return 'production';
	}
}
