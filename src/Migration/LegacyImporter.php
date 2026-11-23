<?php
/**
 * Turns the legacy KulaHub plugin's per-form settings into an Orbit feed.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms\Migration;

use GFAPI;
use Orbit\GravityForms\Addon;
use Orbit\GravityForms\Mapping\Columns;
use Orbit\GravityForms\Mapping\ContactFields;
use Orbit\GravityForms\Mapping\FieldCatalogue;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The KulaHub plugin stored the form type in the form's "formid" (or
 * "kulahubFormId") setting and each field's KulaHub field ID in the field's
 * "encryptField" property. Orbit and KulaHub share one database, so the form
 * type ID and the slot numbers carry over unchanged.
 */
final class LegacyImporter {

	/**
	 * Whether a form carries KulaHub plugin settings.
	 *
	 * @param array $form Form.
	 */
	public static function has_legacy_settings( array $form ): bool {
		return self::legacy_form_type_id( $form ) > 0 && ! empty( self::legacy_field_ids( $form ) );
	}

	/**
	 * The mapping the import would create, without saving anything.
	 *
	 * @param array $form Form.
	 * @return array{form_type_id: int, meta: array<string, mixed>, skipped: string[]}
	 */
	public static function plan( array $form ): array {
		$meta    = array(
			'feedName'  => __( 'Orbit (imported from KulaHub)', 'orbit-gf' ),
			'tidyNames' => '1',
		);
		$fields  = array();
		$skipped = array();

		foreach ( self::legacy_field_ids( $form ) as $field_id => $legacy_id ) {
			$lower = strtolower( $legacy_id );
			if ( 'emailsubscribe' === $lower ) {
				$meta['emailSubscribeField'] = (string) $field_id;
				continue;
			}
			$contact = ContactFields::from_legacy( $legacy_id );
			if ( null !== $contact ) {
				$meta[ 'contactMap_' . $contact ] = (string) $field_id;
				continue;
			}
			$column = Columns::normalise( $legacy_id );
			if ( null !== $column ) {
				$fields[] = array(
					'key'          => $column,
					'custom_key'   => '',
					'value'        => (string) $field_id,
					'custom_value' => '',
				);
				continue;
			}
			$skipped[] = $legacy_id;
		}

		$meta['formFields'] = $fields;
		$meta['formTypeId'] = (string) self::legacy_form_type_id( $form );

		return array(
			'form_type_id' => self::legacy_form_type_id( $form ),
			'meta'         => $meta,
			'skipped'      => $skipped,
		);
	}

	/**
	 * Create the feed.
	 *
	 * @param int    $form_id       Form ID.
	 * @param string $connection_id Orbit account to send to.
	 * @return array{feed_id: int, skipped: string[]}|WP_Error
	 */
	public function import( int $form_id, string $connection_id ) {
		$form = GFAPI::get_form( $form_id );
		if ( ! is_array( $form ) || ! self::has_legacy_settings( $form ) ) {
			return new WP_Error( 'orbit_gf_no_legacy', __( 'This form has no KulaHub settings to import.', 'orbit-gf' ) );
		}

		$addon     = Addon::get_instance();
		$catalogue = $addon->catalogue_for( $connection_id );
		if ( null === $catalogue ) {
			return new WP_Error( 'orbit_gf_no_connection', __( 'Choose an Orbit account to send the form to.', 'orbit-gf' ) );
		}
		if ( is_wp_error( $catalogue ) ) {
			return $catalogue;
		}

		$plan = self::plan( $form );
		if ( $catalogue instanceof FieldCatalogue && ! $catalogue->has_form_type( $plan['form_type_id'] ) ) {
			return new WP_Error(
				'orbit_gf_unknown_form_type',
				/* translators: %d: form type ID. */
				sprintf( __( 'Form type %d is not in this Orbit account. Check you chose the right account, or create the feed by hand.', 'orbit-gf' ), $plan['form_type_id'] )
			);
		}

		$meta               = $plan['meta'];
		$meta['connection'] = $connection_id;
		$feed_id            = GFAPI::add_feed( $form_id, $meta, 'orbit-gf' );
		if ( is_wp_error( $feed_id ) ) {
			return $feed_id;
		}

		return array(
			'feed_id' => (int) $feed_id,
			'skipped' => $plan['skipped'],
		);
	}

	/**
	 * The legacy form type ID.
	 *
	 * @param array $form Form.
	 */
	private static function legacy_form_type_id( array $form ): int {
		foreach ( array( 'formid', 'kulahubFormId', 'formTypeId' ) as $key ) {
			if ( ! empty( $form[ $key ] ) ) {
				return (int) $form[ $key ];
			}
		}
		return 0;
	}

	/**
	 * Gravity Forms field ID => legacy KulaHub field ID.
	 *
	 * @param array $form Form.
	 * @return array<string, string>
	 */
	private static function legacy_field_ids( array $form ): array {
		$ids = array();
		foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
			$legacy = is_object( $field ) ? ( $field->encryptField ?? '' ) : ( $field['encryptField'] ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- property set by the KulaHub plugin.
			$id     = is_object( $field ) ? $field->id : ( $field['id'] ?? '' );
			if ( is_string( $legacy ) && '' !== trim( $legacy ) ) {
				$ids[ (string) $id ] = trim( $legacy );
			}
		}
		return $ids;
	}
}
