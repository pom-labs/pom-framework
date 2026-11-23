<?php

namespace POMFramework\Mcp;

/** Collect a site's providers once so transport registration never depends on plugin order. */
final class Registry {
	private array $providers = array();
	private array $skills = array();
	private bool $frozen = false;
	private bool $conflict = false;

	public function register( string $id, string $label, ?callable $operations, ?callable $items = null ): void {
		if ( $this->frozen || ! preg_match( '/^pom(?:-[a-z0-9]+)*$/D', $id ) || trim( $label ) === '' || isset( $this->providers[ $id ] ) ) {
			$this->conflict = true;
			return;
		}
		$this->providers[ $id ] = array( 'label' => $label, 'operations' => $operations, 'items' => $items );
	}

	private function active_providers(): array {
		return AccountGateway::registered() ? array_intersect_key( $this->providers, array( AccountGateway::PROVIDER => true ) ) : $this->providers;
	}

	public function register_skill( string $provider, SkillPackage $skill ): void {
		if ( $this->frozen || ! isset( $this->providers[ $provider ] ) || isset( $this->skills[ $skill->name ] ) ) {
			$this->conflict = true;
			return;
		}
		$this->skills[ $skill->name ] = array( 'provider' => $provider, 'package' => $skill );
	}

	public function skills(): array {
		$providers = $this->active_providers();
		$skills = array();
		foreach ( $this->skills as $name => $entry ) {
			if ( isset( $providers[ $entry['provider'] ] ) ) { $skills[ $name ] = $entry['package']; }
		}
		ksort( $skills );
		return $skills;
	}

	public function providers(): array {
		$labels = array();
		foreach ( $this->active_providers() as $id => $provider ) {
			$labels[ $id ] = $provider['label'];
		}
		ksort( $labels );
		return $labels;
	}

	public function freeze( Operation $context ): array {
		$this->frozen = true;
		$operations = array( $context->name => $context ) + $this->skill_tools();
		$names = array_fill_keys( array_merge( array_keys( $operations ), Reviews::TOOL_NAMES ), true );
		$providers = $this->active_providers();
		ksort( $providers );
		foreach ( $providers as $provider ) {
			$descriptors = $provider['operations'] === null ? array() : ( $provider['operations'] )();
			if ( ! is_iterable( $descriptors ) ) {
				throw new \UnexpectedValueException( 'A POM MCP provider must return operation descriptors.' );
			}
			foreach ( $descriptors as $operation ) {
				if ( ! $operation instanceof Operation || isset( $names[ $operation->name ] ) ) {
					$this->conflict = true;
					break;
				}
				$names[ $operation->name ] = true;
				if ( $operation->effect === Operation::READ || ( in_array( $operation->effect, array( Operation::WRITE, Operation::COST ), true ) && $operation->preview !== null ) ) {
					$operations[ $operation->name ] = $operation;
				}
			}
		}

		if ( $this->conflict ) {
			throw new \UnexpectedValueException( 'POM MCP providers or their operation names conflict.' );
		}
		return $operations;
	}

	private function skill_tools(): array {
		$skills = $this->skills();
		if ( ! $skills ) { return array(); }
		return array(
			'pom_list_skills' => new Operation( 'pom_list_skills', 'List product skills', 'Discover the packaged skills from products active on this site.',
				Operation::READ, array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ), '__return_true',
				static function () use ( $skills ): array {
					return array( 'skills' => array_values( array_map( static fn( SkillPackage $skill ): array =>
						array( 'name' => $skill->name, 'label' => $skill->label ), $skills ) ) );
				} ),
			'pom_get_skill' => new Operation( 'pom_get_skill', 'Get a skill', 'Read its manifest or fetch an exact file at its manifest revision.',
				Operation::READ, array( 'type' => 'object', 'properties' => array(
					'name' => array( 'type' => 'string', 'enum' => array_keys( $skills ) ),
					'file' => array( 'type' => 'string', 'maxLength' => 255 ),
					'expected_revision' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				), 'required' => array( 'name' ), 'additionalProperties' => false ), '__return_true',
				static fn( array $args ) => $skills[ $args['name'] ]->read( $args ) ),
		);
	}

	/** Resolve items after WordPress and all destination components are initialized. */
	public function items(): array {
		$items = array();
		foreach ( $this->active_providers() as $provider ) {
			if ( $provider['items'] === null ) { continue; }
			foreach ( ( $provider['items'] )() as $item ) {
				if ( ! $item instanceof Catalog_Item ) { throw new \UnexpectedValueException( 'Invalid POM MCP catalogue item.' ); }
				$key = $item->kind . ':' . ( $item->uri ?: $item->name );
				if ( isset( $items[ $key ] ) ) { throw new \UnexpectedValueException( 'Duplicate POM MCP catalogue item.' ); }
				$items[ $key ] = $item;
			}
		}
		ksort( $items );
		return $items;
	}

}
