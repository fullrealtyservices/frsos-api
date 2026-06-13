<?php
/**
 * Listings read endpoints — canonical Darwin-sourced listings projected for the
 * embeddable directory and BP surfaces.
 *
 *   GET /listings                    search/filter (status, city, agent, office, price, beds, bbox, q)
 *   GET /listings/{id}               one listing (+ agent + office links)
 *   GET /people/{id}/listings        listings whose list_agent_user_id = {id}  (BP member link)
 *   GET /places/{id}/listings        listings whose office_group_id   = {id}   (BP office group link)
 *
 * Reads are key/admin auth (Bootstrap::permission_read). Money is returned as
 * both integer cents and a formatted display string.
 *
 * @package FRSOS\Api
 */

namespace FRSOS\Api;

use FRSOS\Config;
use FRSOS\Database\Schema\Listings;

defined( 'ABSPATH' ) || exit;

class ListingsController {

	/** GET /listings */
	public static function list( $request ) {
		global $wpdb;
		$table = Listings::table_name();

		$where  = [ 'source_vendor = %s' ];
		$params = [ 'darwin' ];

		$eq = function ( string $col, $val, string $fmt = '%s' ) use ( &$where, &$params ) {
			$where[]  = "{$col} = {$fmt}";
			$params[] = $val;
		};

		$p = $request->get_params();

		// Default to publishable active inventory unless a status is requested.
		if ( isset( $p['status_code'] ) && '' !== $p['status_code'] ) {
			$eq( 'status_code', strtoupper( (string) $p['status_code'] ) );
		} elseif ( ! isset( $p['all'] ) || ! $p['all'] ) {
			$where[] = 'on_market = 1';
		}

		if ( ! empty( $p['city'] ) )    { $eq( 'LOWER(city)', strtolower( (string) $p['city'] ) ); }
		if ( ! empty( $p['state'] ) )   { $eq( 'UPPER(state)', strtoupper( (string) $p['state'] ) ); }
		if ( ! empty( $p['zip'] ) )     { $eq( 'zip', (string) $p['zip'] ); }
		if ( ! empty( $p['agent'] ) )   { $eq( 'list_agent_user_id', (int) $p['agent'], '%d' ); }
		if ( ! empty( $p['office'] ) )  { $eq( 'office_group_id', (int) $p['office'], '%d' ); }
		if ( ! empty( $p['property_type'] ) ) { $eq( 'LOWER(property_type)', strtolower( (string) $p['property_type'] ) ); }

		$between = function ( string $col, $lo, $hi, string $fmt = '%d' ) use ( &$where, &$params ) {
			if ( null !== $lo && '' !== $lo ) { $where[] = "{$col} >= {$fmt}"; $params[] = $lo; }
			if ( null !== $hi && '' !== $hi ) { $where[] = "{$col} <= {$fmt}"; $params[] = $hi; }
		};
		// Price filters are in dollars on the wire; stored as cents.
		$between( 'list_price_cents', isset( $p['min_price'] ) ? (int) $p['min_price'] * 100 : null, isset( $p['max_price'] ) ? (int) $p['max_price'] * 100 : null );
		$between( 'bedrooms', $p['min_beds'] ?? null, $p['max_beds'] ?? null );
		$between( 'full_baths', $p['min_baths'] ?? null, null );
		$between( 'square_feet', $p['min_sqft'] ?? null, $p['max_sqft'] ?? null );
		$between( 'latitude', $p['south'] ?? null, $p['north'] ?? null, '%f' );
		$between( 'longitude', $p['west'] ?? null, $p['east'] ?? null, '%f' );

		if ( ! empty( $p['q'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $p['q'] ) . '%';
			$where[]  = '(address LIKE %s OR city LIKE %s OR zip LIKE %s OR neighborhood LIKE %s)';
			array_push( $params, $like, $like, $like, $like );
		}

		$sort_map = [
			'price_desc'  => 'list_price_cents DESC',
			'price_asc'   => 'list_price_cents ASC',
			'newest'      => 'list_date DESC',
			'oldest'      => 'list_date ASC',
			'beds_desc'   => 'bedrooms DESC',
			'sqft_desc'   => 'square_feet DESC',
		];
		$order = $sort_map[ (string) ( $p['sort'] ?? 'newest' ) ] ?? $sort_map['newest'];

		$per_page = min( max( 1, (int) ( $p['per_page'] ?? Config::default_per_page() ) ), Config::max_per_page() );
		$page     = max( 1, (int) ( $p['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		// total
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d",
			array_merge( $params, [ $per_page, $offset ] )
		), ARRAY_A );

		$items = array_map( [ __CLASS__, 'shape' ], $rows ?: [] );

		$resp = rest_ensure_response( [
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'items'    => $items,
		] );
		$resp->header( 'X-WP-Total', (string) $total );
		$resp->header( 'X-WP-TotalPages', (string) ( $per_page ? (int) ceil( $total / $per_page ) : 1 ) );
		return $resp;
	}

	/** GET /listings/{id} */
	public static function get( $request ) {
		global $wpdb;
		$table = Listings::table_name();
		$id    = (int) $request['id'];
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'frs_papi_not_found', 'Listing not found.', [ 'status' => 404 ] );
		}
		return rest_ensure_response( self::shape( $row, true ) );
	}

	/** GET /people/{id}/listings — listings for an agent (BP member link). */
	public static function for_person( $request ) {
		return self::scoped( $request, 'list_agent_user_id', (int) $request['id'] );
	}

	/** GET /places/{id}/listings — listings for an office group (BP office link). */
	public static function for_place( $request ) {
		return self::scoped( $request, 'office_group_id', (int) $request['id'] );
	}

	// ---------------------------------------------------------------------

	private static function scoped( $request, string $col, int $val ) {
		global $wpdb;
		$table    = Listings::table_name();
		$per_page = min( max( 1, (int) $request->get_param( 'per_page' ) ?: Config::default_per_page() ), Config::max_per_page() );
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$offset   = ( $page - 1 ) * $per_page;

		$only_active = ! $request->get_param( 'all' );
		$active_sql  = $only_active ? ' AND on_market = 1' : '';

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE source_vendor = 'darwin' AND {$col} = %d{$active_sql}",
			$val
		) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE source_vendor = 'darwin' AND {$col} = %d{$active_sql} ORDER BY list_date DESC LIMIT %d OFFSET %d",
			$val,
			$per_page,
			$offset
		), ARRAY_A );

		$resp = rest_ensure_response( [
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'items'    => array_map( [ __CLASS__, 'shape' ], $rows ?: [] ),
		] );
		$resp->header( 'X-WP-Total', (string) $total );
		return $resp;
	}

	/** Shape a DB row into the API resource. */
	private static function shape( array $r, bool $full = false ): array {
		$price = isset( $r['list_price_cents'] ) ? (int) $r['list_price_cents'] : null;
		$out   = [
			'id'            => (int) $r['id'],
			'mls_number'    => $r['mls_number'],
			'status'        => $r['status'],
			'status_code'   => $r['status_code'],
			'on_market'     => (bool) $r['on_market'],
			'address'       => $r['show_address'] ? $r['address'] : self::mask_address( $r ),
			'city'          => $r['city'],
			'state'         => $r['state'],
			'zip'           => $r['zip'],
			'neighborhood'  => $r['neighborhood'],
			'latitude'      => isset( $r['latitude'] ) ? (float) $r['latitude'] : null,
			'longitude'     => isset( $r['longitude'] ) ? (float) $r['longitude'] : null,
			'price'         => $r['show_price'] && null !== $price ? intdiv( $price, 100 ) : null,
			'display_price' => $r['show_price'] && null !== $price ? '$' . number_format( $price / 100 ) : null,
			'bedrooms'      => self::int( $r['bedrooms'] ),
			'full_baths'    => self::int( $r['full_baths'] ),
			'half_baths'    => self::int( $r['half_baths'] ),
			'square_feet'   => self::int( $r['square_feet'] ),
			'year_built'    => self::int( $r['year_built'] ),
			'property_type' => $r['property_type'],
			'list_date'     => $r['list_date'],
			'photo_count'   => (int) $r['photo_count'],
			'cover_photo'   => self::first_photo( $r['photos_json'] ),
			'agent'         => self::agent_link( $r ),
			'office'        => self::office_link( $r ),
		];

		if ( $full ) {
			$out['description'] = $r['description'];
			$out['photos']      = self::photos( $r['photos_json'] );
			$out['lot_size']    = isset( $r['lot_size'] ) ? (float) $r['lot_size'] : null;
			$out['acreage']     = isset( $r['acreage'] ) ? (float) $r['acreage'] : null;
			$out['subdivision'] = $r['subdivision'];
		}
		return $out;
	}

	private static function agent_link( array $r ): ?array {
		$uid = (int) ( $r['list_agent_user_id'] ?? 0 );
		if ( ! $uid ) {
			return null;
		}
		$u = get_userdata( $uid );
		return [
			'user_id'      => $uid,
			'display_name' => $u ? (string) $u->display_name : null,
			'href'         => rest_url( Bootstrap::NAMESPACE_V1 . '/people/' . $uid ),
		];
	}

	private static function office_link( array $r ): ?array {
		$gid = (int) ( $r['office_group_id'] ?? 0 );
		if ( ! $gid ) {
			return null;
		}
		$g = function_exists( 'groups_get_group' ) ? groups_get_group( $gid ) : null;
		return [
			'group_id' => $gid,
			'name'     => $g && ! empty( $g->id ) ? (string) $g->name : null,
			'href'     => rest_url( Bootstrap::NAMESPACE_V1 . '/places/' . $gid ),
		];
	}

	private static function first_photo( $json ): ?string {
		$arr = self::photos( $json );
		return $arr[0] ?? null;
	}

	private static function photos( $json ): array {
		if ( ! is_string( $json ) || '' === $json ) {
			return [];
		}
		$d = json_decode( $json, true );
		return is_array( $d ) ? $d : [];
	}

	private static function mask_address( array $r ): ?string {
		// Address hidden from web: show city/state only.
		$bits = array_filter( [ $r['city'], $r['state'] ] );
		return $bits ? implode( ', ', $bits ) : null;
	}

	private static function int( $v ): ?int {
		return ( null === $v || '' === $v ) ? null : (int) $v;
	}
}
