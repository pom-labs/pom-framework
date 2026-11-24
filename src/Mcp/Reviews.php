<?php

namespace POMFramework\Mcp;

use POMFramework\Jobs\Queue;
use WP_Error;

/** Dispatch reviewed provider operations through the destination's normal cron bootstrap. */
final class Reviews {
	public const TOOL_NAMES = array( 'pom_preview_operation', 'pom_prepare_review', 'pom_get_review', 'pom_apply_review', 'pom_get_task' );
	private const KIND = 'pom_mcp_reviewed_write';
	private const ACTION = 'pom_mcp_review';
	private TokenStore $tokens;
	private ReviewStore $store;
	private ?Network $network;
	/** @var array<string,Operation> */
	private array $operations;

	public function __construct( TokenStore $tokens, array $operations, ?Network $network = null ) {
		$this->tokens = $tokens;
		$this->store = new ReviewStore();
		$this->operations = $operations;
		$this->network = $network;
	}

	public function hooks(): void {
		Queue::hooks();
		Queue::register( self::KIND, array( $this, 'execute_task' ), static function ( int $blog ): bool {
			$site = get_blog_option( $blog, Settings::OPTION, array() );
			return is_array( $site ) && ! empty( $site['enabled'] ) && ! empty( $site['writes'] ) && ! empty( $site['oauth'] );
		} );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'browser_review' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, 'auth_redirect' );
	}

	/** These endpoints use the authenticated request's actor, outside the provider's argument schema. */
	public function tools(): array {
		$id = array( 'type' => 'string', 'format' => 'uuid' );
		$read = array( 'review_id' => $id );
		$available = array_keys( array_filter( $this->operations, static fn( Operation $operation ): bool => $operation->allows_site_writes() ) );
		$tools = array(
			'pom_preview_operation' => array( 'Preview a change', 'Read the exact proposed change and the current revision without opening a review.',
				array( 'operation' => array( 'type' => 'string', 'enum' => $available ), 'arguments' => array( 'type' => 'object' ) ),
				array( 'operation', 'arguments' ), array( $this, 'inspect_operation' ) ),
			'pom_prepare_review' => array( 'Prepare a write review', 'Preview an available operation and request exact approval. Approval does not apply the operation.',
				array( 'operation' => array( 'type' => 'string', 'enum' => $available ), 'arguments' => array( 'type' => 'object' ),
					'review_mode' => array( 'type' => 'string', 'enum' => array( 'auto', 'web' ), 'description' => 'Use web to require a signed-in WordPress decision.' ) ),
				array( 'operation', 'arguments' ), array( $this, 'prepare' ) ),
			'pom_get_review' => array( 'Get a write review', 'Get the status and receipt of a review requested by this connection.', $read, array( 'review_id' ), array( $this, 'inspect' ) ),
			'pom_apply_review' => array( 'Queue a reviewed change', 'Queue the exact reviewed operation after approval. Poll the returned task instead of repeating the write.', $read,
				array( 'review_id' ), array( $this, 'enqueue' ) ),
		);
		$operations = array( 'pom_get_task' => self::task_tool() );
		if ( ! $available ) { unset( $tools['pom_preview_operation'], $tools['pom_prepare_review'] ); }
		foreach ( $tools as $name => [ $label, $description, $properties, $required, $execute ] ) {
			$operations[ $name ] = new Operation( $name, $label, $description, Operation::READ,
				array( 'type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => $required ), '__return_true', $execute );
		}
		return $operations;
	}

	public static function task_tool(): Operation {
		return new Operation( 'pom_get_task', 'Get a queued task', 'Read task status and the result for this connection.',
			Operation::READ, array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'task_id' ),
				'properties' => array( 'task_id' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{32}$' ) ) ),
			'__return_true', static function ( array $args, array $actor ) {
				return ( $actor['auth_type'] ?? '' ) === 'oauth' ? Queue::status( $args['task_id'], self::owner( $actor ) )
					: new WP_Error( 'pom_mcp_task_scope', 'An OAuth connection is required.', array( 'status' => 403 ) );
			}, null, true );
	}

	public static function record( string $label, string $name, array $args, array $preview, array $actor, string $mode ): array {
		return array( 'user' => get_current_user_id(), 'blog' => get_current_blog_id(), 'tool' => $label,
			'owner' => self::owner( $actor ), 'connection' => self::reference( $actor ), 'entries' => array( ReviewStore::entry( $name, $args, $preview ) ),
			'status' => 'pending', 'expires' => time() + 15 * MINUTE_IN_SECONDS, 'review_mode' => $mode );
	}

	public static function task_id( string $id, string $owner ): string {
		return substr( hash_hmac( 'sha256', 'review-application:' . $id . ':' . $owner, wp_salt( 'auth' ) ), 0, 32 );
	}

	private function allows_record( array $record, array $actor ): bool {
		if ( isset( $record['network'] ) ) {
			return $this->network && $this->network->review_allowed( $record, $actor );
		}
		$operation = $this->operations[ $record['entries'][0]['tool'] ?? '' ] ?? null;
		return $operation && $operation->allows_site_writes() && ( ! AccountDestination::actor( $actor ) || AccountDestination::allows( $operation ) );
	}

	public function authorizes( array $actor ): bool {
		return ( $actor['auth_type'] ?? '' ) === 'oauth' && (int) ( $actor['user_id'] ?? 0 ) === get_current_user_id()
			&& Settings::writes_enabled() && in_array( Settings::WRITE_SCOPE, $actor['scopes'] ?? array(), true );
	}

	public function account_operations(): array {
		$result = array();
		foreach ( $this->operations as $operation ) {
			if ( $operation->allows_site_writes() && AccountDestination::allows( $operation ) ) {
				$result[] = array( 'name' => $operation->name, 'description' => $operation->description, 'inputSchema' => $operation->schema );
			}
		}
		return $result;
	}

	public function inspect_operation( array $input, array $actor ) {
		$operation = $this->operations[ $input['operation'] ] ?? null;
		return $operation && ( ! AccountDestination::actor( $actor ) || AccountDestination::allows( $operation ) )
			? self::preview_operation( $operation, $input['arguments'], $actor, false ) : $this->unavailable();
	}

	public function prepare( array $input, array $actor ) {
		$name = $input['operation'];
		$args = $input['arguments'];
		$operation = $this->operations[ $name ] ?? null;
		if ( ! $operation || ( AccountDestination::actor( $actor ) && ! AccountDestination::allows( $operation ) ) ) { return $this->unavailable(); }
		$preview = self::preview_operation( $operation, $args, $actor );
		if ( is_wp_error( $preview ) ) { return $preview; }
		$mode = $operation->effect === Operation::COST || AccountDestination::actor( $actor ) ? 'web' : ( $input['review_mode'] ?? 'auto' );
		$record = self::record( $operation->label, $name, $args, $preview, $actor, $mode );
		$id = $this->store->create( $record );
		return is_wp_error( $id ) ? $id : self::describe( $id, $record );
	}

	public function inspect( array $input, array $actor ) {
		[ $record ] = $this->store->load( $input['review_id'] );
		return $this->owns( $record, $actor ) ? self::describe( $input['review_id'], $record ) : $this->unavailable();
	}

	/** Only a response to an outstanding human confirmation can use this path. */
	public function client_decide( string $id, array $actor, string $decision ) {
		$record = $this->store->load( $id );
		if ( ! $this->owns( $record[0], $actor ) || ! $this->authorizes( $this->tokens->resume( self::reference( $actor ) ) ) || ! $this->allows_record( $record[0], $actor ) ) { return $this->unavailable(); }
		$result = $this->store->decide( $record, $decision, 'mcp_client' );
		$this->log_decision( $decision, $result, $actor );
		return $result;
	}

	public function enqueue( array $input, array $actor ) {
		$id = $input['review_id'];
		[ $record ] = $this->store->load( $id );
		if ( ! $this->owns( $record, $actor ) ) { return $this->unavailable(); }
		$task = self::task_id( $id, $record['owner'] );
		if ( $record['status'] !== 'approved' || ( $record['entries'][0]['status'] ?? '' ) !== 'pending' ) {
			$existing = Queue::status( $task, $record['owner'] );
			return is_wp_error( $existing ) ? $this->unavailable() : $existing;
		}
		if ( ! $this->allows_record( $record, $actor ) ) { return $this->unavailable(); }
		if ( isset( $record['network'] ) ) {
			return $this->network ? $this->network->enqueue_review( $id, $record, $actor ) : $this->unavailable();
		}
		$payload = array( 'review' => $id, 'connection' => self::reference( $actor ), 'site' => Settings::identity(), 'issuer' => Settings::issuer() );
		$result = Queue::enqueue( self::KIND, $payload, $record['owner'], get_current_blog_id(), $task );
		return is_wp_error( $result ) ? $result : array_merge( $result, array( 'poll_tool' => 'pom_get_task' ) );
	}

	public function execute_task( array $payload, array $row ) {
		$result = $this->run_task( $payload, $row );
		( new Log( new Storage() ) )->record( 'review_execution', is_wp_error( $result ) ? 'error' : 'success', array( 'auth_type' => 'oauth', 'user_id' => (int) ( $payload['connection']['user_id'] ?? 0 ) ) );
		return $result;
	}

	private function run_task( array $payload, array $row ) {
		if ( ! Queue::running() || ( $payload['site'] ?? '' ) !== Settings::identity() || ( $payload['issuer'] ?? '' ) !== Settings::issuer()
			|| (int) $row['origin_blog'] !== get_current_blog_id() || ! is_array( $payload['connection'] ?? null ) || ! is_string( $payload['review'] ?? null ) ) { return $this->unavailable(); }
		$reference = $payload['connection'];
		clean_user_cache( (int) ( $reference['user_id'] ?? 0 ) );
		$actor = $this->tokens->resume( $reference );
		if ( ! $actor ) { return $this->unavailable(); }
		$previous = get_current_user_id();
		wp_set_current_user( $actor['user_id'] );
		try {
			$stored = $this->store->load( $payload['review'] );
			if ( ! $this->authorizes( $actor ) || ! $this->owns( $stored[0], $actor ) || isset( $stored[0]['network'] ) || ! hash_equals( $row['owner'], self::owner( $actor ) ) ) { return $this->unavailable(); }
			$entry = $stored[0]['entries'][0];
			$operation = $this->operations[ $entry['tool'] ] ?? null;
			if ( ! $operation || ( AccountDestination::actor( $actor ) && ! AccountDestination::allows( $operation ) ) ) { return $this->unavailable(); }
			$preview = self::preview_operation( $operation, $entry['arguments'], $actor );
			if ( is_wp_error( $preview ) ) { return $preview; }
			if ( ! $this->authorizes( $this->tokens->resume( $reference ) ) ) { return $this->unavailable(); }
			$permission = ( $operation->permission )( $entry['arguments'] );
			if ( $permission !== true ) { return is_wp_error( $permission ) ? $permission : $this->unavailable(); }
			return $this->store->apply( $stored, $operation->name, $entry['arguments'], $preview, function ( array $args ) use ( $operation, $actor ) {
				if ( ! $operation->allows_site_writes() ) { return $this->unavailable(); }
				return $operation->uses_actor ? ( $operation->execute )( $args, $actor ) : ( $operation->execute )( $args );
			}, fn() => $this->unavailable() );
		} finally { wp_set_current_user( $previous ); }
	}

	public static function preview_operation( Operation $operation, array $args, array $actor, bool $require_revision = true ) {
		if ( ! $operation->allows_site_writes() ) {
			return new WP_Error( 'pom_mcp_write_permission', 'This operation is disabled in the site’s MCP write permissions.', array( 'status' => 403 ) );
		}
		$valid = rest_validate_value_from_schema( $args, $operation->schema, 'arguments' );
		if ( is_wp_error( $valid ) ) { return $valid; }
		// WordPress accepts string booleans. A reviewed value must retain its actual JSON type.
		if ( ! self::strict_booleans( $args, $operation->schema ) ) {
			return new WP_Error( 'pom_mcp_invalid_boolean', 'Boolean arguments must use JSON true or false.', array( 'status' => 400 ) );
		}
		$allowed = ( $operation->permission )( $args );
		if ( $allowed !== true ) { return is_wp_error( $allowed ) ? $allowed : new WP_Error( 'pom_mcp_review_permission', 'The account cannot preview this operation.', array( 'status' => 403 ) ); }
		$preview = $operation->uses_actor ? ( $operation->preview )( $args, $actor ) : ( $operation->preview )( $args );
		$error = ReviewStore::check_preview( $preview, $args, $require_revision );
		if ( $error ) { return $error; }
		if ( $operation->effect === Operation::COST ) {
			$estimate = $preview['estimated_credits'] ?? null;
			$limit = $args['max_credits'] ?? null;
			if ( ( ! is_int( $estimate ) && ! is_float( $estimate ) ) || ! is_finite( (float) $estimate ) || $estimate < 0
				|| ( $require_revision && ! isset( $preview['revision'] ) )
				|| ( $require_revision && $limit === null ) || ( $limit !== null && ( ( ! is_int( $limit ) && ! is_float( $limit ) ) || ! is_finite( (float) $limit ) || $limit < $estimate ) ) ) {
				return new WP_Error( 'pom_mcp_credit_limit', 'Approve a numeric credit limit at least as high as the current estimate.', array( 'status' => 409 ) );
			}
		}
		return $preview;
	}

	private static function strict_booleans( $value, array $schema ): bool {
		if ( ( $schema['type'] ?? '' ) === 'boolean' ) { return is_bool( $value ); }
		if ( ! is_array( $value ) ) { return true; }
		foreach ( $value as $name => $child ) {
			$child_schema = $schema['properties'][ $name ] ?? $schema['items'] ?? array();
			if ( ! self::strict_booleans( $child, $child_schema ) ) { return false; }
		}
		return true;
	}

	public function browser_review(): void {
		$id = isset( $_REQUEST['review_id'] ) && is_string( $_REQUEST['review_id'] ) ? wp_unslash( $_REQUEST['review_id'] ) : '';
		$stored = $this->store->load( $id );
		$record = $stored[0];
		if ( ! $record ) { wp_die( esc_html__( 'This review is unavailable or expired.', 'pom-framework' ), '', array( 'response' => 404 ) ); }
		$actor = $this->tokens->resume( $record['connection'] );
		if ( ! $this->authorizes( $actor ) || ! $this->owns( $record, $actor ) || ! $this->allows_record( $record, $actor ) ) {
			wp_die( esc_html__( 'The connection no longer authorizes this review.', 'pom-framework' ), '', array( 'response' => 403 ) );
		}
		RequestPolicy::send_no_store();
		$nonce = self::ACTION . '_' . $id;
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' ) {
			check_admin_referer( $nonce );
			$decision = isset( $_POST['decision'] ) && is_string( $_POST['decision'] ) ? wp_unslash( $_POST['decision'] ) : '';
			$result = $this->store->decide( $stored, $decision, 'wordpress' );
			$this->log_decision( $decision, $result, array( 'auth_type' => 'wordpress', 'user_id' => get_current_user_id() ) );
			if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) ); }
			wp_safe_redirect( self::url( $id ), 303 );
			exit;
		}
		header( 'X-Frame-Options: DENY', true );
		$description = self::describe( $id, $record );
		ob_start();
		?>
		<p><?php esc_html_e( 'Review the exact change below. Approval lets your assistant queue it and does not apply it immediately.', 'pom-framework' ); ?></p>
		<p><?php echo esc_html( $description['site_url'] . ' · ' . $description['expires_at'] ); ?></p>
		<pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo esc_html( wp_json_encode( $description['operations'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
		<?php if ( $record['status'] === 'pending' ) : ?>
			<form method="post" action="<?php echo esc_url( self::url( $id ) ); ?>">
				<?php wp_nonce_field( $nonce ); ?>
				<button type="submit" name="decision" value="approved"><?php esc_html_e( 'Approve change', 'pom-framework' ); ?></button>
				<button type="submit" name="decision" value="rejected"><?php esc_html_e( 'Reject change', 'pom-framework' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
		wp_die( ob_get_clean(), esc_html( $record['tool'] ), array( 'response' => 200 ) );
	}

	private function log_decision( string $decision, $result, array $actor ): void {
		$status = is_wp_error( $result ) || ! in_array( $decision, array( 'approved', 'rejected' ), true ) ? 'error' : $decision;
		( new Log( new Storage() ) )->record( 'review_decision', $status, $actor );
	}

	public static function describe( string $id, array $record ): array {
		$status = in_array( 'executing', array_column( $record['entries'], 'status' ), true ) ? 'executing_or_uncertain' : $record['status'];
		return array( 'review_id' => $id, 'approval_status' => $status, 'approval_required' => $status === 'pending',
			'review_url' => self::url( $id ), 'title' => $record['tool'], 'site_url' => $record['network']['issuer'] ?? Settings::issuer(), 'expires_at' => gmdate( 'c', $record['expires'] ),
			'operations' => $record['entries'], 'review_mode' => $record['review_mode'], 'decision' => $record['decision'] ?? null );
	}

	private function owns( ?array $record, array $actor ): bool {
		return $record !== null && hash_equals( $record['owner'], self::owner( $actor ) );
	}

	public static function reference( array $actor ): array {
		return array_intersect_key( $actor, array_flip( array( 'user_id', 'grant_id', 'grant_fingerprint' ) ) );
	}

	public static function owner( array $actor ): string {
		return hash_hmac( 'sha256', wp_json_encode( array( get_current_blog_id(), Settings::issuer(), Settings::identity(), $actor['user_id'] ?? 0,
			$actor['grant_id'] ?? 0, $actor['grant_fingerprint'] ?? '' ) ), wp_salt( 'auth' ) );
	}

	private static function url( string $id ): string {
		return add_query_arg( array( 'action' => self::ACTION, 'review_id' => $id ), admin_url( 'admin-post.php' ) );
	}

	private function unavailable(): WP_Error {
		return new WP_Error( 'pom_mcp_review_unavailable', 'The review or its authorization is unavailable. Inspect any existing task before retrying.', array( 'status' => 409 ) );
	}
}
