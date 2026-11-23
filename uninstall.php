<?php
/**
 * Removes everything the plugin stored when it is deleted from the Plugins screen.
 *
 * Orbit feeds are deleted too. Entry meta (the Orbit form and contact IDs) and
 * entry notes stay with the entries, as they record what happened.
 *
 * @package Orbit\GravityForms
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'orbit_gf_connections' );
delete_option( 'orbit_gf_schema_version' );
delete_site_transient( 'orbit_gf_latest_release' );
wp_clear_scheduled_hook( 'orbit_gf_retry_failed' );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}orbit_gf_queue" ); // phpcs:ignore WordPress.DB -- removing the plugin's own table.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}gf_addon_feed WHERE addon_slug = %s", 'orbit-gf' ) ); // phpcs:ignore WordPress.DB -- Gravity Forms has no API that runs without the add-on loaded.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_orbit\_gf\_%' OR option_name LIKE '\_transient\_timeout\_orbit\_gf\_%'" ); // phpcs:ignore WordPress.DB -- cached Orbit lists.
