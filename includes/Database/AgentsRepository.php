<?php
/**
 * Upsert + read access for the `wp_frsos_agents` canonical mirror.
 *
 * Upsert key is (source_vendor, vendor_record_id) — vendor wins, so a sync
 * replaces the vendor-derived columns and stamps provenance. The user crosswalk
 * (user_id, provision_status) is written separately by the ingest flow after
 * AgentProvisioner resolves the WP user.
 *
 * @package FRSOS\Database
 */

namespace FRSOS\Database;

use FRSOS\Database\Schema\Agents;

defined( 'ABSPATH' ) || exit;

class AgentsRepository {

	/**
	 * Insert or update one canonical agent row (vendor-derived columns).
	 *
	 * @param array $row  Output of DarwinAgentNormalizer::normalize().
	 * @param int   $raw_id  Raw buffer row that produced this.
	 * @return array{agent_id:int,created:bool}
	 */
	public static function upsert( array $row, int $raw_id ): array {
		global $wpdb;
		$table = Agents::table_name();

		$row['last_raw_id']    = $raw_id;
		$row['last_synced_at'] = gmdate( 'Y-m-d H:i:s' );
		$row['sync_status']    = 'fresh';

		$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE source_vendor = %s AND vendor_record_id = %s LIMIT 1",
			$row['source_vendor'],
			$row['vendor_record_id']
		) );

		if ( $existing_id > 0 ) {
			$wpdb->update( $table, $row, [ 'id' => $existing_id ] );
			return [ 'agent_id' => $existing_id, 'created' => false ];
		}

		$wpdb->insert( $table, $row );
		return [ 'agent_id' => (int) $wpdb->insert_id, 'created' => true ];
	}

	/** Write the resolved WP user crosswalk back onto the agent row. */
	public static function set_user( int $agent_id, ?int $user_id, string $provision_status ): void {
		global $wpdb;
		$wpdb->update(
			Agents::table_name(),
			[ 'user_id' => $user_id, 'provision_status' => $provision_status ],
			[ 'id' => $agent_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);
	}

	/** Resolve a Darwin personId to a WP user_id via the mirror (or null). */
	public static function user_id_for_darwin_id( string $darwin_person_id ): ?int {
		global $wpdb;
		$uid = $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM " . Agents::table_name() . " WHERE source_vendor = %s AND vendor_record_id = %s LIMIT 1",
			'darwin',
			$darwin_person_id
		) );
		return $uid ? (int) $uid : null;
	}
}
