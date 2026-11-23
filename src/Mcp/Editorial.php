<?php

namespace POMFramework\Mcp;

use WP_Error;
use WP_Post;

/** Shared safeguards for reviewed editorial changes that span a post and its domain data. */
final class Editorial {
	public static function post( int $id, string $type, bool $draft = false ) {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $type || $post->post_password !== ''
			|| ! in_array( $post->post_status, array( 'draft', 'pending', 'publish', 'private', 'future' ), true )
			|| ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'pom_mcp_editorial_target', 'The editorial object is unavailable to this connection.', array( 'status' => 403 ) );
		}
		if ( $draft && $post->post_status !== 'draft' ) {
			return new WP_Error( 'pom_mcp_editorial_draft', 'This operation is limited to drafts.', array( 'status' => 409 ) );
		}
		return $post;
	}

	public static function can_create( string $type ): bool {
		$object = get_post_type_object( $type );
		return $object && current_user_can( $object->cap->create_posts );
	}

	/** Quote and recheck one exact object state. */
	public static function check_revision( array $args, string $revision, bool $preview ) {
		if ( $preview && ! isset( $args['expected_revision'] ) ) { return true; }
		return hash_equals( $revision, $args['expected_revision'] ?? '' ) ? true
			: new WP_Error( 'pom_mcp_editorial_stale', 'The object or site settings changed. Prepare a new review.', array( 'status' => 409 ) );
	}

	public static function revision( array $state ): string {
		return ReviewStore::fingerprint( array( get_current_blog_id(), get_current_user_id(), $state ) );
	}

	/** Start with the parent row so competing MCP edits serialize on the same course or event. */
	public static function transaction( int $parent, array $tables, callable $apply ) {
		global $wpdb;
		$tables = array_unique( array_merge( array( $wpdb->posts, $wpdb->postmeta ), $tables ) );
		$key = 'editorial_engines_' . md5( implode( ',', $tables ) );
		if ( wp_cache_get( $key, 'pom_mcp' ) !== true ) {
			foreach ( $tables as $table ) {
				$definition = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
				if ( ! isset( $definition[1] ) || ! preg_match( '/\bENGINE=InnoDB\b/i', $definition[1] ) ) {
					return self::storage_error();
				}
			}
			wp_cache_set( $key, true, 'pom_mcp', HOUR_IN_SECONDS );
		}
		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) { $wpdb->send_reads_to_masters(); }
		$hint = ' /* IN_TABLE=' . $wpdb->posts . ' */';
		if ( $wpdb->query( 'START TRANSACTION' . $hint ) === false ) { return self::storage_error(); }
		$committed = false;
		$ids = array();
		try {
			if ( $parent && ! self::lock_post( $parent ) ) { return self::storage_error(); }
			$result = $apply( $ids );
			if ( is_wp_error( $result ) ) { return $result; }
			$committed = $wpdb->query( 'COMMIT' . $hint ) !== false;
			return $committed ? $result : self::storage_error();
		} finally {
			if ( ! $committed ) { $wpdb->query( 'ROLLBACK' . $hint ); }
			foreach ( array_unique( array_filter( array_merge( array( $parent ), $ids ) ) ) as $id ) {
				clean_post_cache( $id );
				wp_cache_delete( $id, 'post_meta' );
			}
		}
	}

	public static function lock_post( int $id ): bool {
		global $wpdb;
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $id ) );
		if ( $found !== $id ) { return false; }
		$wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d FOR UPDATE", $id ) );
		if ( $wpdb->last_error !== '' ) { return false; }
		clean_post_cache( $id );
		wp_cache_delete( $id, 'post_meta' );
		return true;
	}

	public static function store_meta( int $id, string $key, $value ): bool {
		return update_post_meta( $id, $key, wp_slash( $value ) ) !== false || get_post_meta( $id, $key, true ) === $value;
	}

	public static function storage_error(): WP_Error {
		return new WP_Error( 'pom_mcp_editorial_storage', 'The editorial change could not be committed safely.', array( 'status' => 503 ) );
	}
}
