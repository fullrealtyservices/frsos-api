<?php
/**
 * Writes raw Darwin payloads to the append-only audit/replay buffer
 * (`wp_frsos_raw_darwin`), deduped by SHA-256(payload). Returns the raw row id
 * and whether the payload was a replay (already stored).
 *
 * @package FRSOS\Ingest
 */

namespace FRSOS\Ingest;

use FRSOS\Database\Schema\RawBufferDarwin;

defined( 'ABSPATH' ) || exit;

class RawBuffer {

	/**
	 * Store one payload. Idempotent: an identical payload (same hash) returns
	 * the existing row id with deduped=true and writes nothing.
	 *
	 * @return array{raw_id:int,deduped:bool}
	 */
	public static function store(
		string $endpoint,
		?string $record_id,
		array $payload,
		?string $request_id = null
	): array {
		global $wpdb;
		$table = RawBufferDarwin::table_name();
		$json  = wp_json_encode( $payload );
		$hash  = hash( 'sha256', (string) $json );

		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE source_vendor = %s AND payload_hash = %s LIMIT 1",
			'darwin',
			$hash
		) );
		if ( $existing > 0 ) {
			return [ 'raw_id' => $existing, 'deduped' => true ];
		}

		$wpdb->insert(
			$table,
			[
				'source_vendor'     => 'darwin',
				'vendor_endpoint'   => $endpoint,
				'vendor_record_id'  => $record_id,
				'payload'           => (string) $json,
				'payload_hash'      => $hash,
				'received_at'       => gmdate( 'Y-m-d H:i:s' ),
				'process_status'    => 'pending',
				'ingest_request_id' => $request_id,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return [ 'raw_id' => (int) $wpdb->insert_id, 'deduped' => false ];
	}

	/** Mark a raw row processed (or failed). */
	public static function mark( int $raw_id, string $status, ?string $error = null ): void {
		global $wpdb;
		$wpdb->update(
			RawBufferDarwin::table_name(),
			[
				'process_status' => $status,
				'process_error'  => $error,
				'processed_at'   => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $raw_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}
}
