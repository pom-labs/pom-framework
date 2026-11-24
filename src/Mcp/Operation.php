<?php

namespace POMFramework\Mcp;

/** Describe one explicitly exposed ability, including the authorization needed for its effect. */
final class Operation {
	public const NATIVE_CATALOG_VERSION = 2;
	public const READ = 'read';
	public const WRITE = 'write';
	public const COST = 'cost';

	public string $name;
	public string $label;
	public string $description;
	public string $effect;
	public array $schema;
	public bool $uses_actor;
	public string $network_group;
	/** @var string[] Site permission groups. The registry supplies the provider ID by default. */
	public array $write_groups;
	/** @var callable */
	public $permission;
	/** @var callable */
	public $execute;
	/** @var callable|null */
	public $preview;

	public function __construct( string $name, string $label, string $description, string $effect, array $schema, callable $permission, callable $execute, ?callable $preview = null, bool $uses_actor = false, string $network_group = '', array $write_groups = array() ) {
		if ( ! preg_match( '/^(?:pom|wp|wc)_[a-z0-9]+(?:_[a-z0-9]+)*$/D', $name ) || strlen( $name ) > 100
			|| trim( $label ) === '' || trim( $description ) === ''
			|| ! in_array( $effect, array( self::READ, self::WRITE, self::COST ), true )
			|| ( $schema['type'] ?? '' ) !== 'object' || ( $schema['additionalProperties'] ?? null ) !== false ) {
			throw new \InvalidArgumentException( 'Invalid POM MCP operation descriptor.' );
		}
		$this->name = $name;
		$this->label = $label;
		$this->description = $description;
		$this->effect = $effect;
		$this->schema = $schema;
		$this->permission = $permission;
		$this->execute = $execute;
		$this->preview = $preview;
		$this->uses_actor = $uses_actor;
		$this->network_group = $network_group;
		$this->write_groups = $write_groups;
	}

	public function allows_site_writes(): bool {
		return Settings::write_groups_allowed( $this->write_groups );
	}
}
