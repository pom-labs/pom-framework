<?php

namespace POMFramework\Mcp;

/** Restrict a Pomatio account origin to its own OAuth grants and routing provider. */
final class AccountGateway {
	public const PROVIDER = 'pom-pomatio-account';
	public const READ = 'account:sites:read';
	public const EXECUTE = 'account:sites:execute';
	private static int $blog = 0;

	public static function register( callable $operations ): void {
		self::$blog = get_current_blog_id();
		Bootstrap::register_provider( self::PROVIDER, 'Pomatio Account', $operations );
	}

	public static function registered(): bool {
		return self::$blog === get_current_blog_id();
	}

	public static function scopes(): array {
		$read = array( Settings::READ_SCOPE, self::READ );
		return Settings::writes_enabled() ? array_merge( $read, array( Settings::WRITE_SCOPE, self::EXECUTE ) ) : $read;
	}

	public static function allows( array $scopes ): bool {
		if ( ! self::registered() ) {
			return ! array_intersect( $scopes, array( self::READ, self::EXECUTE ) );
		}
		return ! array_diff( array( Settings::READ_SCOPE, self::READ ), $scopes )
			&& ! array_diff( $scopes, self::scopes() )
			&& ( in_array( Settings::WRITE_SCOPE, $scopes, true ) === in_array( self::EXECUTE, $scopes, true ) );
	}
}
