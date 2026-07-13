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
 * - Exception: service-scoped products (POM AI) require a per-blog license on
 *   subsites. A subsite administrator sees a notice on their own site whenever
 *   that blog-scoped entry is missing or needs action. The network entry is
 *   reserved for plugin updates and never grants services to the subsite.
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
		$subsite_screen = is_multisite() && ! is_network_admin() && ! is_main_site();

		$missing = array();
		$invalid = array();

		foreach ( self::$products as $slug => $info ) {
			$blog_scoped_here = $subsite_screen && License_Registry::is_service_scoped( $slug );

			if ( ! self::current_user_can_see( $blog_scoped_here, $subsite_screen ) ) {
				continue;
			}

			$state = $blog_scoped_here ? self::blog_product_state( $slug ) : self::product_state( $slug );

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
				esc_url( self::settings_url( $info['url'], $subsite_screen ) )
			);
		}

		foreach ( $invalid as $info ) {
			$parts[] = sprintf(
				/* translators: 1: product name, 2: settings URL. */
				__( 'the %1$s license needs attention (<a href="%2$s">review it here</a>)', 'pom-framework' ),
				esc_html( $info['label'] ),
				esc_url( self::settings_url( $info['url'], $subsite_screen ) )
			);
		}

		echo wp_kses(
			implode( ' &middot; ', $parts ),
			array(
				'a' => array( 'href' => array() ),
			)
		);

		if ( $subsite_screen ) {
			// Subsite notices only ever contain blog-scoped service products.
			echo ' ' . esc_html__( 'Without a valid license this site cannot use the product’s services or credits. Plugin updates are managed from the network’s main site.', 'pom-framework' );
		} else {
			echo ' ' . esc_html__( 'Without a valid license the product does not receive private updates.', 'pom-framework' );
		}

		echo '</p></div>';
	}

	/**
	 * Whether the current user may see a notice for this product on this screen.
	 */
	private static function current_user_can_see( bool $blog_scoped_here, bool $subsite_screen ): bool {
		if ( ! is_multisite() ) {
			return current_user_can( 'manage_options' );
		}

		if ( $blog_scoped_here ) {
			// The subsite's own administrators can fix their blog-scoped key.
			return current_user_can( 'manage_options' );
		}

		// Network-scoped products: only the people who can fix a network
		// license, and never on subsite screens.
		if ( $subsite_screen || ! current_user_can( 'manage_network_options' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * State of a network/site-scoped product entry.
	 *
	 * @return string 'ok' | 'missing' | 'invalid'
	 */
	private static function product_state( string $product_slug, string $scope = '' ): string {
		$entry = License_Registry::get( $product_slug, $scope );

		if ( null === $entry || empty( $entry['key_cipher'] ) ) {
			return 'missing';
		}

		if ( License_Registry::needs_reconnect( $product_slug, $scope ) ) {
			return 'invalid';
		}

		$validation = License_Registry::get_validation( $product_slug, $scope );

		if ( ! is_array( $validation ) ) {
			// Key stored but never validated (save validates immediately, so
			// this is transient); do not nag.
			return 'ok';
		}

		if ( ! empty( $validation['valid'] ) ) {
			return 'ok';
		}

		if ( License_Registry::in_grace( $product_slug, $scope ) ) {
			// Account outage with previously valid state: no nagging.
			return 'ok';
		}

		$code = isset( $validation['error_code'] ) ? (string) $validation['error_code'] : '';

		return in_array( $code, self::ACTIONABLE_ERRORS, true ) ? 'invalid' : 'ok';
	}

	/**
	 * State of a blog-scoped service product on a subsite screen.
	 *
	 * @return string 'ok' | 'missing' | 'invalid'
	 */
	private static function blog_product_state( string $product_slug ): string {
		$entry = License_Registry::get( $product_slug, License_Registry::SCOPE_BLOG );

		if ( null === $entry || empty( $entry['key_cipher'] ) ) {
			return 'missing';
		}

		return self::product_state( $product_slug, License_Registry::SCOPE_BLOG );
	}

	/**
	 * Resolve the license tab URL. Network-scoped tabs live on the main
	 * site; blog-scoped service tabs live on the subsite being viewed.
	 */
	private static function settings_url( string $settings_path, bool $subsite_screen = false ): string {
		if ( is_multisite() && ! $subsite_screen ) {
			return get_admin_url( get_main_site_id(), $settings_path );
		}

		return admin_url( $settings_path );
	}
}
