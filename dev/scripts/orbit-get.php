<?php
/**
 * Reads a path from the Orbit API with the first stored account's key, for checking test results.
 * Run with: wp eval-file .../dev/scripts/orbit-get.php /api/Forms/123
 *
 * @package Orbit\GravityForms
 */

$connections = new Orbit\GravityForms\Connections();
$id          = array_key_first( $connections->all() );
$response    = wp_remote_get(
	Orbit\GravityForms\Api\Client::base_url() . $args[0],
	array(
		'headers'   => array( 'X-Api-Key' => $connections->api_key( $id ), 'Accept' => 'application/json' ),
		'sslverify' => false,
		'timeout'   => 20,
	)
);
echo is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) . ' ' . wp_remote_retrieve_body( $response );
