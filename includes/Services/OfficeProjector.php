<?php
/**
 * Resolve a Darwin office to the existing BuddyPress office group and mirror it
 * into `wp_frsos_offices`.
 *
 * Region groups (and their office subgroups) already exist in BP, so we MATCH
 * rather than create: a Darwin office is linked to a BP office group by
 * `darwin_office_id` group meta (authoritative once stamped), falling back to a
 * case-insensitive name match among `office`-typed groups. The matched group's
 * `parent_id` IS the region — no separate office->region map is needed. When a
 * group is matched by name we stamp the crosswalk meta so future syncs are O(1).
 *
 * @package FRSOS\Services
 */

namespace FRSOS\Services;

use FRSOS\Database\Schema\Offices;

defined( 'ABSPATH' ) || exit;

class OfficeProjector {

	const GROUP_META_DARWIN_ID   = 'darwin_office_id';
	const GROUP_META_DARWIN_GUID = 'darwin_office_guid';
	const OFFICE_GROUP_TYPE       = 'office';

	/** @var array<string,array> per-request resolution cache keyed by darwin office id */
	private static $cache = [];

	/**
	 * @param array $office { vendor_record_id, darwin_office_guid?, office_name?, company_id?, company_name? }
	 * @param int   $raw_id  raw buffer row id (optional provenance)
	 * @return array{office_id:int,group_id:?int,region_group_id:?int,match_status:string}
	 */
	public static function resolve( array $office, int $raw_id = 0 ): array {
		$darwin_id = (string) ( $office['vendor_record_id'] ?? $office['office_darwin_id'] ?? '' );
		if ( '' === $darwin_id ) {
			return [ 'office_id' => 0, 'group_id' => null, 'region_group_id' => null, 'match_status' => 'unmatched' ];
		}
		if ( isset( self::$cache[ $darwin_id ] ) ) {
			return self::$cache[ $darwin_id ];
		}

		$guid = self::str( $office['darwin_office_guid'] ?? null );
		$name = self::str( $office['office_name'] ?? null );

		$group_id = self::match_group( $darwin_id, $guid, $name );
		$region_group_id = $group_id ? self::parent_id( $group_id ) : null;
		$match_status    = $group_id ? 'matched' : 'unmatched';

		$office_id = self::upsert_mirror( [
			'source_vendor'      => 'darwin',
			'vendor_record_id'   => $darwin_id,
			'darwin_office_guid' => $guid,
			'office_name'        => $name,
			'company_id'         => self::str( $office['company_id'] ?? null ),
			'company_name'       => self::str( $office['company_name'] ?? null ),
			'group_id'           => $group_id,
			'region_group_id'    => $region_group_id,
			'match_status'       => $match_status,
			'last_raw_id'        => $raw_id ?: null,
		] );

		$result = [
			'office_id'       => $office_id,
			'group_id'        => $group_id,
			'region_group_id' => $region_group_id,
			'match_status'    => $match_status,
		];
		self::$cache[ $darwin_id ] = $result;
		return $result;
	}

	// ---------------------------------------------------------------------

	/** Find the BP office group for a Darwin office; stamp crosswalk meta on name match. */
	private static function match_group( string $darwin_id, ?string $guid, ?string $name ): ?int {
		if ( ! function_exists( 'groups_get_groups' ) ) {
			return null;
		}

		// 1) Authoritative: group already carries darwin_office_id meta.
		$by_meta = self::group_by_meta( self::GROUP_META_DARWIN_ID, $darwin_id );
		if ( $by_meta ) {
			return $by_meta;
		}
		if ( $guid ) {
			$by_guid = self::group_by_meta( self::GROUP_META_DARWIN_GUID, $guid );
			if ( $by_guid ) {
				return $by_guid;
			}
		}

		// 2) Fallback: case-insensitive name match among office-typed groups.
		if ( $name ) {
			$gid = self::group_by_name( $name );
			if ( $gid ) {
				// Stamp the crosswalk so subsequent syncs hit branch (1).
				groups_update_groupmeta( $gid, self::GROUP_META_DARWIN_ID, $darwin_id );
				if ( $guid ) {
					groups_update_groupmeta( $gid, self::GROUP_META_DARWIN_GUID, $guid );
				}
				return $gid;
			}
		}

		return null;
	}

	private static function group_by_meta( string $key, string $value ): ?int {
		global $wpdb;
		$table = function_exists( 'buddypress' ) ? buddypress()->groups->table_name_groupmeta : ( $wpdb->base_prefix . 'bp_groups_groupmeta' );
		$gid   = $wpdb->get_var( $wpdb->prepare(
			"SELECT group_id FROM {$table} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			$key,
			$value
		) );
		return $gid ? (int) $gid : null;
	}

	/** Case-insensitive name match restricted to office-typed groups. */
	private static function group_by_name( string $name ): ?int {
		$res = groups_get_groups( [
			'search_terms' => $name,
			'group_type'   => self::OFFICE_GROUP_TYPE,
			'show_hidden'  => true,
			'per_page'     => 20,
		] );
		$groups = $res['groups'] ?? [];
		$needle = strtolower( trim( $name ) );
		foreach ( $groups as $g ) {
			if ( strtolower( trim( (string) $g->name ) ) === $needle ) {
				return (int) $g->id;
			}
		}
		return null;
	}

	private static function parent_id( int $group_id ): ?int {
		if ( ! function_exists( 'groups_get_group' ) ) {
			return null;
		}
		$g = groups_get_group( $group_id );
		$pid = $g && ! empty( $g->parent_id ) ? (int) $g->parent_id : 0;
		return $pid > 0 ? $pid : null;
	}

	private static function upsert_mirror( array $row ): int {
		global $wpdb;
		$table = Offices::table_name();
		$row['last_synced_at'] = gmdate( 'Y-m-d H:i:s' );
		$row['sync_status']    = 'fresh';

		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE source_vendor = %s AND vendor_record_id = %s LIMIT 1",
			$row['source_vendor'],
			$row['vendor_record_id']
		) );
		if ( $existing > 0 ) {
			$wpdb->update( $table, $row, [ 'id' => $existing ] );
			return $existing;
		}
		$wpdb->insert( $table, $row );
		return (int) $wpdb->insert_id;
	}

	private static function str( $v ): ?string {
		if ( null === $v ) {
			return null;
		}
		$v = trim( (string) $v );
		return '' === $v ? null : $v;
	}
}
