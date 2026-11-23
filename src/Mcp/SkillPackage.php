<?php

namespace POMFramework\Mcp;

use WP_Error;

/** Read and package only the text files a product explicitly ships with its skill. */
final class SkillPackage {
	private const MAX_FILE_BYTES = 65536;
	private const MAX_PACKAGE_BYTES = 524288;
	public string $name;
	public string $label;
	private string $directory;
	private array $files;

	public function __construct( string $name, string $label, string $directory, array $files = array( 'SKILL.md' ) ) {
		if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $name ) || strlen( $name ) > 64 || trim( $label ) === ''
			|| ! in_array( 'SKILL.md', $files, true ) || count( $files ) > 64 ) {
			throw new \InvalidArgumentException( 'Invalid POM MCP skill descriptor.' );
		}
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || ! preg_match( '~^(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-]+\.(?:md|json|ya?ml)$~D', $file ) ) {
				throw new \InvalidArgumentException( 'Invalid POM MCP skill file path.' );
			}
		}
		$this->name = $name;
		$this->label = $label;
		$this->directory = rtrim( $directory, '/\\' );
		$this->files = array_values( array_unique( $files ) );
	}

	private function snapshot() {
		$root = realpath( $this->directory );
		if ( $root === false || ! is_dir( $root ) || is_link( $this->directory ) || is_link( dirname( $this->directory ) ) ) { return self::unavailable(); }
		$manifest = array();
		$contents = array();
		$total = 0;
		foreach ( $this->files as $file ) {
			$path = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $file );
			// An allowlisted entry must resolve to itself inside the packaged directory.
			if ( realpath( $path ) !== $path || ! is_file( $path ) || ! is_readable( $path ) ) { return self::unavailable(); }
			$contents[ $file ] = file_get_contents( $path, false, null, 0, self::MAX_FILE_BYTES + 1 );
			$content = $contents[ $file ];
			if ( ! is_string( $content ) || strlen( $content ) > self::MAX_FILE_BYTES || strpos( $content, "\0" ) !== false || ! preg_match( '//u', $content ) ) {
				return self::unavailable();
			}
			$total += strlen( $content );
			if ( $total > self::MAX_PACKAGE_BYTES ) { return self::unavailable(); }
			$manifest[ $file ] = array( 'path' => $file, 'bytes' => strlen( $content ), 'sha256' => hash( 'sha256', $content ) );
		}
		if ( ! preg_match( '/\A---\r?\n(.+?)\r?\n---(?:\r?\n|\z)/s', $contents['SKILL.md'], $header )
			|| ! preg_match( '/^name: ([a-z0-9-]+)\r?$/m', $header[1], $name ) || $name[1] !== $this->name
			|| ! preg_match( '/^  version: "([0-9]+\.[0-9]+\.[0-9]+)"\r?$/m', $header[1], $version ) ) {
			return self::unavailable();
		}
		return array( 'name' => $this->name, 'version' => $version[1], 'revision' => hash( 'sha256', wp_json_encode( array_values( $manifest ) ) ),
			'manifest' => $manifest, 'contents' => $contents, 'total_bytes' => $total );
	}

	public function read( array $args = array() ) {
		$file = $args['file'] ?? null;
		if ( $file !== null && ( ! is_string( $file ) || ! in_array( $file, $this->files, true ) ) ) {
			return new WP_Error( 'pom_mcp_skill_file_not_found', 'Select an exact file from the skill manifest.', array( 'status' => 404 ) );
		}
		$package = $this->snapshot();
		if ( is_wp_error( $package ) ) { return $package; }
		if ( isset( $args['expected_revision'] ) && ( ! is_string( $args['expected_revision'] ) || ! hash_equals( $package['revision'], $args['expected_revision'] ) ) ) {
			return new WP_Error( 'pom_mcp_skill_changed', 'The skill changed. Retrieve its current manifest.', array( 'status' => 409 ) );
		}
		$result = array_intersect_key( $package, array_flip( array( 'name', 'version', 'revision' ) ) );
		if ( $file !== null ) {
			return $result + array( 'file' => $package['manifest'][ $file ] + array( 'encoding' => 'utf-8', 'content' => $package['contents'][ $file ] ) );
		}
		return $result + array( 'entrypoint' => 'SKILL.md', 'files' => array_values( $package['manifest'] ), 'total_bytes' => $package['total_bytes'] );
	}

	/** Build the ZIP from the same bounded snapshot that the MCP tool reads. */
	public function zip() {
		$package = $this->snapshot();
		if ( is_wp_error( $package ) ) { return $package; }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$archive = wp_tempnam( $this->name . '.zip' );
		if ( ! $archive ) { return self::unavailable(); }
		try {
			$entries = array();
			foreach ( $package['contents'] as $file => $content ) {
				$entries[] = array( PCLZIP_ATT_FILE_NAME => $this->name . '/' . $file, PCLZIP_ATT_FILE_CONTENT => $content );
			}
			$zip = new \PclZip( $archive );
			if ( ! $zip->create( $entries ) ) { return self::unavailable(); }
			$bytes = file_get_contents( $archive, false, null, 0, 2 * self::MAX_PACKAGE_BYTES );
			return is_string( $bytes ) && $bytes !== '' && strlen( $bytes ) < 2 * self::MAX_PACKAGE_BYTES ? $bytes : self::unavailable();
		} finally {
			unlink( $archive );
		}
	}

	private static function unavailable(): WP_Error {
		return new WP_Error( 'pom_mcp_skill_unavailable', 'The bundled skill could not be read or packaged.', array( 'status' => 503 ) );
	}
}
