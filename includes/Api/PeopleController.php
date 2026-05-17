<?php
/**
 * People endpoints. Read-only projection of WP user_meta + BP xprofile + BP
 * group memberships into a single flat AgentProfile shape via Formatter.
 *
 * @package FRSPapi\Api
 */

namespace FRSPapi\Api;

defined( 'ABSPATH' ) || exit;

class PeopleController {

	public static function me( $request ) {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return new \WP_Error( 'frs_papi_unauth', 'Not authenticated.', [ 'status' => 401 ] );
		}
		return rest_ensure_response( Formatter::profile( $uid, $request ) );
	}

	public static function get( $request ) {
		$uid = (int) $request['id'];
		if ( ! get_userdata( $uid ) ) {
			return new \WP_Error( 'frs_papi_not_found', 'Person not found.', [ 'status' => 404 ] );
		}
		return rest_ensure_response( Formatter::profile( $uid, $request ) );
	}

	public static function get_by_login( $request ) {
		$login = (string) $request['login'];
		$user  = get_user_by( 'login', $login );
		if ( ! $user ) {
			$user = get_user_by( 'slug', $login );
		}
		if ( ! $user ) {
			return new \WP_Error( 'frs_papi_not_found', 'Person not found.', [ 'status' => 404 ] );
		}
		return rest_ensure_response( Formatter::profile( (int) $user->ID, $request ) );
	}

	public static function get_by_nmls( $request ) {
		$nmls = (string) $request['nmls'];
		global $wpdb;
		$uid = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ('nmls','frs_nmls','frs_nmls_number') AND meta_value = %s LIMIT 1",
			$nmls
		) );
		if ( ! $uid ) {
			return new \WP_Error( 'frs_papi_not_found', 'No person with that NMLS.', [ 'status' => 404 ] );
		}
		return rest_ensure_response( Formatter::profile( $uid, $request ) );
	}

	public static function list( $request ) {
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );

		$args = [
			'fields'  => 'ID',
			'number'  => $per_page,
			'paged'   => $page,
			'orderby' => 'display_name',
			'order'   => 'ASC',
		];

		$member_type = (string) $request->get_param( 'member_type' );
		if ( '' !== $member_type && function_exists( 'bp_get_users_of_member_type' ) ) {
			$uids = bp_get_users_of_member_type( $member_type );
			if ( is_array( $uids ) && ! empty( $uids ) ) {
				$args['include'] = $uids;
			} else {
				// Empty result deterministic
				$resp = rest_ensure_response( [] );
				$resp->header( 'X-WP-Total', '0' );
				$resp->header( 'X-WP-TotalPages', '0' );
				return $resp;
			}
		}

		$office = (string) $request->get_param( 'office' );
		if ( '' !== $office ) {
			$args['meta_query'][] = [ 'key' => 'office', 'value' => $office ];
		}
		$region = (string) $request->get_param( 'region' );
		if ( '' !== $region ) {
			$args['meta_query'][] = [ 'key' => 'region', 'value' => $region ];
		}

		$search = trim( (string) $request->get_param( 'search' ) );
		if ( '' !== $search ) {
			$args['search']         = '*' . esc_attr( $search ) . '*';
			$args['search_columns'] = [ 'user_login', 'user_nicename', 'user_email', 'display_name' ];
		}

		$query = new \WP_User_Query( $args );
		$uids  = (array) $query->get_results();
		$total = (int) $query->get_total();

		$out = [];
		foreach ( $uids as $uid ) {
			$out[] = Formatter::profile( (int) $uid, $request );
		}

		$resp = rest_ensure_response( $out );
		$resp->header( 'X-WP-Total',      (string) $total );
		$resp->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / max( 1, $per_page ) ) ) );
		return $resp;
	}
}
