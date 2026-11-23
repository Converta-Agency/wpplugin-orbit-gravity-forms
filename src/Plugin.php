<?php
/**
 * Plugin bootstrap: wires every part of the plugin into WordPress.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms;

use Orbit\GravityForms\Admin\ConnectionsPage;
use Orbit\GravityForms\Admin\FailedSubmissionsPage;
use Orbit\GravityForms\Queue\RetryQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point called from the main plugin file.
 */
final class Plugin {

	/**
	 * Schema version of the retry table, bumped whenever its definition changes.
	 */
	const SCHEMA_VERSION = '1';

	/**
	 * Register hooks once every plugin has loaded.
	 */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'load_textdomain' ) );

		self::maybe_upgrade();

		( new ConnectionsPage( new Connections() ) )->register();
		( new FailedSubmissionsPage( new RetryQueue() ) )->register();
		( new RetryQueue() )->register();

		if ( ! class_exists( 'GFForms' ) ) {
			add_action( 'admin_notices', array( self::class, 'render_missing_gravity_forms_notice' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'orbit-gf', Cli::class );
		}
	}

	/**
	 * Register the Gravity Forms feed add-on once the add-on framework is available.
	 */
	public static function register_addon(): void {
		if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
			return;
		}
		\GFForms::include_feed_addon_framework();
		\GFAddOn::register( Addon::class );
	}

	/**
	 * Load translations on init, as WordPress 6.7 requires.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'orbit-gf', false, dirname( plugin_basename( ORBIT_GF_FILE ) ) . '/languages' );
	}

	/**
	 * Create the retry table and schedule the retry job.
	 */
	public static function activate(): void {
		( new RetryQueue() )->install();
		update_option( 'orbit_gf_schema_version', self::SCHEMA_VERSION, false );
		RetryQueue::schedule();
	}

	/**
	 * Stop the retry job. Data is kept until the plugin is deleted (see uninstall.php).
	 */
	public static function deactivate(): void {
		RetryQueue::unschedule();
	}

	/**
	 * Apply schema changes after an update, which does not fire the activation hook.
	 */
	private static function maybe_upgrade(): void {
		if ( get_option( 'orbit_gf_schema_version' ) === self::SCHEMA_VERSION ) {
			return;
		}
		self::activate();
	}

	/**
	 * Tell administrators the plugin does nothing until Gravity Forms is active.
	 */
	public static function render_missing_gravity_forms_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Orbit for Gravity Forms needs Gravity Forms 2.5 or later. Install and activate Gravity Forms to start sending entries to Orbit.', 'orbit-gf' )
		);
	}
}
