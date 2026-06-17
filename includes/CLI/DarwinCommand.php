<?php
/**
 * WP-CLI: in-process Darwin sync. Pulls listings straight into the canonical
 * mirror using the same normalizer + repository the HTTP ingest path uses — no
 * n8n required for a working prototype.
 *
 *   wp frsos darwin sync-listings [--status=AC] [--limit=0] [--page-size=100]
 *                                 [--token=<t>] [--username=<u>] [--geocode] [--max-pages=50]
 *   wp frsos darwin geocode [--limit=200]
 *   wp frsos darwin status
 *
 * @package FRSOS\CLI
 */

namespace FRSOS\CLI;

use FRSOS\Services\DarwinClient;
use FRSOS\Services\Geocoder;
use FRSOS\Ingest\RawBuffer;
use FRSOS\Ingest\DarwinListingNormalizer;
use FRSOS\Database\ListingsRepository;
use FRSOS\Database\Schema\Listings;
use FRSOS\Database\Schema\Agents;
use FRSOS\Database\Schema\Offices;
use FRSOS\Database\Schema\RawBufferDarwin;

defined( 'ABSPATH' ) || exit;

class DarwinCommand {

	/**
	 * Pull Darwin properties into wp_frsos_listings.
	 *
	 * [--status=<code>]   Darwin statusCode (AC active, CL closed, ...). Default AC.
	 * [--limit=<n>]       Max listings to process (0 = all). Default 0.
	 * [--page-size=<n>]   Darwin page size (1-100). Default 100.
	 * [--max-pages=<n>]   Safety cap on pages. Default 50.
	 * [--token=<token>]   Use this Darwin access token (e.g. from the n8n token node).
	 * [--username=<u>]    Darwin username (default FRSOS_DARWIN_USERNAME).
	 * [--geocode]         Geocode each listing's address after upsert.
	 *
	 * @when after_wp_load
	 */
	public function sync_listings( $args, $assoc ): void {
		$status    = (string) ( $assoc['status'] ?? 'AC' );
		$limit     = (int) ( $assoc['limit'] ?? 0 );
		$page_size = max( 1, min( 100, (int) ( $assoc['page-size'] ?? 100 ) ) );
		$max_pages = max( 1, (int) ( $assoc['max-pages'] ?? 50 ) );
		$geocode   = isset( $assoc['geocode'] );

		$client = new DarwinClient( $assoc['token'] ?? null, $assoc['username'] ?? null );
		try {
			$client->authenticate();
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		$processed = 0; $created = 0; $updated = 0; $errors = 0; $geocoded = 0;

		for ( $page = 0; $page < $max_pages; $page++ ) {
			$stubs = $client->search_properties( [ 'statusCode' => $status ], $page, $page_size );
			if ( empty( $stubs ) ) {
				break;
			}
			\WP_CLI::log( sprintf( 'Page %d: %d properties', $page, count( $stubs ) ) );

			foreach ( $stubs as $stub ) {
				if ( $limit > 0 && $processed >= $limit ) {
					break 2;
				}
				$processed++;
				$pid = $stub['propertyId'] ?? $stub['propertyID'] ?? null;
				if ( ! $pid ) { $errors++; continue; }

				try {
					$details = $client->get_property( $pid ) ?: $stub;
					$report  = $client->get_property_report( $pid );
					$merged  = $details;
					if ( is_array( $report ) && ! empty( $report['propertyMediaInfo'] ) ) {
						$merged['propertyMediaInfo'] = $report['propertyMediaInfo'];
					}

					$normalized = DarwinListingNormalizer::normalize( $merged );
					if ( null === $normalized ) { $errors++; continue; }

					$raw = RawBuffer::store( '/api/property', (string) $pid, $merged );
					$res = ListingsRepository::upsert( $normalized, $raw['raw_id'] );
					RawBuffer::mark( $raw['raw_id'], 'ok' );
					$res['created'] ? $created++ : $updated++;

					if ( $geocode ) {
						$coords = Geocoder::geocode( $normalized['address'] ?? null, $normalized['city'] ?? null, $normalized['state'] ?? null, $normalized['zip'] ?? null );
						ListingsRepository::set_geocode( $res['listing_id'], $coords['lat'] ?? null, $coords['lng'] ?? null, $coords ? 'ok' : 'failed' );
						if ( $coords ) { $geocoded++; }
					}
				} catch ( \Throwable $e ) {
					$errors++;
					\WP_CLI::warning( "property {$pid}: " . $e->getMessage() );
				}
			}
		}

		\WP_CLI::success( sprintf(
			'Listings sync done. processed=%d created=%d updated=%d geocoded=%d errors=%d',
			$processed, $created, $updated, $geocoded, $errors
		) );
	}

	/**
	 * Geocode listings that still lack coordinates.
	 *
	 * [--limit=<n>]  Max to geocode this run. Default 200.
	 *
	 * @when after_wp_load
	 */
	public function geocode( $args, $assoc ): void {
		$limit = (int) ( $assoc['limit'] ?? 200 );
		$rows  = ListingsRepository::needing_geocode( $limit );
		if ( ! $rows ) {
			\WP_CLI::success( 'Nothing to geocode.' );
			return;
		}
		$ok = 0; $miss = 0;
		foreach ( $rows as $r ) {
			$coords = Geocoder::geocode( $r['address'], $r['city'], $r['state'], $r['zip'] );
			ListingsRepository::set_geocode( (int) $r['id'], $coords['lat'] ?? null, $coords['lng'] ?? null, $coords ? 'ok' : 'failed' );
			$coords ? $ok++ : $miss++;
		}
		\WP_CLI::success( "Geocoded {$ok}, missed {$miss}." );
	}

	/**
	 * Show row counts for the canonical mirror.
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc ): void {
		global $wpdb;
		foreach ( [
			'raw_darwin' => RawBufferDarwin::table_name(),
			'agents'     => Agents::table_name(),
			'offices'    => Offices::table_name(),
			'listings'   => Listings::table_name(),
		] as $label => $table ) {
			$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			\WP_CLI::log( sprintf( '%-12s %d', $label, $n ) );
		}
		$on_market = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Listings::table_name() . " WHERE on_market = 1" );
		$geocoded  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Listings::table_name() . " WHERE latitude IS NOT NULL" );
		\WP_CLI::log( sprintf( 'on_market    %d', $on_market ) );
		\WP_CLI::log( sprintf( 'geocoded     %d', $geocoded ) );
	}
}
