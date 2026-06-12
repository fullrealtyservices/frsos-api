<?php
/**
 * REST routes registration.
 *
 *   GET  /wp-json/frs/v1/people                       list (filter: member_type, office, region, search, page, per_page, fields)
 *   GET  /wp-json/frs/v1/people/me                    authenticated current user
 *   GET  /wp-json/frs/v1/people/{id}                  by wp_user_id
 *   GET  /wp-json/frs/v1/people/by-login/{login}      by user_login OR user_nicename
 *   GET  /wp-json/frs/v1/people/by-nmls/{nmls}        by NMLS id
 *
 *   GET  /wp-json/frs/v1/places                       list (filter: type, parent)
 *   GET  /wp-json/frs/v1/places/{id}                  single
 *   GET  /wp-json/frs/v1/places/{id}/people           members of a place
 *
 * Docs (when FRSOS_ENABLE_DOCS is true) are registered by Docs\DocsServer
 * at /wp-json/frs/v1/docs, /openapi.yaml, /swagger-ui, /llms.txt.
 *
 * @package FRSOS\Api
 */

namespace FRSOS\Api;

use FRSOS\Config;

defined( 'ABSPATH' ) || exit;

class Bootstrap {

	const NAMESPACE_V1 = 'frs/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		$read  = [ __CLASS__, 'permission_read' ];
		$write = [ __CLASS__, 'permission_write' ];
		$default_per_page = Config::default_per_page();
		$max_per_page     = Config::max_per_page();

		// ---- People ----
		register_rest_route( self::NAMESPACE_V1, '/people', [
			'methods'             => 'GET',
			'callback'            => [ PeopleController::class, 'list' ],
			'permission_callback' => $read,
			'args'                => [
				'member_type' => [ 'type' => 'string',  'required' => false, 'description' => 'BP member type slug (sales_associate, loan_originator, staff, broker_associate, ...)' ],
				'office'      => [ 'type' => 'string',  'required' => false, 'description' => 'Office display name (e.g. "Walnut")' ],
				'region'      => [ 'type' => 'string',  'required' => false, 'description' => 'Region display name' ],
				'search'      => [ 'type' => 'string',  'required' => false ],
				'page'        => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
				'per_page'    => [ 'type' => 'integer', 'default' => $default_per_page, 'minimum' => 1, 'maximum' => $max_per_page ],
				'fields'      => [ 'type' => 'string',  'required' => false, 'description' => 'CSV of top-level field groups to return (identity,contact,social,credentials,employment,re_production,lo_production,...).' ],
			],
		] );
		register_rest_route( self::NAMESPACE_V1, '/people/me', [
			'methods'             => 'GET',
			'callback'            => [ PeopleController::class, 'me' ],
			'permission_callback' => [ __CLASS__, 'permission_check_authenticated' ],
		] );
		register_rest_route( self::NAMESPACE_V1, '/people/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ PeopleController::class, 'get' ],
			'permission_callback' => $read,
			'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
		] );
		register_rest_route( self::NAMESPACE_V1, '/people/by-login/(?P<login>[\w\.\-@]+)', [
			'methods'             => 'GET',
			'callback'            => [ PeopleController::class, 'get_by_login' ],
			'permission_callback' => $read,
		] );
		register_rest_route( self::NAMESPACE_V1, '/people/by-nmls/(?P<nmls>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ PeopleController::class, 'get_by_nmls' ],
			'permission_callback' => $read,
		] );

		// ---- Places ----
		register_rest_route( self::NAMESPACE_V1, '/places', [
			'methods'             => 'GET',
			'callback'            => [ PlacesController::class, 'list' ],
			'permission_callback' => $read,
			'args'                => [
				'type'   => [ 'type' => 'string',  'required' => false, 'enum' => [ 'office', 'region', 'department', 'project' ] ],
				'parent' => [ 'type' => 'integer', 'required' => false ],
			],
		] );
		register_rest_route( self::NAMESPACE_V1, '/places/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ PlacesController::class, 'get' ],
			'permission_callback' => $read,
		] );
		register_rest_route( self::NAMESPACE_V1, '/places/(?P<id>\d+)/people', [
			'methods'             => 'GET',
			'callback'            => [ PlacesController::class, 'list_people' ],
			'permission_callback' => $read,
			'args'                => [
				'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
				'per_page' => [ 'type' => 'integer', 'default' => $default_per_page, 'minimum' => 1, 'maximum' => 200 ],
			],
		] );

		// ---- Write endpoints (admin auth required) ----
		register_rest_route( self::NAMESPACE_V1, '/people', [
			'methods'             => 'POST',
			'callback'            => [ WriteController::class, 'create_person' ],
			'permission_callback' => $write,
		] );
		register_rest_route( self::NAMESPACE_V1, '/people/me', [
			'methods'             => 'PATCH',
			'callback'            => [ WriteController::class, 'update_me' ],
			'permission_callback' => [ __CLASS__, 'permission_check_authenticated' ],
		] );
		register_rest_route( self::NAMESPACE_V1, '/people/(?P<id>\d+)', [
			'methods'             => 'PATCH',
			'callback'            => [ WriteController::class, 'update_person' ],
			'permission_callback' => $write,
		] );
		register_rest_route( self::NAMESPACE_V1, '/places/(?P<id>\d+)', [
			'methods'             => 'PATCH',
			'callback'            => [ WriteController::class, 'update_place' ],
			'permission_callback' => $write,
		] );

		// ---- Sites (nested under People and Places) ----
		register_rest_route( self::NAMESPACE_V1, '/people/(?P<id>\d+)/sites', [
			'methods'             => 'POST',
			'callback'            => [ WriteController::class, 'create_person_site' ],
			'permission_callback' => $write,
		] );
		register_rest_route( self::NAMESPACE_V1, '/places/(?P<id>\d+)/sites', [
			'methods'             => 'POST',
			'callback'            => [ WriteController::class, 'create_place_site' ],
			'permission_callback' => $write,
		] );
		register_rest_route( self::NAMESPACE_V1, '/sites/(?P<blog_id>\d+)', [
			'methods'             => 'PATCH',
			'callback'            => [ WriteController::class, 'update_site' ],
			'permission_callback' => $write,
		] );
		register_rest_route( self::NAMESPACE_V1, '/sites/(?P<blog_id>\d+)/rebuild', [
			'methods'             => 'POST',
			'callback'            => [ WriteController::class, 'trigger_site_rebuild' ],
			'permission_callback' => $write,
		] );

		// ---- Ingest (internal, HMAC-only — n8n -> WP) ----
		$ingest = [ IngestController::class, 'permission_ingest' ];
		register_rest_route( self::NAMESPACE_V1, '/ingest/darwin/agents', [
			'methods'             => 'POST',
			'callback'            => [ IngestController::class, 'ingest_agents' ],
			'permission_callback' => $ingest,
		] );
		register_rest_route( self::NAMESPACE_V1, '/sync/darwin/cursor', [
			'methods'             => 'GET',
			'callback'            => [ IngestController::class, 'cursor' ],
			'permission_callback' => $ingest,
			'args'                => [
				'domain' => [ 'type' => 'string', 'required' => false, 'enum' => [ 'agents', 'listings' ] ],
			],
		] );
	}

	/**
	 * READ permission: valid `X-FRS-Api-Key` header OR authenticated WP user.
	 * Filter `frs_papi_read_permission` to apply custom logic (e.g., IP allow).
	 */
	public static function permission_read( $request ) {
		$by_key  = '' !== Config::request_api_key( $request );
		$by_user = current_user_can( 'read' );
		$allow   = $by_key || $by_user;
		return (bool) apply_filters( 'frs_papi_read_permission', $allow, $request );
	}

	/**
	 * WRITE permission: requires admin auth (capability `edit_users`).
	 * API keys NEVER grant write. Filter `frs_papi_write_permission` to tighten/relax.
	 */
	public static function permission_write( $request ) {
		$allow = current_user_can( 'edit_users' );
		return (bool) apply_filters( 'frs_papi_write_permission', $allow, $request );
	}

	/** /me PATCH: the user is updating themselves — any authenticated user. */
	public static function permission_check_authenticated( $request ) {
		return current_user_can( 'read' );
	}
}
