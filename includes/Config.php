<?php
/**
 * Configuration reader. All runtime config comes from wp-config.php constants
 * so secrets never live in the plugin's source tree.
 *
 * Expected wp-config.php constants:
 *
 *   // Read access: comma-separated list of valid API keys. Any client
 *   // presenting one of these in the `X-FRS-Api-Key` header gets read access.
 *   // Admins (capability `read`) always get read access regardless.
 *   define( 'FRS_PAPI_API_KEYS', 'key-for-mobile-app,key-for-llm-agent,key-for-marketing-site' );
 *
 *   // Optional throttling (per-IP).
 *   define( 'FRS_PAPI_RATE_LIMIT_PER_MIN', 120 );
 *
 *   // Pagination caps.
 *   define( 'FRS_PAPI_DEFAULT_PER_PAGE', 20 );
 *   define( 'FRS_PAPI_MAX_PER_PAGE',     100 );
 *
 *   // Expose /openapi.yaml, /swagger-ui, /llms.txt under the API namespace.
 *   define( 'FRS_PAPI_ENABLE_DOCS', true );
 *
 *   // Debug.
 *   define( 'FRS_PAPI_LOG_REQUESTS', false );
 *
 * Write access: NOT key-based. Always requires admin auth (capability
 * `edit_users`). Filter `frs_papi_write_permission` to relax/tighten.
 *
 * @package FRSPapi
 */

namespace FRSPapi;

defined( 'ABSPATH' ) || exit;

class Config {

	const HEADER_API_KEY = 'X-FRS-Api-Key';

	/** @var array<string,mixed> resolved config values */
	private static $cache = [];

	const DEFAULTS = [
		'api_keys'           => '',     // CSV of valid read keys
		'rate_limit_per_min' => 120,
		'default_per_page'   => 20,
		'max_per_page'       => 100,
		'enable_docs'        => true,
		'log_requests'       => false,
	];

	public static function init(): void {
		foreach ( self::DEFAULTS as $key => $default ) {
			$const = 'FRS_PAPI_' . strtoupper( $key );
			self::$cache[ $key ] = defined( $const ) ? constant( $const ) : $default;
		}
	}

	public static function get( string $key, $fallback = null ) {
		return self::$cache[ $key ] ?? $fallback ?? self::DEFAULTS[ $key ] ?? null;
	}

	public static function docs_enabled(): bool {
		return (bool) self::get( 'enable_docs' );
	}

	public static function default_per_page(): int {
		return max( 1, (int) self::get( 'default_per_page' ) );
	}

	public static function max_per_page(): int {
		return max( 1, (int) self::get( 'max_per_page' ) );
	}

	public static function rate_limit_per_min(): int {
		return max( 0, (int) self::get( 'rate_limit_per_min' ) );
	}

	/**
	 * Return the set of valid API keys configured in wp-config.
	 * Accepts CSV string or pre-split array.
	 * @return string[]
	 */
	public static function api_keys(): array {
		$raw = self::get( 'api_keys', '' );
		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'trim', $raw ) ) );
		}
		$raw = (string) $raw;
		if ( '' === $raw ) {
			return [];
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	/**
	 * Inspect the inbound request for an API key. Returns the matched key
	 * (truncated for log safety) on success, empty string otherwise.
	 */
	public static function request_api_key( $request ): string {
		if ( ! $request instanceof \WP_REST_Request ) {
			return '';
		}
		$presented = (string) $request->get_header( self::HEADER_API_KEY );
		if ( '' === $presented ) {
			return '';
		}
		foreach ( self::api_keys() as $valid ) {
			if ( hash_equals( $valid, $presented ) ) {
				return $valid;
			}
		}
		return '';
	}
}
