<?php
/**
 * Plugin Name:  FRS OS API — MU Loader
 * Description:  Force-loads the FRS OS API plugin regardless of
 *               whether someone has accidentally deactivated it in the network
 *               plugins UI. The API is load-bearing infrastructure; mobile
 *               apps and LLM agents must always be able to reach it.
 * Network:      true
 * Author:       Full Realty Services
 * Version:      1.0.0
 *
 * Install: copy this file (only this single PHP file) to:
 *     wp-content/mu-plugins/frs-papi-loader.php
 *
 * Do NOT copy the whole plugin directory into mu-plugins — only this loader.
 * MU plugins auto-load on every request, before regular plugins, and cannot
 * be disabled from the UI. The loader bridges the regular plugin into the
 * mu-plugin load order so it cannot be turned off.
 *
 * @package FRSPapi\MuLoader
 */

defined( 'ABSPATH' ) || exit;

$frs_papi_main = WP_PLUGIN_DIR . '/frs-people-and-places-api/frs-people-and-places-api.php';

if ( file_exists( $frs_papi_main ) ) {
	require_once $frs_papi_main;
} else {
	// Log once — don't spam — that the plugin is missing.
	add_action( 'admin_notices', function () {
		if ( current_user_can( 'manage_network' ) ) {
			echo '<div class="notice notice-error"><p>'
				. '<strong>FRS OS API loader</strong>: the main plugin is missing at '
				. '<code>wp-content/plugins/frs-people-and-places-api/</code>. '
				. 'Mobile apps, the LLM agent integration, and partner sites cannot reach the API until the plugin is restored.'
				. '</p></div>';
		}
	} );
}
