<?php

namespace POMFramework\Mcp;

use WP_Error;

/** Initialize the connection store only when an administrator enables the service. */
final class Storage {
	public const CLEANUP = 'pom_mcp_cleanup';
	public const CLEANUP_BATCH = 'pom_mcp_cleanup_batch';
	private const VERSION_OPTION = 'pom_mcp_storage_version';
	private const VERSION = '1';

	public function ready(): bool {
		return (string) get_option( self::VERSION_OPTION ) === self::VERSION;
	}

	public function install() {
		$cron = self::CLEANUP;
		if ( $this->ready() && wp_get_schedule( $cron ) === 'hourly' ) { return true; }
		return $this->locked( 'install', function () use ( $cron ) {
			$version_option = self::VERSION_OPTION;
			wp_cache_delete( $version_option, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( ! $this->ready() ) {
				if ( ! $this->create_tables() ) {
					return new WP_Error( 'pom_mcp_storage_unavailable', 'POM MCP storage could not be created.' );
				}
				update_option( $version_option, self::VERSION, false );
				if ( ! $this->ready() ) { return new WP_Error( 'pom_mcp_storage_unavailable', 'POM MCP storage could not be saved.' ); }
			}

			// Re-read cron under the same writer lock so concurrent setup keeps one event.
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'cron', 'options' );
			if ( wp_get_schedule( $cron ) !== 'hourly' ) {
				wp_clear_scheduled_hook( $cron );
				return wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', $cron, array(), true );
			}
			return true;
		} );
	}

	public function with_client_lock( string $client, callable $callback ) {
		return $this->locked( 'oauth:' . $client, $callback );
	}

	/** Serialize setup and OAuth changes on the writer to keep grants and revocation consistent. */
	public function locked( string $scope, callable $callback ) {
		global $wpdb;
		$table = $this->tokens_table();
		$identity = $table . ':' . $scope;
		if ( strpos( $scope, 'review-admission:' ) === 0 ) {
			$table = $wpdb->options;
			$identity = DB_NAME . ':' . $table . ':' . $scope;
		}
		$name = 'pom-mcp-' . substr( hash( 'sha256', $identity ), 0, 48 );
		return $this->named_lock( $name, $table, $callback );
	}

	/** Revoke the current grant even if the client lost the last refresh response. */
	public function revoke_grant( string $token, string $client_id, string $resource ) {
		return $this->with_client_lock( $client_id, function () use ( $token, $client_id, $resource ) {
			global $wpdb;
			$table = $this->tokens_table();
			$salt = wp_salt( 'auth' );
			$hash = hash_hmac( 'sha256', $token, $salt );
			$now = gmdate( 'Y-m-d H:i:s' );
			$blog = get_current_blog_id();
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET revoked_at = %s WHERE client_id = %s AND blog_id = %d AND revoked_at IS NULL AND (token_hash = %s OR refresh_token_hash = %s)",
				$now, $client_id, $blog, $hash, $hash
			) );
			$prefix = 'pommcp_rt_';
			if ( $updated !== 0 || ! preg_match( '/^' . $prefix . '[A-Za-z0-9_-]{43}$/D', $token ) ) { return $updated !== false; }

			$name = $this->prefix() . 'refresh_receipt_' . $hash;
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( $wpdb->last_error !== '' ) { return false; }
			$receipt = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
			if ( ! is_array( $receipt ) || ( $receipt['expires'] ?? 0 ) < time()
				|| ( $receipt['client_id'] ?? '' ) !== $client_id || ( $receipt['resource'] ?? '' ) !== $resource ) { return true; }
			$replacement = $prefix . rtrim( strtr( base64_encode( hash_hmac( 'sha256', "refresh\0" . $token, $salt, true ) ), '+/', '-_' ), '=' );
			return $wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET revoked_at = %s WHERE id = %d AND client_id = %s AND blog_id = %d AND resource = %s AND revoked_at IS NULL AND refresh_token_hash = %s",
				$now, (int) $receipt['grant_id'], $client_id, $blog, $resource, hash_hmac( 'sha256', $replacement, $salt )
			) ) !== false;
		} );
	}

	private function named_lock( string $name, string $table, callable $callback ) {
		global $wpdb;
		if ( function_exists( 'pom_pomstandard_force_database_reads_to_writer' ) ) {
			pom_pomstandard_force_database_reads_to_writer();
		}
		if ( (string) $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK(%s, 0) /* IN_TABLE={$table} */", $name ) ) !== '1' ) {
			return new WP_Error( 'temporarily_unavailable', 'POM MCP storage is busy. Try again later.', array( 'status' => 503 ) );
		}
		try {
			return $callback();
		} finally {
			$wpdb->get_var( $wpdb->prepare( "SELECT RELEASE_LOCK(%s) /* IN_TABLE={$table} */", $name ) );
		}
	}

	private function prefix(): string {
		return 'pom_mcp_';
	}

	/** Read one expiring record at a time and advance an indexed cursor instead of loading large jobs. */
	public static function prune_options( string $prefix, string $cursor_option ): void {
		global $wpdb;
		$cursor = (string) get_option( $cursor_option, '' );
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name LIMIT 10", $wpdb->esc_like( $prefix ) . '%', $cursor ) );
		if ( $wpdb->last_error !== '' || ( ! $names && $cursor === '' ) ) { return; }
		foreach ( $names as $name ) {
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			$record = maybe_unserialize( $raw );
			if ( is_array( $record ) && ( $record['expires'] ?? PHP_INT_MAX ) < time() ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s", $name, $raw ) );
				wp_cache_delete( $name, 'options' );
			}
		}
		update_option( $cursor_option, count( $names ) === 10 ? end( $names ) : '', false );
	}

	private function create_tables(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$logs_table      = $this->logs_table();
		$clients_table   = $this->clients_table();
		$codes_table     = $this->auth_codes_table();
		$tokens_table    = $this->tokens_table();
		$rate_table      = $wpdb->prefix . $this->prefix() . 'rate_limits';

		// These fields are still part of the historical logger and registration contract.

		dbDelta(
			array(
				"CREATE TABLE {$rate_table} (
					bucket_key char(64) NOT NULL,
					window_start bigint unsigned NOT NULL,
					requests int unsigned NOT NULL,
					expires_at bigint unsigned NOT NULL,
					PRIMARY KEY  (bucket_key),
					KEY expires_at (expires_at)
				) {$charset_collate};",
				"CREATE TABLE {$logs_table} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					timestamp datetime NOT NULL,
					actor_user_id bigint(20) unsigned NULL,
					auth_type varchar(40) NOT NULL DEFAULT 'none',
					action varchar(100) NOT NULL,
					status varchar(50) NOT NULL DEFAULT 'success',
					PRIMARY KEY  (id),
					KEY timestamp (timestamp),
					KEY action (action),
					KEY status (status)
				) {$charset_collate};",
				"CREATE TABLE {$clients_table} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					client_id varchar(191) NOT NULL,
					client_name varchar(191) NOT NULL,
					redirect_uris longtext NOT NULL,
					scope longtext NULL,
					blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
					created_at datetime NOT NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY client_id (client_id),
					KEY blog_id (blog_id),
					KEY created_at (created_at)
				) {$charset_collate};",
				"CREATE TABLE {$codes_table} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					code_hash varchar(255) NOT NULL,
					client_id varchar(191) NOT NULL,
					user_id bigint(20) unsigned NOT NULL,
					blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
					redirect_uri text NOT NULL,
					scope longtext NULL,
					resource text NOT NULL,
					code_challenge varchar(255) NOT NULL,
					code_challenge_method varchar(20) NOT NULL,
					expires_at datetime NOT NULL,
					used_at datetime NULL,
					created_at datetime NOT NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY code_hash (code_hash),
					KEY client_id (client_id),
					KEY user_id (user_id),
					KEY blog_id (blog_id),
					KEY expires_at (expires_at),
					KEY used_at (used_at)
				) {$charset_collate};",
				"CREATE TABLE {$tokens_table} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					token_hash varchar(255) NOT NULL,
					refresh_token_hash varchar(255) NOT NULL,
					client_id varchar(191) NOT NULL,
					user_id bigint(20) unsigned NOT NULL,
					blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
					scope longtext NULL,
					resource text NOT NULL,
					expires_at datetime NOT NULL,
					refresh_expires_at datetime NOT NULL,
					revoked_at datetime NULL,
					created_at datetime NOT NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY token_hash (token_hash),
					KEY refresh_token_hash (refresh_token_hash),
					KEY client_id (client_id),
					KEY user_id (user_id),
					KEY blog_id (blog_id),
					KEY expires_at (expires_at),
					KEY refresh_expires_at (refresh_expires_at),
					KEY revoked_at (revoked_at)
				) {$charset_collate};",
			)
		);
		foreach ( array( $rate_table, $logs_table, $clients_table, $codes_table, $tokens_table ) as $table ) {
			if ( $wpdb->query( "SELECT * FROM {$table} LIMIT 0" ) === false ) { return false; }
		}
		return true;
	}

	public function cleanup(): void {
		if ( ! $this->ready() ) {
			return;
		}
		$this->locked( 'cleanup', array( $this, 'prune' ) );
	}

	private function prune(): void {
		global $wpdb;
		$prefix = $this->prefix();
		self::prune_options( $prefix . 'refresh_receipt_', $prefix . 'refresh_cleanup_cursor' );

		$now = current_time( 'mysql', true );
		$more = false;
		foreach ( array( array( $this->auth_codes_table(), 'expires_at', '<' ), array( $this->auth_codes_table(), 'used_at', '<=' ), array( $this->tokens_table(), 'refresh_expires_at', '<' ), array( $this->tokens_table(), 'revoked_at', '<=' ) ) as $target ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$target[0]} WHERE {$target[1]} {$target[2]} %s ORDER BY {$target[1]} LIMIT 500", $now ) );
			$more = $more || $wpdb->rows_affected === 500;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->logs_table()} WHERE timestamp < %s ORDER BY timestamp LIMIT 500", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		$more = $more || $wpdb->rows_affected === 500;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}{$prefix}rate_limits WHERE expires_at < %d ORDER BY expires_at LIMIT 500", time() ) );
		$more = $more || $wpdb->rows_affected === 500;
		// A bounded cursor visits old registrations even when the oldest one still has an active grant.
		$cursor = (int) get_option( $prefix . 'client_cleanup_cursor', 0 );
		$clients = $wpdb->get_results( $wpdb->prepare( "SELECT id, client_id FROM {$this->clients_table()} WHERE id > %d ORDER BY id LIMIT 100", $cursor ) );
		foreach ( $clients ?: array() as $client ) {
			$this->with_client_lock( $client->client_id, function () use ( $wpdb, $client, $now ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->clients_table()} WHERE id = %d AND created_at < %s
				 AND NOT EXISTS (SELECT 1 FROM {$this->tokens_table()} WHERE client_id = %s AND revoked_at IS NULL AND refresh_expires_at >= %s)
				 AND NOT EXISTS (SELECT 1 FROM {$this->auth_codes_table()} WHERE client_id = %s AND used_at IS NULL AND expires_at >= %s)",
				 $client->id, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), $client->client_id, $now, $client->client_id, $now ) );
			} );
			$cursor = (int) $client->id;
		}
		update_option( $prefix . 'client_cleanup_cursor', count( $clients ?: array() ) === 100 ? $cursor : 0, false );
		if ( ( $more || count( $clients ?: array() ) === 100 ) && ! wp_next_scheduled( $prefix . 'cleanup_batch' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, $prefix . 'cleanup_batch' );
		}
	}

	public function logs_table(): string {
		global $wpdb;

		return $wpdb->prefix . $this->prefix() . 'logs';
	}

	public function clients_table(): string {
		global $wpdb;

		return $wpdb->prefix . $this->prefix() . 'oauth_clients';
	}

	public function auth_codes_table(): string {
		global $wpdb;

		return $wpdb->prefix . $this->prefix() . 'oauth_auth_codes';
	}

	public function tokens_table(): string {
		global $wpdb;

		return $wpdb->prefix . $this->prefix() . 'oauth_tokens';
	}
}
