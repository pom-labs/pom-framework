<?php
/**
 * Reusable license settings UI for POM product adapters.
 *
 * Renders one license section per product (masked key after save, status,
 * validate / reconnect / unlink actions) and processes its admin-post
 * actions. Product adapters only supply their canonical slug and where to
 * render; they never invent product-specific key options.
 *
 * Scope: the field always manages the product's management scope. On a
 * multisite subsite a service-scoped product (POM AI) manages the subsite's
 * own blog entry — site administrators may edit it — while every other
 * product manages the network entry and stays network-admin only.
 */

namespace POMFramework\Licensing;

class License_Field {

	/**
	 * Register the shared admin-post handler once.
	 *
	 * Product adapters must call this on every admin request (the license
	 * form posts to admin-post.php, which never fires settings page hooks).
	 */
	public static function boot(): void {
		static $booted = false;

		if ( $booted ) {
			return;
		}

		$booted = true;

		add_action( 'admin_post_pom_license_field_action', array( __CLASS__, 'handle_action' ) );
	}

	/**
	 * A complete "License" section for a POM Framework settings definition.
	 *
	 * Usage from a product adapter:
	 *   $settings['license'] = License_Field::settings_section( 'pom-cache', 'POM Cache' );
	 *
	 * The section renders at .../options-general.php?page=<page>&section=license.
	 *
	 * @param array<string, mixed> $args Extra render() args (description, redirect).
	 * @return array<string, mixed>
	 */
	public static function settings_section( string $product_slug, string $product_label, array $args = array() ): array {
		return array(
			'title'         => __( 'License', 'pom-framework' ),
			'allowed_roles' => array( 'administrator' ),
			'tab'           => array(
				'license' => self::settings_tab( $product_slug, $product_label, $args ),
			),
		);
	}

	/**
	 * A single license tab for consumers that already own a settings section.
	 *
	 * @param array<string, mixed> $args Extra render() args.
	 * @return array<string, mixed>
	 */
	public static function settings_tab( string $product_slug, string $product_label, array $args = array() ): array {
		return array(
			'title'           => sprintf( '%s license', $product_label ),
			'description'     => '',
			'settings'        => array(),
			'render_callback' => static function () use ( $product_slug, $product_label, $args ): void {
				if ( License_Registry::SCOPE_BLOG === License_Registry::management_scope( $product_slug ) ) {
					$defaults = array(
						'title'       => sprintf(
							/* translators: %s: product name. */
							__( '%s license for this site', 'pom-framework' ),
							$product_label
						),
						'description' => sprintf(
							/* translators: %s: product name. */
							__( 'This license authorizes this domain to use %s services and consume credits from the license owner’s account. Plugin updates are managed separately from the multisite network’s main site.', 'pom-framework' ),
							$product_label
						),
					);
				} else {
					$defaults = array(
						'title'       => sprintf( '%s license', $product_label ),
						'description' => sprintf(
							/* translators: %s: product name. */
							__( 'Paste the %s license key from account.pom.es (My Account > Licenses & Downloads). The license authorizes this domain, private downloads, and automatic updates.', 'pom-framework' ),
							$product_label
						),
					);
				}

				$defaults['label'] = $product_label;

				self::render( $product_slug, array_merge( $defaults, $args ) );
			},
		);
	}

	/**
	 * Whether the current user may manage this product's license.
	 *
	 * Network-scoped keys require a network administrator; blog-scoped
	 * service keys (POM AI on a subsite) belong to that site's own
	 * administrators, who may be third parties.
	 */
	public static function current_user_can_manage( string $product_slug = '' ): bool {
		if ( ! is_multisite() ) {
			return current_user_can( 'manage_options' );
		}

		if ( '' !== $product_slug && License_Registry::SCOPE_BLOG === License_Registry::management_scope( $product_slug ) ) {
			return current_user_can( 'manage_options' );
		}

		return current_user_can( 'manage_network_options' );
	}

	/**
	 * Render the license panel for a product.
	 *
	 * @param array<string, mixed> $args title, description, label, redirect (return URL).
	 */
	public static function render( string $product_slug, array $args = array() ): void {
		self::boot();

		$scope      = License_Registry::management_scope( $product_slug );
		$blog_scope = License_Registry::SCOPE_BLOG === $scope;
		$entry      = License_Registry::get( $product_slug, $scope );
		$validation = License_Registry::get_validation( $product_slug, $scope );
		$reconnect  = License_Registry::needs_reconnect( $product_slug, $scope );
		$can_manage = self::current_user_can_manage( $product_slug );
		$redirect   = isset( $args['redirect'] ) ? (string) $args['redirect'] : ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : admin_url() );
		$title      = isset( $args['title'] ) ? (string) $args['title'] : __( 'Product license', 'pom-framework' );
		$label      = isset( $args['label'] ) ? (string) $args['label'] : $product_slug;

		if ( isset( $_GET['pom_license_notice'] ) ) {
			self::render_notice( sanitize_key( wp_unslash( $_GET['pom_license_notice'] ) ) );
		}

		?>
		<div class="pom-license-field" style="border:1px solid #ccd0d4;background:#fff;padding:16px;max-width:720px;margin:12px 0;">
			<h3 style="margin-top:0;"><?php echo esc_html( $title ); ?></h3>

			<?php if ( ! empty( $args['description'] ) ) : ?>
				<p class="description"><?php echo esc_html( (string) $args['description'] ); ?></p>
			<?php endif; ?>

			<?php if ( $blog_scope ) : ?>
				<p class="description" style="border-left:4px solid #72aee6;padding:6px 10px;background:#f0f6fc;">
					<?php
					printf(
						/* translators: %s: product name. */
						esc_html__( 'Automatic plugin updates require %s to be active and licensed on the network’s main site. The license saved here authorizes this domain to consume credits from its owner’s account.', 'pom-framework' ),
						esc_html( $label )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $reconnect ) : ?>
				<p style="color:#b32d2e;"><strong><?php esc_html_e( 'The stored license could not be read (site secrets changed). Paste the license key again to reconnect.', 'pom-framework' ); ?></strong></p>
			<?php endif; ?>

			<?php if ( null !== $entry && ! $reconnect ) : ?>
				<p>
					<strong><?php esc_html_e( 'License key:', 'pom-framework' ); ?></strong>
					<code><?php echo esc_html( (string) ( $entry['masked'] ?? '' ) ); ?></code>
				</p>
				<?php if ( is_array( $validation ) ) : ?>
					<p>
						<strong><?php esc_html_e( 'Status:', 'pom-framework' ); ?></strong>
						<?php if ( ! empty( $validation['valid'] ) ) : ?>
							<span style="color:#00701a;">&#10003; <?php echo esc_html( (string) ( $validation['license_status'] ?? 'active' ) ); ?></span>
						<?php else : ?>
							<span style="color:#b32d2e;">&#10007; <?php echo esc_html( (string) ( $validation['error_code'] ?? 'invalid' ) ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $validation['plan'] ) ) : ?>
							— <?php esc_html_e( 'Plan:', 'pom-framework' ); ?> <?php echo esc_html( (string) $validation['plan'] ); ?>
						<?php endif; ?>
						<?php if ( ! empty( $validation['expires_at'] ) ) : ?>
							— <?php esc_html_e( 'Expires:', 'pom-framework' ); ?> <?php echo esc_html( (string) $validation['expires_at'] ); ?>
						<?php endif; ?>
						<?php if ( ! empty( $validation['domain'] ) ) : ?>
							— <?php esc_html_e( 'Domain:', 'pom-framework' ); ?> <?php echo esc_html( (string) $validation['domain'] ); ?>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( ! $can_manage ) : ?>
				<p class="description">
					<?php
					if ( $blog_scope ) {
						esc_html_e( 'Only administrators of this site can change this license.', 'pom-framework' );
					} else {
						esc_html_e( 'Only network administrators can change this license.', 'pom-framework' );
					}
					?>
				</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px;">
					<input type="hidden" name="action" value="pom_license_field_action">
					<input type="hidden" name="pom_license_do" value="save">
					<input type="hidden" name="product" value="<?php echo esc_attr( $product_slug ); ?>">
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">
					<?php wp_nonce_field( 'pom_license_field_' . $product_slug ); ?>
					<input type="password" name="license_key" style="width:380px;" placeholder="<?php echo esc_attr( 'pom_' . $product_slug . '_…' ); ?>" autocomplete="off">
					<button type="submit" class="button button-primary">
						<?php echo null === $entry || $reconnect ? esc_html__( 'Save and validate', 'pom-framework' ) : esc_html__( 'Replace and validate', 'pom-framework' ); ?>
					</button>
				</form>

				<?php if ( null !== $entry && ! $reconnect ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<input type="hidden" name="action" value="pom_license_field_action">
						<input type="hidden" name="pom_license_do" value="validate">
						<input type="hidden" name="product" value="<?php echo esc_attr( $product_slug ); ?>">
						<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">
						<?php wp_nonce_field( 'pom_license_field_' . $product_slug ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Revalidate', 'pom-framework' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Unlink this license from this site?', 'pom-framework' ) ); ?>');">
						<input type="hidden" name="action" value="pom_license_field_action">
						<input type="hidden" name="pom_license_do" value="unlink">
						<input type="hidden" name="product" value="<?php echo esc_attr( $product_slug ); ?>">
						<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">
						<?php wp_nonce_field( 'pom_license_field_' . $product_slug ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Unlink', 'pom-framework' ); ?></button>
					</form>
				<?php endif; ?>

				<p class="description"><?php esc_html_e( 'You can copy or rotate your license key from My Account on account.pom.es. Keys are shown masked after saving.', 'pom-framework' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Process save / validate / unlink actions.
	 *
	 * Every action is pinned to the product's management scope: a subsite
	 * request can only ever create, revalidate, or remove the subsite's own
	 * blog entry, never the network key used for plugin updates.
	 */
	public static function handle_action(): void {
		$product_slug = isset( $_POST['product'] ) ? sanitize_key( wp_unslash( $_POST['product'] ) ) : '';
		$do           = isset( $_POST['pom_license_do'] ) ? sanitize_key( wp_unslash( $_POST['pom_license_do'] ) ) : '';
		$redirect     = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url();

		if ( '' === $product_slug ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		check_admin_referer( 'pom_license_field_' . $product_slug );

		if ( ! self::current_user_can_manage( $product_slug ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this license.', 'pom-framework' ) );
		}

		$scope  = License_Registry::management_scope( $product_slug );
		$notice = 'error';

		if ( 'save' === $do ) {
			$license_key = isset( $_POST['license_key'] ) ? trim( (string) wp_unslash( $_POST['license_key'] ) ) : '';

			if ( '' === $license_key ) {
				$notice = 'empty';
			} else {
				// Validate the candidate before replacing the stored key so a typo
				// never destroys a previously working local connection.
				$result = License_Client::validate( $product_slug, $license_key, true );

				if ( empty( $result['valid'] ) ) {
					$notice = 'invalid';
				} else {
					$stored = License_Registry::store_key( $product_slug, $license_key, $scope );

					if ( is_wp_error( $stored ) ) {
						$notice = 'error';
					} else {
						License_Registry::store_validation( $product_slug, $result, (int) ( $result['cache_ttl'] ?? DAY_IN_SECONDS ), $scope );
						$notice = 'valid';

						/**
						 * Fires after a product license was saved and validated.
						 *
						 * Product adapters use this to complete migrations (e.g.
						 * POM AI deletes its legacy key option).
						 *
						 * @param string               $product_slug Product slug.
						 * @param array<string, mixed> $result       Validation result.
						 */
						do_action( 'pom_license_saved', $product_slug, $result );
					}
				}
			}
		} elseif ( 'validate' === $do ) {
			$license_key = License_Registry::get_key( $product_slug, $scope );

			if ( null === $license_key ) {
				$notice = 'invalid';
			} else {
				$result = License_Client::validate( $product_slug, $license_key, true );

				if ( 'network_error' !== ( $result['error_code'] ?? '' ) ) {
					License_Registry::store_validation( $product_slug, $result, (int) ( $result['cache_ttl'] ?? DAY_IN_SECONDS ), $scope );
				}

				$notice = ! empty( $result['valid'] ) ? 'valid' : 'invalid';
			}
		} elseif ( 'unlink' === $do ) {
			$license_key = License_Registry::get_key( $product_slug, $scope );

			if ( null !== $license_key ) {
				$result = License_Client::deactivate_domain( $product_slug, $license_key );

				if ( empty( $result['valid'] ) ) {
					$notice = 'unlink_failed';
					wp_safe_redirect( add_query_arg( 'pom_license_notice', $notice, $redirect ) );
					exit;
				}
			}

			License_Registry::forget( $product_slug, $scope );
			$notice = 'unlinked';
		}

		wp_safe_redirect( add_query_arg( 'pom_license_notice', $notice, $redirect ) );
		exit;
	}

	private static function render_notice( string $notice ): void {
		$map = array(
			'valid'    => array( 'notice-success', __( 'License validated. This domain is authorized.', 'pom-framework' ) ),
			'invalid'  => array( 'notice-error', __( 'The license could not be validated. Check the key, your subscription, and the authorized domains in My Account.', 'pom-framework' ) ),
			'empty'    => array( 'notice-warning', __( 'Paste a license key before saving.', 'pom-framework' ) ),
			'unlinked'     => array( 'notice-info', __( 'The license was unlinked from this site.', 'pom-framework' ) ),
			'unlink_failed' => array( 'notice-error', __( 'The license server could not deactivate this domain. The local key was kept so you can try again safely.', 'pom-framework' ) ),
			'error'    => array( 'notice-error', __( 'The license could not be stored.', 'pom-framework' ) ),
		);

		$config = $map[ $notice ] ?? null;

		if ( null === $config ) {
			return;
		}

		printf( '<div class="notice %s"><p>%s</p></div>', esc_attr( $config[0] ), esc_html( $config[1] ) );
	}
}
