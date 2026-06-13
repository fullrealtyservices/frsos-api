<?php
/**
 * `wp_frsos_listings` — canonical listings mirror, sourced from Darwin.
 *
 * One row per Darwin property (vendor_record_id = Darwin `propertyId`). Built
 * from three Darwin calls — Property search (enumerate) + Property Details
 * (specs) + Property Report (photos via propertyMediaInfo, agents). Carries
 * provenance; the iron rule is vendor-wins, so each sync upserts by
 * (source_vendor, vendor_record_id) and never edits fields locally.
 *
 * Money is stored as BIGINT cents (never floats). Darwin returns decimal
 * dollars; the normalizer multiplies by 100. Darwin has NO lat/lng, so
 * latitude/longitude are populated by a geocoding step (geocode_status tracks
 * it). The agent crosswalk resolves list_agent_darwin_id -> wp_frsos_agents ->
 * list_agent_user_id, re-resolved on each upsert so late-created users backfill.
 *
 * @package FRSOS\Database\Schema
 */

namespace FRSOS\Database\Schema;

defined( 'ABSPATH' ) || exit;

class Listings implements SchemaInterface {

	const VERSION = '1.0.0';
	const TABLE   = 'frsos_listings';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->base_prefix . self::TABLE;
	}

	public static function version(): string {
		return self::VERSION;
	}

	public static function up(): void {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_vendor VARCHAR(32) NOT NULL DEFAULT 'darwin',
			vendor_record_id VARCHAR(128) NOT NULL,
			darwin_atguid VARCHAR(64) NULL,

			status VARCHAR(64) NULL,
			status_code VARCHAR(8) NULL,
			tr_type VARCHAR(16) NULL,
			on_market TINYINT(1) NOT NULL DEFAULT 0,

			mls_number VARCHAR(64) NULL,
			address VARCHAR(255) NULL,
			street_number VARCHAR(32) NULL,
			street_name VARCHAR(128) NULL,
			unit VARCHAR(32) NULL,
			city VARCHAR(128) NULL,
			state VARCHAR(8) NULL,
			zip VARCHAR(16) NULL,
			neighborhood VARCHAR(255) NULL,
			subdivision VARCHAR(255) NULL,

			latitude DECIMAL(10,7) NULL,
			longitude DECIMAL(10,7) NULL,
			geocode_status VARCHAR(16) NOT NULL DEFAULT 'pending',

			list_price_cents BIGINT NULL,
			selling_price_cents BIGINT NULL,

			bedrooms SMALLINT NULL,
			full_baths SMALLINT NULL,
			half_baths SMALLINT NULL,
			square_feet INT NULL,
			lot_size DECIMAL(12,2) NULL,
			acreage DECIMAL(12,4) NULL,
			year_built SMALLINT NULL,
			parking_spaces SMALLINT NULL,
			property_type VARCHAR(64) NULL,
			property_type_id VARCHAR(32) NULL,
			is_condo TINYINT(1) NOT NULL DEFAULT 0,

			description LONGTEXT NULL,
			show_on_internet TINYINT(1) NOT NULL DEFAULT 0,
			show_address TINYINT(1) NOT NULL DEFAULT 0,
			show_price TINYINT(1) NOT NULL DEFAULT 0,

			list_date DATE NULL,
			pending_date DATE NULL,
			close_date DATE NULL,
			vendor_updated_at DATETIME NULL,

			list_agent_darwin_id VARCHAR(64) NULL,
			list_agent_user_id BIGINT UNSIGNED NULL,
			office_darwin_id VARCHAR(64) NULL,
			office_group_id BIGINT UNSIGNED NULL,
			company_id VARCHAR(64) NULL,

			photos_json LONGTEXT NULL,
			photo_count INT NOT NULL DEFAULT 0,

			last_synced_at DATETIME NOT NULL,
			last_raw_id BIGINT UNSIGNED NULL,
			sync_status VARCHAR(16) NOT NULL DEFAULT 'fresh',

			PRIMARY KEY (id),
			UNIQUE KEY vendor_record (source_vendor, vendor_record_id),
			KEY status_code (status_code),
			KEY on_market (on_market),
			KEY city_state (city, state),
			KEY list_agent_user_id (list_agent_user_id),
			KEY office_darwin_id (office_darwin_id),
			KEY office_group_id (office_group_id),
			KEY list_price (list_price_cents),
			KEY vendor_updated_at (vendor_updated_at),
			KEY geocode_status (geocode_status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			error_log( 'FRSOS: failed to create ' . self::TABLE );
		}
	}
}
