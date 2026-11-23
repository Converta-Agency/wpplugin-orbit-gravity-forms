<?php
/**
 * Unit test bootstrap: loads the plugin's classes without WordPress.
 *
 * @package Orbit\GravityForms
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'ORBIT_GF_VERSION' ) || define( 'ORBIT_GF_VERSION', 'test' );
