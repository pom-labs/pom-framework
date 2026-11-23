<?php

namespace POMFramework;

use WP_Error;

/** Apply a product-validated patch to the ordinary settings files without changing unrelated fields. */
final class Settings_Patch {
	public static function with_lock( string $page, callable $apply ) {
		POM_Framework_Disk::create_settings_dir( $page );
		$path = POM_Framework_Disk::get_settings_path( $page ) . '.pom-settings.lock';
		$handle = fopen( $path, 'c' );
		if ( ! $handle ) { return self::error(); }
		try {
			if ( ! flock( $handle, LOCK_EX ) ) { return self::error(); }
			return $apply();
		} finally {
			fclose( $handle );
		}
	}

	/** Call within the owning page's lock, using its current enabled and module values. */
	public static function save_locked( string $page, string $module, ?bool $enabled, array $fields ) {
		if ( ( $enabled !== null && $fields ) || sanitize_key( $page ) !== $page || sanitize_key( $module ) !== $module || $module === '' ) { return self::error(); }
		$disk = new POM_Framework_Disk();
		$directory = POM_Framework_Disk::get_settings_path( $page );
		$all_modules = POM_Framework_Disk::read_file( 'enabled_settings.php', $page, 'array', true );
		if ( $fields ) {
			$before = POM_Framework_Disk::read_file( $module . '.php', $page, 'array', true );
			$after = array_replace( $before, $fields );
			if ( ! POM_Framework_Disk::replace_file( $directory . $module . '.php', $disk->generate_file_content( $after, "Settings file for $module." ) ) ) {
				return self::error();
			}
		}
		// A patch changes activation or fields. Each branch commits through one file replacement.
		if ( $enabled !== null ) {
			$all_modules[$module] = $enabled ? '1' : '0';
			if ( ! POM_Framework_Disk::replace_file( $directory . 'enabled_settings.php', $disk->generate_file_content( $all_modules, 'Enabled settings array file.' ) ) ) {
				return self::error();
			}
		}
		return true;
	}

	private static function error(): WP_Error {
		return new WP_Error( 'pom_settings_storage_failed', 'Settings could not be saved. Check the current values before retrying.', array( 'status' => 503 ) );
	}
}
