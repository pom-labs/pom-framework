<?php
/**
 * Combined wp-admin notices for missing or invalid POM product licenses.
 *
 * Product adapters register their product once; a single dismissible notice
 * lists every product that actually needs attention. The notice never nags
 * people who already pay correctly:
 *
 * - A stored key whose last validation is valid (or still inside the account
 *   outage grace window) produces no notice.
 * - On multisite the license registry is network-scoped, so a valid network
 *   license silences every subsite. When something IS missing, only network
 *   administrators see it, and only in the network admin or on the main
 *   site — subsite screens stay clean because subsite admins cannot fix it.
 * - Only deterministic failures (missing key, undecryptable key, invalid /
 *   expired / revoked / product mismatch) trigger the notice; transient
 *   network errors never do.
 * - No remote calls: everything is read from the cached registry state.
 */

namespace POMFramework\Licensing;

class License_Notices {

	/**
	 * @var array<string, array{label: string, url: string}>
	 */
	private static $products = array();

	/**
	 * Deterministic error codes that mean the license really needs action.
	 */
	private const ACTIONABLE_ERRORS = array(
		'invalid_license_key',
		'license_inactive',
		'license_expired',
		'product_mismatch',
		'domain_not_authorized',
		'domain_limit_reached',
		'network_not_authorized',
		'reconnect_required',
	);

	/**
	 * Register a product for license notices.
	 *
	 * @param string $product_slug  Canonical product slug.
	 * @param string $product_label Human product name.
	 * @param string $settings_path Relative admin path to the license tab,
	 *                              e.g. "options-general.php?page=pom-cache&section=license".
	 */
	public static function register( string $product_slug, string $product_label, string $settings_path ): void {
		self::$products[ $product_slug ] = array(
			'label' => $product_label,
			'url'   => $settings_path,
		);

		static $hooked = false;

		if ( ! $hooked ) {
			$hooked = true;

			add_action( 'admin_notices', array( __CLASS__, 'render' ) );
			add_action( 'network_admin_notices', array( __CLASS__, 'render' ) );
		}
	}

	public static function render(): void {
		if ( ! self::should_render_here() ) {
			return;
		}

		$missing = array();
		$invalid = array();

		foreach ( self::$products as $slug => $info ) {
			$state = self::product_state( $slug );

			if ( 'missing' === $state ) {
				$missing[ $slug ] = $info;
			} elseif ( 'invalid' === $state ) {
				$invalid[ $slug ] = $info;
			}
		}

		if ( empty( $missing ) && empty( $invalid ) ) {
			return;
		}

		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo '<strong>' . esc_html__( 'POM licenses', 'pom-framework' ) . ':</strong> ';

		$parts = array();

		foreach ( $missing as $info ) {
			$parts[] = sprintf(
				/* translators: 1: product name, 2: settings URL. */
				__( '%1$s has no license key yet (<a href="%2$s">add it here</a>)', 'pom-framework' ),
				esc_html( $info['label'] ),
				esc_url( self::settings_url( $info['url'] ) )
			);
		}

		foreach ( $invalid as $info ) {
			$parts[] = sprintf(
				/* translators: 1: product name, 2: settings URL. */
				__( 'the %1$s license needs attention (<a href="%2$s">review it here</a>)', 'pom-framework' ),
				esc_html( $info['label'] ),
				esc_url( self::settings_url( $info['url'] ) )
			);
		}

		echo wp_kses(
			implode( ' &middot; ', $parts ),
			array(
				'a' => array( 'href' => array() ),
			)
		);

		echo ' ' . esc_html__( 'Without a valid license the product does not receive private updates.', 'pom-framework' );
		echo '</p></div>';
	}

	/**
	 * Whether the current screen/user combination should ever see the notice.
	 */
	private static function should_render_here(): bool {
		if ( is_multisite() ) {
			// Only the people who can actually fix a network license, and never
			// on subsite screens: paying subsites must not be nagged.
			if ( ! current_user_can( 'manage_network_options' ) ) {
				return false;
			}

			return is_network_admin() || is_main_site();
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * @return string 'ok' | 'missing' | 'invalid'
	 */
	private static function product_state( string $product_slug ): string {
		$entry = License_Registry::get( $product_slug );

		if ( null === $entry || empty( $entry['key_cipher'] ) ) {
			return 'missing';
		}

		if ( License_Registry::needs_reconnect( $product_slug ) ) {
			return 'invalid';
		}

		$validation = License_Registry::get_validation( $product_slug );

		if ( ! is_array( $validation ) ) {
			// Key stored but never validated (save validates immediately, so
			// this is transient); do not nag.
			return 'ok';
		}

		if ( ! empty( $validation['valid'] ) ) {
			return 'ok';
		}

		if ( License_Registry::in_grace( $product_slug ) ) {
			// Account outage with previously valid state: no nagging.
			return 'ok';
		}

		$code = isset( $validation['error_code'] ) ? (string) $validation['error_code'] : '';

		return in_array( $code, self::ACTIONABLE_ERRORS, true ) ? 'invalid' : 'ok';
	}

	/**
	 * Resolve the license tab URL; on multisite the tabs live on the main
	 * site, which is also the only place the notice renders.
	 */
	private static function settings_url( string $settings_path ): string {
		if ( is_multisite() ) {
			return get_admin_url( get_main_site_id(), $settings_path );
		}

		return admin_url( $settings_path );
	}
}
