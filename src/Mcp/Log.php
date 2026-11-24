<?php

namespace POMFramework\Mcp;

/** Keep an audit trail of outcomes without storing request or response payloads. */
final class Log {
	private Storage $storage;

	public function __construct( Storage $storage ) {
		$this->storage = $storage;
	}

	public function record( string $action, string $status, array $actor = array() ): void {
		if ( ! $this->storage->ready() ) { return; }
		global $wpdb;
		$wpdb->insert( $this->storage->logs_table(), array(
			'timestamp'     => gmdate( 'Y-m-d H:i:s' ),
			'actor_user_id' => isset( $actor['user_id'] ) ? (int) $actor['user_id'] : null,
			'auth_type'     => $actor['auth_type'] ?? 'none',
			'action'        => substr( sanitize_key( $action ), 0, 100 ),
			'status'        => $status,
		), array( '%s', '%d', '%s', '%s', '%s' ) );
	}

	public function recent( int $before = 0 ): array {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$query = $wpdb->prepare( "SELECT id, timestamp, actor_user_id, auth_type, action, status FROM {$this->storage->logs_table()} WHERE timestamp >= %s", $cutoff );
		if ( $before > 0 ) { $query .= $wpdb->prepare( ' AND id < %d', $before ); }
		return $wpdb->get_results( $query . ' ORDER BY id DESC LIMIT 31', ARRAY_A ) ?: array();
	}
}
