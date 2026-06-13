<?php
/**
 * Internal ingest endpoints (n8n -> WP). HMAC-authenticated; never exposed to
 * read clients. n8n pulls Darwin deltas and POSTs batches here; this controller
 * writes the raw buffer, normalizes, upserts canonical rows, and (for agents)
 * provisions WP users.
 *
 *   POST /wp-json/frs/v1/ingest/darwin/agents     { request_id, batch:[person,...] }
 *   POST /wp-json/frs/v1/ingest/darwin/listings   { request_id, batch:[property,...] }   (Phase 2b)
 *   GET  /wp-json/frs/v1/sync/darwin/cursor?domain=agents|listings
 *
 * Auth: X-FRS-Webhook-Timestamp (unix) + X-FRS-Webhook-Signature
 *       = hash_hmac('sha256', "{timestamp}.{rawBody}", FRSOS_DARWIN_INGEST_SECRET).
 * Timestamp drift > 5 min is rejected (replay mitigation); the raw buffer's
 * UNIQUE(payload_hash) is defense in depth.
 *
 * @package FRSOS\Api
 */

namespace FRSOS\Api;

use FRSOS\Config;
use FRSOS\Ingest\RawBuffer;
use FRSOS\Ingest\DarwinAgentNormalizer;
use FRSOS\Ingest\DarwinListingNormalizer;
use FRSOS\Database\AgentsRepository;
use FRSOS\Database\ListingsRepository;
use FRSOS\Services\AgentProvisioner;
use FRSOS\Services\OfficeProjector;
use FRSOS\Services\BpPlacement;

defined( 'ABSPATH' ) || exit;

class IngestController {

	const HEADER_TIMESTAMP = 'X-FRS-Webhook-Timestamp';
	const HEADER_SIGNATURE = 'X-FRS-Webhook-Signature';
	const MAX_DRIFT_SEC    = 300;
	const MAX_BATCH        = 500;

	/** HMAC permission check for all /ingest/* routes. */
	public static function permission_ingest( $request ): bool {
		$secret = Config::darwin_ingest_secret();
		if ( '' === $secret ) {
			return false; // ingest disabled until a secret is configured
		}
		$ts  = (string) $request->get_header( self::HEADER_TIMESTAMP );
		$sig = (string) $request->get_header( self::HEADER_SIGNATURE );
		if ( '' === $ts || '' === $sig ) {
			return false;
		}
		if ( abs( time() - (int) $ts ) > self::MAX_DRIFT_SEC ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $ts . '.' . $request->get_body(), $secret );
		return hash_equals( $expected, $sig );
	}

	/** POST /ingest/darwin/agents */
	public static function ingest_agents( $request ) {
		$batch      = self::batch( $request );
		$request_id = self::str( $request->get_param( 'request_id' ) );
		if ( null === $batch ) {
			return new \WP_Error( 'frs_papi_bad_batch', 'Body must be { request_id, batch:[...] } (max ' . self::MAX_BATCH . ').', [ 'status' => 422 ] );
		}

		$counts = [ 'received' => 0, 'deduped' => 0, 'created' => 0, 'matched' => 0, 'needs_email' => 0, 'errors' => 0 ];
		$hwm    = null;

		foreach ( $batch as $payload ) {
			$counts['received']++;
			if ( ! is_array( $payload ) ) {
				$counts['errors']++;
				continue;
			}

			$normalized = DarwinAgentNormalizer::normalize( $payload );
			if ( null === $normalized ) {
				$counts['errors']++;
				continue;
			}

			$raw = RawBuffer::store( '/api/person', $normalized['vendor_record_id'], $payload, $request_id );
			if ( $raw['deduped'] ) {
				$counts['deduped']++;
				continue;
			}

			try {
				$agent = AgentsRepository::upsert( $normalized, $raw['raw_id'] );
				$prov  = AgentProvisioner::provision( $normalized );
				AgentsRepository::set_user( $agent['agent_id'], $prov['user_id'], $prov['provision_status'] );

				// Place the user into the existing BP structure: resolve the
				// Darwin office -> BP office group (region = its parent), then
				// add memberships + member type.
				if ( $prov['user_id'] && ! empty( $normalized['office_darwin_id'] ) ) {
					$office = OfficeProjector::resolve( [
						'vendor_record_id'   => $normalized['office_darwin_id'],
						'office_name'        => $normalized['office_name'] ?? null,
						'company_id'         => $normalized['company_id'] ?? null,
					], $raw['raw_id'] );
					BpPlacement::place_agent(
						(int) $prov['user_id'],
						$office['group_id'],
						$office['region_group_id'],
						(string) ( $normalized['person_type'] ?? 'agent' )
					);
				}

				if ( isset( $counts[ $prov['provision_status'] ] ) ) {
					$counts[ $prov['provision_status'] ]++;
				}
				if ( ! empty( $normalized['vendor_updated_at'] ) && ( null === $hwm || $normalized['vendor_updated_at'] > $hwm ) ) {
					$hwm = $normalized['vendor_updated_at'];
				}
				RawBuffer::mark( $raw['raw_id'], 'ok' );
			} catch ( \Throwable $e ) {
				$counts['errors']++;
				RawBuffer::mark( $raw['raw_id'], 'failed', $e->getMessage() );
			}
		}

		if ( null !== $hwm ) {
			self::advance_cursor( 'agents', $hwm );
		}

		$counts['request_id'] = $request_id;
		return rest_ensure_response( $counts );
	}

	/** POST /ingest/darwin/listings */
	public static function ingest_listings( $request ) {
		$batch      = self::batch( $request );
		$request_id = self::str( $request->get_param( 'request_id' ) );
		if ( null === $batch ) {
			return new \WP_Error( 'frs_papi_bad_batch', 'Body must be { request_id, batch:[...] } (max ' . self::MAX_BATCH . ').', [ 'status' => 422 ] );
		}

		$counts = [ 'received' => 0, 'deduped' => 0, 'created' => 0, 'updated' => 0, 'errors' => 0 ];
		$hwm    = null;

		foreach ( $batch as $payload ) {
			$counts['received']++;
			if ( ! is_array( $payload ) ) {
				$counts['errors']++;
				continue;
			}

			$normalized = DarwinListingNormalizer::normalize( $payload );
			if ( null === $normalized ) {
				$counts['errors']++;
				continue;
			}

			$raw = RawBuffer::store( '/api/property', $normalized['vendor_record_id'], $payload, $request_id );
			if ( $raw['deduped'] ) {
				$counts['deduped']++;
				continue;
			}

			try {
				$res = ListingsRepository::upsert( $normalized, $raw['raw_id'] );
				$counts[ $res['created'] ? 'created' : 'updated' ]++;
				if ( ! empty( $normalized['vendor_updated_at'] ) && ( null === $hwm || $normalized['vendor_updated_at'] > $hwm ) ) {
					$hwm = $normalized['vendor_updated_at'];
				}
				RawBuffer::mark( $raw['raw_id'], 'ok' );
			} catch ( \Throwable $e ) {
				$counts['errors']++;
				RawBuffer::mark( $raw['raw_id'], 'failed', $e->getMessage() );
			}
		}

		if ( null !== $hwm ) {
			self::advance_cursor( 'listings', $hwm );
		}

		$counts['request_id'] = $request_id;
		return rest_ensure_response( $counts );
	}

	/** GET /sync/darwin/cursor?domain=agents|listings */
	public static function cursor( $request ) {
		$domain = self::str( $request->get_param( 'domain' ) ) ?? 'agents';
		$domain = in_array( $domain, [ 'agents', 'listings' ], true ) ? $domain : 'agents';
		return rest_ensure_response( [
			'domain'          => $domain,
			'high_water_mark' => get_site_option( self::cursor_option( $domain ), null ),
		] );
	}

	// ---------------------------------------------------------------------

	/** Persist the max vendor_updated_at seen, for incremental pulls. */
	public static function advance_cursor( string $domain, string $value ): void {
		$opt     = self::cursor_option( $domain );
		$current = (string) get_site_option( $opt, '' );
		if ( '' === $current || $value > $current ) {
			update_site_option( $opt, $value );
		}
	}

	private static function cursor_option( string $domain ): string {
		return 'frsos_darwin_high_water_mark_' . $domain;
	}

	/** Extract and bound-check the batch array, or null if malformed. */
	private static function batch( $request ): ?array {
		$batch = $request->get_param( 'batch' );
		if ( ! is_array( $batch ) || count( $batch ) > self::MAX_BATCH ) {
			return null;
		}
		return $batch;
	}

	private static function str( $v ): ?string {
		if ( null === $v ) {
			return null;
		}
		$v = trim( (string) $v );
		return '' === $v ? null : $v;
	}
}
