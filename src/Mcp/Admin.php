<?php

namespace POMFramework\Mcp;

/** Own site settings and credential lifecycle through the standard WordPress admin. */
final class Admin {
	private const PAGE = 'pom-mcp';
	private const ACTION = 'pom_mcp_settings';
	private Bootstrap $bootstrap;

	public function __construct( Bootstrap $bootstrap ) {
		$this->bootstrap = $bootstrap;
	}

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function menu(): void {
		$page = add_options_page( 'POM MCP', 'POM MCP', 'manage_options', self::PAGE, array( $this, 'render' ) );
		if ( $page ) {
			add_action( 'admin_enqueue_scripts', static function ( string $hook ) use ( $page ): void {
				if ( $hook === $page ) { self::styles(); }
			} );
		}
	}

	private static function styles(): void {
		wp_register_style( 'pom-mcp-admin', false );
		wp_enqueue_style( 'pom-mcp-admin' );
		wp_add_inline_style( 'pom-mcp-admin', <<<'CSS'
.pom-mcp-admin { max-width: 1080px; }
.pom-mcp-admin .pom-mcp-panel { margin: 16px 0; padding: 20px; border: 1px solid #dcdcde; border-radius: 6px; background: #fff; }
.pom-mcp-admin .pom-mcp-panel > :first-child { margin-top: 0; }
.pom-mcp-admin .pom-mcp-panel > :last-child { margin-bottom: 0; }
.pom-mcp-admin .pom-mcp-connection { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 12px; }
.pom-mcp-admin .pom-mcp-endpoint { display: inline-block; padding: 8px 10px; overflow-wrap: anywhere; }
.pom-mcp-admin .pom-mcp-pill { display: inline-block; padding: 2px 9px; border-radius: 999px; background: #f0f0f1; color: #1d2327; }
.pom-mcp-admin .pom-mcp-pill-active { background: #edfaef; color: #135e26; }
.pom-mcp-admin .pom-mcp-pill-error { background: #fcf0f1; color: #8a2424; }
.pom-mcp-admin .pom-mcp-products { display: flex; flex-wrap: wrap; gap: 5px; }
.pom-mcp-admin .pom-mcp-products li { margin: 0; }
.pom-mcp-admin .pom-mcp-permissions { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); gap: 6px; }
.pom-mcp-admin .pom-mcp-permissions label { padding: 9px 10px; border: 1px solid #dcdcde; border-radius: 4px; }
.pom-mcp-admin .pom-mcp-scroll { overflow-x: auto; }
.pom-mcp-admin .pom-mcp-log code { overflow-wrap: anywhere; }
.pom-mcp-admin .pom-mcp-log th:first-child { min-width: 125px; }
.pom-mcp-admin .pom-mcp-actions { display: flex; flex-wrap: wrap; gap: 8px; }
@media (max-width: 782px) { .pom-mcp-admin .pom-mcp-panel { padding: 14px; } }
CSS
		);
	}

	private static function url(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			wp_die( esc_html__( 'You cannot change POM MCP settings on this site.', 'pom-framework' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$action = sanitize_key( self::field( 'operation' ) );
		if ( $action === 'download_skill' ) {
			$this->download_skill();
		}
		$storage = new Storage();
		switch ( $action ) {
			case 'save':
				$this->save( $storage );
				( new Log( $storage ) )->record( 'settings_saved', 'success', self::admin_actor() );
				break;
			case 'rotate_key':
				$this->rotate_key( $storage );
				break;
			case 'revoke_key':
				delete_option( Settings::API_KEY );
				if ( get_option( Settings::API_KEY, false ) !== false ) {
					wp_die( esc_html__( 'The API key could not be revoked.', 'pom-framework' ) );
				}
				( new Log( $storage ) )->record( 'api_key_revoke', 'success', self::admin_actor() );
				break;
			case 'revoke_agent':
				if ( ! $storage->ready() ) {
					wp_die( esc_html__( 'POM MCP connection storage is unavailable.', 'pom-framework' ) );
				}
				$result = ( new TokenStore( $storage ) )->revoke_authorized_client( sanitize_text_field( self::field( 'client' ) ) );
				if ( is_wp_error( $result ) ) {
					wp_die( esc_html( $result->get_error_message() ) );
				}
				( new Log( $storage ) )->record( 'oauth_revoke_admin', 'success', self::admin_actor() );
				break;
			default:
				wp_die( esc_html__( 'Unknown POM MCP settings action.', 'pom-framework' ), '', array( 'response' => 400 ) );
		}
		wp_safe_redirect( add_query_arg( 'pom_mcp_saved', '1', self::url() ), 303 );
		exit;
	}

	private function download_skill(): void {
		$name = sanitize_key( self::field( 'skill' ) );
		$skill = $this->bootstrap->skills()[ $name ] ?? null;
		if ( ! $skill ) {
			wp_die( esc_html__( 'This skill is unavailable on this site.', 'pom-framework' ), '', array( 'response' => 404 ) );
		}
		$archive = $skill->zip();
		if ( is_wp_error( $archive ) ) {
			wp_die( esc_html( $archive->get_error_message() ), '', array( 'response' => 503 ) );
		}
		RequestPolicy::send_no_store();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $skill->name . '.zip"' );
		header( 'Content-Length: ' . strlen( $archive ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $archive;
		exit;
	}

	private function save( Storage $storage ): void {
		$current = Settings::get();
		$permissions = (array) $current['write_permissions'];
		$posted = (array) ( $_POST['write_permissions'] ?? array() );
		foreach ( $this->bootstrap->write_permissions() as $group => $label ) {
			// The hidden input preserves an unchecked value; missing groups may be inactive.
			if ( in_array( $posted[ $group ] ?? null, array( '0', '1' ), true ) ) {
				$permissions[ $group ] = $posted[ $group ] === '1';
			}
		}
		$settings = array(
			'enabled' => self::field( 'enabled' ) === '1',
			'oauth' => self::field( 'oauth' ) === '1',
			'writes' => self::field( 'writes' ) === '1',
			'write_permissions' => $permissions,
			'network_destination' => is_multisite() && ! is_main_site() && self::field( 'network_destination' ) !== '' ? self::field( 'network_destination' ) === '1' : (bool) $current['network_destination'],
			'account_destination' => ! AccountGateway::registered() && self::field( 'account_destination' ) === '1',
			'api_key' => ! AccountGateway::registered() && self::field( 'api_key' ) === '1',
			'service_user' => absint( self::field( 'service_user' ) ),
			'rate_limit' => max( 1, min( 600, absint( self::field( 'rate_limit' ) ) ) ),
			'origins' => array(),
		);
		$origins = preg_split( '/\R/', trim( substr( self::field( 'origins' ), 0, 4096 ) ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $origins ?: array() as $origin ) {
			$origin = trim( $origin );
			$canonical = RequestPolicy::origin_from_url( $origin );
			if ( $canonical === '' || $canonical !== untrailingslashit( $origin ) || count( $settings['origins'] ) >= 20 ) {
				wp_die( esc_html__( 'Enter up to 20 exact origins using their scheme, host and optional port.', 'pom-framework' ), '', array( 'response' => 400 ) );
			}
			$settings['origins'][] = $canonical;
		}
		$settings['origins'] = array_values( array_unique( $settings['origins'] ) );
		if ( $settings['api_key'] && ! Settings::actor_can_connect( $settings['service_user'] ) ) {
			wp_die( esc_html__( 'Choose an existing service user with access to this site.', 'pom-framework' ), '', array( 'response' => 400 ) );
		}
		if ( $settings['enabled'] ) {
			$requirements = $this->bootstrap->requirements();
			if ( $requirements !== '' ) {
				wp_die( esc_html( $requirements ), '', array( 'response' => 400 ) );
			}
			if ( ! $settings['api_key'] && ( ! $settings['oauth'] || ! get_option( 'permalink_structure' ) ) ) {
				wp_die( esc_html__( 'Enable API key access or turn on OAuth with pretty permalinks.', 'pom-framework' ), '', array( 'response' => 400 ) );
			}
			$result = $storage->install();
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ) );
			}
			if ( Settings::identity() === '' ) {
				wp_die( esc_html__( 'POM MCP could not create a valid site identity.', 'pom-framework' ) );
			}
		}
		update_option( Settings::OPTION, $settings, true );
		if ( get_option( Settings::OPTION ) !== $settings ) {
			wp_die( esc_html__( 'POM MCP settings could not be saved.', 'pom-framework' ) );
		}
	}

	private function rotate_key( Storage $storage ): void {
		$settings = Settings::get();
		$user_id = (int) $settings['service_user'];
		if ( ! $settings['enabled'] || ! $settings['api_key'] || ! $storage->ready() || ! Settings::actor_can_connect( $user_id ) ) {
			wp_die( esc_html__( 'Enable API key access and save a valid service user first.', 'pom-framework' ), '', array( 'response' => 400 ) );
		}
		$key = Pkce::random_token( 'pommcp_key_' );
		$record = array( 'hash' => Server::api_key_hash( $key ), 'last4' => substr( $key, -4 ), 'user' => $user_id );
		if ( ! update_option( Settings::API_KEY, $record, false ) ) {
			wp_die( esc_html__( 'The API key could not be saved.', 'pom-framework' ) );
		}
		( new Log( $storage ) )->record( 'api_key_rotate', 'success', self::admin_actor() );
		RequestPolicy::send_no_store();
		wp_die(
			'<p>' . esc_html__( 'Copy this key now. You will only see it once.', 'pom-framework' ) . '</p><p><code>'
			. esc_html( $key ) . '</code></p><p><a href="' . esc_url( self::url() ) . '">'
			. esc_html__( 'Return to POM MCP', 'pom-framework' ) . '</a></p>',
			esc_html__( 'POM MCP API key', 'pom-framework' ),
			array( 'response' => 200 )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage POM MCP on this site.', 'pom-framework' ), '', array( 'response' => 403 ) );
		}
		$settings = Settings::get();
		$requirements = $this->bootstrap->requirements();
		$problem = $this->bootstrap->problem() ?: $requirements;
		$storage = new Storage();
		$active = $settings['enabled'] && $problem === '';
		?>
		<div class="wrap pom-mcp-admin">
			<h1>POM MCP</h1>
			<?php if ( isset( $_GET['pom_mcp_saved'] ) && $_GET['pom_mcp_saved'] === '1' ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'POM MCP settings updated.', 'pom-framework' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $problem !== '' ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $problem ); ?></p></div>
			<?php endif; ?>
			<section class="pom-mcp-panel" aria-labelledby="pom-mcp-connection-heading">
				<div class="pom-mcp-connection">
					<h2 id="pom-mcp-connection-heading"><?php esc_html_e( 'Connection', 'pom-framework' ); ?></h2>
					<span class="pom-mcp-pill <?php echo $active ? 'pom-mcp-pill-active' : ''; ?>"><?php echo $active ? esc_html__( 'Enabled', 'pom-framework' ) : esc_html__( 'Inactive', 'pom-framework' ); ?></span>
				</div>
				<p><?php echo esc_html( sprintf( __( 'Connection controller: %s', 'pom-framework' ), AccountGateway::label() ?: 'POM Framework' ) ); ?></p>
				<p><code class="pom-mcp-endpoint"><?php echo esc_html( Settings::endpoint() ); ?></code></p>
				<ul class="pom-mcp-products" aria-label="<?php esc_attr_e( 'Active MCP products', 'pom-framework' ); ?>">
					<?php foreach ( $this->bootstrap->providers() as $label ) : ?>
						<li class="pom-mcp-pill"><?php echo esc_html( $label ); ?></li>
					<?php endforeach; ?>
				</ul>
			</section>
			<section class="pom-mcp-panel" aria-labelledby="pom-mcp-settings-heading">
				<h2 id="pom-mcp-settings-heading"><?php esc_html_e( 'Settings', 'pom-framework' ); ?></h2>
				<?php $this->render_settings( $settings, $requirements ); ?>
			</section>
			<?php $this->render_activity( $storage ); ?>
			<?php if ( $storage->ready() ) : ?>
				<section class="pom-mcp-panel" aria-labelledby="pom-mcp-credentials-heading">
					<?php $this->render_connections( $settings, $storage ); ?>
				</section>
			<?php endif; ?>
			<?php $this->render_skills(); ?>
		</div>
		<?php
	}

	private function render_skills(): void {
		$skills = $this->bootstrap->skills();
		if ( ! $skills ) { return; }
		?>
		<section class="pom-mcp-panel" aria-labelledby="pom-mcp-skills-heading">
		<h2 id="pom-mcp-skills-heading"><?php esc_html_e( 'Skills', 'pom-framework' ); ?></h2>
		<p><?php esc_html_e( 'Download a ZIP to install a skill in your AI client, or ask a connected agent to read it with pom_list_skills and pom_get_skill.', 'pom-framework' ); ?></p>
		<table class="widefat striped">
			<thead><tr><th scope="col"><?php esc_html_e( 'Skill', 'pom-framework' ); ?></th><th scope="col"><?php esc_html_e( 'Download', 'pom-framework' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $skills as $skill ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $skill->label ); ?></th>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php self::form_fields( 'download_skill' ); ?>
								<input type="hidden" name="skill" value="<?php echo esc_attr( $skill->name ); ?>">
								<button type="submit" class="button" aria-label="<?php echo esc_attr( sprintf( __( 'Download %s ZIP', 'pom-framework' ), $skill->label ) ); ?>"><?php esc_html_e( 'Download ZIP', 'pom-framework' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</section>
		<?php
	}

	private function render_settings( array $settings, string $requirements ): void {
		$gateway = AccountGateway::registered();
		$permissions = $this->bootstrap->write_permissions();
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<?php self::form_fields( 'save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Server', 'pom-framework' ); ?></th>
					<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?>> <?php esc_html_e( 'Enable POM MCP', 'pom-framework' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Authentication', 'pom-framework' ); ?></th>
					<td>
						<label><input type="checkbox" name="oauth" value="1" <?php checked( $settings['oauth'] ); ?>> <?php esc_html_e( 'Allow OAuth', 'pom-framework' ); ?></label>
						<?php if ( ! $gateway ) : ?><br><label><input type="checkbox" name="api_key" value="1" <?php checked( $settings['api_key'] ); ?>> <?php esc_html_e( 'Allow API key', 'pom-framework' ); ?></label><?php endif; ?>
						<?php if ( ! get_option( 'permalink_structure' ) ) : ?>
							<p class="description"><?php esc_html_e( 'OAuth requires pretty permalinks.', 'pom-framework' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Reviewed changes', 'pom-framework' ); ?></th>
					<td><label><input type="checkbox" name="writes" value="1" <?php checked( $settings['writes'] ); ?>> <?php esc_html_e( 'Allow reviewed changes through OAuth', 'pom-framework' ); ?></label><p class="description"><?php echo esc_html( $gateway ? __( 'Each change requires authorization and approval on its destination website.', 'pom-framework' ) : __( 'Each change requires approval and the connected account’s current WordPress permissions.', 'pom-framework' ) ); ?></p></td>
				</tr>
				<?php if ( $permissions ) : ?>
				<tr>
					<th scope="row" id="pom-mcp-permissions-heading"><?php esc_html_e( 'Write permissions', 'pom-framework' ); ?></th>
					<td>
						<fieldset class="pom-mcp-permissions" aria-labelledby="pom-mcp-permissions-heading" aria-describedby="pom-mcp-permissions-description">
							<?php foreach ( $permissions as $group => $label ) : ?>
								<input type="hidden" name="write_permissions[<?php echo esc_attr( $group ); ?>]" value="0">
								<label><input type="checkbox" name="write_permissions[<?php echo esc_attr( $group ); ?>]" value="1" <?php checked( Settings::write_groups_allowed( array( $group ) ) ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
						<p id="pom-mcp-permissions-description" class="description"><?php esc_html_e( 'Applies to MCP writes to this site, including delegated writes. Direct changes also require reviewed changes to be enabled above.', 'pom-framework' ); ?></p>
					</td>
				</tr>
				<?php endif; ?>
				<?php if ( is_multisite() && ! is_main_site() && ! $gateway ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Multisite delegation', 'pom-framework' ); ?></th>
					<td>
						<input type="hidden" name="network_destination" value="0">
						<label><input type="checkbox" name="network_destination" value="1" <?php checked( $settings['network_destination'] ); ?>> <?php esc_html_e( 'Allow delegation from your multisite network', 'pom-framework' ); ?></label>
						<p class="description"><?php esc_html_e( 'Applies when the network administrator has enabled delegation and listed this site. You can keep this site’s direct MCP endpoint inactive.', 'pom-framework' ); ?></p>
					</td>
				</tr>
				<?php endif; ?>
				<?php if ( ! $gateway ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'External account connections', 'pom-framework' ); ?></th>
					<td><label><input type="checkbox" name="account_destination" value="1" <?php checked( $settings['account_destination'] ); ?>> <?php esc_html_e( 'Allow external account connections', 'pom-framework' ); ?></label><p class="description"><?php esc_html_e( 'Lets an external account service connect this website with consent from its WordPress user. Uses this site’s direct MCP endpoint and OAuth.', 'pom-framework' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="pom-mcp-service-user"><?php esc_html_e( 'API key service user ID', 'pom-framework' ); ?></label></th>
					<td><input id="pom-mcp-service-user" name="service_user" type="number" min="0" value="<?php echo esc_attr( (string) $settings['service_user'] ); ?>"></td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><label for="pom-mcp-rate"><?php esc_html_e( 'Requests per connection per minute', 'pom-framework' ); ?></label></th>
					<td><input id="pom-mcp-rate" name="rate_limit" type="number" min="1" max="600" value="<?php echo esc_attr( (string) $settings['rate_limit'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="pom-mcp-origins"><?php esc_html_e( 'Allowed browser origins', 'pom-framework' ); ?></label></th>
					<td>
						<textarea id="pom-mcp-origins" name="origins" class="large-text code" rows="3"><?php echo esc_textarea( implode( "\n", $settings['origins'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Enter one origin per line. The site’s own origin is allowed.', 'pom-framework' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( null, 'primary', 'submit', true, $requirements !== '' && ! $settings['enabled'] ? array( 'disabled' => 'disabled' ) : array() ); ?>
		</form>
		<?php
	}

	private function render_connections( array $settings, Storage $storage ): void {
		$key = get_option( Settings::API_KEY, array() );
		$after = isset( $_GET['agent_after'] ) && is_string( $_GET['agent_after'] ) ? absint( $_GET['agent_after'] ) : 0;
		$clients = ( new TokenStore( $storage ) )->authorized_clients( $after );
		?>
		<h2 id="pom-mcp-credentials-heading"><?php esc_html_e( 'Credentials', 'pom-framework' ); ?></h2>
		<?php if ( ! empty( $key['last4'] ) ) : ?>
			<p><?php echo esc_html( sprintf( __( 'API key ending in %s', 'pom-framework' ), $key['last4'] ) ); ?></p>
		<?php endif; ?>
		<?php if ( $settings['enabled'] && $settings['api_key'] ) : ?>
			<p><?php echo esc_html( sprintf( __( 'Send the API key in the %s request header.', 'pom-framework' ), Settings::API_KEY_HEADER ) ); ?></p>
			<?php $this->button( 'rotate_key', __( 'Generate or rotate API key', 'pom-framework' ) ); ?>
		<?php endif; ?>
		<?php if ( ! empty( $key['last4'] ) ) : ?>
			<?php $this->button( 'revoke_key', __( 'Revoke API key', 'pom-framework' ) ); ?>
		<?php endif; ?>
		<h3><?php esc_html_e( 'Your OAuth connections', 'pom-framework' ); ?></h3>
		<?php if ( is_wp_error( $clients ) ) : ?>
			<p><?php echo esc_html( $clients->get_error_message() ); ?></p>
		<?php elseif ( $clients['agents'] ) : ?>
			<ul>
				<?php foreach ( $clients['agents'] as $client ) : ?>
					<li>
						<strong><?php echo esc_html( $client['client_name'] ); ?></strong>
						<?php $this->button( 'revoke_agent', __( 'Revoke connection', 'pom-framework' ), $client['client_id'] ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $clients['next_after'] ) : ?>
				<p><a href="<?php echo esc_url( add_query_arg( 'agent_after', $clients['next_after'], self::url() ) ); ?>"><?php esc_html_e( 'More connections', 'pom-framework' ); ?></a></p>
			<?php endif; ?>
		<?php else : ?>
			<p><?php esc_html_e( 'There are no OAuth connections for your account.', 'pom-framework' ); ?></p>
		<?php endif; ?>
		<?php
	}

	private function render_activity( Storage $storage ): void {
		$before = isset( $_GET['activity_before'] ) && is_string( $_GET['activity_before'] ) ? absint( $_GET['activity_before'] ) : 0;
		$ready = $storage->ready();
		$entries = $ready ? ( new Log( $storage ) )->recent( $before ) : array();
		$more = count( $entries ) > 30;
		if ( $more ) { array_pop( $entries ); }
		$statuses = array(
			'success' => __( 'Success', 'pom-framework' ),
			'error' => __( 'Error', 'pom-framework' ),
			'denied' => __( 'Denied', 'pom-framework' ),
			'approved' => __( 'Approved', 'pom-framework' ),
			'rejected' => __( 'Rejected', 'pom-framework' ),
		);
		?>
		<section id="pom-mcp-activity" class="pom-mcp-panel" aria-labelledby="pom-mcp-activity-heading">
			<h2 id="pom-mcp-activity-heading"><?php esc_html_e( 'Recent activity', 'pom-framework' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Shows MCP actions, review decisions and outcomes for the last 30 days. Times are shown in this site’s time zone.', 'pom-framework' ); ?></p>
			<?php if ( ! $ready ) : ?>
				<p><?php esc_html_e( 'Activity collection starts when you enable POM MCP. For a delegated-only site, check POM MCP on the network’s main site.', 'pom-framework' ); ?></p>
			<?php elseif ( ! $entries ) : ?>
				<p><?php esc_html_e( 'There is no activity on this page yet.', 'pom-framework' ); ?></p>
			<?php else : ?>
				<div class="pom-mcp-scroll" role="region" aria-labelledby="pom-mcp-activity-heading" tabindex="0">
					<table class="widefat striped pom-mcp-log">
						<thead><tr><th scope="col"><?php esc_html_e( 'Time', 'pom-framework' ); ?></th><th scope="col"><?php esc_html_e( 'User ID', 'pom-framework' ); ?></th><th scope="col"><?php esc_html_e( 'Authentication', 'pom-framework' ); ?></th><th scope="col"><?php esc_html_e( 'Action', 'pom-framework' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'pom-framework' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<?php $status = $entry['status']; $badge = in_array( $status, array( 'success', 'approved' ), true ) ? 'pom-mcp-pill-active' : 'pom-mcp-pill-error'; ?>
							<tr>
								<td><?php echo esc_html( get_date_from_gmt( $entry['timestamp'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
								<td><?php echo esc_html( $entry['actor_user_id'] ?: '—' ); ?></td>
								<td><?php echo esc_html( $entry['auth_type'] ); ?></td>
								<td><code><?php echo esc_html( $entry['action'] ); ?></code></td>
								<td><span class="pom-mcp-pill <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $statuses[ $status ] ?? $status ); ?></span></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
			<?php if ( $before || $more ) : ?>
				<p class="pom-mcp-actions">
					<?php if ( $before ) : ?><a class="button" href="<?php echo esc_url( self::url() . '#pom-mcp-activity' ); ?>"><?php esc_html_e( 'Latest activity', 'pom-framework' ); ?></a><?php endif; ?>
					<?php if ( $more ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'activity_before', end( $entries )['id'], self::url() ) . '#pom-mcp-activity' ); ?>"><?php esc_html_e( 'Older activity', 'pom-framework' ); ?></a><?php endif; ?>
				</p>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function admin_actor(): array {
		return array( 'auth_type' => 'wordpress', 'user_id' => get_current_user_id() );
	}

	private function button( string $action, string $label, string $client = '' ): void {
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<?php self::form_fields( $action ); ?>
			<?php if ( $client !== '' ) : ?>
				<input type="hidden" name="client" value="<?php echo esc_attr( $client ); ?>">
			<?php endif; ?>
			<?php submit_button( $label, 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function form_fields( string $action ): void {
		wp_nonce_field( self::ACTION );
		?>
		<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
		<input type="hidden" name="operation" value="<?php echo esc_attr( $action ); ?>">
		<?php
	}

	private static function field( string $name ): string {
		return is_string( $_POST[ $name ] ?? null ) ? wp_unslash( $_POST[ $name ] ) : '';
	}
}
