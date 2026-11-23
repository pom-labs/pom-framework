<?php

namespace POMFramework\Mcp;

use WP_Error;

final class RateLimiter {
	/** Count each admitted request atomically across workers and object-cache backends. */
	public static function check( string $bucket, string $actor, int $limit ) {
		global $wpdb;
		$now = time();
		$window = intdiv( $now, MINUTE_IN_SECONDS );
		$table = $wpdb->prefix . 'pom_mcp_rate_limits';
		$key = hash( 'sha256', $bucket . ':' . $actor );
		if ( function_exists( 'pom_pomstandard_force_database_reads_to_writer' ) ) {
			pom_pomstandard_force_database_reads_to_writer();
		}
		$result = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (bucket_key, window_start, requests, expires_at) VALUES (%s, %d, LAST_INSERT_ID(1), %d)
			 ON DUPLICATE KEY UPDATE requests = LAST_INSERT_ID(IF(window_start < %d, 1, LEAST(requests + 1, %d))), window_start = GREATEST(window_start, %d), expires_at = %d",
			$key, $window, $now + 120, $window, $limit + 1, $window, $now + 120
		) );
		if ( $result === false || (int) $wpdb->insert_id < 1 ) {
			return new WP_Error( 'pom_mcp_rate_unavailable', 'Request limiting is temporarily unavailable.', array( 'status' => 503 ) );
		}

		return (int) $wpdb->insert_id <= $limit ? true : new WP_Error(
			'pom_mcp_rate_limited',
			'Rate limit exceeded.',
			array( 'status' => 429, 'retry_after' => max( 1, ( $window + 1 ) * MINUTE_IN_SECONDS - time() ) )
		);
	}
}
