<?php

namespace POMFramework\Mcp;

use WP_Error;

/** Persist exact human decisions and claim each reviewed operation before its effects start. */
final class ReviewStore {
	private const PREFIX = 'pom_mcp_review_';

	/** Object keys are unordered in JSON. List order and scalar types remain significant. */
	public static function fingerprint( $value ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonical( $value ) ) );
	}

	private static function canonical( $value ) {
		if ( is_object( $value ) ) { $value = (array) $value; }
		if ( ! is_array( $value ) || $value === array() ) { return $value; }
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { ksort( $value ); }
		return array_map( array( self::class, 'canonical' ), $value );
	}

	public static function entry( string $name, array $args, array $preview ): array {
		return array( 'tool' => $name, 'arguments' => $args, 'preview' => $preview,
			'fingerprint' => self::fingerprint( array( $name, $args ) ), 'preview_hash' => self::fingerprint( $preview ), 'status' => 'pending' );
	}

	public static function check_preview( $preview, array $args, bool $require_revision = true ): ?WP_Error {
		$error = self::preview_error( $preview );
		if ( $error !== null ) { return $error; }
		if ( ! is_array( $preview ) || ( $preview['dry_run'] ?? false ) !== true ) {
			return new WP_Error( 'pom_ai_mcp_preview_required', 'This operation did not provide a reviewable preview.', array( 'status' => 409 ) );
		}
		if ( $require_revision && isset( $preview['revision'] ) && empty( $args['expected_revision'] ) ) {
			return new WP_Error( 'pom_ai_mcp_revision_required', 'Preview the target and supply expected_revision before requesting approval.', array( 'status' => 409 ) );
		}
		return null;
	}

	private static function preview_error( $value ): ?WP_Error {
		if ( is_wp_error( $value ) ) { return $value; }
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				$error = self::preview_error( $child );
				if ( $error !== null ) { return $error; }
			}
		}
		return null;
	}

	public function create( array $record ) {
		$owner = get_current_user_id();
		if ( $owner < 1 || ( $record['user'] ?? 0 ) !== $owner || ( $record['blog'] ?? 0 ) !== get_current_blog_id() ) {
			return new WP_Error( 'pom_ai_mcp_approval_actor', 'A signed-in WordPress actor is required to approve writes.', array( 'status' => 403 ) );
		}
		$encoded = wp_json_encode( $record );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 2 * MB_IN_BYTES ) {
			return new WP_Error( 'pom_ai_mcp_approval_size', 'Use a smaller operation for review.', array( 'status' => 400 ) );
		}
		return ( new Storage() )->locked( 'review-admission:' . self::PREFIX . $owner, function () use ( $record, $owner ) {
			global $wpdb;
			$this->cleanup();
			$prefix = self::PREFIX . $owner . '_';
			$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 100", $wpdb->esc_like( $prefix ) . '%' ) );
			if ( $wpdb->last_error !== '' ) { return new WP_Error( 'pom_ai_mcp_approval_storage', 'The approval request could not be stored.', array( 'status' => 503 ) ); }
			if ( count( $options ) >= 100 ) { return new WP_Error( 'pom_ai_mcp_approval_limit', 'The approval queue is full. Wait for previous requests to expire.', array( 'status' => 429 ) ); }
			$id = wp_generate_uuid4();
			return add_option( $prefix . $id, $record, '', false )
				? $id : new WP_Error( 'pom_ai_mcp_approval_storage', 'The approval request could not be stored.', array( 'status' => 503 ) );
		} );
	}

	/** WordPress option caches cannot arbitrate between concurrent decisions and applications. */
	public function load( string $id, bool $include_expired = false ): array {
		global $wpdb;
		if ( ! wp_is_uuid( $id, 4 ) || get_current_user_id() < 1 ) { return array( null, null, null ); }
		if ( function_exists( 'pom_pomstandard_force_database_reads_to_writer' ) ) { pom_pomstandard_force_database_reads_to_writer(); }
		$option = self::PREFIX . get_current_user_id() . '_' . $id;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		$record = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		if ( ! is_array( $record ) || ( $record['user'] ?? 0 ) !== get_current_user_id() || ( $record['blog'] ?? 0 ) !== get_current_blog_id()
			|| ( ! $include_expired && ( $record['expires'] ?? 0 ) < time() ) ) { return array( null, null, null ); }
		return array( $record, $raw, $option );
	}

	public function replace( string $option, string $raw, array $record ): bool {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", maybe_serialize( $record ), $option, $raw ) );
		wp_cache_delete( $option, 'options' );
		return $result === 1;
	}

	public function decide( array $stored, string $decision, string $source ) {
		[ $record, $raw, $option ] = $stored;
		if ( ! $record || $record['expires'] < time() || $record['status'] !== 'pending' || ! in_array( $decision, array( 'approved', 'rejected' ), true ) ) {
			return new WP_Error( 'pom_ai_mcp_approval_changed', 'This review is unavailable or already decided.', array( 'status' => 409 ) );
		}
		if ( $source === 'mcp_client' && ( $record['review_mode'] ?? 'auto' ) === 'web' ) {
			return new WP_Error( 'pom_ai_mcp_approval_web_only', 'This job requires the user to approve or reject through its WordPress review link.', array( 'status' => 409 ) );
		}
		$record['status'] = $decision;
		$record['decision'] = array( 'status' => $decision, 'source' => $source, 'recorded_at' => gmdate( 'c' ) );
		return $this->replace( $option, $raw, $record ) ? $record
			: new WP_Error( 'pom_ai_mcp_approval_changed', 'The review changed before this decision was saved.', array( 'status' => 409 ) );
	}

	/** The caller must check connection ownership and reproduce the preview under the current actor. */
	public function apply( array $stored, string $name, array $args, array $preview, callable $execute, callable $pending ) {
		[ $record, $raw, $option ] = $stored;
		$match = null;
		$fingerprint = self::fingerprint( array( $name, $args ) );
		foreach ( $record['entries'] as $index => $entry ) {
			if ( hash_equals( $entry['fingerprint'], $fingerprint ) ) { $match = $index; break; }
		}
		if ( $match === null || ! hash_equals( $record['entries'][ $match ]['preview_hash'], self::fingerprint( $preview ) ) ) {
			return new WP_Error( 'pom_ai_mcp_approval_changed', 'This operation or its preview differs from the approved job. Read and preview again.', array( 'status' => 409 ) );
		}
		if ( $record['status'] === 'pending' ) { return $pending( $record ); }
		if ( $record['expires'] < time() || $record['status'] !== 'approved' || $record['entries'][ $match ]['status'] !== 'pending' || in_array( 'executing', array_column( $record['entries'], 'status' ), true ) ) {
			return new WP_Error( 'pom_ai_mcp_approval_used', 'This approval is rejected, paused, executing or already consumed. Inspect the job and destination before continuing.', array( 'status' => 409 ) );
		}
		$record['entries'][ $match ]['status'] = 'executing';
		if ( ! $this->replace( $option, $raw, $record ) ) {
			return new WP_Error( 'pom_ai_mcp_approval_used', 'Another request changed this approval. Inspect the job before continuing.', array( 'status' => 409 ) );
		}
		$claimed = maybe_serialize( $record );
		try {
			$result = $execute( $args );
			$record['entries'][ $match ]['status'] = self::incomplete( $result ) ? 'uncertain' : 'applied';
			return $result;
		} finally {
			if ( $record['entries'][ $match ]['status'] === 'executing' ) { $record['entries'][ $match ]['status'] = 'uncertain'; }
			$states = array_column( $record['entries'], 'status' );
			$record['status'] = in_array( 'uncertain', $states, true ) ? 'paused' : ( in_array( 'pending', $states, true ) ? 'approved' : 'used' );
			$this->replace( $option, $claimed, $record );
		}
	}

	private static function incomplete( $result ): bool {
		if ( is_wp_error( $result ) ) { return true; }
		if ( ! is_array( $result ) ) { return false; }
		if ( ! empty( $result['isError'] ) || ! empty( $result['approval_required'] ) ) { return true; }
		foreach ( array( 'complete', 'success', 'ok', 'applied' ) as $flag ) {
			if ( ( $result[ $flag ] ?? null ) === false ) { return true; }
		}
		foreach ( $result as $value ) { if ( self::incomplete( $value ) ) { return true; } }
		return false;
	}

	public function cleanup(): void {
		Storage::prune_options( self::PREFIX, 'pom_mcp_review_cleanup_cursor' );
	}
}
