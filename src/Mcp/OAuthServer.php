<?php

namespace POMFramework\Mcp;

use WP;
use WP_Error;

/** Serve discovery and authorization only on the current site's published OAuth paths. */
final class OAuthServer {
	public const RESOURCE_METADATA = '.well-known/oauth-protected-resource';
	private const AUTHORIZATION_METADATA = '.well-known/oauth-authorization-server';
	private TokenStore $token_store;
	private Log $logger;
	private bool $review_tools;
	private array $network_scopes;

	public function __construct( TokenStore $token_store, Log $logger, bool $review_tools = false, array $network_scopes = array() ) {
		$this->token_store = $token_store;
		$this->logger = $logger;
		$this->review_tools = $review_tools;
		$this->network_scopes = $network_scopes;
	}

	public function handle( WP $wp ): void {
		$route = trim( $wp->request, '/' );
		$actions = array(
			self::RESOURCE_METADATA => 'resource_metadata',
			self::AUTHORIZATION_METADATA => 'authorization_metadata',
			Settings::OAUTH_PATH . 'register' => 'handle_register',
			Settings::OAUTH_PATH . 'authorize' => 'handle_authorize',
			Settings::OAUTH_PATH . 'token' => 'handle_token',
			Settings::OAUTH_PATH . 'revoke' => 'handle_revoke',
		);
		if ( ! isset( $actions[ $route ] ) || ! Settings::oauth_available() ) {
			return;
		}

		RequestPolicy::send_no_store();
		$origin = (string) ( $_SERVER['HTTP_ORIGIN'] ?? '' );
		if ( ! Settings::secure_request() || ! RequestPolicy::origin_allowed( $origin ) ) {
			$this->json_error( 'forbidden', 'This request must use the configured origin and transport.', 403 );
		}

		$methods = $route === self::RESOURCE_METADATA || $route === self::AUTHORIZATION_METADATA ? 'GET, OPTIONS'
			: ( $route === Settings::OAUTH_PATH . 'authorize' ? 'GET, POST, OPTIONS' : 'POST, OPTIONS' );
		RequestPolicy::send_cors( $origin, $methods );
		$method = $this->request_method();
		if ( $method === 'OPTIONS' ) {
			status_header( 204 );
			exit;
		}
		if ( ! in_array( $method, explode( ', ', $methods ), true ) ) {
			header( 'Allow: ' . $methods, true );
			$this->json_error( 'method_not_allowed', 'This HTTP method is unavailable on the selected endpoint.', 405 );
		}
		if ( (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > RequestPolicy::MAX_BODY_BYTES ) {
			$this->json_error( 'invalid_request', 'Request body too large.', 413 );
		}

		$this->{$actions[ $route ]}();
	}

	private function resource_metadata(): void {
		$this->json( array(
			'resource' => Settings::endpoint(),
			'authorization_servers' => array( Settings::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported' => $this->scopes(),
		) );
	}

	private function authorization_metadata(): void {
		$this->json( array(
			'issuer' => Settings::issuer(),
			'authorization_endpoint' => Settings::url( Settings::OAUTH_PATH . 'authorize' ),
			'token_endpoint' => Settings::url( Settings::OAUTH_PATH . 'token' ),
			'revocation_endpoint' => Settings::url( Settings::OAUTH_PATH . 'revoke' ),
			'registration_endpoint' => Settings::url( Settings::OAUTH_PATH . 'register' ),
			'response_types_supported' => array( 'code' ),
			'grant_types_supported' => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported' => array( 'S256' ),
			'scopes_supported' => $this->scopes(),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none' ),
		) );
	}

	private function handle_register(): void {
		if ( $this->request_method() !== 'POST' ) {
			$this->json_error( 'method_not_allowed', __( 'Registration requires POST.', 'pom-framework' ), 405 );
		}

		$rate = RateLimiter::check( 'oauth-register', RequestPolicy::ip_hash(), 20 );
		if ( is_wp_error( $rate ) ) {
			$this->rate_error( $rate );
		}

		$body          = $this->request_data();
		$redirect_uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? $body['redirect_uris'] : array();
		$redirect_uris = $this->sanitize_redirect_uris( $redirect_uris );

		if ( empty( $redirect_uris ) ) {
			$this->json_error( 'invalid_redirect_uris', __( 'At least one redirect URI is required.', 'pom-framework' ), 400 );
		}

		$client_name = is_string( $body['client_name'] ?? null ) ? substr( sanitize_text_field( (string) $body['client_name'] ), 0, 191 ) : 'MCP client';
		if ( $client_name === '' ) {
			$client_name = 'MCP client';
		}
		$scopes = $this->requested_scopes( array_key_exists( 'scope', $body ) ? $body['scope'] : '' );
		if ( empty( $scopes ) ) {
			$this->json_error( 'invalid_scope', 'Request mcp:read and only scopes available to this client.', 400 );
		}

		$client = $this->token_store->create_client( $client_name, $redirect_uris, $scopes );
		if ( is_wp_error( $client ) ) { $this->json_error( 'temporarily_unavailable', $client->get_error_message(), 503 ); }
		$this->logger->record( 'oauth_register', 'success' );

		$this->json(
			array(
				'client_id'                  => $client['client_id'],
				'client_name'                => $client['client_name'],
				'redirect_uris'              => $client['redirect_uris'],
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
				'scope'                      => implode( ' ', $scopes ),
			),
			201
		);
	}

	private function handle_authorize(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $this->current_url() ) );
			exit;
		}

		if ( ! Settings::actor_can_connect( get_current_user_id() ) ) {
			wp_die( esc_html__( 'This account cannot connect to this site.', 'pom-framework' ), '', array( 'response' => 403 ) );
		}

		$method = $this->request_method();
		if ( $method === 'POST' ) {
			check_admin_referer( 'pom_mcp_oauth_authorize', 'pom_mcp_oauth_nonce' );
		}

		if ( $method === 'POST' ) {
			$params = array_map( 'wp_unslash', $_POST );
		} else {
			$params = array_map( 'wp_unslash', $_GET );
		}

		$validation = $this->validate_authorize_params( $params );
		if ( is_wp_error( $validation ) ) {
			wp_die( esc_html( $validation->get_error_message() ), esc_html__( 'OAuth authorization failed', 'pom-framework' ), array( 'response' => 400 ) );
		}

		if ( $method === 'POST' ) {
			if ( empty( $_POST['approve'] ) ) {
				$this->redirect_with_error( $validation, 'access_denied', 'The authorization request was denied.' );
			}

			$code = $this->token_store->store_auth_code(
				$validation['client']['client_id'],
				get_current_user_id(),
				$validation['redirect_uri'],
				$validation['scopes'],
				$validation['resource'],
				$validation['code_challenge'],
				'S256'
			);

			if ( is_wp_error( $code ) ) { $this->json_error( 'temporarily_unavailable', $code->get_error_message(), 503 ); }

			$redirect = add_query_arg(
				array_filter(
					array(
						'code'  => $code,
						'state' => rawurlencode( $validation['state'] ),
					),
					static fn( $value ) => $value !== ''
				),
				$validation['redirect_uri']
			);

			$this->logger->record( 'oauth_authorize', 'success', array( 'auth_type' => 'oauth', 'user_id' => get_current_user_id() ) );
			wp_redirect( $redirect );
			exit;
		}

		$data     = $validation;
		$template = __DIR__ . '/consent.php';
		if ( file_exists( $template ) ) {
			include $template;
			exit;
		}

		wp_die( esc_html__( 'Authorization template missing.', 'pom-framework' ), esc_html__( 'OAuth authorization failed', 'pom-framework' ), array( 'response' => 500 ) );
	}

	private function handle_token(): void {
		if ( $this->request_method() !== 'POST' ) {
			$this->json_error( 'method_not_allowed', __( 'Token endpoint requires POST.', 'pom-framework' ), 405 );
		}

		$rate = RateLimiter::check( 'oauth-token', RequestPolicy::ip_hash(), 20 );
		if ( is_wp_error( $rate ) ) {
			$this->rate_error( $rate );
		}

		$data       = $this->request_data();
		foreach ( array( 'grant_type', 'client_id', 'resource', 'code', 'redirect_uri', 'code_verifier', 'refresh_token' ) as $field ) {
			if ( isset( $data[ $field ] ) && ( ! is_string( $data[ $field ] ) || strlen( $data[ $field ] ) > 4096 ) ) {
				$this->json_error( 'invalid_request', 'An OAuth field has an invalid format.', 400 );
			}
		}
		$grant_type = isset( $data['grant_type'] ) ? sanitize_key( (string) $data['grant_type'] ) : '';
		$client_id  = isset( $data['client_id'] ) ? sanitize_text_field( (string) $data['client_id'] ) : '';
		$resource   = (string) ( $data['resource'] ?? '' );

		if ( ! Settings::valid_resource( $resource ) ) {
			$this->json_error( 'invalid_resource', __( 'The resource parameter must match this site MCP endpoint.', 'pom-framework' ), 400 );
		}

		$resource = Settings::endpoint();
		$client = $this->token_store->get_client( $client_id );
		if ( empty( $client ) ) {
			$this->json_error( 'invalid_client', __( 'Unknown OAuth client.', 'pom-framework' ), 401 );
		}

		$result = $this->token_store->with_client_lock( $client_id, function () use ( $grant_type, $data, $client_id, $resource ) {
			if ( $grant_type === 'authorization_code' ) {
				$code_data = $this->token_store->consume_auth_code(
					(string) ( $data['code'] ?? '' ), $client_id, $resource,
					(string) ( $data['redirect_uri'] ?? '' ), (string) ( $data['code_verifier'] ?? '' )
				);
				if ( ! $code_data || ( AccountGateway::registered() && ! AccountGateway::allows( (array) $code_data['scope'] ) ) || ( in_array( AccountDestination::SCOPE, (array) $code_data['scope'], true ) && ! AccountDestination::enabled() ) ) {
					return new WP_Error( 'invalid_grant', 'Invalid authorization code or PKCE verifier.', array( 'status' => 400 ) );
				}
				$tokens = $this->token_store->issue_tokens( $client_id, (int) $code_data['user_id'], (array) $code_data['scope'], $resource );
				if ( ! $tokens ) {
					return new WP_Error( 'temporarily_unavailable', 'Authorization could not be saved. Start authorization again.', array( 'status' => 503 ) );
				}
				$this->logger->record( 'oauth_token', 'success', array( 'auth_type' => 'oauth', 'user_id' => (int) $code_data['user_id'] ) );
				return $tokens;
			}
			if ( $grant_type === 'refresh_token' ) {
				$tokens = $this->token_store->refresh_tokens( isset( $data['refresh_token'] ) ? (string) $data['refresh_token'] : '', $client_id, $resource );
				$reason = is_wp_error( $tokens ) ? $tokens->get_error_code() : ( $tokens ? 'renewed' : 'invalid_grant' );
				$this->logger->record( 'oauth_refresh', $reason === 'renewed' ? 'success' : 'denied' );
				return $tokens ?: new WP_Error( 'invalid_grant', 'Invalid refresh token.', array( 'status' => 400 ) );
			}
			return new WP_Error( 'unsupported_grant_type', 'Unsupported grant type.', array( 'status' => 400 ) );
		} );
		// Send the response only after releasing the lock; JSON helpers terminate PHP.
		if ( is_wp_error( $result ) ) {
			$this->json_error( $result->get_error_code(), $result->get_error_message(), (int) ( $result->get_error_data()['status'] ?? 400 ) );
		}
		$this->json( $result );
	}

	/** RFC 7009 revocation authenticates with the credential being revoked. */
	private function handle_revoke(): void {
		if ( $this->request_method() !== 'POST' ) {
			$this->json_error( 'method_not_allowed', 'Revocation requires POST.', 405 );
		}
		$rate = RateLimiter::check( 'oauth-revoke', RequestPolicy::ip_hash(), 20 );
		if ( is_wp_error( $rate ) ) {
			$this->rate_error( $rate );
		}
		$data = $this->request_data();
		if ( ! is_string( $data['token'] ?? null ) || strlen( $data['token'] ) > 512
			|| ! is_string( $data['client_id'] ?? null ) || strlen( $data['client_id'] ) > 191 ) {
			$this->json_error( 'invalid_request', 'A token and client ID are required.', 400 );
		}
		$revoked = $this->token_store->revoke( $data['token'], $data['client_id'] );
		if ( $revoked !== true ) {
			$this->json_error( 'temporarily_unavailable', 'Revocation could not be completed. Try again later.', 503 );
		}
		// Unknown or already revoked tokens receive the same response.
		header( 'X-POM-MCP-Account-Destination: ' . AccountDestination::VERSION );
		$this->json( array() );
	}

	private function validate_authorize_params( array $params ) {
		foreach ( array( 'client_id', 'resource', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method', 'response_type' ) as $field ) {
			if ( isset( $params[ $field ] ) && ( ! is_string( $params[ $field ] ) || strlen( $params[ $field ] ) > 4096 ) ) {
				return new WP_Error( 'invalid_request', 'An authorization field has an invalid format.' );
			}
		}
		$client_id = isset( $params['client_id'] ) ? sanitize_text_field( (string) $params['client_id'] ) : '';
		$client    = $this->token_store->get_client( $client_id );
		if ( empty( $client ) ) {
			return new WP_Error( 'invalid_client', __( 'Unknown OAuth client.', 'pom-framework' ) );
		}

		$redirect_uri = isset( $params['redirect_uri'] ) ? esc_url_raw( (string) $params['redirect_uri'] ) : '';
		if ( ! $this->token_store->redirect_uri_allowed( $client, $redirect_uri ) ) {
			return new WP_Error( 'invalid_redirect_uri', __( 'Redirect URI is not registered for this client.', 'pom-framework' ) );
		}

		$resource = (string) ( $params['resource'] ?? '' );
		if ( ! Settings::valid_resource( $resource ) ) {
			return new WP_Error( 'invalid_resource', __( 'The resource parameter must match this site MCP endpoint.', 'pom-framework' ) );
		}

		if ( ( $params['response_type'] ?? '' ) !== 'code' ) {
			return new WP_Error( 'unsupported_response_type', __( 'Only authorization code flow is supported.', 'pom-framework' ) );
		}

		if ( ( $params['code_challenge_method'] ?? '' ) !== 'S256' || ! preg_match( '/^[A-Za-z0-9_-]{43}$/D', (string) ( $params['code_challenge'] ?? '' ) ) ) {
			return new WP_Error( 'invalid_pkce', __( 'PKCE S256 is required.', 'pom-framework' ) );
		}

		$scopes = $this->requested_scopes( $params['scope'] ?? '' );
		if ( ! $scopes || array_diff( $scopes, $client['scope'] ) ) {
			return new WP_Error( 'invalid_scope', esc_html__( 'Request mcp:read and only scopes available to this client.', 'pom-framework' ) );
		}

		if ( array_intersect( array( Network::READ, Network::WRITE ), $scopes )
			&& ( ! is_super_admin( get_current_user_id() ) || ! current_user_can( 'manage_network_options' ) ) ) {
			return new WP_Error( 'invalid_scope', __( 'Only a network administrator can authorize network delegation.', 'pom-framework' ) );
		}

		return array(
			'client'         => $client,
			'redirect_uri'   => $redirect_uri,
			'resource'       => Settings::endpoint(),
			'scopes'         => $scopes,
			'state'          => (string) ( $params['state'] ?? '' ),
			'code_challenge' => $params['code_challenge'],
		);
	}

	private function redirect_with_error( array $data, string $error, string $description ): void {
		$redirect = add_query_arg(
			array_filter(
				array(
					'error'             => $error,
					'error_description' => rawurlencode( $description ),
					'state'             => rawurlencode( $data['state'] ?? '' ),
				)
			),
			$data['redirect_uri']
		);
		wp_redirect( $redirect );
		exit;
	}

	private function request_data(): array {
		$type = strtolower( trim( explode( ';', (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ) )[0] ) );
		if ( $type === 'application/x-www-form-urlencoded' ) {
			return wp_unslash( $_POST );
		}
		if ( $type !== 'application/json' ) {
			$this->json_error( 'invalid_request', 'Use JSON or form-encoded fields.', 415 );
		}
		$raw = file_get_contents( 'php://input', false, null, 0, RequestPolicy::MAX_BODY_BYTES + 1 );
		if ( ! is_string( $raw ) || strlen( $raw ) > RequestPolicy::MAX_BODY_BYTES ) {
			$this->json_error( 'invalid_request', 'Request body too large.', 413 );
		}
		$json = json_decode( $raw, true );
		if ( ! is_array( $json ) ) {
			$this->json_error( 'invalid_request', 'Malformed JSON.', 400 );
		}
		return $json;
	}

	private function sanitize_redirect_uris( array $redirect_uris ): array {
		$clean = array();

		foreach ( array_slice( $redirect_uris, 0, 10 ) as $redirect_uri ) {
			if ( ! is_string( $redirect_uri ) ) { continue; }
			$redirect_uri = esc_url_raw( $redirect_uri );
			if ( $redirect_uri === '' || strlen( $redirect_uri ) > 2048 || ! $this->redirect_uri_is_allowed_for_registration( $redirect_uri ) ) {
				continue;
			}
			$clean[] = $redirect_uri;
		}

		return array_slice( array_values( array_unique( $clean ) ), 0, 10 );
	}

	private function redirect_uri_is_allowed_for_registration( string $redirect_uri ): bool {
		$parts = wp_parse_url( $redirect_uri );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( trim( (string) $parts['host'], '[]' ) );

		if ( $scheme === 'https' ) {
			return true;
		}

		return $scheme === 'http' && in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	/** A storage failure is temporary unavailability, not evidence that the caller exceeded a quota. */
	private function rate_error( WP_Error $error ): void {
		$data = (array) $error->get_error_data();
		$limited = $error->get_error_code() === 'pom_mcp_rate_limited';
		if ( $limited ) { header( 'Retry-After: ' . max( 1, (int) ( $data['retry_after'] ?? 60 ) ) ); }
		$this->json_error( $limited ? 'rate_limited' : 'temporarily_unavailable', $error->get_error_message(), $limited ? 429 : 503 );
	}

	private function json( array $data, int $status = 200 ): void {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8', true );
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		exit;
	}

	private function json_error( string $error, string $description, int $status ): void {
		$this->json(
			array(
				'error'             => $error,
				'error_description' => $description,
			),
			$status
		);
	}

	private function current_url(): string {
		// Text sanitization removes percent-encoded OAuth parameters during the login round trip.
		$query = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_QUERY ) : '';
		return esc_url_raw( Settings::url( Settings::OAUTH_PATH . 'authorize' ) . ( is_string( $query ) && $query !== '' ? '?' . $query : '' ) );
	}

	private function request_method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	private function requested_scopes( $requested ): array {
		if ( ! is_string( $requested ) ) { return array(); }
		$default = AccountGateway::registered() ? array( Settings::READ_SCOPE, AccountGateway::READ ) : array( Settings::READ_SCOPE );
		$scopes = trim( $requested ) === '' ? $default : preg_split( '/\s+/', trim( $requested ) );
		$available = $this->scopes();
		if ( AccountGateway::registered() && ! AccountGateway::allows( $scopes ) ) { return array(); }
		return in_array( Settings::READ_SCOPE, $scopes, true ) && ! array_diff( $scopes, $available )
			&& ( ! in_array( AccountDestination::SCOPE, $scopes, true ) || ! array_intersect( $scopes, array( Network::READ, Network::WRITE ) ) )
			&& ( ! in_array( Network::WRITE, $scopes, true ) || ! array_diff( array( Network::READ, Settings::WRITE_SCOPE ), $scopes ) )
			? array_values( array_intersect( $available, $scopes ) ) : array();
	}

	private function scopes(): array {
		if ( AccountGateway::registered() ) { return AccountGateway::scopes(); }
		return array_merge( $this->review_tools ? array( Settings::READ_SCOPE, Settings::WRITE_SCOPE ) : array( Settings::READ_SCOPE ), $this->network_scopes,
			AccountDestination::enabled() ? array( AccountDestination::SCOPE ) : array() );
	}
}
