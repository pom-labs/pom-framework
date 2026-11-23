<?php

namespace POMFramework\Mcp;

use WP_Error;

final class TokenStore {
	private const REFRESH_RECEIPT_PREFIX = 'pom_mcp_refresh_receipt_';
	private Storage $storage;

	public function __construct( Storage $storage ) {
		$this->storage = $storage;
	}

	public function create_client( string $client_name, array $redirect_uris, array $scopes ) {
		global $wpdb;

		$client_id = 'pommcp_client_' . wp_generate_password( 24, false, false );
		$now       = current_time( 'mysql', true );

		$stored = $wpdb->insert(
			$this->storage->clients_table(),
			array(
				'client_id'      => $client_id,
				'client_name'    => $client_name,
				'redirect_uris'  => wp_json_encode( array_values( $redirect_uris ) ),
				'scope'          => implode( ' ', $scopes ),
				'blog_id'        => get_current_blog_id(),
				'created_at'     => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( $stored === false ) { return new WP_Error( 'oauth_storage_unavailable', 'Client registration could not be saved.', array( 'status' => 503 ) ); }
		$client = $this->get_client( $client_id );
		return $client ?: new WP_Error( 'oauth_storage_unavailable', 'Client registration could not be read back.', array( 'status' => 503 ) );
	}

	public function get_client( string $client_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->storage->clients_table()} WHERE client_id = %s AND blog_id = %d",
				$client_id,
				get_current_blog_id()
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array();
		}

		$row['redirect_uris'] = $this->json_array( $row['redirect_uris'] ?? '' );
		$row['scope']         = isset( $row['scope'] ) ? preg_split( '/\s+/', (string) $row['scope'] ) : array();

		return $row;
	}

	public function redirect_uri_allowed( array $client, string $redirect_uri ): bool {
		return in_array( $redirect_uri, $client['redirect_uris'] ?? array(), true );
	}

	public function store_auth_code( string $client_id, int $user_id, string $redirect_uri, array $scopes, string $resource, string $code_challenge, string $code_challenge_method ) {
		// Coordinate registration expiry with authorization so a code can never outlive a deleted client.
		return $this->with_client_lock( $client_id, function () use ( $client_id, $user_id, $redirect_uri, $scopes, $resource, $code_challenge, $code_challenge_method ) {
			global $wpdb;
			if ( ! $this->get_client( $client_id ) ) { return new WP_Error( 'invalid_client', 'Register this client again before authorizing it.', array( 'status' => 400 ) ); }

			$code = Pkce::random_token( 'pommcp_code_' );
			$stored = $wpdb->insert(
				$this->storage->auth_codes_table(),
				array(
					'code_hash'             => $this->hash_token( $code ),
					'client_id'             => $client_id,
					'user_id'               => $user_id,
					'blog_id'               => get_current_blog_id(),
					'redirect_uri'          => $redirect_uri,
					'scope'                 => implode( ' ', $scopes ),
					'resource'              => $resource,
					'code_challenge'        => $code_challenge,
					'code_challenge_method' => $code_challenge_method,
					'expires_at'            => gmdate( 'Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS ),
					'created_at'            => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			return $stored === false ? new WP_Error( 'oauth_storage_unavailable', 'Authorization could not be saved.', array( 'status' => 503 ) ) : $code;
		} );
	}

	public function consume_auth_code( string $code, string $client_id, string $resource, string $redirect, string $verifier ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->storage->auth_codes_table()} WHERE code_hash = %s AND client_id = %s AND blog_id = %d AND used_at IS NULL",
				$this->hash_token( $code ),
				$client_id,
				get_current_blog_id()
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || strcmp( (string) $row['expires_at'], gmdate( 'Y-m-d H:i:s' ) ) < 0
			|| $row['resource'] !== $resource || $row['redirect_uri'] !== $redirect
			|| ! Pkce::verify( $verifier, (string) $row['code_challenge'], (string) $row['code_challenge_method'] )
			|| ! Settings::actor_can_connect( (int) $row['user_id'] ) ) {
			return array();
		}

		$now     = current_time( 'mysql', true );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->storage->auth_codes_table()} SET used_at = %s WHERE id = %d AND blog_id = %d AND used_at IS NULL AND expires_at >= %s",
				$now,
				(int) $row['id'],
				get_current_blog_id(),
				$now
			)
		);
		if ( $updated !== 1 ) {
			return array();
		}

		$row['scope'] = isset( $row['scope'] ) ? preg_split( '/\s+/', (string) $row['scope'] ) : array();

		return $row;
	}

	public function issue_tokens( string $client_id, int $user_id, array $scopes, string $resource ): array {
		global $wpdb;

		$access_token  = Pkce::random_token( 'pommcp_at_' );
		$refresh_token = Pkce::random_token( 'pommcp_rt_' );
		$now           = current_time( 'mysql', true );

		$stored = $wpdb->insert(
			$this->storage->tokens_table(),
			array(
				'token_hash'         => $this->hash_token( $access_token ),
				'refresh_token_hash' => $this->hash_token( $refresh_token ),
				'client_id'          => $client_id,
				'user_id'            => $user_id,
				'blog_id'            => get_current_blog_id(),
				'scope'              => implode( ' ', $scopes ),
				'resource'           => $resource,
				'expires_at'         => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
				'created_at'         => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( $stored === false ) {
			return array();
		}

		return array(
			'access_token'  => $access_token,
			'refresh_token' => $refresh_token,
			'token_type'    => 'Bearer',
			'expires_in'    => HOUR_IN_SECONDS,
			'scope'         => implode( ' ', $scopes ),
		);
	}

	public function validate_access_token( string $token ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->storage->tokens_table()} WHERE token_hash = %s AND blog_id = %d AND revoked_at IS NULL",
				$this->hash_token( $token ),
				get_current_blog_id()
			),
			ARRAY_A
		);
		return $this->grant_context( $row );
	}

	/** Check the durable grant after an HTTP request has ended, including after token rotation. */
	public function resume( array $reference ): array {
		global $wpdb;
		if ( ! Settings::oauth_available() || empty( $reference['grant_id'] ) || ! is_string( $reference['grant_fingerprint'] ?? null ) ) { return array(); }
		if ( function_exists( 'pom_pomstandard_force_database_reads_to_writer' ) ) { pom_pomstandard_force_database_reads_to_writer(); }
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->storage->tokens_table()} WHERE id = %d AND blog_id = %d AND revoked_at IS NULL",
			(int) $reference['grant_id'], get_current_blog_id()
		), ARRAY_A );
		$context = $this->grant_context( $row, 'refresh_expires_at' );
		return $context && $context['user_id'] === (int) ( $reference['user_id'] ?? 0 )
			&& hash_equals( $context['grant_fingerprint'], $reference['grant_fingerprint'] ) ? $context : array();
	}

	private function grant_context( $row, string $expiry = 'expires_at' ): array {
		if ( ! is_array( $row ) || strcmp( (string) $row[ $expiry ], gmdate( 'Y-m-d H:i:s' ) ) < 0 ) {
			return array();
		}

		if ( (string) $row['resource'] !== Settings::endpoint() ) {
			return array();
		}

		if ( ! Settings::actor_can_connect( (int) $row['user_id'] ) ) {
			return array();
		}

		return array(
			'auth_type' => 'oauth',
			'grant_id'  => (int) $row['id'],
			'grant_fingerprint' => hash_hmac( 'sha256', wp_json_encode( array( $row['id'], $row['blog_id'], $row['user_id'], $row['client_id'], $row['created_at'], $row['resource'], $row['scope'] ) ), wp_salt( 'auth' ) ),
			'user_id'   => (int) $row['user_id'],
			'client_id' => (string) $row['client_id'],
			'scopes'    => isset( $row['scope'] ) ? preg_split( '/\s+/', (string) $row['scope'] ) : array(),
			'resource'  => (string) $row['resource'],
			'rate_key'  => 'oauth:' . (string) $row['client_id'] . ':' . (int) $row['user_id'],
		);
	}

	public function refresh_tokens( string $refresh_token, string $client_id, string $resource ) {
		global $wpdb;
		if ( ! preg_match( '/^pommcp_rt_[A-Za-z0-9_-]{43}$/D', $refresh_token ) ) { return array(); }
		// A brief retry returns the same pair after a lost response. Only hashes are persisted;
		// the presented old secret and server salt are both required to reconstruct that pair.
		$access_token = 'pommcp_at_' . rtrim( strtr( base64_encode( hash_hmac( 'sha256', "access\0" . $refresh_token, wp_salt( 'auth' ), true ) ), '+/', '-_' ), '=' );
		$next_refresh = 'pommcp_rt_' . rtrim( strtr( base64_encode( hash_hmac( 'sha256', "refresh\0" . $refresh_token, wp_salt( 'auth' ), true ) ), '+/', '-_' ), '=' );
		$receipt_name = self::REFRESH_RECEIPT_PREFIX . $this->hash_token( $refresh_token );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->storage->tokens_table()} WHERE refresh_token_hash = %s AND client_id = %s AND blog_id = %d AND revoked_at IS NULL",
				$this->hash_token( $refresh_token ),
				$client_id,
				get_current_blog_id()
			),
			ARRAY_A
		);
		if ( $wpdb->last_error !== '' ) { return new WP_Error( 'temporarily_unavailable', 'Token renewal is temporarily unavailable. Retry later.', array( 'status' => 503 ) ); }

		if ( ! is_array( $row ) ) {
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $receipt_name ) );
			if ( $wpdb->last_error !== '' ) { return new WP_Error( 'temporarily_unavailable', 'Token renewal is temporarily unavailable. Retry later.', array( 'status' => 503 ) ); }
			$receipt = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
			if ( ! is_array( $receipt ) || ( $receipt['expires'] ?? 0 ) < time() || ( $receipt['client_id'] ?? '' ) !== $client_id || ( $receipt['resource'] ?? '' ) !== $resource ) { return array(); }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->storage->tokens_table()} WHERE id = %d AND client_id = %s AND blog_id = %d AND revoked_at IS NULL", $receipt['grant_id'], $client_id, get_current_blog_id() ), ARRAY_A );
			if ( $wpdb->last_error !== '' ) { return new WP_Error( 'temporarily_unavailable', 'Token renewal is temporarily unavailable. Retry later.', array( 'status' => 503 ) ); }
			// A subsequent rotation or revocation immediately invalidates the retry receipt.
			if ( ! $row || ! $this->grant_context( $row ) || ! hash_equals( (string) $row['token_hash'], $this->hash_token( $access_token ) ) || ! hash_equals( (string) $row['refresh_token_hash'], $this->hash_token( $next_refresh ) ) ) { return array(); }
			return array( 'access_token' => $access_token, 'refresh_token' => $next_refresh, 'token_type' => 'Bearer', 'expires_in' => max( 0, strtotime( $row['expires_at'] . ' UTC' ) - time() ), 'scope' => (string) $row['scope'] );
		}
		if ( strcmp( (string) $row['refresh_expires_at'], gmdate( 'Y-m-d H:i:s' ) ) < 0 || (string) $row['resource'] !== $resource
			|| ! Settings::actor_can_connect( (int) $row['user_id'] ) ) {
			return array();
		}

		$now = time();
		// Store recovery before consuming the old token so a terminated response can be retried.
		// The caller holds the client lock across this receipt and the compare-and-swap below.
		delete_option( $receipt_name );
		if ( ! add_option( $receipt_name, array( 'expires' => $now + 30, 'grant_id' => (int) $row['id'], 'client_id' => $client_id, 'resource' => $resource ), '', false ) ) { return new WP_Error( 'temporarily_unavailable', 'Token renewal could not be saved. Retry later.', array( 'status' => 503 ) ); }
		// Keep the grant row stable for queued work, while consuming the old refresh token exactly once.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$this->storage->tokens_table()} SET token_hash = %s, refresh_token_hash = %s, expires_at = %s, refresh_expires_at = %s
			 WHERE id = %d AND client_id = %s AND blog_id = %d AND refresh_token_hash = %s AND revoked_at IS NULL AND refresh_expires_at >= %s",
			$this->hash_token( $access_token ), $this->hash_token( $next_refresh ),
			gmdate( 'Y-m-d H:i:s', $now + HOUR_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $now + 30 * DAY_IN_SECONDS ),
			(int) $row['id'], $client_id, get_current_blog_id(), (string) $row['refresh_token_hash'], gmdate( 'Y-m-d H:i:s', $now )
		) );
		if ( $updated !== 1 ) {
			delete_option( $receipt_name );
			return $updated === false ? new WP_Error( 'temporarily_unavailable', 'Token renewal could not be saved. Retry later.', array( 'status' => 503 ) ) : array();
		}
		Storage::prune_options( self::REFRESH_RECEIPT_PREFIX, 'pom_mcp_refresh_cleanup_cursor' );

		return array(
			'access_token' => $access_token, 'refresh_token' => $next_refresh, 'token_type' => 'Bearer',
			'expires_in' => HOUR_IN_SECONDS, 'scope' => (string) $row['scope'],
		);
	}

	/** Revoke both credentials in one grant without affecting other clients. */
	public function revoke( string $token, string $client_id ) {
		return $this->with_client_lock( $client_id, function () use ( $token, $client_id ) {
			global $wpdb;
			$hash = $this->hash_token( $token );
			return $wpdb->query( $wpdb->prepare(
				"UPDATE {$this->storage->tokens_table()} SET revoked_at = %s WHERE client_id = %s AND blog_id = %d AND revoked_at IS NULL AND (token_hash = %s OR refresh_token_hash = %s)",
				current_time( 'mysql', true ), $client_id, get_current_blog_id(), $hash, $hash
			) ) !== false;
		} );
	}

	/** Serialize exchange and owner revocation so rotation cannot restore removed access. */
	public function with_client_lock( string $client_id, callable $callback ) {
		return $this->storage->with_client_lock( $client_id, $callback );
	}

	/** List only this signed-in user's current agents; never return credentials or hashes. */
	public function authorized_clients( int $after = 0 ) {
		global $wpdb;
		$owner = get_current_user_id();
		if ( ! $owner || ! current_user_can( 'read' ) ) {
			return new WP_Error( 'oauth_owner_required', 'Sign in to manage your agents.', array( 'status' => 403 ) );
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.client_id, c.client_name, MAX(t.created_at) AS authorized_at, GROUP_CONCAT(DISTINCT t.scope SEPARATOR ' ') AS scopes
			 FROM {$this->storage->tokens_table()} t INNER JOIN {$this->storage->clients_table()} c ON c.client_id = t.client_id AND c.blog_id = t.blog_id
			 WHERE t.user_id = %d AND t.blog_id = %d AND t.revoked_at IS NULL AND t.refresh_expires_at >= %s AND c.id > %d
			 GROUP BY c.id, c.client_id, c.client_name ORDER BY c.id ASC LIMIT 21",
			$owner, get_current_blog_id(), gmdate( 'Y-m-d H:i:s' ), max( 0, $after )
		), ARRAY_A );
		if ( $wpdb->last_error !== '' ) {
			return new WP_Error( 'oauth_storage_unavailable', 'Agent access is temporarily unavailable.', array( 'status' => 503 ) );
		}
		$items = array_slice( $rows ?: array(), 0, 20 );
		foreach ( $items as &$row ) {
			$row['scopes'] = array_values( array_unique( preg_split( '/\s+/', trim( $row['scopes'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) ) );
		}
		unset( $row );
		return array( 'agents' => $items, 'next_after' => count( $rows ?: array() ) > 20 ? (int) end( $items )['id'] : null );
	}

	/** Revoke this user's client grants and pending codes without deleting a shared registration. */
	public function revoke_authorized_client( string $client_id ) {
		global $wpdb;
		$owner = get_current_user_id();
		if ( ! $owner || ! current_user_can( 'read' ) || $client_id === '' || strlen( $client_id ) > 191 ) {
			return new WP_Error( 'oauth_owner_required', 'Sign in to manage your agents.', array( 'status' => 403 ) );
		}
		return $this->with_client_lock( $client_id, function () use ( $wpdb, $owner, $client_id ) {
			$now = gmdate( 'Y-m-d H:i:s' );
			$tokens = $wpdb->query( $wpdb->prepare( "UPDATE {$this->storage->tokens_table()} SET revoked_at = %s WHERE user_id = %d AND client_id = %s AND blog_id = %d AND revoked_at IS NULL", $now, $owner, $client_id, get_current_blog_id() ) );
			$codes = $wpdb->query( $wpdb->prepare( "UPDATE {$this->storage->auth_codes_table()} SET used_at = %s WHERE user_id = %d AND client_id = %s AND blog_id = %d AND used_at IS NULL", $now, $owner, $client_id, get_current_blog_id() ) );
			return $tokens === false || $codes === false
				? new WP_Error( 'oauth_storage_unavailable', 'Agent revocation could not be completed. Try again later.', array( 'status' => 503 ) ) : true;
		} );
	}

	private function hash_token( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	private function json_array( string $json ): array {
		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
