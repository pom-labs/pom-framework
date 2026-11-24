<?php

namespace POMFramework\Mcp;

/** Restrict an external account gateway to its own OAuth grants and routing provider. */
final class AccountGateway {
	public const READ = 'account:sites:read';
	public const EXECUTE = 'account:sites:execute';
	private static int $blog = 0;
	private static string $provider = '';
	private static string $label = '';
	private static array $execute_tools = array();
	private static ?\Closure $consent = null;

	/** Register the controller before the site's catalogue is built. */
	public static function register( string $provider, string $label, callable $operations, array $execute_tools = array(), ?callable $consent = null ): void {
		self::$blog = get_current_blog_id();
		self::$provider = $provider;
		self::$label = $label;
		self::$execute_tools = $execute_tools;
		self::$consent = $consent === null ? null : \Closure::fromCallable( $consent );
		Bootstrap::register_provider( $provider, $label, $operations );
	}

	public static function registered(): bool {
		return self::$blog > 0 && self::$blog === get_current_blog_id();
	}

	public static function provider(): string {
		return self::registered() ? self::$provider : '';
	}

	public static function label(): string {
		return self::registered() ? self::$label : '';
	}

	public static function consent(): string {
		return self::registered() && self::$consent !== null ? ( self::$consent )() : '';
	}

	public static function requires_execution_scope( string $tool ): bool {
		return self::registered() && in_array( $tool, self::$execute_tools, true );
	}

	public static function allows_tool( string $tool, array $scopes ): bool {
		return ! self::requires_execution_scope( $tool ) || in_array( self::EXECUTE, $scopes, true );
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
