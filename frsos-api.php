<?php
/**
 * Plugin Name:       FRSOS
 * Plugin URI:        https://github.com/fullrealtyservices/frsos-api
 * Description:       Canonical REST API for FRS People (agents, loan originators, staff) and Places (offices, regions). Backend-For-Frontend projection over WP user_meta + BP xprofile + BP groups. Self-documenting via OpenAPI + Swagger UI + llms.txt.
 * Version:           1.0.0
 * Network:           true
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Full Realty Services
 * Author URI:        https://fullrealtyservices.com
 * License:           GPLv2 or later
 * Text Domain:       frsos
 *
 * @package FRSOS
 */

defined( 'ABSPATH' ) || exit;

define( 'FRSOS_VERSION', '1.0.0' );
define( 'FRSOS_PLUGIN_FILE', __FILE__ );
define( 'FRSOS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FRSOS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FRSOS_DOCS_DIR', FRSOS_PLUGIN_DIR . 'docs/' );

// Composer-free PSR-4 autoloader for the FRSOS namespace.
spl_autoload_register( function ( $class ) {
	$prefix = 'FRSOS\\';
	if ( 0 !== strpos( $class, $prefix ) ) {
		return;
	}
	$rel  = substr( $class, strlen( $prefix ) );
	$path = FRSOS_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $rel ) . '.php';
	if ( file_exists( $path ) ) {
		require_once $path;
	}
} );

// Boot.
add_action( 'plugins_loaded', function () {
	\FRSOS\Config::init();
	\FRSOS\Api\Bootstrap::init();
	\FRSOS\Docs\DocsServer::init();
}, 5 );

/**
 * Network-only activation guard. This plugin is meaningless on a single
 * subsite — it must be network-activated so REST routes exist for every site
 * in the multisite. Refuse single-site activation with a clear message.
 */
register_activation_hook( __FILE__, function ( $network_wide ) {
	if ( is_multisite() && ! $network_wide ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'FRSOS must be Network Activated on multisite. Please activate from the Network Admin → Plugins screen.', 'frsos' ),
			esc_html__( 'Plugin activation error', 'frsos' ),
			[ 'back_link' => true ]
		);
	}
} );
