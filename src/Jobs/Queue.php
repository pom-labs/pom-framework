<?php

namespace POMFramework\Jobs;

use Throwable;
use WP_Error;

/**
 * Durable, bounded work requested through MCP.
 *
 * WordPress owns scheduling so external cron runners can execute work on their
 * own hosts. Admission and claims use the writer database, not process-local
 * caches or expiring leases: a live operation must never acquire a second worker.
 */
final class Queue {
	public const API_VERSION = 1;
	public const HOOK = 'pom_mcp_run_task';
	private const MAINTENANCE_HOOK = 'pom_mcp_background_maintenance';
	private const SCHEMA = '1';
	private const STORAGE_LIMIT = 128 * 1048576;
	private static array $handlers = array();
	private static array $eligibility = array();
	private static bool $hooked = false;
	private static bool $running = false;
	private static int $boot_blog = 0;
	private const CAPACITY = 64;
	private const RECEIPT_CAPACITY = 512;
	private const RETENTION = 3600;

	public static function hooks(): void {
		if ( self::$hooked ) { return; }
		self::$hooked = true;
		self::$boot_blog = get_current_blog_id();
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 2 );
		add_action( 'wp_scheduled_delete', array( __CLASS__, 'cleanup' ) );
		add_action( self::MAINTENANCE_HOOK, array( __CLASS__, 'maintenance' ) );
	}

	public static function register( string $kind, callable $handler, ?callable $eligible = null ): void {
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/D', $kind ) || isset( self::$handlers[ $kind ] ) ) {
			throw new \InvalidArgumentException( 'Invalid or duplicate background job kind.' );
		}
		self::$handlers[ $kind ] = $handler;
		if ( $eligible !== null ) { self::$eligibility[ $kind ] = $eligible; }
	}
	public static function running(): bool { return self::$running; }

	private static function table(): string {
		global $wpdb;
		if ( function_exists( 'pom_pomstandard_force_database_reads_to_writer' ) ) { pom_pomstandard_force_database_reads_to_writer(); }
		return $wpdb->base_prefix . 'pom_mcp_tasks';
	}

	/** One installation-wide table, created once without inspecting the table catalog. */
	private static function install(): bool {
		global $wpdb;
		$table = self::table();
		if ( get_site_option( 'pom_mcp_tasks_schema' ) === self::SCHEMA ) { return true; }
		if ( (int) get_site_option( 'pom_mcp_tasks_install_retry', 0 ) > time() ) { return false; }
		update_site_option( 'pom_mcp_tasks_install_retry', time() + 60 );
		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id char(32) NOT NULL,
			blog_id bigint unsigned NOT NULL,
			origin_blog bigint unsigned NOT NULL,
			owner char(64) NOT NULL,
			kind varchar(32) NOT NULL,
			state varchar(16) NOT NULL,
			created bigint unsigned NOT NULL,
			started bigint unsigned NOT NULL DEFAULT 0,
			expires bigint unsigned NOT NULL,
			payload longtext NOT NULL,
			result longtext NOT NULL,
			storage_bytes int unsigned NOT NULL DEFAULT 2097152,
			PRIMARY KEY (id), KEY state (state, created), KEY expires (expires), KEY storage (state, storage_bytes)
		) {$wpdb->get_charset_collate()}";
		if ( $wpdb->query( $sql ) === false ) { return false; }
		if ( $wpdb->query( "CREATE TABLE IF NOT EXISTS {$table}_queue (id tinyint unsigned NOT NULL, blog_id bigint unsigned NOT NULL, PRIMARY KEY (id)) {$wpdb->get_charset_collate()}" ) === false ) { return false; }
		update_site_option( 'pom_mcp_tasks_schema', self::SCHEMA );
		delete_site_option( 'pom_mcp_tasks_install_retry' );
		return true;
	}

	/** Use the same connection and routing hint for every global queue lock. */
	private static function lock( string $scope, bool $release = false ): bool {
		global $wpdb;
		$table = self::table();
		$name = 'pom-mcp-task-' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->base_prefix . ':' . $scope ), 0, 48 );
		$sql = $release ? 'RELEASE_LOCK(%s)' : 'GET_LOCK(%s, 0)';
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT {$sql} /* IN_TABLE={$table} */", $name ) ) === '1';
	}

	/** Never execute as a fallback when scheduling or persistence fails. */
	public static function enqueue( string $kind, array $payload, string $owner, int $blog = 0, string $request_id = '' ) {
		global $wpdb;
		$encoded = wp_json_encode( $payload );
		if ( ! self::$hooked || ( $request_id !== '' && ! preg_match( '/^[a-f0-9]{32}$/D', $request_id ) ) || ! isset( self::$handlers[ $kind ] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $owner ) || ! is_string( $encoded ) || strlen( $encoded ) > MB_IN_BYTES ) {
			return new WP_Error( 'pom_mcp_task_invalid', 'The operation is unavailable or exceeds the 1 MiB job limit.', array( 'status' => 400 ) );
		}
		if ( ! self::lock( 'admission' ) ) { return self::busy(); }
		try {
			if ( ! self::install() ) { return new WP_Error( 'pom_mcp_task_storage', 'Background storage is unavailable.', array( 'status' => 503 ) ); }
			$table = self::table();
			if ( $request_id !== '' ) {
				$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, blog_id, origin_blog, owner, kind, state, created, started, expires, result FROM {$table} WHERE id = %s", $request_id ), ARRAY_A );
				if ( $wpdb->last_error !== '' ) { return new WP_Error( 'pom_mcp_task_storage', 'Background storage is unavailable.', array( 'status' => 503 ) ); }
				if ( $existing ) {
					return $existing['owner'] === $owner && $existing['kind'] === $kind && (int) $existing['origin_blog'] === get_current_blog_id() && (int) $existing['blog_id'] === ( $blog ?: get_current_blog_id() )
						? self::status( $request_id, $owner ) : new WP_Error( 'pom_mcp_task_conflict', 'This request ID is already in use.', array( 'status' => 409 ) );
				}
			}
			// Both result and pending storage are bounded, including when cron is stopped.
			$receipts = $wpdb->get_results( $wpdb->prepare( "SELECT state, storage_bytes FROM {$table} FORCE INDEX (storage) LIMIT %d", self::RECEIPT_CAPACITY ), ARRAY_A );
			if ( $wpdb->last_error !== '' ) { return new WP_Error( 'pom_mcp_task_storage', 'Background storage is unavailable.', array( 'status' => 503 ) ); }
			$pending = 0; $bytes = 0;
			foreach ( $receipts as $receipt ) { $pending += in_array( $receipt['state'], array( 'queued', 'scheduled', 'running' ), true ) ? 1 : 0; $bytes += (int) $receipt['storage_bytes']; }
			if ( $pending >= self::CAPACITY ) { return self::busy(); }
			// Reserve the maximum result before accepting a write. Reclaim only terminal receipts,
			// oldest completion first; active work and separate write-approval receipts never move.
			$count = count( $receipts );
			if ( $count >= self::RECEIPT_CAPACITY || $bytes + 2 * MB_IN_BYTES > self::STORAGE_LIMIT ) {
				// Keep ordinary admission on the covering storage index; fetch cleanup metadata only under pressure.
				$receipts = $wpdb->get_results( $wpdb->prepare( "SELECT id, state, storage_bytes FROM {$table} FORCE INDEX (expires) WHERE state IN ('completed','failed','uncertain','expired') ORDER BY expires, id LIMIT %d", self::RECEIPT_CAPACITY ), ARRAY_A );
				if ( $wpdb->last_error !== '' ) { return new WP_Error( 'pom_mcp_task_storage', 'Background storage is unavailable.', array( 'status' => 503 ) ); }
				foreach ( $receipts as $receipt ) {
					if ( $count < self::RECEIPT_CAPACITY && $bytes + 2 * MB_IN_BYTES <= self::STORAGE_LIMIT ) { break; }
					$deleted = $wpdb->delete( $table, array( 'id' => $receipt['id'], 'state' => $receipt['state'] ) );
					if ( $deleted === false ) { return new WP_Error( 'pom_mcp_task_storage', 'Background storage is unavailable.', array( 'status' => 503 ) ); }
					// Concurrent cleanup may already have removed this immutable terminal row.
					--$count;
					$bytes -= (int) $receipt['storage_bytes'];
				}
			}
			if ( $count >= self::RECEIPT_CAPACITY || $bytes + 2 * MB_IN_BYTES > self::STORAGE_LIMIT ) { return self::busy(); }
			$id = $request_id !== '' ? $request_id : bin2hex( random_bytes( 16 ) );
			$blog = $blog ?: get_current_blog_id();
			$record = array( 'id' => $id, 'blog_id' => $blog, 'origin_blog' => get_current_blog_id(), 'owner' => $owner, 'kind' => $kind,
				'state' => 'queued', 'created' => time(), 'expires' => time() + self::RETENTION, 'payload' => $encoded, 'result' => '', 'storage_bytes' => 2 * MB_IN_BYTES );
			if ( ! $wpdb->insert( $table, $record ) ) { return new WP_Error( 'pom_mcp_task_storage', 'The operation could not be saved.', array( 'status' => 503 ) ); }
			if ( ! self::ensure_maintenance() ) {
				$wpdb->delete( $table, array( 'id' => $id, 'state' => 'queued' ) );
				return new WP_Error( 'pom_mcp_task_schedule', 'WordPress could not schedule this operation. No work was started.', array( 'status' => 503 ) );
			}
			// The queue's recovery event is durable before any destination worker is scheduled.
			self::dispatch_locked( $blog );
			$receipt = self::status( $id, $owner );
			// Once accepted, preserve its ID even if the immediate status read is unavailable.
			return is_wp_error( $receipt ) ? self::receipt( $record ) : $receipt;
		} finally { self::lock( 'admission', true ); }
	}

	private static function busy(): WP_Error {
		return new WP_Error( 'pom_mcp_task_busy', 'The background queue is full or busy. Retry later; no operation was started.', array( 'status' => 429, 'retry_after' => 5 ) );
	}

	private static function schedule( string $id, int $blog, int $at ) {
		$switched = is_multisite() && $blog !== get_current_blog_id();
		if ( $switched ) { switch_to_blog( $blog ); }
		try { return wp_get_scheduled_event( self::HOOK, array( $id, $blog ) ) ? true : wp_schedule_single_event( $at, self::HOOK, array( $id, $blog ), true ); }
		finally { if ( $switched ) { restore_current_blog(); } }
	}

	/** One installation-wide wakeup replaces an event for every waiting task. Called under admission lock. */
	private static function ensure_maintenance( bool $active = true ): bool {
		global $wpdb;
		$table = self::table();
		// An idle destination must not put another site's pending work to sleep.
		if ( ! $active ) {
			$active = (bool) $wpdb->get_var( "SELECT id FROM {$table} FORCE INDEX (state) WHERE state IN ('queued','scheduled','running') LIMIT 1" );
			if ( $wpdb->last_error !== '' ) { return false; }
		}
		$blog = (int) $wpdb->get_var( "SELECT blog_id FROM {$table}_queue WHERE id = 1" );
		if ( ! $blog || ! self::active_site( $blog ) ) {
			// Rehoming must not leave a second wakeup behind if the old site is reactivated.
			if ( $blog && is_multisite() && get_site( $blog ) ) {
				switch_to_blog( $blog );
				try { wp_clear_scheduled_hook( self::MAINTENANCE_HOOK ); }
				finally { restore_current_blog(); }
			}
			$blog = get_current_blog_id();
			if ( $wpdb->replace( "{$table}_queue", array( 'id' => 1, 'blog_id' => $blog ) ) === false ) { return false; }
		}
		$due = $active ? time() + 60 : (int) $wpdb->get_var( "SELECT expires FROM {$table} ORDER BY expires LIMIT 1" ) + 1;
		$switched = is_multisite() && $blog !== get_current_blog_id();
		if ( $switched ) { switch_to_blog( $blog ); }
		try {
			$event = wp_get_scheduled_event( self::MAINTENANCE_HOOK );
			if ( $due === 1 ) { if ( $event ) { wp_unschedule_event( $event->timestamp, self::MAINTENANCE_HOOK ); } return true; }
			$due = max( time() + 1, $due );
			// Keep overdue wakeups eligible: incoming requests must never postpone recovery.
			if ( $event && ( $event->timestamp <= time() || ( $active ? $event->timestamp <= $due : abs( $event->timestamp - $due ) < 2 ) ) ) { return true; }
			if ( $event ) { wp_unschedule_event( $event->timestamp, self::MAINTENANCE_HOOK ); }
			$result = wp_schedule_single_event( $due, self::MAINTENANCE_HOOK, array(), true );
			// Keep the previous recovery point if the scheduler rejected a replacement.
			if ( ( is_wp_error( $result ) || ! $result ) && $event ) { wp_schedule_single_event( $event->timestamp, self::MAINTENANCE_HOOK ); }
			return ! is_wp_error( $result ) && (bool) $result;
		} finally { if ( $switched ) { restore_current_blog(); } }
	}

	private static function active_site( int $blog, string $kind = '' ): bool {
		if ( ! is_multisite() ) { return $blog === get_current_blog_id(); }
		$site = get_site( $blog );
		if ( ! $site || $site->deleted || $site->archived || $site->spam ) { return false; }
		if ( $kind !== '' && isset( self::$eligibility[ $kind ] ) ) {
			return (bool) ( self::$eligibility[ $kind ] )( $blog );
		}
		if ( $kind === '' ) {
			foreach ( self::$eligibility as $eligible ) { if ( $eligible( $blog ) ) { return true; } }
		}
		// A worker must run on the destination URL and still resolve the actual registered handler.
		return $kind !== '' || $blog === self::$boot_blog;
	}

	/** Each destination has one queue head; only the execution lock serializes heavy work. */
	private static function dispatch_locked( int $blog ): void {
		global $wpdb;
		$table = self::table();
		// Pending states contain at most CAPACITY rows, so the state index bounds every lookup.
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} FORCE INDEX (state) WHERE state = 'running' AND blog_id = %d LIMIT 1", $blog ) ) ) { self::ensure_maintenance(); return; }
		for ( $attempt = 0; $attempt < 8; ++$attempt ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, blog_id, kind, state, expires FROM {$table} FORCE INDEX (state) WHERE state = 'scheduled' AND blog_id = %d ORDER BY created, id LIMIT 1", $blog ), ARRAY_A );
			if ( ! $row ) { $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, blog_id, kind, state, expires FROM {$table} FORCE INDEX (state) WHERE state = 'queued' AND blog_id = %d ORDER BY created, id LIMIT 1", $blog ), ARRAY_A ); }
			if ( ! $row ) { self::ensure_maintenance( false ); return; }
			if ( (int) $row['expires'] < time() || ! self::active_site( (int) $row['blog_id'], $row['kind'] ) ) {
				self::finish( $row['id'], 'failed', new WP_Error( 'pom_mcp_task_unavailable', 'The queued operation expired or its destination is no longer active.' ), $row['state'] );
				continue;
			}
			if ( $row['state'] === 'queued' && $wpdb->update( $table, array( 'state' => 'scheduled' ), array( 'id' => $row['id'], 'state' => 'queued' ) ) !== 1 ) { return; }
			$scheduled = self::schedule( $row['id'], $blog, time() );
			if ( ! is_wp_error( $scheduled ) && $scheduled ) { self::ensure_maintenance(); return; }
			self::finish( $row['id'], 'failed', new WP_Error( 'pom_mcp_task_schedule', 'WordPress could not schedule this operation. No work was started.' ), 'scheduled' );
		}
		self::ensure_maintenance();
	}

	private static function dispatch( int $blog = 0 ): void {
		if ( get_site_option( 'pom_mcp_tasks_schema' ) !== self::SCHEMA || ! self::lock( 'admission' ) ) { return; }
		try { self::dispatch_locked( $blog ?: get_current_blog_id() ); }
		finally { self::lock( 'admission', true ); }
	}

	/** Recover a lost event or abandoned claim without ever replaying an operation that started. */
	public static function maintenance(): void {
		global $wpdb;
		if ( ! self::lock( 'admission' ) ) {
			// The admission owner normally arms recovery; retain a wakeup if it was interrupted.
			if ( ! wp_get_scheduled_event( self::MAINTENANCE_HOOK ) ) { wp_schedule_single_event( time() + 60, self::MAINTENANCE_HOOK ); }
			return;
		}
		try {
			if ( ! self::install() ) { return; }
			// Arm recovery before inspecting jobs, so a killed maintenance process has a successor.
			self::ensure_maintenance();
		} finally { self::lock( 'admission', true ); }
		self::cleanup();
		$table = self::table();
		// Visit only destinations with pending rows, never enumerate the multisite network.
		$blogs = $wpdb->get_col( $wpdb->prepare( "SELECT blog_id FROM {$table} FORCE INDEX (state) WHERE state IN ('queued','scheduled') LIMIT %d", self::CAPACITY ) );
		foreach ( array_unique( array_map( 'intval', $blogs ) ) as $blog ) { self::dispatch( $blog ); }
		if ( ! $blogs ) { self::dispatch(); }
	}

	private static function receipt( array $row ): array {
		$age = max( 0, time() - (int) ( $row['created'] ?? time() ) );
		$terminal = in_array( $row['state'], array( 'completed', 'failed', 'uncertain', 'expired' ), true );
		$phases = array( 'queued' => 'waiting_for_dispatch', 'scheduled' => 'waiting_for_cron', 'running' => 'executing' );
		$phase = $phases[ $row['state'] ] ?? $row['state'];
		$result = array( 'task_id' => $row['id'], 'status' => $row['state'] === 'scheduled' ? 'queued' : $row['state'], 'blog_id' => (int) $row['blog_id'],
			'phase' => $phase, 'terminal' => $terminal, 'created_at' => gmdate( 'c', (int) $row['created'] ),
			'started_at' => empty( $row['started'] ) ? null : gmdate( 'c', (int) $row['started'] ),
			'expires_at' => $row['state'] === 'running' ? null : gmdate( 'c', (int) $row['expires'] ), 'poll_after_seconds' => min( 30, 5 + 5 * intdiv( $age, 30 ) ),
			'next' => 'Wait for this task and poll again after poll_after_seconds until terminal is true. Do not resubmit the original operation.' );
		if ( $terminal ) {
			$result['result'] = json_decode( $row['result'], true );
			$result['retention'] = 'Available until expires_at unless older terminal receipts are reclaimed for new work. Save this result; a missing receipt never permits replaying a write.';
			unset( $result['poll_after_seconds'], $result['next'] );
		}
		return $result;
	}

	/** Lookup is exact and scoped to the original actor, credential and connection site. */
	public static function status( string $id, string $owner ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $id ) || ! get_site_option( 'pom_mcp_tasks_schema' ) ) { return new WP_Error( 'pom_mcp_task_missing', 'Task not found.', array( 'status' => 404 ) ); }
		$table = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, blog_id, state, created, started, expires, result FROM {$table} WHERE id = %s AND owner = %s AND origin_blog = %d", $id, $owner, get_current_blog_id() ), ARRAY_A );
		if ( ! $row || ( $row['state'] !== 'running' && (int) $row['expires'] < time() ) ) { return new WP_Error( 'pom_mcp_task_missing', 'Task not found or its receipt expired. Inspect the site before repeating a write.', array( 'status' => 404 ) ); }
		// Only maintenance can confirm abandonment after acquiring the execution lock.
		// Elapsed time alone cannot make a still-running operation terminal.
		return self::receipt( $row );
	}

	/** Exactly one active heavy operation across all sites using MCP. */
	public static function run( string $id, int $blog ): void {
		global $wpdb;
		if ( self::$running || ! preg_match( '/^[a-f0-9]{32}$/D', $id ) || $blog !== get_current_blog_id() ) { return; }
		if ( get_site_option( 'pom_mcp_tasks_schema' ) !== self::SCHEMA ) { self::maintenance(); return; }
		$table = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT state FROM {$table} WHERE id = %s AND blog_id = %d", $id, $blog ), ARRAY_A );
		if ( ! $row || $row['state'] !== 'scheduled' ) { if ( ( $row['state'] ?? '' ) === 'queued' ) { self::dispatch(); } return; }
		if ( $blog !== self::$boot_blog ) {
			self::finish( $id, 'failed', new WP_Error( 'pom_mcp_task_bootstrap', 'The cron runner must start WordPress for the destination site. Switching database context alone cannot load its theme and plugins.' ), 'scheduled' );
			self::dispatch();
			return;
		}
		// Spread retries so many destination sites cannot create a five-second cron stampede.
		if ( ! self::lock( 'execution' ) ) {
			$scheduled = self::schedule( $id, $blog, time() + random_int( 30, 60 ) );
			if ( is_wp_error( $scheduled ) || ! $scheduled ) { self::finish( $id, 'failed', new WP_Error( 'pom_mcp_task_schedule', 'WordPress could not reschedule this operation. No work was started.' ), 'scheduled' ); self::dispatch(); }
			return;
		}
		$claimed = false;
		try {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s AND blog_id = %d AND state = 'scheduled'", $id, $blog ), ARRAY_A );
			if ( ! $row ) { return; }
			if ( (int) $row['expires'] < time() ) {
				self::finish( $id, 'expired', new WP_Error( 'pom_mcp_task_expired', 'The operation expired before it started.' ), 'scheduled' );
				return;
			}
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state = 'running', started = %d WHERE id = %s AND state = 'scheduled'", time(), $id ) ) === 1;
			if ( ! $claimed ) { return; }
			self::$running = true;
			$payload = json_decode( $row['payload'], true );
			$result = isset( self::$handlers[ $row['kind'] ] ) && is_array( $payload )
				? call_user_func( self::$handlers[ $row['kind'] ], $payload, $row )
				: new WP_Error( 'pom_mcp_task_unavailable', 'This feature is no longer available on the destination site.' );
			self::finish( $id, is_wp_error( $result ) ? 'failed' : 'completed', $result );
		} catch ( Throwable $error ) {
			if ( $claimed ) { self::finish( $id, 'uncertain', new WP_Error( 'pom_mcp_task_unconfirmed', 'The operation stopped without confirmed completion. Inspect the site before repeating a write.' ) ); }
		} finally { self::$running = false; self::lock( 'execution', true ); self::dispatch(); }
	}

	private static function finish( string $id, string $state, $result, string $expected = 'running' ): void {
		global $wpdb;
		if ( is_wp_error( $result ) ) { $result = array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'data' => $result->get_error_data() ); }
		$json = wp_json_encode( $result );
		if ( ! is_string( $json ) || strlen( $json ) > 2 * MB_IN_BYTES ) {
			$state = 'uncertain';
			$json = wp_json_encode( array( 'code' => 'pom_mcp_task_result_size', 'message' => 'The result could not be retained. Inspect the site before repeating a write; use smaller read operations.' ) );
		}
		$table = self::table();
		$wpdb->update( $table, array( 'state' => $state, 'expires' => time() + self::RETENTION, 'result' => $json, 'payload' => '', 'storage_bytes' => strlen( $json ) ), array( 'id' => $id, 'state' => $expected ) );
	}

	public static function cleanup(): void {
		global $wpdb;
		if ( get_site_option( 'pom_mcp_tasks_schema' ) !== self::SCHEMA ) { return; }
		$table = self::table();
		// A running task is retained until its worker releases the installation lock.
		if ( ! self::lock( 'cleanup' ) ) { return; }
		try {
			if ( ! self::$running && self::lock( 'execution' ) ) {
				try {
					// No worker owns the connection lock, so abandoned claims cannot resume.
					$json = wp_json_encode( array( 'code' => 'pom_mcp_task_unconfirmed', 'message' => 'The worker stopped without a confirmed result. Inspect the site before repeating a write.' ) );
					$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state = 'uncertain', expires = %d, payload = '', result = %s, storage_bytes = %d WHERE state = 'running' LIMIT 64", time() + self::RETENTION, $json, strlen( $json ) ) );
				} finally { self::lock( 'execution', true ); }
			}
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, blog_id, state FROM {$table} FORCE INDEX (expires) WHERE expires < %d AND state <> 'running' ORDER BY expires LIMIT 100", time() ), ARRAY_A );
			foreach ( $rows as $row ) {
				// A queued row may have been claimed or finished since this cleanup snapshot.
				if ( $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %s AND state = %s AND expires < %d", $row['id'], $row['state'], time() ) ) !== 1 ) { continue; }
				if ( $row['state'] === 'scheduled' ) {
					$switched = is_multisite() && (int) $row['blog_id'] !== get_current_blog_id();
					if ( $switched ) { switch_to_blog( (int) $row['blog_id'] ); }
					try { wp_clear_scheduled_hook( self::HOOK, array( $row['id'], (int) $row['blog_id'] ) ); }
					finally { if ( $switched ) { restore_current_blog(); } }
				}
			}
		}
		finally { self::lock( 'cleanup', true ); }
	}
}
