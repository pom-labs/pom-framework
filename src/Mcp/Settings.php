<?php

namespace POMFramework\Mcp;

final class Settings {
	public const OPTION = 'pom_mcp_settings';
	public const SITE_ID = 'pom_mcp_site_id';
	public const API_KEY = 'pom_mcp_api_key';
	public const REST_NAMESPACE = 'pom-mcp/v1';
	public const ROUTE = '/' . self::REST_NAMESPACE . '/mcp';
	public const STATUS_ROUTE = '/' . self::REST_NAMESPACE . '/status';
	public const OAUTH_PATH = 'pom-mcp/oauth/';
	public const API_KEY_HEADER = 'X-POM-MCP-API-Key';
	public const READ_SCOPE = 'mcp:read';
	public const WRITE_SCOPE = 'mcp:write';
	public const MANAGE_CAPABILITY = 'pom_manage_mcp';

	public static function get(): array {
		$value = get_option( self::OPTION, array() );
		$settings = wp_parse_args( is_array( $value ) ? $value : array(), array(
			'enabled' => false,
			'oauth' => true,
			'writes' => false,
			'write_permissions' => array(),
			'network_destination' => true,
			'account_destination' => false,
			'api_key' => false,
			'service_user' => 0,
			'origins' => array(),
			'rate_limit' => 60,
		) );
		if ( AccountGateway::registered() ) {
			$settings['api_key'] = false;
			$settings['account_destination'] = false;
		}
		return $settings;
	}

	public static function endpoint(): string {
		// The registered URL is stable when the request reaches WordPress through a TLS proxy.
		$scheme = (string) wp_parse_url( self::issuer(), PHP_URL_SCHEME );
		return rest_url( ltrim( self::ROUTE, '/' ), $scheme );
	}

	public static function issuer(): string {
		return untrailingslashit( (string) get_option( 'home' ) );
	}

	public static function url( string $relative_path ): string {
		return self::issuer() . '/' . ltrim( $relative_path, '/' );
	}

	public static function valid_resource( string $resource ): bool {
		return $resource === '' || hash_equals( self::endpoint(), $resource );
	}

	public static function secure_site(): bool {
		$url = wp_parse_url( self::issuer() );
		return is_array( $url ) && ! empty( $url['host'] ) && (
			( $url['scheme'] ?? '' ) === 'https'
			|| ( ( $url['scheme'] ?? '' ) === 'http' && in_array( strtolower( $url['host'] ), array( 'localhost', '127.0.0.1', '[::1]' ), true ) )
		);
	}

	public static function secure_request(): bool {
		return is_ssl() || ( wp_parse_url( self::issuer(), PHP_URL_SCHEME ) === 'http' && self::secure_site() );
	}

	public static function oauth_available(): bool {
		return (bool) self::get()['oauth'] && (string) get_option( 'permalink_structure' ) !== '';
	}

	public static function writes_enabled(): bool {
		return (bool) self::get()['writes'] && self::oauth_available();
	}

	/** Existing reviewed-write configurations keep their scope until a group is disabled. */
	public static function write_groups_allowed( array $groups ): bool {
		$permissions = (array) self::get()['write_permissions'];
		foreach ( $groups as $group ) {
			if ( array_key_exists( $group, $permissions ) && $permissions[ $group ] !== true ) { return false; }
		}
		return true;
	}

	public static function accepts_network_delegation( int $blog ): bool {
		$settings = get_blog_option( $blog, self::OPTION, array() );
		return ( $settings['network_destination'] ?? true ) === true;
	}

	public static function actor_can_connect( int $user ): bool {
		return $user > 0 && ( ! is_multisite() || is_user_member_of_blog( $user ) ) && user_can( $user, 'read' );
	}

	/** The account controller keeps its own administration policy. */
	public static function can_manage_site_mcp(): bool {
		return current_user_can( 'manage_options' ) || ( ! AccountGateway::registered() && current_user_can( self::MANAGE_CAPABILITY ) );
	}

	/** A delegated key stays confined to its issuing site role, even if that user is promoted or loses access. */
	public static function api_key_user( array $record, array $settings ): int {
		$user_id = (int) ( $record['user'] ?? 0 );
		if ( ! self::actor_can_connect( $user_id ) ) { return 0; }
		if ( ( $record['owner_bound'] ?? false ) === true ) {
			return ! AccountGateway::registered() && user_can( $user_id, self::MANAGE_CAPABILITY ) && ! user_can( $user_id, 'manage_options' ) ? $user_id : 0;
		}
		return $user_id === (int) $settings['service_user'] ? $user_id : 0;
	}

	public static function identity(): string {
		$id = get_option( self::SITE_ID, '' );
		if ( $id === '' ) {
			add_option( self::SITE_ID, wp_generate_uuid4(), '', false );
			$id = get_option( self::SITE_ID, '' );
		}
		return is_string( $id ) && wp_is_uuid( $id, 4 ) ? $id : '';
	}
}
