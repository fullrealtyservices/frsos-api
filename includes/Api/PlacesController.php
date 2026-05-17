<?php
/**
 * Places endpoints — BP groups projected as offices / regions / departments.
 *
 * @package FRSPapi\Api
 */

namespace FRSPapi\Api;

defined( 'ABSPATH' ) || exit;

class PlacesController {

	public static function list( $request ) {
		if ( ! function_exists( 'groups_get_groups' ) ) {
			return new \WP_Error( 'frs_papi_bp_missing', 'BuddyPress is not loaded.', [ 'status' => 503 ] );
		}
		$args = [
			'per_page'    => 200,
			'show_hidden' => true,
			'orderby'     => 'name',
			'order'       => 'ASC',
		];
		$type = (string) $request->get_param( 'type' );
		if ( '' !== $type ) {
			$args['group_type'] = $type;
		}
		$parent = (int) $request->get_param( 'parent' );
		if ( $parent > 0 ) {
			$args['parent_id'] = $parent;
		}
		$result = groups_get_groups( $args );
		$groups = isset( $result['groups'] ) ? $result['groups'] : [];

		$out = [];
		foreach ( $groups as $g ) {
			$out[] = Formatter::place( $g );
		}
		return rest_ensure_response( $out );
	}

	public static function get( $request ) {
		if ( ! function_exists( 'groups_get_group' ) ) {
			return new \WP_Error( 'frs_papi_bp_missing', 'BuddyPress is not loaded.', [ 'status' => 503 ] );
		}
		$id = (int) $request['id'];
		$g  = groups_get_group( $id );
		if ( ! $g || empty( $g->id ) ) {
			return new \WP_Error( 'frs_papi_not_found', 'Place not found.', [ 'status' => 404 ] );
		}
		return rest_ensure_response( Formatter::place( $g ) );
	}

	public static function list_people( $request ) {
		if ( ! function_exists( 'groups_get_group_members' ) ) {
			return new \WP_Error( 'frs_papi_bp_missing', 'BuddyPress is not loaded.', [ 'status' => 503 ] );
		}
		$id       = (int) $request['id'];
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );

		$res = groups_get_group_members( [
			'group_id'            => $id,
			'per_page'            => $per_page,
			'page'                => $page,
			'exclude_admins_mods' => false,
		] );
		$out = [];
		if ( ! empty( $res['members'] ) ) {
			foreach ( $res['members'] as $m ) {
				$out[] = Formatter::profile( (int) $m->ID, $request );
			}
		}
		$resp = rest_ensure_response( $out );
		$resp->header( 'X-WP-Total', (string) ( $res['count'] ?? count( $out ) ) );
		return $resp;
	}
}
