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

	/** Register a packaged, read-only skill after its product provider. */
	public static function register_skill( string $provider, string $name, string $label, string $directory, array $files = array( 'SKILL.md' ) ): void {
		if ( self::$instance !== null ) {
			self::$instance->registry->register_skill( $provider, new SkillPackage( $name, $label, $directory, $files ) );
		}
	}

	private function __construct() {
		$this->registry = new Registry();
		if ( is_multisite() ) { $this->network = new Network( array( $this, 'operations' ), $this->registry ); }
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

	/** @return array<string,SkillPackage> */
	public function skills(): array {
		return $this->registry->skills();
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
			function ( array $args, array $actor ): array {
				$result = array(
					'site' => array( 'site_id' => Settings::identity(), 'site_url' => Settings::issuer(), 'blog_id' => get_current_blog_id() ),
					'contract_version' => self::API_VERSION,
					'providers' => array_keys( $this->providers() ),
					'multisite' => is_multisite(),
				);
				if ( AccountDestination::actor( $actor ) ) {
					$result['account_destination_version'] = AccountDestination::VERSION;
					$result['connection'] = array( 'auth_type' => 'oauth', 'actor_user_id' => $actor['user_id'], 'client_id' => $actor['client_id'], 'scopes' => $actor['scopes'] );
				}
				return $result;
			},
			null,
			true,
			Operation::READ
		) );
	}

	public function start(): void {
		// Keep this controller and its late-loaded runtime classes in the same package.
		// An unrelated plugin can prepend an older Composer loader during bootstrap.
		spl_autoload_register( static function ( string $class ): void {
			$prefix = __NAMESPACE__ . '\\';
			if ( strpos( $class, $prefix ) !== 0 ) { return; }
			$short_name = substr( $class, strlen( $prefix ) );
			if ( ! preg_match( '/^[A-Z][A-Za-z0-9_]*$/D', $short_name ) ) { return; }
			$file = __DIR__ . '/' . $short_name . '.php';
			if ( is_file( $file ) ) { require_once $file; }
		}, true, true );
		if ( $this->network ) { $this->network->hooks(); }
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) { Queue::hooks(); }
		if ( ! Settings::get()['enabled'] ) {
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
		if ( ( ! AccountGateway::registered() && ( $write || in_array( Network::WRITE, $network_scopes, true ) ) ) && Settings::writes_enabled() ) {
			$reviews = new Reviews( $tokens, $write, $this->network );
			$reviews->hooks();
			$read += $reviews->tools();
		} elseif ( $network_scopes || AccountGateway::registered() ) {
			$read += array( 'pom_get_task' => Reviews::task_tool() );
		}
		( new Server( $read, $tokens, $logger, $reviews, array( $this->registry, 'items' ) ) )->hooks();
		if ( Settings::oauth_available() ) {
			add_action( 'parse_request', array( new OAuthServer( $tokens, $logger, $reviews !== null, $network_scopes ), 'handle' ), 1 );
		}
	}
}
