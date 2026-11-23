<?php
/**
 * Builds the body for Orbit's POST /api/Forms.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms\Mapping;

/**
 * Pure payload assembly, free of WordPress and Gravity Forms so it can be unit tested.
 *
 * Orbit creates the form and, from the "contact" object, either creates a new
 * contact or attaches the form to the existing contact with the same email address.
 */
final class PayloadBuilder {

	/**
	 * Orbit's limit on a contact's email address.
	 */
	const EMAIL_MAX_LENGTH = 80;

	/**
	 * Form type to create.
	 *
	 * @var int
	 */
	private $form_type_id;

	/**
	 * Contact property => value.
	 *
	 * @var array<string, string>
	 */
	private $contact = array();

	/**
	 * Email opt-in, or null when the form does not ask.
	 *
	 * @var bool|null
	 */
	private $email_subscribe;

	/**
	 * Orbit column => converted value.
	 *
	 * @var array<string, mixed>
	 */
	private $custom = array();

	/**
	 * Source ID, if any.
	 *
	 * @var int|null
	 */
	private $source_id;

	/**
	 * Category IDs for the contact.
	 *
	 * @var int[]
	 */
	private $category_ids = array();

	/**
	 * Start a payload for a form type.
	 *
	 * @param int $form_type_id Orbit form type ID.
	 */
	public function __construct( int $form_type_id ) {
		$this->form_type_id = $form_type_id;
	}

	/**
	 * Set a contact field; empty values are skipped.
	 *
	 * @param string $property   Orbit contact property, for example firstName.
	 * @param mixed  $value      Submitted value.
	 * @param bool   $tidy_names Tidy all-capitals or all-lower-case names.
	 */
	public function contact( string $property, $value, bool $tidy_names = false ): self {
		$text = ValueConverter::to_text( $value );
		if ( null === $text ) {
			return $this;
		}
		if ( 'email' === $property ) {
			$text = strtolower( $text );
		} elseif ( $tidy_names && in_array( $property, ContactFields::NAME_FIELDS, true ) ) {
			$text = ValueConverter::tidy_name( $text );
		}
		$this->contact[ $property ] = $text;
		return $this;
	}

	/**
	 * Record the email opt-in answer.
	 *
	 * @param bool $subscribed Whether the person opted in.
	 */
	public function email_subscribe( bool $subscribed ): self {
		$this->email_subscribe = $subscribed;
		return $this;
	}

	/**
	 * Set a form custom field, converting the value for the slot's type.
	 *
	 * @param string $column Orbit column (Text1, Money2, Bit3) or its database name.
	 * @param mixed  $value  Submitted value.
	 * @return bool False when the column is not a form slot.
	 */
	public function custom( string $column, $value ): bool {
		$normalised = Columns::normalise( $column );
		if ( null === $normalised ) {
			return false;
		}
		$converted = ValueConverter::for_kind( (string) Columns::kind( $normalised ), $value );
		if ( null !== $converted ) {
			$this->custom[ $normalised ] = $converted;
		}
		return true;
	}

	/**
	 * Set the source the form came from.
	 *
	 * @param int|null $source_id Orbit source ID.
	 */
	public function source( ?int $source_id ): self {
		$this->source_id = null !== $source_id && $source_id > 0 ? $source_id : null;
		return $this;
	}

	/**
	 * Set the categories a contact is added to.
	 *
	 * @param int[] $category_ids Orbit category IDs.
	 */
	public function categories( array $category_ids ): self {
		$this->category_ids = array_values( array_unique( array_filter( array_map( 'intval', $category_ids ) ) ) );
		return $this;
	}

	/**
	 * Problems that would make Orbit reject the payload, in plain words.
	 *
	 * @return string[]
	 */
	public function problems(): array {
		$problems = array();
		$email    = $this->contact['email'] ?? '';
		if ( '' !== $email && ( strlen( $email ) > self::EMAIL_MAX_LENGTH || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) ) {
			$problems[] = sprintf( 'The email address "%s" is not one Orbit accepts (it must be valid and at most 80 characters).', $email );
		}
		if ( empty( $this->contact ) ) {
			$problems[] = 'No contact details were filled in, so Orbit has no contact to attach the form to.';
		}
		return $problems;
	}

	/**
	 * The request body.
	 *
	 * @return array<string, mixed>
	 */
	public function build(): array {
		$contact = $this->contact;
		if ( null !== $this->email_subscribe ) {
			$contact['emailSubscribe'] = $this->email_subscribe;
		}
		if ( ! empty( $this->category_ids ) ) {
			$contact['categoryIds'] = $this->category_ids;
		}
		if ( null !== $this->source_id ) {
			$contact['sourceId'] = $this->source_id;
		}

		$payload = array( 'formTypeId' => $this->form_type_id );
		if ( null !== $this->source_id ) {
			$payload['sourceId'] = $this->source_id;
		}
		$payload            = array_merge( $payload, $this->custom );
		$payload['contact'] = (object) $contact;

		return $payload;
	}
}
