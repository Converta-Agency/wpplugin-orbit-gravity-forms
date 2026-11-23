<?php
/**
 * Creates the test forms, feeds and page used for end-to-end testing.
 * Run with: wp eval-file wp-content/plugins/orbit-gravity-forms/dev/scripts/seed-forms.php \
 *   <connection-id> <form-type-id> <pipeline-form-type-id> [source-id]
 *
 * The first form type needs a text field in slot Text1; the pipeline one a money
 * field in Money1 and a date field in DateTime1 (see the account's form types
 * in a feed's settings).
 *
 * @package Orbit\GravityForms
 */

$connection        = $args[0] ?? '';
$form_type_id      = $args[1] ?? '';
$pipeline_type_id  = $args[2] ?? '';
$source_id         = $args[3] ?? '';

$enquiry_id = GFAPI::add_form(
	array(
		'title'  => 'Website enquiry (Microsite)',
		'fields' => array(
			array( 'id' => 1, 'type' => 'name', 'label' => 'Name', 'nameFormat' => 'advanced', 'inputs' => array( array( 'id' => '1.3', 'label' => 'First' ), array( 'id' => '1.6', 'label' => 'Last' ) ) ),
			array( 'id' => 2, 'type' => 'email', 'label' => 'Email', 'isRequired' => true ),
			array( 'id' => 3, 'type' => 'text', 'label' => 'Company' ),
			array( 'id' => 4, 'type' => 'text', 'label' => 'Postcode' ),
			array( 'id' => 5, 'type' => 'textarea', 'label' => 'Comments' ),
			array( 'id' => 6, 'type' => 'consent', 'label' => 'Marketing', 'checkboxLabel' => 'Send me news by email', 'inputs' => array( array( 'id' => '6.1', 'label' => 'Consent' ), array( 'id' => '6.2', 'label' => 'Text', 'isHidden' => true ), array( 'id' => '6.3', 'label' => 'Description', 'isHidden' => true ) ) ),
		),
	)
);
GFAPI::add_feed(
	$enquiry_id,
	array(
		'feedName'                  => 'Enquiry to Orbit',
		'connection'                => $connection,
		'formTypeId'                => $form_type_id,
		'contactMap_email'          => '2',
		'contactMap_firstName'      => '1.3',
		'contactMap_lastName'       => '1.6',
		'contactMap_organisationName' => '3',
		'contactMap_postcode'       => '4',
		'emailSubscribeField'       => '6',
		'tidyNames'                 => '1',
		'formFields'                => array( array( 'key' => 'Text1', 'custom_key' => '', 'value' => '5', 'custom_value' => '' ) ),
		'sourceId'                  => $source_id,
	),
	'orbit-gf'
);

$deal_id = GFAPI::add_form(
	array(
		'title'  => 'Quote request (Sales Pipeline Form)',
		'fields' => array(
			array( 'id' => 1, 'type' => 'text', 'label' => 'First name' ),
			array( 'id' => 2, 'type' => 'email', 'label' => 'Email', 'isRequired' => true ),
			array( 'id' => 3, 'type' => 'number', 'label' => 'Budget', 'numberFormat' => 'decimal_dot' ),
			array( 'id' => 4, 'type' => 'date', 'label' => 'Decision date', 'dateType' => 'datepicker', 'dateFormat' => 'dmy' ),
			array( 'id' => 5, 'type' => 'radio', 'label' => 'Newsletter', 'choices' => array( array( 'text' => 'Yes please', 'value' => 'Yes please' ), array( 'text' => 'No thanks', 'value' => 'No thanks' ) ) ),
		),
	)
);
GFAPI::add_feed(
	$deal_id,
	array(
		'feedName'             => 'Quote to Orbit pipeline',
		'connection'           => $connection,
		'formTypeId'           => $pipeline_type_id,
		'contactMap_email'     => '2',
		'contactMap_firstName' => '1',
		'emailSubscribeField'  => '5',
		'formFields'           => array(
			array( 'key' => 'Money1', 'custom_key' => '', 'value' => '3', 'custom_value' => '' ),
			array( 'key' => 'DateTime1', 'custom_key' => '', 'value' => '4', 'custom_value' => '' ),
		),
	),
	'orbit-gf'
);

// A form configured the KulaHub plugin's way, to test the import.
$legacy_id = GFAPI::add_form(
	array(
		'title'    => 'Legacy KulaHub form',
		'formid'   => $form_type_id,
		'clientid' => '1',
		'fields'   => array(
			array( 'id' => 1, 'type' => 'text', 'label' => 'First name', 'encryptField' => 'firstname' ),
			array( 'id' => 2, 'type' => 'email', 'label' => 'Email', 'encryptField' => 'email' ),
			array( 'id' => 3, 'type' => 'textarea', 'label' => 'Comments', 'encryptField' => 'varchar1' ),
			array( 'id' => 4, 'type' => 'text', 'label' => 'Course', 'encryptField' => 'coursetitle' ),
			array( 'id' => 5, 'type' => 'checkbox', 'label' => 'Opt in', 'encryptField' => 'emailsubscribe', 'choices' => array( array( 'text' => 'Yes', 'value' => 'Yes' ) ), 'inputs' => array( array( 'id' => '5.1', 'label' => 'Yes' ) ) ),
		),
	)
);

$page_id = wp_insert_post(
	array(
		'post_title'   => 'Orbit test forms',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => "[gravityform id=\"{$enquiry_id}\" title=\"true\" ajax=\"false\"]\n\n[gravityform id=\"{$deal_id}\" title=\"true\" ajax=\"false\"]\n\n[gravityform id=\"{$legacy_id}\" title=\"true\" ajax=\"false\"]",
	)
);

echo wp_json_encode( compact( 'enquiry_id', 'deal_id', 'legacy_id', 'page_id' ) ) . "\n";
