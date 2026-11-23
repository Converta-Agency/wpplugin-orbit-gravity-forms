<?php
/**
 * An Orbit account's form types, sources and categories.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms\Mapping;

/**
 * Normalises the API's list responses into the few values the feed settings need.
 */
final class FieldCatalogue {

	/**
	 * Form type ID => [name, fields: column => [name, kind, required]].
	 *
	 * @var array<int, array{name: string, fields: array<string, array{name: string, kind: string, required: bool}>}>
	 */
	private $form_types;

	/**
	 * Source ID => name.
	 *
	 * @var array<int, string>
	 */
	private $sources;

	/**
	 * Category ID => name.
	 *
	 * @var array<int, string>
	 */
	private $categories;

	/**
	 * Build from normalised arrays.
	 *
	 * @param array $form_types Form types.
	 * @param array $sources    Sources.
	 * @param array $categories Categories.
	 */
	private function __construct( array $form_types, array $sources, array $categories ) {
		$this->form_types = $form_types;
		$this->sources    = $sources;
		$this->categories = $categories;
	}

	/**
	 * Build from the raw list responses of /api/FormTypes, /api/Sources and /api/Categories.
	 *
	 * @param array<int, array<string, mixed>> $form_types Raw form types.
	 * @param array<int, array<string, mixed>> $sources    Raw sources.
	 * @param array<int, array<string, mixed>> $categories Raw categories.
	 */
	public static function from_api( array $form_types, array $sources, array $categories ): self {
		$types = array();
		foreach ( $form_types as $form_type ) {
			$id = (int) self::read( $form_type, array( 'formTypeId', 'FormTypeId', 'id' ) );
			if ( $id <= 0 ) {
				continue;
			}
			$fields      = array();
			$definitions = self::read( $form_type, array( 'customFieldDefinition', 'CustomFieldDefinition' ) );
			foreach ( is_array( $definitions ) ? $definitions : array() as $definition ) {
				// Fields created in V2 may carry only the database column name.
				$column = Columns::normalise( (string) self::read( $definition, array( 'customFieldTableColumnName', 'CustomFieldTableColumnName' ) ) )
					?? Columns::normalise( (string) self::read( $definition, array( 'dataColumnV2', 'DataColumnV2' ) ) );
				if ( null === $column || isset( $fields[ $column ] ) ) {
					continue;
				}
				$fields[ $column ] = array(
					'name'     => (string) self::read( $definition, array( 'name', 'Name' ) ),
					'kind'     => (string) Columns::kind( $column ),
					'required' => (bool) self::read( $definition, array( 'required', 'Required' ) ),
				);
			}
			$types[ $id ] = array(
				'name'   => (string) self::read( $form_type, array( 'name', 'Name' ) ),
				'fields' => $fields,
			);
		}

		return new self(
			$types,
			self::id_name_list( $sources, array( 'sourceId', 'SourceId', 'id' ) ),
			self::id_name_list( $categories, array( 'categoryId', 'CategoryId', 'id' ) )
		);
	}

	/**
	 * Restore from the cached array.
	 *
	 * @param array<string, mixed> $data Output of to_array().
	 */
	public static function from_array( array $data ): self {
		return new self(
			(array) ( $data['form_types'] ?? array() ),
			(array) ( $data['sources'] ?? array() ),
			(array) ( $data['categories'] ?? array() )
		);
	}

	/**
	 * Export for caching.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'form_types' => $this->form_types,
			'sources'    => $this->sources,
			'categories' => $this->categories,
		);
	}

	/**
	 * Form type names keyed by ID, sorted by name.
	 *
	 * @return array<int, string>
	 */
	public function form_type_names(): array {
		$names = array_map(
			static function ( $type ) {
				return $type['name'];
			},
			$this->form_types
		);
		asort( $names, SORT_NATURAL | SORT_FLAG_CASE );
		return $names;
	}

	/**
	 * Whether the account has the form type.
	 *
	 * @param int $form_type_id Form type ID.
	 */
	public function has_form_type( int $form_type_id ): bool {
		return isset( $this->form_types[ $form_type_id ] );
	}

	/**
	 * A form type's custom fields, column => [name, kind, required].
	 *
	 * @param int $form_type_id Form type ID.
	 * @return array<string, array{name: string, kind: string, required: bool}>
	 */
	public function fields( int $form_type_id ): array {
		return $this->form_types[ $form_type_id ]['fields'] ?? array();
	}

	/**
	 * Sources keyed by ID.
	 *
	 * @return array<int, string>
	 */
	public function sources(): array {
		return $this->sources;
	}

	/**
	 * Categories keyed by ID.
	 *
	 * @return array<int, string>
	 */
	public function categories(): array {
		return $this->categories;
	}

	/**
	 * Read the first present key, as the API's casing has varied between versions.
	 *
	 * @param mixed    $row  Row from the API.
	 * @param string[] $keys Candidate keys.
	 * @return mixed
	 */
	private static function read( $row, array $keys ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $row ) ) {
				return $row[ $key ];
			}
		}
		return null;
	}

	/**
	 * Turn a list of rows into ID => name, sorted by name.
	 *
	 * @param array<int, array<string, mixed>> $rows    Rows.
	 * @param string[]                         $id_keys Candidate ID keys.
	 * @return array<int, string>
	 */
	private static function id_name_list( array $rows, array $id_keys ): array {
		$list = array();
		foreach ( $rows as $row ) {
			$id   = (int) self::read( $row, $id_keys );
			$name = trim( (string) self::read( $row, array( 'name', 'Name' ) ) );
			if ( $id > 0 && '' !== $name ) {
				$list[ $id ] = $name;
			}
		}
		asort( $list, SORT_NATURAL | SORT_FLAG_CASE );
		return $list;
	}
}
