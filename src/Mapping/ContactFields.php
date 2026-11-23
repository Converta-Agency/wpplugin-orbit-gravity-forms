<?php
/**
 * The Orbit contact fields a form can fill in.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms\Mapping;

/**
 * Contact properties accepted by POST /api/Forms under "contact".
 *
 * Mapping is explicit and exact. The legacy plugin matched field IDs by
 * substring, so a custom field called "coursetitle" was sent as the contact's
 * title; that cannot happen here.
 */
final class ContactFields {

	/**
	 * Orbit property => legacy KulaHub field ID (lower case).
	 *
	 * @var array<string, string>
	 */
	const LEGACY_IDS = array(
		'email'            => 'email',
		'firstName'        => 'firstname',
		'lastName'         => 'lastname',
		'title'            => 'title',
		'jobTitle'         => 'jobtitle',
		'organisationName' => 'organisationname',
		'telephone'        => 'telephone',
		'mobile'           => 'mobile',
		'website'          => 'website',
		'address1'         => 'address1',
		'address2'         => 'address2',
		'address3'         => 'address3',
		'town'             => 'town',
		'county'           => 'county',
		'postcode'         => 'postcode',
		'country'          => 'country',
	);

	/**
	 * Fields whose capitalisation can be tidied (names typed in all capitals or all lower case).
	 *
	 * @var string[]
	 */
	const NAME_FIELDS = array( 'firstName', 'lastName', 'title', 'town', 'county' );

	/**
	 * Labels for the feed settings field map, in display order.
	 *
	 * @return array<string, string> Orbit property => label.
	 */
	public static function labels(): array {
		return array(
			'email'            => __( 'Email address', 'orbit-gf' ),
			'firstName'        => __( 'First name', 'orbit-gf' ),
			'lastName'         => __( 'Last name', 'orbit-gf' ),
			'title'            => __( 'Title (Mr, Ms, Dr)', 'orbit-gf' ),
			'jobTitle'         => __( 'Job title', 'orbit-gf' ),
			'organisationName' => __( 'Organisation name', 'orbit-gf' ),
			'telephone'        => __( 'Telephone', 'orbit-gf' ),
			'mobile'           => __( 'Mobile', 'orbit-gf' ),
			'website'          => __( 'Website', 'orbit-gf' ),
			'address1'         => __( 'Address line 1', 'orbit-gf' ),
			'address2'         => __( 'Address line 2', 'orbit-gf' ),
			'address3'         => __( 'Address line 3', 'orbit-gf' ),
			'town'             => __( 'Town or city', 'orbit-gf' ),
			'county'           => __( 'County', 'orbit-gf' ),
			'postcode'         => __( 'Postcode', 'orbit-gf' ),
			'country'          => __( 'Country', 'orbit-gf' ),
		);
	}

	/**
	 * The Orbit property for a legacy KulaHub field ID, matched exactly.
	 *
	 * @param string $legacy_id Field ID from the legacy plugin, for example "firstname".
	 * @return string|null Orbit property, or null when it is not a contact field.
	 */
	public static function from_legacy( string $legacy_id ): ?string {
		$found = array_search( strtolower( trim( $legacy_id ) ), self::LEGACY_IDS, true );
		return false === $found ? null : $found;
	}
}
