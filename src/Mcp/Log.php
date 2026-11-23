<?php

namespace POMFramework\Mcp;

/** Keep an audit trail of outcomes without storing request or response payloads. */
final class Log {
	private Storage $storage;

	public function __construct( Storage $storage ) {
		$this->storage = $storage;
	}

	public function record( string $action, string $status, array $actor = array() ): void {
		global $wpdb;
		$wpdb->insert( $this->storage->logs_table(), array(
			'timestamp'     => gmdate( 'Y-m-d H:i:s' ),
			'actor_user_id' => isset( $actor['user_id'] ) ? (int) $actor['user_id'] : null,
			'auth_type'     => $actor['auth_type'] ?? 'none',
			'action'        => substr( sanitize_key( $action ), 0, 100 ),
			'status'        => $status,
		), array( '%s', '%d', '%s', '%s', '%s' ) );
	}

	public function recent(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT timestamp, actor_user_id, auth_type, action, status FROM {$this->storage->logs_table()} ORDER BY id DESC LIMIT 20",
			ARRAY_A
		) ?: array();
	}
}
