<?php
/**
 * `wp_frsos_raw_darwin` — append-only audit/replay buffer.
 *
 * Every Darwin payload (person, property, propertyreport, ...) lands here
 * exactly as received before any normalization. This is the replay tape: if a
 * normalizer bug slips through or Darwin's shape shifts, we re-derive the
 * canonical tables from here instead of re-pulling from the vendor.
 *
 * `vendor_endpoint` distinguishes record types (e.g. /api/person,
 * /api/property/{id}, /api/propertyreport). Dedupe is enforced at the DB layer
 * by UNIQUE(source_vendor, payload_hash) so n8n can replay batches safely.
 *
 * @package FRSOS\Database\Schema
 */

namespace FRSOS\Database\Schema;

defined( 'ABSPATH' ) || exit;

class RawBufferDarwin implements SchemaInterface {

	const VERSION = '1.0.0';
	const TABLE   = 'frsos_raw_darwin';

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
			vendor_endpoint VARCHAR(128) NOT NULL,
			vendor_record_id VARCHAR(128) NULL,
			payload LONGTEXT NOT NULL,
			payload_hash CHAR(64) NOT NULL,
			received_at DATETIME NOT NULL,
			processed_at DATETIME NULL,
			process_status VARCHAR(16) NOT NULL DEFAULT 'pending',
			process_error TEXT NULL,
			ingest_request_id CHAR(36) NULL,
			PRIMARY KEY (id),
			UNIQUE KEY vendor_payload (source_vendor, payload_hash),
			KEY vendor_record (source_vendor, vendor_record_id),
			KEY process_status (process_status, received_at),
			KEY ingest_request_id (ingest_request_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			error_log( 'FRSOS: failed to create ' . self::TABLE );
		}
	}
}
