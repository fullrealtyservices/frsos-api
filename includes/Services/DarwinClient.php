<?php
/**
 * Minimal Darwin (AccountTECH) API client for the in-process WP-CLI sync.
 *
 * Auth precedence (first that works):
 *   1. Explicit token       — pass a currently-valid token (e.g. copied from the
 *                             n8n "Get Valid Token" node). No refresh-chain risk.
 *   2. Login creds          — FRSOS_DARWIN_CLIENT_ID/_SECRET/_API_KEY/_PASSWORD
 *                             + _USERNAME. Issues an independent token; does NOT
 *                             touch the n8n single-use refresh chain. Preferred
 *                             for unattended runs.
 *   3. Refresh token        — FRSOS_DARWIN_REFRESH_TOKEN (base64). Single-use and
 *                             rotates; sharing it with n8n will desync one side.
 *
 * Darwin sits behind Cloudflare, which 403s non-browser UAs — every call sends a
 * browser-like User-Agent. Authenticated calls use Basic base64(username:token).
 *
 * @package FRSOS\Services
 */

namespace FRSOS\Services;

defined( 'ABSPATH' ) || exit;

class DarwinClient {

	const BASE = 'https://api.darwin.cloud';
	const UA   = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

	const TOKEN_OPTION = 'frsos_darwin_cli_token'; // { token, username, expires }

	/** @var string */
	private $username;
	/** @var string */
	private $token = '';

	public function __construct( ?string $token = null, ?string $username = null ) {
		$this->username = $username ?: (string) self::cfg( 'USERNAME', '' );
		if ( $token ) {
			$this->token = $token;
		}
	}

	/** Ensure we hold a usable token, acquiring one if needed. Throws on failure. */
	public function authenticate(): void {
		if ( '' !== $this->token ) {
			return;
		}
		// Reuse a cached CLI token if still valid.
		$cached = get_site_option( self::TOKEN_OPTION, [] );
		if ( is_array( $cached ) && ! empty( $cached['token'] ) && ! empty( $cached['expires'] ) && ( (int) $cached['expires'] - time() ) > 300 ) {
			$this->token    = (string) $cached['token'];
			$this->username = $cached['username'] ?: $this->username;
			return;
		}

		if ( self::cfg( 'CLIENT_ID' ) && self::cfg( 'PASSWORD' ) ) {
			$this->login();
		} elseif ( self::cfg( 'REFRESH_TOKEN' ) ) {
			$this->refresh();
		} else {
			throw new \RuntimeException(
				'No Darwin credentials. Pass --token=, or define FRSOS_DARWIN_CLIENT_ID/_CLIENT_SECRET/_API_KEY/_PASSWORD/_USERNAME, or FRSOS_DARWIN_REFRESH_TOKEN.'
			);
		}
	}

	/** Full login flow — issues an independent token (no refresh-chain coupling). */
	private function login(): void {
		$resp = $this->raw_post( '/api/auth/login/' . rawurlencode( $this->username ), [
			'client_id'     => (string) self::cfg( 'CLIENT_ID' ),
			'client_secret' => (string) self::cfg( 'CLIENT_SECRET' ),
			'api_key'       => (string) self::cfg( 'API_KEY' ),
			'password'      => (string) self::cfg( 'PASSWORD' ),
		] );
		$this->store_token( $resp );
	}

	/** Refresh-token flow (single-use, base64 in / raw GUID out). */
	private function refresh(): void {
		$resp = $this->raw_post( '/api/auth/refresh-token', [
			'userName'     => $this->username,
			'refreshToken' => (string) self::cfg( 'REFRESH_TOKEN' ),
		] );
		$this->store_token( $resp );
	}

	private function store_token( array $resp ): void {
		if ( empty( $resp['token'] ) ) {
			throw new \RuntimeException( 'Darwin auth returned no token: ' . wp_json_encode( $resp ) );
		}
		$this->token    = (string) $resp['token'];
		$this->username = ! empty( $resp['userName'] ) ? (string) $resp['userName'] : $this->username;
		$expires        = ! empty( $resp['tokenExpiration'] ) ? strtotime( (string) $resp['tokenExpiration'] ) : ( time() + 23 * HOUR_IN_SECONDS );
		update_site_option( self::TOKEN_OPTION, [
			'token'    => $this->token,
			'username' => $this->username,
			'expires'  => $expires ?: ( time() + 23 * HOUR_IN_SECONDS ),
		] );
	}

	// ----- Property reads -------------------------------------------------

	/**
	 * Enumerate properties (one page). Returns the decoded array of property
	 * stubs. Use statusCode='AC' for active inventory.
	 *
	 * @return array<int,array>
	 */
	public function search_properties( array $query = [], int $page_index = 0, int $page_size = 100 ): array {
		$qs = array_merge( [
			'statusCode' => '',
			'companyId'  => '',
			'withTrash'  => '0',
			'pageIndex'  => $page_index,
			'pageSize'   => $page_size,
		], $query );
		$res = $this->get( '/api/property', $qs );
		return is_array( $res ) ? $res : [];
	}

	/** Full property detail (specs). */
	public function get_property( $property_id ): ?array {
		$res = $this->get( '/api/property/' . rawurlencode( (string) $property_id ) );
		return is_array( $res ) ? $res : null;
	}

	/** Property report (photos via propertyMediaInfo, agents). Returns first row. */
	public function get_property_report( $property_id ): ?array {
		$res = $this->get( '/api/propertyreport', [
			'PropertyId'         => (string) $property_id,
			'showAgentNetList'   => 'true',
			'showAgentNetSell'   => 'true',
		] );
		if ( is_array( $res ) && isset( $res[0] ) ) {
			return $res[0];
		}
		return is_array( $res ) ? $res : null;
	}

	// ----- HTTP -----------------------------------------------------------

	private function get( string $path, array $qs = [] ) {
		$this->authenticate();
		$url  = self::BASE . $path . ( $qs ? ( '?' . http_build_query( $qs ) ) : '' );
		$resp = wp_remote_get( $url, [
			'timeout' => 30,
			'headers' => [
				'Accept'        => 'application/json',
				'User-Agent'    => self::UA,
				'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->token ),
			],
		] );
		return $this->decode( $resp, $url );
	}

	private function raw_post( string $path, array $body ) {
		$resp = wp_remote_post( self::BASE . $path, [
			'timeout' => 30,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'User-Agent'   => self::UA,
			],
			'body'    => wp_json_encode( $body ),
		] );
		return $this->decode( $resp, $path );
	}

	private function decode( $resp, string $where ) {
		if ( is_wp_error( $resp ) ) {
			throw new \RuntimeException( "Darwin request failed ({$where}): " . $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		if ( $code >= 400 ) {
			throw new \RuntimeException( "Darwin HTTP {$code} ({$where}): " . substr( (string) $body, 0, 500 ) );
		}
		$data = json_decode( (string) $body, true );
		return null === $data ? [] : $data;
	}

	private static function cfg( string $key, $default = null ) {
		$const = 'FRSOS_DARWIN_' . $key;
		return defined( $const ) ? constant( $const ) : $default;
	}
}
