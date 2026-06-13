<?php
/**
 * Upsert + read access for the `wp_frsos_listings` canonical mirror.
 *
 * On upsert it resolves the two BuddyPress crosswalks so listings are linked to
 * the org structure:
 *   - list_agent_darwin_id -> wp_frsos_agents -> list_agent_user_id (member)
 *   - office_darwin_id      -> OfficeProjector -> office_group_id (office group)
 * Both are re-resolved every upsert so late-created agents/offices backfill.
 *
 * @package FRSOS\Database
 */

namespace FRSOS\Database;

use FRSOS\Database\Schema\Listings;
use FRSOS\Services\OfficeProjector;

defined( 'ABSPATH' ) || exit;

class ListingsRepository {

	/** Columns that are real table columns (drops normalizer helper keys like _office_name). */
	const COLUMNS = [
		'source_vendor', 'vendor_record_id', 'darwin_atguid',
		'status', 'status_code', 'tr_type', 'on_market',
		'mls_number', 'address', 'street_number', 'street_name', 'unit',
		'city', 'state', 'zip', 'neighborhood', 'subdivision',
		'latitude', 'longitude', 'geocode_status',
		'list_price_cents', 'selling_price_cents',
		'bedrooms', 'full_baths', 'half_baths', 'square_feet', 'lot_size',
		'acreage', 'year_built', 'parking_spaces', 'property_type', 'property_type_id', 'is_condo',
		'description', 'show_on_internet', 'show_address', 'show_price',
		'list_date', 'pending_date', 'close_date', 'vendor_updated_at',
		'list_agent_darwin_id', 'list_agent_user_id', 'office_darwin_id', 'office_group_id', 'company_id',
		'photos_json', 'photo_count',
		'last_synced_at', 'last_raw_id', 'sync_status',
	];

	/**
	 * @param array $row  DarwinListingNormalizer::normalize() output.
	 * @param int   $raw_id
	 * @return array{listing_id:int,created:bool}
	 */
	public static function upsert( array $row, int $raw_id ): array {
		global $wpdb;
		$table = Listings::table_name();

		// Resolve crosswalks.
		$row['list_agent_user_id'] = ! empty( $row['list_agent_darwin_id'] )
			? AgentsRepository::user_id_for_darwin_id( (string) $row['list_agent_darwin_id'] )
			: null;

		if ( ! empty( $row['office_darwin_id'] ) ) {
			$office = OfficeProjector::resolve( [
				'vendor_record_id' => $row['office_darwin_id'],
				'office_name'      => $row['_office_name'] ?? null,
				'company_id'       => $row['company_id'] ?? null,
			], $raw_id );
			$row['office_group_id'] = $office['group_id'];
		}

		// Provenance + preserve geocode across re-syncs (don't clobber lat/lng).
		$row['last_raw_id']    = $raw_id;
		$row['last_synced_at'] = gmdate( 'Y-m-d H:i:s' );
		$row['sync_status']    = 'fresh';

		$row = self::only_columns( $row );

		$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE source_vendor = %s AND vendor_record_id = %s LIMIT 1",
			$row['source_vendor'],
			$row['vendor_record_id']
		) );

		if ( $existing_id > 0 ) {
			$wpdb->update( $table, $row, [ 'id' => $existing_id ] );
			return [ 'listing_id' => $existing_id, 'created' => false ];
		}

		$row['geocode_status'] = $row['geocode_status'] ?? 'pending';
		$wpdb->insert( $table, $row );
		return [ 'listing_id' => (int) $wpdb->insert_id, 'created' => true ];
	}

	/** Drop any non-column helper keys (e.g. _office_name) before write. */
	private static function only_columns( array $row ): array {
		return array_intersect_key( $row, array_flip( self::COLUMNS ) );
	}
}
