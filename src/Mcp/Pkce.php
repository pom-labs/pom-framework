<?php

namespace POMFramework\Mcp;

final class Pkce {
	public static function verify( string $verifier, string $challenge, string $method ): bool {
		if ( $method !== 'S256' || ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/D', $verifier ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/D', $challenge ) ) {
			return false;
		}

		$hash       = hash( 'sha256', $verifier, true );
		$calculated = rtrim( strtr( base64_encode( $hash ), '+/', '-_' ), '=' );

		return hash_equals( $challenge, $calculated );
	}

	public static function random_token( string $prefix ): string {
		return $prefix . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}
}
