<?php
/**
 * `wp_frsos_agents` — canonical Darwin agent mirror.
 *
 * One row per Darwin Person (agent), keyed by Darwin `personId`
 * (vendor_record_id). Carries provenance and the crosswalk to a WordPress user.
 *
 * Identity policy (2026-06-12): Darwin is the source of truth for users today.
 * Each agent is resolved to a wp_user — matched by email/agentNumber, else
 * created (when email is present) by AgentProvisioner. `user_id` is written
 * back here; `provision_status` records how the link was established.
 * Eventually provisioning targets Entra; this table is unchanged by that swap.
 *
 * @package FRSOS\Database\Schema
 */

namespace FRSOS\Database\Schema;

defined( 'ABSPATH' ) || exit;

class Agents implements SchemaInterface {

	const VERSION = '1.0.0';
	const TABLE   = 'frsos_agents';

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
			agent_number VARCHAR(64) NULL,
			user_id BIGINT UNSIGNED NULL,
			first_name VARCHAR(255) NULL,
			last_name VARCHAR(255) NULL,
			full_name VARCHAR(255) NULL,
			email VARCHAR(255) NULL,
			phone VARCHAR(64) NULL,
			person_type VARCHAR(64) NULL,
			office_darwin_id VARCHAR(64) NULL,
			office_name VARCHAR(255) NULL,
			company_id VARCHAR(64) NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			start_date DATE NULL,
			terminated_date DATE NULL,
			provision_status VARCHAR(16) NOT NULL DEFAULT 'pending',
			vendor_updated_at DATETIME NULL,
			last_synced_at DATETIME NOT NULL,
			last_raw_id BIGINT UNSIGNED NULL,
			sync_status VARCHAR(16) NOT NULL DEFAULT 'fresh',
			PRIMARY KEY (id),
			UNIQUE KEY vendor_record (source_vendor, vendor_record_id),
			KEY user_id (user_id),
			KEY email (email),
			KEY darwin_atguid (darwin_atguid),
			KEY agent_number (agent_number),
			KEY provision_status (provision_status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			error_log( 'FRSOS: failed to create ' . self::TABLE );
		}
	}
}
