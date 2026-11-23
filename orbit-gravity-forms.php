<?php
/**
 * Plugin Name:       Orbit for Gravity Forms
 * Plugin URI:        https://github.com/Converta-Agency/wpplugin-orbit-gravity-forms
 * Description:       Sends Gravity Forms entries to Orbit CRM as forms, creating or matching the contact, using Orbit API keys.
 * Version:           1.0.0
 * Author:            Converta
 * Author URI:        https://orbitcrm.co.uk
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       orbit-gf
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  gravityforms
 *
 * @package Orbit\GravityForms
 */

defined( 'ABSPATH' ) || exit;

define( 'ORBIT_GF_VERSION', '1.0.0' );
define( 'ORBIT_GF_FILE', __FILE__ );
define( 'ORBIT_GF_DIR', plugin_dir_path( __FILE__ ) );
define( 'ORBIT_GF_URL', plugin_dir_url( __FILE__ ) );

/*
 * The plugin ships without a vendor directory, so classes under src/ are
 * loaded by this small PSR-4 autoloader rather than Composer's.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Orbit\\GravityForms\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = ORBIT_GF_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'Orbit\\GravityForms\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Orbit\\GravityForms\\Plugin', 'deactivate' ) );

/*
 * The updater runs on every request, not only in the admin, because WordPress
 * also checks for plugin updates from its twice-daily cron. Registering it only
 * in the admin let the cron check drop this plugin's release from the list.
 */
( new Orbit\GravityForms\Support\Updater( __FILE__ ) )->register();

add_action( 'plugins_loaded', array( 'Orbit\\GravityForms\\Plugin', 'boot' ) );

/*
 * Gravity Forms fires gform_loaded once its add-on framework is available.
 * Priority 5 registers the add-on before Gravity Forms initialises add-ons.
 */
add_action( 'gform_loaded', array( 'Orbit\\GravityForms\\Plugin', 'register_addon' ), 5 );
