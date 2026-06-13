<?php
/**
 * Pure transform: a Darwin property payload -> canonical listing row for
 * `wp_frsos_listings`. No DB/WP calls beyond formatting.
 *
 * Sources merged by the n8n sync before POST: Property Details (specs) +
 * Property Report (photos via propertyMediaInfo[].previewURL, agents). Money is
 * decimal dollars in Darwin -> stored as BIGINT cents. Darwin has no lat/lng,
 * so geocoding happens later (geocode_status defaults to 'pending').
 *
 * `on_market` is the directory's "publishable active inventory" flag: status
 * code AC and showOnInternet true.
 *
 * @package FRSOS\Ingest
 */

namespace FRSOS\Ingest;

defined( 'ABSPATH' ) || exit;

class DarwinListingNormalizer {

	/**
	 * @return array|null Canonical row (vendor-derived columns; crosswalks +
	 *                    provenance added by the repository), or null when the
	 *                    payload lacks a usable propertyId.
	 */
	public static function normalize( array $p ): ?array {
		$property_id = Normalize::str( Normalize::pick( $p, [ 'propertyId', 'propertyID', 'PropertyId' ] ) );
		if ( null === $property_id ) {
			return null;
		}

		$status_code      = Normalize::str( Normalize::pick( $p, [ 'statusCode', 'StatusCode' ] ) );
		$show_on_internet = Normalize::boolint( Normalize::pick( $p, [ 'showOnInternet', 'ShowOnInternet' ], false ) );

		return [
			'source_vendor'        => 'darwin',
			'vendor_record_id'     => $property_id,
			'darwin_atguid'        => Normalize::str( Normalize::pick( $p, [ 'atGUID', 'atGuid', 'transactionGUID' ] ) ),

			'status'               => Normalize::str( Normalize::pick( $p, [ 'status', 'Status' ] ) ),
			'status_code'          => $status_code,
			'tr_type'              => Normalize::str( Normalize::pick( $p, [ 'trType', 'TrType' ] ) ),
			'on_market'            => ( 'AC' === strtoupper( (string) $status_code ) && $show_on_internet ) ? 1 : 0,

			'mls_number'           => Normalize::str( Normalize::pick( $p, [ 'mlsNumber1', 'mlsNumber', 'MLSNumber1' ] ) ),
			'address'              => Normalize::str( Normalize::pick( $p, [ 'propertyAddress', 'address', 'Address' ] ) ),
			'street_number'        => Normalize::str( Normalize::pick( $p, [ 'streetNumber' ] ) ),
			'street_name'          => Normalize::str( Normalize::pick( $p, [ 'streetName' ] ) ),
			'unit'                 => Normalize::str( Normalize::pick( $p, [ 'suiteAptNumber', 'unit' ] ) ),
			'city'                 => Normalize::str( Normalize::pick( $p, [ 'city', 'City' ] ) ),
			'state'                => Normalize::str( Normalize::pick( $p, [ 'state', 'State' ] ) ),
			'zip'                  => Normalize::str( Normalize::pick( $p, [ 'zip', 'Zip', 'zipCode' ] ) ),
			'neighborhood'         => Normalize::str( Normalize::pick( $p, [ 'neigborhoodName', 'neighborhoodName', 'propertyLocation' ] ) ),
			'subdivision'          => Normalize::str( Normalize::pick( $p, [ 'subDivision', 'subdivision' ] ) ),

			'list_price_cents'     => Normalize::cents( Normalize::pick( $p, [ 'listingPrice', 'listPrice' ] ) ),
			'selling_price_cents'  => Normalize::cents( Normalize::pick( $p, [ 'sellingPrice' ] ) ),

			'bedrooms'             => Normalize::intOrNull( Normalize::pick( $p, [ 'bedrooms', 'Bedrooms' ] ) ),
			'full_baths'           => Normalize::intOrNull( Normalize::pick( $p, [ 'fullBaths', 'fullBathsTotal' ] ) ),
			'half_baths'           => Normalize::intOrNull( Normalize::pick( $p, [ 'halfBathsTotal', 'halfBaths' ] ) ),
			'square_feet'          => Normalize::intOrNull( Normalize::pick( $p, [ 'squareFeet', 'SquareFeet' ] ) ),
			'lot_size'             => self::floatOrNull( Normalize::pick( $p, [ 'lotSize' ] ) ),
			'acreage'              => self::floatOrNull( Normalize::pick( $p, [ 'numberOfAcres', 'acreage' ] ) ),
			'year_built'           => Normalize::intOrNull( Normalize::pick( $p, [ 'yearBuilt' ] ) ),
			'parking_spaces'       => Normalize::intOrNull( Normalize::pick( $p, [ 'parkingSpaces' ] ) ),
			'property_type'        => Normalize::str( Normalize::pick( $p, [ 'propertyType', 'typeCode' ] ) ),
			'property_type_id'     => Normalize::str( Normalize::pick( $p, [ 'propertyTypeId' ] ) ),
			'is_condo'             => Normalize::boolint( Normalize::pick( $p, [ 'condo' ], false ) ),

			'description'          => Normalize::str( Normalize::pick( $p, [ 'notes', 'remarks', 'publicRemarks' ] ) ),
			'show_on_internet'     => $show_on_internet,
			'show_address'         => Normalize::boolint( Normalize::pick( $p, [ 'showAddressOnInternet' ], false ) ),
			'show_price'           => Normalize::boolint( Normalize::pick( $p, [ 'showPriceOnInternet' ], false ) ),

			'list_date'            => Normalize::date( Normalize::pick( $p, [ 'listDate' ] ) ),
			'pending_date'         => Normalize::date( Normalize::pick( $p, [ 'pendingDate' ] ) ),
			'close_date'           => Normalize::date( Normalize::pick( $p, [ 'closeDate', 'closingDate' ] ) ),
			'vendor_updated_at'    => Normalize::datetime( Normalize::pick( $p, [ 'modifyDate', 'ModifyDate' ] ) ),

			'list_agent_darwin_id' => Normalize::str( Normalize::pick( $p, [ 'listAgentID', 'listAgentId', 'agent_PersonID' ] ) ),
			'office_darwin_id'     => Normalize::str( Normalize::pick( $p, [ 'officeId', 'officeID', 'listOfficeId' ] ) ),
			'company_id'           => Normalize::str( Normalize::pick( $p, [ 'companyID', 'companyId' ] ) ),

			'photos_json'          => self::photos_json( $p ),
			'photo_count'          => (int) Normalize::intOrNull( Normalize::pick( $p, [ 'photoCount' ], 0 ) ),

			// office_name carried for OfficeProjector (not a listings column).
			'_office_name'         => Normalize::str( Normalize::pick( $p, [ 'listOffice', 'officeName', 'office' ] ) ),
		];
	}

	/** Extract previewURLs from Property Report's propertyMediaInfo[] as a JSON array. */
	private static function photos_json( array $p ): ?string {
		$media = Normalize::pick( $p, [ 'propertyMediaInfo', 'media', 'photos' ] );
		if ( ! is_array( $media ) ) {
			return null;
		}
		$urls = [];
		foreach ( $media as $m ) {
			if ( is_array( $m ) ) {
				$url = Normalize::pick( $m, [ 'previewURL', 'url', 'fullURL', 'thumbnailURL' ] );
			} else {
				$url = $m;
			}
			$url = Normalize::str( $url );
			if ( null !== $url ) {
				$urls[] = $url;
			}
		}
		return empty( $urls ) ? null : wp_json_encode( $urls );
	}

	private static function floatOrNull( $v ): ?float {
		if ( null === $v || '' === $v ) {
			return null;
		}
		return (float) $v;
	}
}
