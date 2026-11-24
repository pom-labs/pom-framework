<?php

namespace POMFramework\Mcp;

/** Apply the shared site authoring profile to credentials issued to an external account. */
final class AccountDestination {
	public const VERSION = 1;
	public const SCOPE = 'account:destination';

	public static function enabled(): bool {
		$settings = Settings::get();
		return (bool) ( $settings['account_destination'] ?? false ) && (bool) $settings['enabled'] && Settings::oauth_available();
	}

	public static function actor( array $actor ): bool {
		return ( $actor['auth_type'] ?? '' ) === 'oauth' && in_array( self::SCOPE, $actor['scopes'] ?? array(), true );
	}

	/** Approve website authoring capabilities explicitly so newly added tools stay site-local by default. */
	public static function allows_name( string $name ): bool {
		$groups = array(
			'wp_' => 'get_posts get_post create_post update_post delete_post count_posts get_post_types get_pages get_page create_page update_page delete_page get_media get_media_item upload_media_from_url upload_media set_featured_image update_media delete_media count_media get_categories get_tags get_taxonomies get_terms get_term rename_terms reorder_terms create_term update_term delete_term add_post_terms count_terms get_term_meta update_term_meta delete_term_meta get_comments get_pending_comments create_comment delete_comment approve_comment spam_comment trash_comment get_post_meta update_post_meta delete_post_meta get_site_info search get_option update_option get_menus get_menu_items create_menu_item update_menu_item delete_menu_item reorder_menu_items get_seo_meta update_seo_meta get_menu_locations create_menu assign_menu_location get_site_pages set_site_pages',
			'wc_' => 'get_products get_product create_product update_product get_product_variations get_variation create_variation update_variation delete_variation batch_update_variations get_product_attributes get_attribute_terms create_product_attribute set_product_attributes get_coupons get_coupon create_coupon update_coupon delete_coupon empty_coupon_trash get_coupon_count get_store_stats',
			'pom_' => 'get_task get_site_context get_authoring_skill get_authoring_context get_available_blocks get_block_schema validate_block_content get_available_mergetags get_available_shortcodes get_shortcode_schema get_template_schema validate_builder_content validate_template_markup',
			'pom_theme_' => 'get_status list_settings_schema get_settings_values update_setting update_settings_batch get_global_css update_global_css get_custom_css upsert_custom_css delete_custom_css get_css_build_info get_generated_css generate_assets list_html_templates get_html_template upsert_html_template delete_html_template get_template_assignments assign_template configure_content_model get_settings_catalog get_setting_contract patch_settings get_script_catalog get_content_scripts assign_content_scripts',
			'pom_site_' => 'find_forms adopt_plan_form get_form_contract get_form create_form save_form preview_plan create_plan get_plan prepare_plan_step apply_plan_step adopt_plan_content discard_plan get_listing_catalog save_query save_facets reindex_facets build_listing get_content save_content get_content_css save_content_css',
			'pom_cache_' => 'get_status clear_site_cache',
		);
		foreach ( $groups as $prefix => $suffixes ) {
			if ( strpos( $name, $prefix ) === 0 && in_array( substr( $name, strlen( $prefix ) ), explode( ' ', $suffixes ), true ) ) { return true; }
		}
		return false;
	}

	public static function allows( Operation $operation ): bool {
		return self::allows_operation( $operation->name, $operation->effect, $operation->network_group );
	}

	public static function allows_operation( string $name, string $effect, string $network_group ): bool {
		// Reuse the reviewed delegation policy. Site-only and chargeable operations stay local.
		if ( ! self::allows_name( $name ) ) { return false; }
		if ( $effect === Operation::READ ) { return $network_group === Operation::READ; }
		return $effect === Operation::WRITE && in_array( $network_group,
			array( 'site_content_writes', 'theme_writes', 'option_writes', 'woocommerce_writes', 'cache_writes' ), true );
	}

	public static function allows_tool( Operation $operation, array $actor ): bool {
		if ( self::allows( $operation ) || $operation->name === 'pom_get_task' ) { return true; }
		return in_array( Settings::WRITE_SCOPE, $actor['scopes'], true ) && in_array( $operation->name, Reviews::TOOL_NAMES, true );
	}
}
