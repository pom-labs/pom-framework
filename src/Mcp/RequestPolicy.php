<?php

namespace POMFramework\Mcp;

final class RequestPolicy {
	public const MAX_BODY_BYTES = 65536;

	/** Match full origins so browser credentials stay within the configured web applications. */
	public static function origin_allowed( string $origin ): bool {
		if ( $origin === '' ) {
			return true;
		}
		$site_origin = self::origin_from_url( Settings::issuer() );
		$origins = Settings::get()['origins'];
		return $origin === $site_origin || ( is_array( $origins ) && in_array( $origin, $origins, true ) );
	}

	public static function origin_from_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true )
			|| ! preg_match( '/^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])$/D', $parts['host'] )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$port = (int) ( $parts['port'] ?? 0 );
		if ( $port === ( $parts['scheme'] === 'https' ? 443 : 80 ) ) {
			$port = 0;
		}
		return $parts['scheme'] . '://' . strtolower( $parts['host'] ) . ( $port ? ':' . $port : '' );
	}

	public static function ip_hash(): string {
		return hash_hmac( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), wp_salt( 'auth' ) );
	}

	public static function send_no_store(): void {
		nocache_headers();
		header( 'Cache-Control: no-store', true );
		header( 'Pragma: no-cache', true );
	}

	public static function send_cors( string $origin, string $methods ): void {
		// WordPress's general REST CORS handler reflects arbitrary origins. Scope this override to POM routes.
		header_remove( 'Access-Control-Allow-Credentials' );
		header_remove( 'Access-Control-Allow-Origin' );
		header_remove( 'Access-Control-Allow-Methods' );
		header_remove( 'Access-Control-Allow-Headers' );
		header_remove( 'Access-Control-Expose-Headers' );
		header( 'Vary: Origin', false );
		if ( $origin === '' || ! self::origin_allowed( $origin ) ) {
			return;
		}

		header( 'Access-Control-Allow-Origin: ' . $origin, true );
		header( 'Access-Control-Allow-Methods: ' . $methods, true );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Accept, MCP-Protocol-Version, Mcp-Session-Id, ' . Settings::API_KEY_HEADER, true );
		header( 'Access-Control-Expose-Headers: Mcp-Session-Id, MCP-Protocol-Version, WWW-Authenticate, Retry-After', true );
	}
}
