<?php

namespace POMFramework\Mcp;

use WP\MCP\Core\McpServer;
use WP\MCP\Transport\HttpTransport;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** Publish private Abilities through one authenticated HTTP server. */
final class Server {
	public const VERSION = '1.0.0';
	private TokenStore $tokens;
	private Log $logger;
	private ?Reviews $reviews;
	private ?Elicitation $elicitation;
	/** @var array<string,Operation> */
	private array $operations;
	private array $registered = array();
	private array $components = array();
	private array $component_names = array();
	/** @var callable|null */
	private $items;
	private int $blog;
	private ?WP_REST_Request $request = null;
	private ?McpServer $transport = null;
	private array $actor = array();
	private int $previous_user = 0;

	public function __construct( array $operations, TokenStore $tokens, Log $logger, ?Reviews $reviews = null, ?callable $items = null ) {
		$this->operations = $operations;
		$this->tokens = $tokens;
		$this->logger = $logger;
		$this->reviews = $reviews;
		$this->items = $items;
		$this->elicitation = $reviews ? new Elicitation( $reviews ) : null;
		$this->blog = get_current_blog_id();
	}

	public function hooks(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'rest_api_init', array( $this, 'register_transport' ), 15 );
		add_action( 'rest_api_init', array( $this, 'register_status' ), 17 );
		foreach ( array( 'tool', 'resource', 'prompt' ) as $kind ) {
			add_filter( 'mcp_adapter_' . $kind . '_name', array( $this, 'tool_name' ), 10, 2 );
		}
		add_filter( 'rest_pre_dispatch', array( $this, 'authenticate' ), 5, 3 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'begin' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'finish' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'add_response_headers' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( $this, 'send_scoped_cors' ), PHP_INT_MAX - 1, 3 );
		if ( $this->elicitation ) { add_filter( 'rest_pre_serve_request', array( $this->elicitation, 'serve' ), PHP_INT_MAX, 3 ); }
	}

	public function register_category(): void {
		wp_register_ability_category( 'pom-mcp', array(
			'label' => 'POM MCP',
			'description' => 'Operations explicitly registered on the POM MCP server.',
		) );
	}

	public function register_abilities(): void {
		foreach ( $this->operations as $operation ) {
			$id = 'pom-mcp/' . str_replace( '_', '-', $operation->name );
			$reviewed_change = in_array( $operation->name, array( 'pom_prepare_review', 'pom_apply_review', 'pom_network_execute_site' ), true );
			$ability = wp_register_ability( $id, array(
				'label' => $operation->label,
				'description' => $operation->description,
				'category' => 'pom-mcp',
				'input_schema' => $operation->schema,
				'permission_callback' => function ( $input = null ) use ( $operation ) {
					return $this->permission( $operation, is_array( $input ) ? $input : array() );
				},
				'execute_callback' => function ( $input = null ) use ( $operation ) {
					$input = is_array( $input ) ? $input : array();
					$allowed = $this->permission( $operation, $input );
					if ( $allowed !== true ) { return $allowed; }
					if ( ! in_array( $operation->name, Reviews::TOOL_NAMES, true ) && ! $operation->uses_actor ) { return ( $operation->execute )( $input ); }
					$result = ( $operation->execute )( $input, $this->actor );
					if ( is_array( $result ) && isset( $result['approval_required'] ) && $this->elicitation ) { $this->elicitation->offer( $result ); }
					return $result;
				},
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'annotations' => array( 'readonly' => ! $reviewed_change, 'destructive' => $operation->name === 'pom_apply_review', 'idempotent' => ! $reviewed_change ),
				),
			) );
			if ( $ability ) {
				$this->registered[ $id ] = $operation->name;
			}
		}
		$this->register_items();
	}

	private function register_items(): void {
		if ( $this->items === null ) { return; }
		try { $items = ( $this->items )(); }
		catch ( \Throwable $error ) { $this->logger->record( 'catalogue_setup', 'error' ); return; }
		foreach ( $items as $key => $item ) {
			$id = 'pom-mcp/' . $item->kind . '-' . substr( hash( 'sha256', $key ), 0, 24 );
			$meta = array( 'type' => $item->kind, 'public' => false );
			$schema = array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false );
			if ( $item->kind === Catalog_Item::RESOURCE ) {
				$meta += array( 'uri' => $item->uri, 'mimeType' => $item->mime_type );
			} else {
				$meta['arguments'] = $item->arguments;
				foreach ( $item->arguments as $arg ) {
					$schema['properties'][ $arg['name'] ] = array( 'type' => 'string', 'description' => $arg['description'] ?? '' );
					if ( ! empty( $arg['required'] ) ) { $schema['required'][] = $arg['name']; }
				}
			}
			$permission = function () use ( $item ) {
				return $this->is_actor_current() && ( $item->permission )() === true;
			};
			$registered = wp_register_ability( $id, array(
				'label' => $item->name, 'description' => $item->description, 'category' => 'pom-mcp',
				'input_schema' => $item->kind === Catalog_Item::RESOURCE ? array() : $schema, 'permission_callback' => $permission,
				'execute_callback' => static function ( $input = null ) use ( $item, $permission ) {
					return $permission() ? ( $item->read )( is_array( $input ) ? $input : array() )
						: new WP_Error( 'pom_mcp_forbidden', 'This connection cannot read the requested catalogue item.', array( 'status' => 403 ) );
				},
				'meta' => array( 'public' => false, 'show_in_rest' => false, 'mcp' => $meta ),
			) );
			if ( $registered ) {
				$this->components[ $item->kind ][] = $id;
				$this->component_names[ $id ] = $item->name;
			}
		}
	}

	public function tool_name( string $name, \WP_Ability $ability ): string {
		return $this->registered[ $ability->get_name() ] ?? $this->component_names[ $ability->get_name() ] ?? $name;
	}

	public function register_transport( WP_REST_Server $rest ): void {
		if ( isset( $rest->get_routes()[ Settings::ROUTE ] ) ) {
			return;
		}

		wp_get_abilities();
		if ( count( $this->registered ) !== count( $this->operations ) ) {
			return;
		}

		try {
			// Construct only the HTTP server. The adapter singleton would also bootstrap its generic executor.
			$transport = new McpServer(
				'pom-mcp', Settings::REST_NAMESPACE, 'mcp', 'POM MCP',
				'Operations provided by the POM products active on this WordPress site.', self::VERSION,
				array( HttpTransport::class ), null, null, array_keys( $this->registered ),
				$this->components[ Catalog_Item::RESOURCE ] ?? array(), $this->components[ Catalog_Item::PROMPT ] ?? array(),
				function ( WP_REST_Request $request ): bool {
					return $request === $this->request && $this->is_actor_current();
				}
			);
			if ( count( $transport->get_tools() ) === count( $this->registered ) ) {
				$this->transport = $transport;
			}
		} catch ( \Throwable $error ) {
			$this->logger->record( 'transport_setup', 'error' );
		}
	}

	public function register_status( WP_REST_Server $rest ): void {
		if ( isset( $rest->get_routes()[ Settings::STATUS_ROUTE ] ) ) {
			return;
		}
		register_rest_route( Settings::REST_NAMESPACE, '/status', array(
			'methods' => 'GET',
			'permission_callback' => '__return_true',
			'callback' => function (): WP_REST_Response {
				return new WP_REST_Response( array(
					'active' => $this->transport !== null,
					'mcp_endpoint' => Settings::endpoint(),
					'oauth_enabled' => Settings::oauth_available(),
					'api_key_enabled' => (bool) Settings::get()['api_key'],
					'contract_version' => Bootstrap::API_VERSION,
				), $this->transport === null ? 503 : 200 );
			},
		) );
	}

	public function authenticate( $response, WP_REST_Server $rest, WP_REST_Request $request ) {
		$route = untrailingslashit( $request->get_route() );
		if ( ! self::is_route( $route ) || $response !== null ) {
			return $response;
		}
		if ( ! Settings::secure_request() || ! RequestPolicy::origin_allowed( (string) $request->get_header( 'origin' ) ) ) {
			return new WP_Error( 'pom_mcp_origin_denied', 'This request must use the configured origin and transport.', array( 'status' => 403 ) );
		}
		if ( $request->get_method() === 'OPTIONS' ) {
			return new WP_REST_Response( null, 204 );
		}
		if ( $route === Settings::STATUS_ROUTE ) {
			return null;
		}
		if ( $this->transport === null || $this->request !== null || $this->blog !== get_current_blog_id() ) {
			return new WP_Error( 'pom_mcp_unavailable', 'POM MCP is unavailable in this request.', array( 'status' => 503 ) );
		}

		$body = $request->get_body();
		if ( strlen( $body ) > RequestPolicy::MAX_BODY_BYTES ) {
			return new WP_Error( 'pom_mcp_body_too_large', 'Request body too large.', array( 'status' => 413 ) );
		}
		if ( $request->get_method() === 'POST' && substr( ltrim( $body ), 0, 1 ) !== '{' ) {
			return new WP_Error( 'pom_mcp_invalid_message', 'Send one JSON-RPC object per request.', array( 'status' => 400 ) );
		}

		$ip = RequestPolicy::ip_hash();
		$attempt = RateLimiter::check( 'authenticate', $ip, 600 );
		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}
		$actor = $this->verify_credentials( $request );
		if ( is_wp_error( $actor ) ) {
			if ( RateLimiter::check( 'authentication_audit', $ip, 5 ) === true ) {
				$this->logger->record( 'authenticate', 'denied' );
			}
			return $actor;
		}

		$limit = RateLimiter::check( 'mcp', $actor['rate_key'], (int) Settings::get()['rate_limit'] );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$reply = $this->elicitation ? $this->elicitation->respond( $request, $actor ) : null;
		if ( $reply !== null ) {
			$this->logger->record( 'review_reply', $reply->get_status() < 400 ? 'success' : 'denied', $actor );
			return $reply;
		}
		$this->request = $request;
		$this->actor = $actor;
		return null;
	}

	public function begin( $response, array $handler, WP_REST_Request $request ) {
		if ( $request === $this->request ) {
			$this->previous_user = get_current_user_id();
			if ( $response === null ) {
				wp_set_current_user( $this->actor['user_id'] );
				if ( $this->elicitation ) { $this->elicitation->begin( $request, $this->actor ); }
			}
		}
		return $response;
	}

	private function verify_credentials( WP_REST_Request $request ) {
		$authorization = (string) $request->get_header( 'authorization' );
		if ( $authorization === '' && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$authorization = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		$api_key = (string) $request->get_header( Settings::API_KEY_HEADER );
		if ( $authorization !== '' && $api_key !== '' ) {
			return new WP_Error( 'pom_mcp_ambiguous_auth', 'Use either Bearer authentication or the API key header.', array( 'status' => 400 ) );
		}
		if ( Settings::oauth_available() && preg_match( '/^Bearer (pommcp_at_[A-Za-z0-9_-]{43})$/Di', $authorization, $matches ) ) {
			$actor = $this->tokens->validate_access_token( $matches[1] );
			if ( $actor && in_array( Settings::READ_SCOPE, $actor['scopes'], true ) ) {
				return $actor;
			}
		}
		if ( $api_key !== '' && preg_match( '/^pommcp_key_[A-Za-z0-9_-]{43}$/D', $api_key ) ) {
			$settings = Settings::get();
			$key = get_option( Settings::API_KEY, array() );
			$user_id = (int) $settings['service_user'];
			if ( $settings['api_key'] && is_array( $key ) && (int) ( $key['user'] ?? 0 ) === $user_id
				&& hash_equals( (string) ( $key['hash'] ?? '' ), self::api_key_hash( $api_key ) )
				&& Settings::actor_can_connect( $user_id ) ) {
				return array( 'user_id' => $user_id, 'auth_type' => 'api_key', 'rate_key' => 'api_key:' . $user_id );
			}
		}
		return new WP_Error( 'pom_mcp_auth_required', 'A valid POM MCP credential is required.', array( 'status' => 401 ) );
	}

	public static function api_key_hash( string $api_key ): string {
		return hash_hmac( 'sha256', 'pom-mcp-api-key:' . $api_key, wp_salt( 'auth' ) );
	}

	private function is_actor_current(): bool {
		return $this->request !== null && $this->blog === get_current_blog_id()
			&& get_current_user_id() === (int) $this->actor['user_id'] && current_user_can( 'read' );
	}

	private function permission( Operation $operation, array $input ) {
		if ( ! $this->is_actor_current() || $operation->effect !== Operation::READ ) {
			return new WP_Error( 'pom_mcp_forbidden', 'An authenticated POM MCP read request is required.', array( 'status' => 403 ) );
		}
		if ( $operation->name !== 'pom_get_task' && in_array( $operation->name, Reviews::TOOL_NAMES, true ) && ( ! $this->reviews || ! $this->reviews->authorizes( $this->actor ) ) ) {
			return new WP_Error( 'pom_mcp_write_scope', 'An OAuth grant with reviewed write access is required.', array( 'status' => 403 ) );
		}
		if ( in_array( $operation->name, array( 'pom_preview_operation', 'pom_prepare_review' ), true ) && ! Settings::writes_enabled() ) {
			return new WP_Error( 'pom_mcp_write_scope', 'Reviewed local writes are disabled.', array( 'status' => 403 ) );
		}
		$result = ( $operation->permission )( $input );
		return $result === true || is_wp_error( $result ) ? $result : new WP_Error( 'pom_mcp_forbidden', 'This account cannot run the operation.', array( 'status' => 403 ) );
	}

	public function finish( $response, array $handler, WP_REST_Request $request ) {
		if ( $request !== $this->request ) {
			return $response;
		}
		$actor = $this->actor;
		$this->request = null;
		$this->actor = array();
		wp_set_current_user( $this->previous_user );

		$data = $response instanceof WP_REST_Response ? (array) $response->get_data() : array();
		$result = (array) ( $data['result'] ?? array() );
		$failed = is_wp_error( $response ) || ( $response instanceof WP_REST_Response && $response->get_status() >= 400 )
			|| ! empty( $data['error'] ) || ! empty( $result['isError'] );
		$payload = $request->get_json_params();
		$method = is_string( $payload['method'] ?? null ) ? $payload['method'] : '';
		$tool = $payload['params']['name'] ?? null;
		$action = $method === 'tools/call' && is_string( $tool ) && in_array( $tool, $this->registered, true )
			? $tool : ( $method === 'initialize' ? 'initialize' : 'mcp_request' );
		$this->logger->record( $action, $failed ? 'error' : 'success', $actor );
		return $response;
	}

	public function add_response_headers( $response, WP_REST_Server $rest, WP_REST_Request $request ) {
		if ( ! $response instanceof WP_REST_Response || ! self::is_route( untrailingslashit( $request->get_route() ) ) ) {
			return $response;
		}
		if ( $request === $this->request ) {
			// Other REST filters can short-circuit before WordPress calls the handler.
			$this->request = null;
			$this->actor = array();
		}
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		if ( $response->get_status() === 401 && Settings::oauth_available() ) {
			$response->header( 'WWW-Authenticate', 'Bearer resource_metadata="' . esc_url_raw( Settings::url( OAuthServer::RESOURCE_METADATA ) ) . '"' );
		}
		if ( $response->get_status() === 429 ) {
			$retry = $response->get_data()['data']['retry_after'] ?? 60;
			$response->header( 'Retry-After', (string) max( 1, (int) $retry ) );
		}
		return $response;
	}

	public function send_scoped_cors( bool $served, $response, WP_REST_Request $request ): bool {
		$route = untrailingslashit( $request->get_route() );
		if ( self::is_route( $route ) ) {
			RequestPolicy::send_cors( (string) $request->get_header( 'origin' ), $route === Settings::STATUS_ROUTE ? 'GET, OPTIONS' : 'GET, POST, DELETE, OPTIONS' );
		}
		return $served;
	}

	private static function is_route( string $route ): bool {
		return in_array( $route, array( Settings::ROUTE, Settings::STATUS_ROUTE ), true );
	}
}
