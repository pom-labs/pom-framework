<?php

namespace POMFramework\Mcp;

use WP_Error;

/** Reserve product-owned objects in generic authoring only when their replacement is available. */
final class Ownership {
	public static function check( string $tool, array $args, bool $write ) {
		if ( ! str_starts_with( $tool, 'wp_' ) && ! in_array( $tool, array( 'pom_site_save_content', 'pom_site_get_content', 'pom_theme_assign_content_scripts', 'pom_theme_configure_content_model' ), true ) ) {
			return true;
		}
		$events = class_exists( 'POM_Events_MCP_Provider', false ) && \POM_Events_MCP_Provider::available();
		$lms = class_exists( 'POM_LMS_MCP_Provider', false ) && \POM_LMS_MCP_Provider::available();
		$types = array();
		if ( $events ) { $types['event'] = 'POM Events'; }
		if ( $lms ) { $types += array( 'course' => 'POM LMS', 'lesson' => 'POM LMS' ); }
		if ( ! $types ) { return true; }
		$protected_read = in_array( $tool, array( 'wp_get_post', 'wp_get_posts', 'wp_search', 'wp_get_post_meta', 'pom_site_get_content' ), true );
		if ( ! $write && ! $protected_read ) { return true; }
		$target = $args['post_type'] ?? '';
		if ( is_string( $target ) && isset( $types[ $target ] ) ) { return self::denied( $types[ $target ] ); }

		if ( $tool === 'pom_theme_configure_content_model' ) {
			foreach ( $args['post_types'] ?? array() as $row ) {
				$type = is_array( $row ) && is_string( $row['slug'] ?? null ) ? sanitize_key( $row['slug'] ) : '';
				if ( isset( $types[$type] ) ) { return self::denied( $types[$type] ); }
			}
			foreach ( $args['taxonomies'] ?? array() as $row ) {
				$slug = is_array( $row ) && is_string( $row['slug'] ?? null ) ? sanitize_key( $row['slug'] ) : '';
				if ( $events && in_array( $slug, array( 'event_category', 'event_type' ), true ) ) { return self::denied( 'POM Events' ); }
			}
		}

		$post_id = 0;
		if ( in_array( $tool, array( 'wp_update_post', 'wp_delete_post', 'wp_get_post', 'wp_update_page', 'wp_delete_page', 'pom_site_save_content', 'pom_site_get_content', 'pom_theme_assign_content_scripts' ), true ) ) {
			$post_id = $args['id'] ?? 0;
		} elseif ( in_array( $tool, array( 'wp_set_featured_image', 'wp_get_post_meta', 'wp_update_post_meta', 'wp_delete_post_meta', 'wp_add_post_terms', 'wp_update_seo_meta', 'wp_create_comment', 'wp_upload_media', 'wp_upload_media_from_url' ), true ) ) {
			$post_id = $args['post_id'] ?? $args['id'] ?? 0;
		} elseif ( in_array( $tool, array( 'wp_approve_comment', 'wp_delete_comment', 'wp_spam_comment', 'wp_trash_comment' ), true ) ) {
			$post_id = get_comment( (int) ( $args['comment_id'] ?? $args['id'] ?? 0 ) )->comment_post_ID ?? 0;
		}
		$type = $post_id ? get_post_type( (int) $post_id ) : '';
		if ( isset( $types[ $type ] ) ) { return self::denied( $types[ $type ] ); }
		$taxonomy = $args['taxonomy'] ?? '';
		return $write && $events && in_array( $taxonomy, array( 'event_type', 'event_category' ), true ) ? self::denied( 'POM Events' ) : true;
	}

	private static function denied( string $owner ): WP_Error {
		return new WP_Error( 'pom_mcp_product_owns_write', sprintf( 'Use %s for this operation on a product-owned object.', $owner ), array( 'status' => 409 ) );
	}
}
