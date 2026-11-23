<?php

namespace POMFramework\Mcp;

use Composer\Autoload\ClassLoader;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WP\McpSchema\Common\Protocol\DTO\InitializeResult;

/** Resolve the adapter and its protocol DTOs from the same compatible Composer installation. */
final class AdapterLoader {
	private static bool $resolved = false;

	public static function prefer_compatible_package(): void {
		if ( self::$resolved || class_exists( McpAdapter::class, false ) || class_exists( McpServer::class, false )
			|| class_exists( InitializeResult::class, false ) || ! class_exists( ClassLoader::class, false ) ) {
			return;
		}
		self::$resolved = true;
		foreach ( ClassLoader::getRegisteredLoaders() as $vendor => $loader ) {
			// WooCommerce and other products can prepend older copies of the same namespaces.
			// Prefer the compatible pair before PHP defines either class. Leave every other
			// Composer namespace in its original order.
			if ( ! $loader->findFile( McpAdapter::class ) || ! $loader->findFile( InitializeResult::class ) ) { continue; }
			$metadata = $vendor . '/composer/installed.php';
			if ( ! is_readable( $metadata ) ) { continue; }
			$installed = require $metadata;
			$version = $installed['versions']['wordpress/mcp-adapter']['version'] ?? '';
			if ( version_compare( $version, '0.6.1', '<' ) || version_compare( $version, '0.7.0', '>=' ) ) { continue; }
			spl_autoload_register( static function ( string $class ) use ( $loader ): void {
				if ( strpos( $class, 'WP\\MCP\\' ) === 0 || strpos( $class, 'WP\\McpSchema\\' ) === 0 ) {
					$loader->loadClass( $class );
				}
			}, true, true );
			return;
		}
	}
}
