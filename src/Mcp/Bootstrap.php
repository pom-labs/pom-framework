<?php

namespace POMFramework\Mcp;

use POMFramework\Jobs\Queue;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WP\MCP\Transport\HttpTransport;

/** Select the only POM MCP endpoint owner after plugins and the active theme have registered. */
final class Bootstrap {
	public const API_VERSION = 1;
	private static ?self $instance = null;
	private Registry $registry;
	private string $problem = '';
	private ?Network $network = null;

	public static function register_provider( string $id, string $label, ?callable $operations = null, ?callable $items = null ): void {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		self::$instance->registry->register( $id, $label, $operations, $items );
	}

	private function __construct() {
		$this->registry = new Registry();
		if ( is_multisite() ) { $this->network = new Network( array( $this, 'operations' ), $this->registry ); }
		// The legacy POM AI settings loader runs on init:1 and owns its existing transport.
		add_action( 'init', array( $this, 'start' ), 9 );
		// Continue expiring connection data after a site disables the transport.
		$storage = new Storage();
		add_action( Storage::CLEANUP, array( $storage, 'cleanup' ) );
		add_action( Storage::CLEANUP_BATCH, array( $storage, 'cleanup' ) );
		add_action( 'wp_scheduled_delete', array( new ReviewStore(), 'cleanup' ) );
		add_action( 'wp_scheduled_delete', array( Elicitation::class, 'cleanup' ) );
		if ( is_admin() ) {
			( new Admin( $this ) )->hooks();
		}
	}

	public function legacy_owner(): bool {
		if ( class_exists( 'POM_AI_MCP_Bootstrap', false ) || class_exists( 'POM_AI_Account_MCP', false ) ) {
			return true;
		}
		// A shared local endpoint must not bypass an existing network's delegated-only policy.
		$network = is_multisite() ? get_network_option( get_current_network_id(), 'pom_ai_mcp_network', array() ) : array();
		return is_array( $network ) && ( $network['enabled'] ?? false ) === true;
	}

	public function requirements(): string {
		if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' )
			|| ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return __( 'POM MCP requires WordPress 6.9 or later.', 'pom-framework' );
		}
		AdapterLoader::prefer_compatible_package();
		if ( ! defined( McpAdapter::class . '::VERSION' ) || ! class_exists( McpServer::class ) || ! class_exists( HttpTransport::class )
			|| ! class_exists( '\\WP\\McpSchema\\Common\\Protocol\\DTO\\InitializeResult' )
			|| version_compare( McpAdapter::VERSION, '0.6.1', '<' ) || version_compare( McpAdapter::VERSION, '0.7.0', '>=' ) ) {
			return __( 'Install a compatible POM MCP Adapter package (0.6.1 series) with its schema dependency.', 'pom-framework' );
		}
		if ( ! Settings::secure_site() ) {
			return __( 'Configure HTTPS on this site before enabling POM MCP.', 'pom-framework' );
		}
		return '';
	}

	public function providers(): array {
		return $this->registry->providers();
	}

	public function problem(): string {
		return $this->problem;
	}

	public function operations(): array {
		return $this->registry->freeze( new Operation(
			'pom_get_site_context',
			'POM MCP site context',
			'Identify the connected WordPress site and its active POM MCP providers.',
			Operation::READ,
			array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
			'__return_true',
			function (): array {
				return array(
					'site' => array( 'site_id' => Settings::identity(), 'site_url' => Settings::issuer(), 'blog_id' => get_current_blog_id() ),
					'contract_version' => self::API_VERSION,
					'providers' => array_keys( $this->providers() ),
					'multisite' => is_multisite(),
				);
			},
			null,
			false,
			Operation::READ
		) );
	}

	public function start(): void {
		if ( $this->network ) { $this->network->hooks(); }
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) { Queue::hooks(); }
		if ( ! Settings::get()['enabled'] || $this->legacy_owner() ) {
			return;
		}
		$this->problem = $this->requirements();
		if ( $this->problem !== '' ) {
			return;
		}
		$storage = new Storage();
		if ( ! $storage->ready() || Settings::identity() === '' ) {
			$this->problem = __( 'POM MCP storage or the site identity is unavailable.', 'pom-framework' );
			return;
		}
		if ( did_action( 'wp_abilities_api_init' ) || did_action( 'wp_abilities_api_categories_init' ) ) {
			$this->problem = __( 'The WordPress Abilities API initialized before the POM MCP catalogue.', 'pom-framework' );
			return;
		}
		try {
			$operations = $this->operations();
		} catch ( \Throwable $error ) {
			$this->problem = __( 'The POM MCP catalogue contains an invalid or conflicting provider.', 'pom-framework' );
			return;
		}

		$tokens = new TokenStore( $storage );
		$logger = new Log( $storage );
		$read = array_filter( $operations, static fn( Operation $operation ): bool => $operation->effect === Operation::READ );
		$write = array_diff_key( $operations, $read );
		$reviews = null;
		$network_scopes = $this->network ? $this->network->scopes() : array();
		if ( $network_scopes ) { $read += $this->network->tools(); }
		if ( ( $write || in_array( Network::WRITE, $network_scopes, true ) ) && Settings::writes_enabled() && Queue::available() ) {
			$reviews = new Reviews( $tokens, $write, $this->network );
			$reviews->hooks();
			$read += $reviews->tools();
		} elseif ( $network_scopes ) {
			$read += array( 'pom_get_task' => Reviews::task_tool() );
		}
		( new Server( $read, $tokens, $logger, $reviews, array( $this->registry, 'items' ) ) )->hooks();
		if ( Settings::oauth_available() ) {
			add_action( 'parse_request', array( new OAuthServer( $tokens, $logger, $reviews !== null, $network_scopes ), 'handle' ), 1 );
		}
	}
}
