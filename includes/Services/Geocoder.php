<?php
/**
 * Address -> lat/lng geocoder. Darwin carries no coordinates, so the directory
 * map depends on this. Uses OpenStreetMap Nominatim (free) by default, cached
 * by address hash in a network option so we never re-geocode the same address
 * and stay within Nominatim's ~1 req/sec policy.
 *
 * Override the endpoint with FRSOS_GEOCODER_URL (e.g. a self-hosted Nominatim or
 * a Google geocode proxy that returns {lat,lon}). FRSOS_GEOCODER_EMAIL is sent
 * per Nominatim policy.
 *
 * @package FRSOS\Services
 */

namespace FRSOS\Services;

defined( 'ABSPATH' ) || exit;

class Geocoder {

	const CACHE_OPTION = 'frsos_geocode_cache';
	const ENDPOINT     = 'https://nominatim.openstreetmap.org/search';

	/** @var array<string,array{lat:float,lng:float}>|null */
	private static $cache = null;

	/**
	 * @return array{lat:float,lng:float}|null
	 */
	public static function geocode( ?string $address, ?string $city, ?string $state, ?string $zip ): ?array {
		$query = trim( implode( ', ', array_filter( [ $address, $city, $state, $zip ] ) ) );
		if ( '' === $query ) {
			return null;
		}
		$key = md5( strtolower( $query ) );

		$cache = self::cache();
		if ( array_key_exists( $key, $cache ) ) {
			return $cache[ $key ] ?: null; // cached miss stored as false-y []
		}

		$result = self::lookup( $query );

		// Persist hit or miss (miss = empty array) so we don't retry every run.
		$cache[ $key ]   = $result ?? [];
		self::$cache     = $cache;
		update_site_option( self::CACHE_OPTION, $cache );

		// Be polite to the public endpoint.
		if ( ! defined( 'FRSOS_GEOCODER_URL' ) ) {
			usleep( 1100000 ); // ~1.1s
		}
		return $result;
	}

	private static function lookup( string $query ): ?array {
		$endpoint = defined( 'FRSOS_GEOCODER_URL' ) ? constant( 'FRSOS_GEOCODER_URL' ) : self::ENDPOINT;
		$email    = defined( 'FRSOS_GEOCODER_EMAIL' ) ? constant( 'FRSOS_GEOCODER_EMAIL' ) : '';

		$url  = $endpoint . '?' . http_build_query( array_filter( [
			'q'              => $query,
			'format'         => 'json',
			'limit'          => 1,
			'countrycodes'   => 'us',
			'email'          => $email,
		] ) );
		$resp = wp_remote_get( $url, [
			'timeout' => 20,
			'headers' => [
				'Accept'     => 'application/json',
				'User-Agent' => 'FRSOS/1.0 (+https://myhub21.com; listings geocoder)',
			],
		] );
		if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) >= 400 ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data[0]['lat'] ) || empty( $data[0]['lon'] ) ) {
			return null;
		}
		return [ 'lat' => (float) $data[0]['lat'], 'lng' => (float) $data[0]['lon'] ];
	}

	private static function cache(): array {
		if ( null === self::$cache ) {
			$c = get_site_option( self::CACHE_OPTION, [] );
			self::$cache = is_array( $c ) ? $c : [];
		}
		return self::$cache;
	}
}
