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
		add_options_page( 'POM MCP', 'POM MCP', 'manage_options', self::PAGE, array( $this, 'render' ) );
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
				break;
			case 'rotate_key':
				$this->rotate_key( $storage );
				break;
			case 'revoke_key':
				delete_option( Settings::API_KEY );
				if ( get_option( Settings::API_KEY, false ) !== false ) {
					wp_die( esc_html__( 'The API key could not be revoked.', 'pom-framework' ) );
				}
				break;
			case 'revoke_agent':
				if ( ! $storage->ready() ) {
					wp_die( esc_html__( 'POM MCP connection storage is unavailable.', 'pom-framework' ) );
				}
				$result = ( new TokenStore( $storage ) )->revoke_authorized_client( sanitize_text_field( self::field( 'client' ) ) );
				if ( is_wp_error( $result ) ) {
					wp_die( esc_html( $result->get_error_message() ) );
				}
				( new Log( $storage ) )->record( 'oauth_revoke_admin', 'success', array( 'user_id' => get_current_user_id() ) );
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
		$settings = array(
			'enabled' => self::field( 'enabled' ) === '1',
			'oauth' => self::field( 'oauth' ) === '1',
			'writes' => self::field( 'writes' ) === '1',
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
		( new Log( $storage ) )->record( 'api_key_rotate', 'success', array( 'user_id' => get_current_user_id() ) );
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
		?>
		<div class="wrap">
			<h1>POM MCP</h1>
			<?php if ( isset( $_GET['pom_mcp_saved'] ) && $_GET['pom_mcp_saved'] === '1' ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'POM MCP settings updated.', 'pom-framework' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $problem !== '' ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $problem ); ?></p></div>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Connection', 'pom-framework' ); ?></h2>
			<p><?php echo esc_html( sprintf( __( 'Connection controller: %s', 'pom-framework' ), AccountGateway::registered() ? 'Pomatio Account' : 'POM Framework' ) ); ?></p>
			<p><code><?php echo esc_html( Settings::endpoint() ); ?></code></p>
			<h2><?php esc_html_e( 'Products', 'pom-framework' ); ?></h2>
			<ul class="ul-disc">
				<?php foreach ( $this->bootstrap->providers() as $label ) : ?>
					<li><?php echo esc_html( $label ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php $this->render_settings( $settings, $requirements ); ?>
			<?php $this->render_skills(); ?>
			<?php if ( $storage->ready() ) : ?>
				<?php $this->render_connections( $settings, $storage ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_skills(): void {
		$skills = $this->bootstrap->skills();
		if ( ! $skills ) { return; }
		?>
		<h2><?php esc_html_e( 'Skills', 'pom-framework' ); ?></h2>
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
		<?php
	}

	private function render_settings( array $settings, string $requirements ): void {
		$gateway = AccountGateway::registered();
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
				<?php if ( ! $gateway ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Account connections', 'pom-framework' ); ?></th>
					<td><label><input type="checkbox" name="account_destination" value="1" <?php checked( $settings['account_destination'] ); ?>> <?php esc_html_e( 'Allow Pomatio account connections', 'pom-framework' ); ?></label><p class="description"><?php esc_html_e( 'Requires OAuth and authorization by a WordPress user. Account connections use the site’s reviewed authoring tools.', 'pom-framework' ); ?></p></td>
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
		<h2><?php esc_html_e( 'Credentials', 'pom-framework' ); ?></h2>
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
		<h2><?php esc_html_e( 'Recent activity', 'pom-framework' ); ?></h2>
		<ul>
			<?php foreach ( ( new Log( $storage ) )->recent() as $entry ) : ?>
				<li><?php echo esc_html( implode( ' · ', array( $entry['timestamp'] . ' UTC', $entry['auth_type'], $entry['action'], $entry['status'] ) ) ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php
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
