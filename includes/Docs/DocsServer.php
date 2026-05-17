<?php
/**
 * Serves the API documentation at REST routes:
 *
 *   GET /wp-json/frs/v1/docs           → HTML index linking to swagger-ui and llms.txt
 *   GET /wp-json/frs/v1/openapi.yaml   → the OpenAPI spec
 *   GET /wp-json/frs/v1/swagger-ui     → interactive Swagger UI page
 *   GET /wp-json/frs/v1/llms.txt       → LLM-optimized API docs
 *
 * All four are public (no auth) so anyone can discover the API. Disable via
 * `define( 'FRS_PAPI_ENABLE_DOCS', false );` in wp-config.
 *
 * Files live in the plugin's `docs/` directory and ship with the codebase —
 * always in sync with the API implementation.
 *
 * @package FRSPapi\Docs
 */

namespace FRSPapi\Docs;

use FRSPapi\Api\Bootstrap;
use FRSPapi\Config;

defined( 'ABSPATH' ) || exit;

class DocsServer {

	public static function init(): void {
		if ( ! Config::docs_enabled() ) {
			return;
		}
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		$ns = Bootstrap::NAMESPACE_V1;
		$public = '__return_true';

		register_rest_route( $ns, '/docs', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'serve_index' ],
			'permission_callback' => $public,
		] );
		register_rest_route( $ns, '/openapi.yaml', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'serve_openapi' ],
			'permission_callback' => $public,
		] );
		register_rest_route( $ns, '/swagger-ui', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'serve_swagger_ui' ],
			'permission_callback' => $public,
		] );
		register_rest_route( $ns, '/llms.txt', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'serve_llms' ],
			'permission_callback' => $public,
		] );
	}

	public static function serve_index() {
		$base = rest_url( Bootstrap::NAMESPACE_V1 );
		$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>FRS OS API — Docs</title>'
			. '<style>body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;max-width:640px;margin:60px auto;padding:0 24px;line-height:1.6;color:#1e293b}'
			. 'h1{color:#1e3a5f}a{color:#1d4ed8;text-decoration:none}a:hover{text-decoration:underline}'
			. 'ul{padding-left:24px}code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:14px}'
			. '.meta{color:#64748b;font-size:13px;margin-top:48px}</style></head>'
			. '<body><h1>FRS OS API</h1>'
			. '<p>The canonical REST API for People (agents, loan originators, staff) and Places (offices, regions).</p>'
			. '<ul>'
			. '<li><a href="' . esc_url( $base . '/swagger-ui' ) . '">Interactive Swagger UI</a></li>'
			. '<li><a href="' . esc_url( $base . '/openapi.yaml' ) . '">OpenAPI 3.1 spec</a> (machine-readable)</li>'
			. '<li><a href="' . esc_url( $base . '/llms.txt' ) . '">LLM-friendly docs</a> (concise, agent-friendly)</li>'
			. '</ul>'
			. '<p>Read access: include header <code>X-FRS-Api-Key: &lt;your-key&gt;</code>.<br>'
			. 'Write access: requires admin authentication (capability <code>edit_users</code>).</p>'
			. '<p class="meta">Version ' . esc_html( FRS_PAPI_VERSION ) . ' · plugin: <code>frs-people-and-places-api</code></p>'
			. '</body></html>';
		return self::raw_html_response( $html );
	}

	public static function serve_openapi() {
		return self::serve_file( 'openapi.yaml', 'application/yaml' );
	}

	public static function serve_swagger_ui() {
		// We rewrite the in-file `./openapi.yaml` URL to the actual REST endpoint
		// so the Swagger UI loads from `/wp-json/frs/v1/openapi.yaml`.
		$path = FRS_PAPI_DOCS_DIR . 'swagger-ui.html';
		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'frs_papi_docs_missing', 'swagger-ui.html not shipped with plugin.', [ 'status' => 500 ] );
		}
		$html = file_get_contents( $path );
		$openapi_url = rest_url( Bootstrap::NAMESPACE_V1 . '/openapi.yaml' );
		$html = str_replace( "'./openapi.yaml'", "'" . esc_url( $openapi_url ) . "'", $html );
		$html = str_replace( '"./openapi.yaml"', '"' . esc_url( $openapi_url ) . '"', $html );
		return self::raw_html_response( $html );
	}

	public static function serve_llms() {
		return self::serve_file( 'llms.txt', 'text/plain; charset=utf-8' );
	}

	// ---------------------------------------------------------------------

	private static function serve_file( string $filename, string $content_type ) {
		$path = FRS_PAPI_DOCS_DIR . $filename;
		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'frs_papi_docs_missing', "Doc file not shipped: $filename", [ 'status' => 500 ] );
		}
		$body = file_get_contents( $path );
		return self::raw_response( $body, $content_type );
	}

	private static function raw_html_response( string $html ) {
		return self::raw_response( $html, 'text/html; charset=utf-8' );
	}

	private static function raw_response( string $body, string $content_type ) {
		$response = new \WP_REST_Response( null );
		$response->set_status( 200 );
		add_filter( 'rest_pre_serve_request', function ( $served, $result ) use ( $body, $content_type ) {
			header( 'Content-Type: ' . $content_type );
			echo $body;
			return true;
		}, 10, 2 );
		return $response;
	}
}
