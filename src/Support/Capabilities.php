<?php
/**
 * Gravity Forms capability checks.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Gravity Forms gives administrators "gform_full_access" instead of each
 * gravityforms_* capability, so current_user_can( 'gravityforms_edit_entries' )
 * is false for them. These helpers ask Gravity Forms, which knows both.
 */
final class Capabilities {

	/**
	 * Whether the current user has a Gravity Forms capability.
	 *
	 * @param string $capability Capability, for example gravityforms_edit_entries.
	 */
	public static function can( string $capability ): bool {
		if ( class_exists( 'GFCommon' ) ) {
			return (bool) \GFCommon::current_user_can_any( $capability );
		}
		return current_user_can( $capability ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Gravity Forms capability name passed in.
	}

	/**
	 * The capability to register an admin menu item with, so administrators see it.
	 *
	 * @param string $capability Capability the page needs.
	 */
	public static function for_menu( string $capability ): string {
		if ( class_exists( 'GFCommon' ) ) {
			$held = \GFCommon::current_user_can_which( array( $capability, 'gform_full_access' ) );
			return $held ? (string) $held : $capability;
		}
		return $capability;
	}
}
