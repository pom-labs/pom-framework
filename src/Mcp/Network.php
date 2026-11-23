<?php

namespace POMFramework\Mcp;

use POMFramework\Jobs\Queue;
use POMFramework\Licensing\License_Client;
use POMFramework\Licensing\License_Registry;
use WP_Error;

/** Use the destination's normal WordPress worker without sharing its credentials or REST endpoint. */
final class Network {
	public const OPTION = 'pom_mcp_network';
	public const READ = 'network:sites:read';
	public const WRITE = 'network:sites:write';
	private const JOB = 'pom_mcp_network_work';
	private const ACTION = 'pom_mcp_network_settings';
	private const GROUPS = array(
		'content_writes' => 'Page content',
		'site_content_writes' => 'Content, forms, media and taxonomy',
		'theme_writes' => 'Theme settings, models, templates and search',
		'option_writes' => 'Supported WordPress site settings',
		'woocommerce_writes' => 'WooCommerce catalogue and coupons',
		'cache_writes' => 'Site cache maintenance',
	);
	/** @var callable */
	private $catalog;
	private Registry $registry;
	private static int $write_blog = 0;

	/** The local transport can stay off on a destination that explicitly accepts delegated work. */
	public static function delegated_write_active(): bool {
		return self::$write_blog === get_current_blog_id() && Queue::running();
	}

	public function __construct( callable $catalog, Registry $registry ) {
		$this->catalog = $catalog;
		$this->registry = $registry;
	}

	public static function policy(): array {
		$stored = is_multisite() ? (array) get_network_option( get_current_network_id(), self::OPTION, array() ) : array();
		return array(
			'enabled' => ( $stored['enabled'] ?? false ) === true,
			'writes' => ( $stored['writes'] ?? false ) === true,
			'groups' => array_values( array_intersect( array_keys( self::GROUPS ), (array) ( $stored['groups'] ?? array() ) ) ),
			'sites' => array_values( array_filter( (array) ( $stored['sites'] ?? array() ), static fn( $id ): bool => is_int( $id ) && $id > 0 ) ),
			'generation' => (string) ( $stored['generation'] ?? '' ),
		);
	}

	public static function main(): int {
		return is_multisite() ? get_main_site_id( get_current_network_id() ) : 0;
	}

	public function hooks(): void {
		if ( ! is_multisite() ) { return; }
		add_action( 'network_admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
		if ( self::policy()['enabled'] && Queue::available() ) {
			Queue::hooks();
			Queue::register( self::JOB, array( $this, 'work' ), static function ( int $blog ): bool {
				return self::allowed_site( $blog, self::policy() );
			} );
		}
	}

	public function shared_origin(): bool {
		return self::main() === get_current_blog_id() && self::policy()['enabled'] && Settings::oauth_available() && Queue::available();
	}

	public function scopes(): array {
		if ( ! $this->shared_origin() ) { return array(); }
		return Settings::writes_enabled() && self::policy()['writes'] && self::policy()['groups'] ? array( self::READ, self::WRITE ) : array( self::READ );
	}

	private static function allowed_site( int $blog, array $policy ): bool {
		if ( ! $policy['enabled'] || $blog === self::main() || ! in_array( $blog, $policy['sites'], true ) ) { return false; }
		$site = get_site( $blog );
		if ( ! $site || (int) $site->network_id !== get_current_network_id() || $site->archived || $site->deleted || $site->spam ) {
			return false;
		}
		// A scheduled job needs at least one provider bootstrap on the actual destination.
		// Activation can change while an item waits. Check it again at the worker.
		$network_plugins = (array) get_network_option( get_current_network_id(), 'active_sitewide_plugins', array() );
		$active_plugins = (array) get_blog_option( $blog, 'active_plugins', array() );
		$theme = get_blog_option( $blog, 'template' ) === 'pom' && is_readable( get_theme_root( 'pom' ) . '/pom/includes/mcp/class-provider.php' );
		$cache = ( isset( $network_plugins['pom-cache/pom-cache.php'] ) || in_array( 'pom-cache/pom-cache.php', $active_plugins, true ) )
			&& is_readable( WP_PLUGIN_DIR . '/pom-cache/includes/mcp/class-provider.php' );
		return apply_filters( 'pom_mcp_network_worker_available', $theme || $cache, $blog ) === true;
	}

	private static function authorize( array $actor, bool $write ): bool {
		$user = (int) ( $actor['user_id'] ?? 0 );
		$scopes = $write ? array( Settings::READ_SCOPE, Settings::WRITE_SCOPE, self::READ, self::WRITE ) : array( Settings::READ_SCOPE, self::READ );
		return $user > 0 && ( $actor['auth_type'] ?? '' ) === 'oauth' && is_super_admin( $user ) && user_can( $user, 'manage_network_options' )
			&& ! array_diff( $scopes, $actor['scopes'] ?? array() ) && ( ! $write || ( self::policy()['writes'] && Settings::writes_enabled() ) );
	}

	public function tools(): array {
		if ( ! $this->shared_origin() ) { return array(); }
		$blog = array( 'type' => 'integer', 'minimum' => 1 );
		$name = array( 'type' => 'string', 'maxLength' => 180 );
		$argument = array( 'type' => 'object' );
		$tools = array(
			'pom_network_get_context' => array( 'Get network delegation context', array(), array(), fn() => array( 'origin_blog' => self::main(), 'reviewed_writes' => in_array( self::WRITE, $this->scopes(), true ), 'write_groups' => self::policy()['groups'], 'execution' => 'wordpress_cron' ) ),
			'pom_network_list_sites' => array( 'List delegated destinations', array(
				'after_blog_id' => array( 'type' => 'integer', 'minimum' => 0 ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
			), array(), array( $this, 'sites' ) ),
			'pom_network_discover_site' => array( 'Discover a destination catalogue', array( 'blog_id' => $blog, 'kind' => array( 'type' => 'string', 'enum' => array( 'tools', 'resources', 'prompts' ) ) ), array( 'blog_id', 'kind' ),
				fn( array $args, array $actor ) => $this->admit( 'discover', $args, $actor ) ),
			'pom_network_read_site' => array( 'Read a destination operation', array( 'blog_id' => $blog, 'name' => $name, 'arguments' => $argument,
				'kind' => array( 'type' => 'string', 'enum' => array( 'tool', 'resource', 'prompt' ) ) ), array( 'blog_id', 'name' ),
				fn( array $args, array $actor ) => $this->admit( 'read', $args, $actor ) ),
			'pom_network_execute_site' => array( 'Prepare a reviewed destination change', array( 'blog_id' => $blog, 'name' => $name, 'arguments' => $argument,
				'preview_only' => array( 'type' => 'boolean', 'description' => 'Return the change and current revision without opening a review.' ) ), array( 'blog_id', 'name' ),
				fn( array $args, array $actor ) => $this->admit( 'prepare', $args, $actor ) ),
		);
		if ( ! in_array( self::WRITE, $this->scopes(), true ) ) { unset( $tools['pom_network_execute_site'] ); }
		$result = array();
		foreach ( $tools as $slug => [ $label, $properties, $required, $callback ] ) {
			$result[ $slug ] = new Operation( $slug, $label, $label . '. Destination work runs through WordPress cron.', Operation::READ,
				array( 'type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => $required ), '__return_true',
				static function ( array $args, array $actor ) use ( $callback, $slug ) {
					return self::authorize( $actor, $slug === 'pom_network_execute_site' ) ? $callback( $args, $actor ) : self::denied();
				}, null, true );
		}
		return $result;
	}

	private function sites( array $args ): array {
		$policy = self::policy();
		$after = (int) ( $args['after_blog_id'] ?? 0 );
		$limit = (int) ( $args['limit'] ?? 50 );
		$ids = array_filter( $policy['sites'], static fn( int $id ): bool => $id > $after );
		sort( $ids );
		$sites = array();
		$page = array_slice( $ids, 0, $limit );
		foreach ( $page as $blog ) {
			if ( self::allowed_site( $blog, $policy ) ) {
				$sites[] = array( 'blog_id' => $blog, 'name' => (string) get_blog_option( $blog, 'blogname' ), 'site_url' => untrailingslashit( (string) get_blog_option( $blog, 'home' ) ) );
			}
		}
		return array( 'sites' => $sites, 'next_after_blog_id' => count( $ids ) > $limit ? end( $page ) : null );
	}

	/** Keep page-only grants narrow and require explicit groups for every delegated write. */
	public static function write_allowed( string $group, string $name, array $args, bool $discovery = false ): bool {
		$policy = self::policy();
		if ( ! $policy['writes'] ) { return false; }
		if ( in_array( $group, $policy['groups'], true ) ) { return true; }
		return $group === 'site_content_writes' && $name === 'pom_site_save_content' && in_array( 'content_writes', $policy['groups'], true )
			&& ( $discovery || ( ( $args['post_type'] ?? '' ) === 'page' && ( empty( $args['id'] ) || get_post_type( (int) $args['id'] ) === 'page' ) ) );
	}

	private function admit( string $action, array $args, array $actor, string $review = '' ) {
		$blog = (int) $args['blog_id'];
		$policy = self::policy();
		if ( ! $this->shared_origin() || ! self::allowed_site( $blog, $policy ) ) { return self::denied(); }
		if ( isset( $args['preview_only'] ) && ! is_bool( $args['preview_only'] ) ) {
			return new WP_Error( 'pom_mcp_invalid_boolean', 'Preview-only must use JSON true or false.', array( 'status' => 400 ) );
		}
		$owner = Reviews::owner( $actor );
		$payload = array( 'action' => $action, 'connection' => Reviews::reference( $actor ), 'generation' => $policy['generation'],
			'issuer' => untrailingslashit( (string) get_blog_option( $blog, 'home' ) ), 'args' => $args, 'review' => $review );
		$task = $review === '' ? '' : Reviews::task_id( $review, $owner );
		$result = Queue::enqueue( self::JOB, $payload, $owner, $blog, $task );
		return is_wp_error( $result ) ? $result : $result + array( 'poll_tool' => 'pom_get_task' );
	}

	public function review_allowed( array $record, array $actor ): bool {
		$destination = $record['network'];
		$policy = self::policy();
		return $this->shared_origin() && self::authorize( $actor, true ) && self::allowed_site( $destination['blog'], $policy )
			&& ! self::legacy_origin() && hash_equals( $policy['generation'], $destination['generation'] )
			&& $destination['issuer'] === untrailingslashit( (string) get_blog_option( $destination['blog'], 'home' ) );
	}

	public function enqueue_review( string $id, array $record, array $actor ) {
		if ( ! $this->review_allowed( $record, $actor ) ) { return self::denied(); }
		return $this->admit( 'apply', array( 'blog_id' => $record['network']['blog'] ), $actor, $id );
	}

	public function work( array $payload, array $row ) {
		$blog = get_current_blog_id();
		$origin = (int) $row['origin_blog'];
		$policy = self::policy();
		if ( ! Queue::running() || ! Settings::secure_site() || class_exists( 'POM_AI_MCP_Bootstrap', false ) || class_exists( 'POM_AI_Account_MCP', false )
			|| $origin !== self::main() || ! self::allowed_site( $blog, $policy )
			|| $payload['generation'] !== $policy['generation'] || $payload['issuer'] !== Settings::issuer() ) { return self::denied(); }
		$action = $payload['action'];
		$write = in_array( $action, array( 'prepare', 'apply' ), true );
		$previous = get_current_user_id();
		$actor = $this->at_origin( $origin, static function () use ( $payload, $row, $write, $blog ) {
			if ( ! Settings::get()['enabled'] || ! Settings::oauth_available() || self::legacy_origin() ) { return self::denied(); }
			$user = (int) ( $payload['connection']['user_id'] ?? 0 );
			clean_user_cache( $user );
			$actor = ( new TokenStore( new Storage() ) )->resume( $payload['connection'] );
			if ( ! self::authorize( $actor, $write ) || ! hash_equals( $row['owner'], Reviews::owner( $actor ) ) ) { return self::denied(); }
			$license = self::entitlement();
			if ( is_wp_error( $license ) ) { return $license; }
			$entitled = apply_filters( 'pom_mcp_network_entitlement', true, $blog, $write );
			return $entitled === true ? $actor : ( is_wp_error( $entitled ) ? $entitled : self::denied() );
		} );
		if ( is_wp_error( $actor ) ) {
			$this->log_result( $origin, $blog, $action, $actor );
			return $actor;
		}
		wp_set_current_user( $actor['user_id'] );
		self::$write_blog = $write ? $blog : 0;
		try {
			if ( Settings::identity() === '' ) { return self::denied(); }
			$catalog = ( $this->catalog )();
			switch ( $action ) {
				case 'discover':
					$result = $this->discover( $catalog, $payload['args']['kind'], $actor );
					break;
				case 'apply':
					$result = $this->apply( $origin, $catalog, $payload, $actor );
					break;
				default:
					$result = $this->read_or_prepare( $origin, $catalog, $payload, $actor );
			}
			$this->log_result( $origin, $blog, $action, $result, $actor );
			return $result;
		} finally { self::$write_blog = 0; wp_set_current_user( $previous ); }
	}

	private function log_result( int $origin, int $destination, string $action, $result, array $actor = array() ): void {
		$this->at_origin( $origin, static function () use ( $destination, $action, $result, $actor ): void {
			( new Log( new Storage() ) )->record( 'network_' . $action . '_site_' . $destination, is_wp_error( $result ) ? 'denied' : 'success', $actor );
		} );
	}

	private static function legacy_origin(): bool {
		$legacy = (array) get_network_option( get_current_network_id(), 'pom_ai_mcp_network', array() );
		if ( ! empty( $legacy['enabled'] ) ) { return true; }
		$basename = 'pom-ai/pom-ai.php';
		$active = (array) get_network_option( get_current_network_id(), 'active_sitewide_plugins', array() );
		if ( ! isset( $active[ $basename ] ) && ! in_array( $basename, (array) get_option( 'active_plugins', array() ), true ) ) { return false; }
		return \POMFramework\POM_Framework_Settings::is_setting_enabled( 'ai-mcp', 'pom-ai' );
	}

	/** Preserve the current network entitlement while the platform and products transfer ownership. */
	private static function entitlement() {
		if ( ! is_multisite() || ! is_main_site() || ! class_exists( License_Registry::class ) || ! class_exists( License_Client::class ) ) {
			return new WP_Error( 'pom_mcp_entitlement_unavailable', 'Network MCP entitlement cannot be checked.', array( 'status' => 503 ) );
		}

		$key = License_Registry::get_key( 'pom-ai', License_Registry::management_scope( 'pom-ai' ) );
		if ( ! is_string( $key ) || $key === '' ) {
			return new WP_Error( 'pom_mcp_entitlement_required', 'Connect an active POM AI Unlimited license on the network main site.', array( 'status' => 403 ) );
		}

		// Fresh validation must never enroll a domain as a side effect of a delegated job.
		$limit = static function ( array $args, string $url ) use ( $key ): array {
			$body = is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true ) : null;
			if ( $url === License_Client::api_base() . '/license/validate' && ( $body['product'] ?? '' ) === 'pom-ai' && ( $body['license_key'] ?? '' ) === $key ) {
				$args['timeout'] = min( 5, (float) ( $args['timeout'] ?? 5 ) );
			}
			return $args;
		};
		add_filter( 'http_request_args', $limit, 100, 2 );
		try { $result = License_Client::validate( 'pom-ai', $key, false, defined( 'POM_AI_VERSION' ) ? POM_AI_VERSION : '' ); }
		finally { remove_filter( 'http_request_args', $limit, 100 ); }
		$error = $result['error_code'] ?? '';
		if ( in_array( $error, array( 'network_error', 'licensing_unavailable', 'signing_error', 'rate_limited' ), true ) ) {
			return new WP_Error( 'pom_mcp_entitlement_unavailable', 'Network MCP entitlement cannot be checked.', array( 'status' => 'rate_limited' === $error ? 429 : 503 ) );
		}

		return ( $result['valid'] ?? false ) === true && ( $result['network_mcp_allowed'] ?? false ) === true
			&& ( $result['domain_authorized'] ?? false ) === true && empty( $result['requires_reactivation'] )
			? true : new WP_Error( 'pom_mcp_entitlement_required', 'A current POM AI Unlimited network entitlement is required.', array( 'status' => 403 ) );
	}

	private function discover( array $catalog, string $kind, array $actor ): array {
		if ( $kind !== 'tools' ) {
			$items = array();
			foreach ( $this->registry->items() as $item ) {
				if ( ! $item->delegated || $item->kind . 's' !== $kind || ( $item->permission )() !== true ) { continue; }
				$items[] = array( 'name' => $item->name, 'description' => $item->description ) + ( $item->uri ? array( 'uri' => $item->uri, 'mimeType' => $item->mime_type ) : array( 'arguments' => $item->arguments ) );
			}
			return array( $kind => $items );
		}
		$tools = array();
		foreach ( $catalog as $name => $operation ) {
			if ( $operation->effect === Operation::COST ) { continue; }
			if ( $operation->effect === Operation::READ && $operation->network_group !== Operation::READ ) { continue; }
			if ( $operation->effect === Operation::WRITE && ( ! in_array( self::WRITE, $actor['scopes'], true )
				|| ! self::write_allowed( $operation->network_group, $name, array(), true ) ) ) { continue; }
			$tools[] = array( 'name' => $name, 'description' => $operation->description, 'inputSchema' => $operation->schema, 'write' => $operation->effect === Operation::WRITE, 'network_group' => $operation->network_group );
		}
		return array( 'tools' => $tools, 'site_id' => Settings::identity() );
	}

	private function read_or_prepare( int $origin, array $catalog, array $payload, array $actor ) {
		$input = $payload['args'];
		$name = $input['name'];
		$args = $input['arguments'] ?? array();
		$kind = $input['kind'] ?? 'tool';
		if ( $payload['action'] === 'read' && $kind !== 'tool' ) {
			foreach ( $this->registry->items() as $item ) {
				if ( ! $item->delegated || $item->kind !== $kind || ( $item->uri ?: $item->name ) !== $name || ( $item->permission )() !== true ) { continue; }
				$properties = array();
				$required = array();
				foreach ( $item->arguments as $argument ) {
					$properties[ $argument['name'] ] = array( 'type' => 'string' );
					if ( ! empty( $argument['required'] ) ) { $required[] = $argument['name']; }
				}
				$valid = rest_validate_value_from_schema( $args, array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false ), 'arguments' );
				return is_wp_error( $valid ) ? $valid : ( $item->read )( $args );
			}
			return self::denied();
		}
		$operation = $catalog[ $name ] ?? null;
		if ( $payload['action'] === 'read' ) { return $operation && $operation->effect === Operation::READ && $operation->network_group === Operation::READ ? self::invoke( $operation, $args, $actor, false ) : self::denied(); }
		if ( $payload['action'] !== 'prepare' || ! $operation || $operation->effect !== Operation::WRITE || ! self::write_allowed( $operation->network_group, $operation->name, $args ) ) { return self::denied(); }
		$preview_only = ( $input['preview_only'] ?? false ) === true;
		$preview = Reviews::preview_operation( $operation, $args, $actor, ! $preview_only );
		if ( is_wp_error( $preview ) || $preview_only ) { return $preview; }
		$network = array( 'blog' => get_current_blog_id(), 'issuer' => Settings::issuer(), 'uuid' => Settings::identity(), 'generation' => $payload['generation'] );
		return $this->at_origin( $origin, static function () use ( $actor, $name, $args, $preview, $network, $operation ) {
			$record = Reviews::record( $operation->label, $name, $args, $preview, $actor, 'web' ) + array( 'network' => $network );
			$id = ( new ReviewStore() )->create( $record );
			return is_wp_error( $id ) ? $id : Reviews::describe( $id, $record );
		} );
	}

	private function apply( int $origin, array $catalog, array $payload, array $actor ) {
		$store = new ReviewStore();
		$stored = $this->at_origin( $origin, fn() => $store->load( $payload['review'] ) );
		$record = $stored[0];
		if ( ! $record || ! isset( $record['network'] ) || $record['status'] !== 'approved' || $record['network']['issuer'] !== Settings::issuer() || $record['network']['uuid'] !== Settings::identity()
			|| $record['network']['blog'] !== get_current_blog_id() || $record['network']['generation'] !== $payload['generation'] ) { return self::denied(); }
		$entry = $record['entries'][0];
		$operation = $catalog[ $entry['tool'] ] ?? null;
		if ( ! $operation || $operation->effect !== Operation::WRITE || ! self::write_allowed( $operation->network_group, $operation->name, $entry['arguments'] ) ) { return self::denied(); }
		$preview = Reviews::preview_operation( $operation, $entry['arguments'], $actor );
		if ( is_wp_error( $preview ) ) { return $preview; }
		$target = get_current_blog_id();
		return $this->at_origin( $origin, function () use ( $store, $stored, $entry, $preview, $actor, $operation, $target, $origin ) {
			$live = ( new TokenStore( new Storage() ) )->resume( Reviews::reference( $actor ) );
			if ( ! $this->review_allowed( $stored[0], $live ) || ! hash_equals( $stored[0]['owner'], Reviews::owner( $live ) ) ) { return self::denied(); }
			return $store->apply( $stored, $operation->name, $entry['arguments'], $preview, function ( array $args ) use ( $operation, $actor, $target, $origin ) {
				// Restore the real, already-bootstrapped destination before invoking product code.
				restore_current_blog();
				try { return get_current_blog_id() === $target ? self::invoke( $operation, $args, $actor, true ) : self::denied(); }
				finally { switch_to_blog( $origin ); }
			}, static fn() => self::denied() );
		} );
	}

	private static function invoke( Operation $operation, array $args, array $actor, bool $write ) {
		$valid = rest_validate_value_from_schema( $args, $operation->schema, 'arguments' );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$permission = ( $operation->permission )( $args );
		if ( $permission !== true || $write !== ( $operation->effect === Operation::WRITE ) ) { return is_wp_error( $permission ) ? $permission : self::denied(); }
		return $operation->uses_actor ? ( $operation->execute )( $args, $actor ) : ( $operation->execute )( $args );
	}

	/** Switching is solely for storage. Destination handlers always run on the originally bootstrapped site. */
	private function at_origin( int $blog, callable $callback ) {
		switch_to_blog( $blog );
		try { return $callback(); }
		finally { restore_current_blog(); }
	}

	private static function denied(): WP_Error {
		return new WP_Error( 'pom_mcp_network_denied', 'The original grant, network policy or destination no longer permits this operation.', array( 'status' => 403 ) );
	}

	public function menu(): void {
		if ( get_current_blog_id() === self::main() ) {
			add_submenu_page( 'settings.php', 'POM MCP Network', 'POM MCP', 'manage_network_options', 'pom-mcp-network', array( $this, 'settings' ) );
		}
	}

	public function settings(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) { wp_die( 'Network administrator permission is required.' ); }
		$p = self::policy();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'POM MCP Network', 'pom-framework' ); ?></h1>
			<?php if ( self::legacy_origin() ) : ?>
				<p><?php esc_html_e( 'This network already has a POM AI MCP owner. Manage its connection in the existing network settings.', 'pom-framework' ); ?></p>
			</div>
				<?php return; ?>
			<?php endif; ?>
			<p><?php esc_html_e( 'Connect to the main site with OAuth. List each destination explicitly. Its active products determine which operations are available.', 'pom-framework' ); ?></p>
			<form method="post" action="<?php echo esc_url( get_admin_url( self::main(), 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<p><label><input type="checkbox" name="enabled" value="1" <?php checked( $p['enabled'] ); ?>> <?php esc_html_e( 'Enable network delegation', 'pom-framework' ); ?></label></p>
				<p><label><input type="checkbox" name="writes" value="1" <?php checked( $p['writes'] ); ?>> <?php esc_html_e( 'Allow reviewed destination changes', 'pom-framework' ); ?></label></p>
				<p><label for="pom-mcp-destinations"><?php esc_html_e( 'Destination site IDs, one per line', 'pom-framework' ); ?></label></p>
				<textarea id="pom-mcp-destinations" class="large-text code" name="sites" rows="6"><?php echo esc_textarea( implode( "\n", $p['sites'] ) ); ?></textarea>
				<fieldset>
					<legend><?php esc_html_e( 'Allowed write groups', 'pom-framework' ); ?></legend>
					<?php foreach ( self::GROUPS as $key => $label ) : ?>
						<p><label><input type="checkbox" name="groups[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $p['groups'], true ) ); ?>> <?php echo esc_html( $label ); ?></label></p>
					<?php endforeach; ?>
				</fieldset>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function save(): void {
		if ( get_current_blog_id() !== self::main() || ! current_user_can( 'manage_network_options' ) || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) { wp_die( 'Network administrator permission is required.' ); }
		check_admin_referer( self::ACTION );
		if ( self::legacy_origin() ) { wp_die( esc_html__( 'An existing POM AI MCP owner manages this network.', 'pom-framework' ), '', array( 'response' => 409 ) ); }
		$raw = isset( $_POST['sites'] ) && is_string( $_POST['sites'] ) ? wp_unslash( $_POST['sites'] ) : '';
		$values = preg_split( '/\s+/', trim( $raw ), -1, PREG_SPLIT_NO_EMPTY );
		if ( strlen( $raw ) > 65536 || count( $values ) > 5000 ) { wp_die( 'List up to 5,000 destination site IDs.' ); }
		$sites = array();
		foreach ( $values as $value ) {
			$site = ctype_digit( $value ) ? get_site( (int) $value ) : null;
			if ( ! $site || (int) $site->network_id !== get_current_network_id() || (int) $site->blog_id === self::main() || $site->deleted || $site->archived || $site->spam ) {
				wp_die( 'Every ID must identify an active destination in this network.' );
			}
			$sites[] = (int) $site->blog_id;
		}
		$sites = array_values( array_unique( $sites ) );
		sort( $sites );
		$groups = array_intersect( array_keys( self::GROUPS ), array_filter( (array) ( $_POST['groups'] ?? array() ), 'is_string' ) );
		$values = array( 'enabled' => ( $_POST['enabled'] ?? '' ) === '1', 'writes' => ( $_POST['writes'] ?? '' ) === '1', 'groups' => array_values( $groups ), 'sites' => $sites );
		$old = self::policy();
		if ( array_intersect_key( $old, $values ) !== $values ) { update_network_option( get_current_network_id(), self::OPTION, $values + array( 'generation' => wp_generate_uuid4() ) ); }
		wp_safe_redirect( network_admin_url( 'settings.php?page=pom-mcp-network' ), 303 );
		exit;
	}
}
