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

	public static function get(): array {
		$value = get_option( self::OPTION, array() );
		$settings = wp_parse_args( is_array( $value ) ? $value : array(), array(
			'enabled' => false,
			'oauth' => true,
			'writes' => false,
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

	public static function actor_can_connect( int $user ): bool {
		return $user > 0 && ( ! is_multisite() || is_user_member_of_blog( $user ) ) && user_can( $user, 'read' );
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
