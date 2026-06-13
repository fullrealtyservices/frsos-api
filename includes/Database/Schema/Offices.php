<?php
/**
 * `wp_frsos_offices` — canonical Darwin office mirror + BP-group crosswalk.
 *
 * Darwin's org hierarchy is Company -> Office (there is no "region" in Darwin;
 * region is an FRS overlay that already exists as BP region groups). Each row
 * mirrors a Darwin office and records the BP office group it maps to plus that
 * group's parent region group — so agents and listings can be linked into the
 * existing BP structure without inventing a region map.
 *
 * @package FRSOS\Database\Schema
 */

namespace FRSOS\Database\Schema;

defined( 'ABSPATH' ) || exit;

class Offices implements SchemaInterface {

	const VERSION = '1.0.0';
	const TABLE   = 'frsos_offices';

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
			darwin_office_guid VARCHAR(64) NULL,
			office_name VARCHAR(255) NULL,
			company_id VARCHAR(64) NULL,
			company_name VARCHAR(255) NULL,
			group_id BIGINT UNSIGNED NULL,
			region_group_id BIGINT UNSIGNED NULL,
			match_status VARCHAR(16) NOT NULL DEFAULT 'unmatched',
			last_synced_at DATETIME NOT NULL,
			last_raw_id BIGINT UNSIGNED NULL,
			sync_status VARCHAR(16) NOT NULL DEFAULT 'fresh',
			PRIMARY KEY (id),
			UNIQUE KEY vendor_record (source_vendor, vendor_record_id),
			KEY group_id (group_id),
			KEY region_group_id (region_group_id),
			KEY match_status (match_status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			error_log( 'FRSOS: failed to create ' . self::TABLE );
		}
	}
}
