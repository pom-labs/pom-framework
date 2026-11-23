<?php

namespace POMFramework\Mcp;

/** An explicitly registered MCP resource or prompt, evaluated as the current connection. */
final class Catalog_Item {
	public const RESOURCE = 'resource';
	public const PROMPT = 'prompt';

	public string $kind;
	public string $name;
	public string $description;
	public string $uri;
	public string $mime_type;
	public array $arguments;
	public bool $delegated;
	/** @var callable */
	public $permission;
	/** @var callable */
	public $read;

	public function __construct( string $kind, string $name, string $description, callable $permission, callable $read, string $uri = '', string $mime_type = 'text/plain', array $arguments = array(), bool $delegated = false ) {
		if ( ! in_array( $kind, array( self::RESOURCE, self::PROMPT ), true ) || trim( $name ) === '' || strlen( $name ) > 180 || trim( $description ) === ''
			|| ( $kind === self::RESOURCE && ! preg_match( '~^pom://[a-z0-9_/-]{1,200}$~D', $uri ) ) ) {
			throw new \InvalidArgumentException( 'Invalid POM MCP catalogue item.' );
		}
		$this->kind = $kind;
		$this->name = $name;
		$this->description = $description;
		$this->permission = $permission;
		$this->read = $read;
		$this->uri = $uri;
		$this->mime_type = $mime_type;
		$this->arguments = $arguments;
		$this->delegated = $delegated;
	}
}
