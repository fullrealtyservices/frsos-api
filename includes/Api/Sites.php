<?php
/**
 * Sites helper. Sites are NOT a top-level resource — they belong to People
 * (one or more sites per agent) or to Places (one or more sites per office or
 * region). This class:
 *
 *   - Resolves the list of sites owned by a user or by a BP group
 *   - Serializes a WP multisite blog into our canonical Site shape
 *   - Provides create / update / rebuild operations
 *
 * Owner linkage uses WP site options on the child site:
 *   _frs_owner_user_id      → wp_user_id of the owning Person (mutually exclusive with group)
 *   _frs_owner_group_id     → BP group ID of the owning Place (mutually exclusive with user)
 *   _frs_site_kind          → "agent" | "office" | "region" | "marketing" | "dashboard" | ...
 *   _frs_screenshot_url     → URL of the static-site screenshot (set via webhook)
 *   _frs_rebuild_status     → "queued" | "building" | "ready" | "failed"
 *   _frs_last_rebuilt_at    → ISO8601 timestamp
 *   _frs_rebuild_webhook    → n8n webhook URL to call on POST .../rebuild
 *
 * The site itself is a real WP multisite blog; we never duplicate its core
 * metadata (domain, path, blog_id, name) here.
 *
 * @package FRSOS\Api
 */

namespace FRSOS\Api;

defined( 'ABSPATH' ) || exit;

class Sites {

	const META_OWNER_USER    = '_frs_owner_user_id';
	const META_OWNER_GROUP   = '_frs_owner_group_id';
	const META_KIND          = '_frs_site_kind';
	const META_SCREENSHOT    = '_frs_screenshot_url';
	const META_REBUILD_STAT  = '_frs_rebuild_status';
	const META_REBUILT_AT    = '_frs_last_rebuilt_at';
	const META_REBUILD_HOOK  = '_frs_rebuild_webhook';

	/**
	 * Return all sites owned by a user. Searches every site in the network
	 * for the `_frs_owner_user_id` option matching $uid. O(N sites), so on
	 * very large networks this could be slow — fine for hundreds of sites.
	 *
	 * @return array<int,array> Site shapes
	 */
	public static function for_user( int $uid ): array {
		if ( ! is_multisite() || $uid <= 0 ) {
			return [];
		}
		return self::scan_sites( self::META_OWNER_USER, $uid );
	}

	/**
	 * Return all sites owned by a BP group (Place).
	 *
	 * @return array<int,array> Site shapes
	 */
	public static function for_group( int $gid ): array {
		if ( ! is_multisite() || $gid <= 0 ) {
			return [];
		}
		return self::scan_sites( self::META_OWNER_GROUP, $gid );
	}

	/**
	 * Serialize a multisite blog into our canonical Site shape.
	 */
	public static function format( int $blog_id ): array {
		$details = get_blog_details( $blog_id );
		if ( ! $details ) {
			return [];
		}
		switch_to_blog( $blog_id );
		$out = [
			'id'              => (int) $blog_id,
			'name'            => (string) get_option( 'blogname' ),
			'description'     => (string) get_option( 'blogdescription' ),
			'url'             => home_url( '/' ),
			'admin_url'       => admin_url(),
			'kind'            => (string) get_option( self::META_KIND, '' ),
			'screenshot_url'  => (string) get_option( self::META_SCREENSHOT, '' ),
			'rebuild_status'  => (string) get_option( self::META_REBUILD_STAT, '' ),
			'last_rebuilt_at' => (string) get_option( self::META_REBUILT_AT, '' ),
			'owner' => [
				'user_id'  => (int) get_option( self::META_OWNER_USER,  0 ),
				'group_id' => (int) get_option( self::META_OWNER_GROUP, 0 ),
			],
			'registered'      => (string) $details->registered,
			'last_updated'    => (string) $details->last_updated,
		];
		restore_current_blog();
		// Clean up owner — only include the one that's set
		if ( empty( $out['owner']['user_id'] ) && empty( $out['owner']['group_id'] ) ) {
			$out['owner'] = null;
		}
		return $out;
	}

	/**
	 * Create a new multisite blog and link it to a user or group.
	 *
	 * @param array $args { name, path, owner_user_id|owner_group_id, kind, rebuild_webhook }
	 * @return int|\WP_Error new blog_id, or WP_Error on failure
	 */
	public static function create( array $args ) {
		if ( ! is_multisite() ) {
			return new \WP_Error( 'frs_papi_not_multisite', 'WP multisite is not enabled.', [ 'status' => 503 ] );
		}
		if ( ! function_exists( 'wpmu_create_blog' ) ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
		}

		$name  = trim( (string) ( $args['name'] ?? '' ) );
		$path  = trim( (string) ( $args['path'] ?? '' ) );
		$kind  = (string) ( $args['kind'] ?? 'marketing' );
		if ( '' === $path ) {
			$path = '/' . sanitize_title( $name ) . '/';
		}
		if ( '/' !== substr( $path, 0, 1 ) ) {
			$path = '/' . $path;
		}
		if ( '/' !== substr( $path, -1 ) ) {
			$path .= '/';
		}

		$current_site = get_current_site();
		$domain       = $current_site->domain;

		$admin_user_id = (int) ( $args['owner_user_id'] ?? 0 );
		if ( ! $admin_user_id ) {
			$admin_user_id = get_current_user_id();
		}
		if ( ! $admin_user_id ) {
			return new \WP_Error( 'frs_papi_no_admin', 'Cannot determine site admin user.', [ 'status' => 400 ] );
		}

		$blog_id = wpmu_create_blog( $domain, $path, $name, $admin_user_id );
		if ( is_wp_error( $blog_id ) ) {
			return $blog_id;
		}
		$blog_id = (int) $blog_id;

		// Link to owner
		switch_to_blog( $blog_id );
		if ( ! empty( $args['owner_user_id'] ) ) {
			update_option( self::META_OWNER_USER, (int) $args['owner_user_id'] );
		}
		if ( ! empty( $args['owner_group_id'] ) ) {
			update_option( self::META_OWNER_GROUP, (int) $args['owner_group_id'] );
		}
		update_option( self::META_KIND, $kind );
		if ( ! empty( $args['rebuild_webhook'] ) ) {
			update_option( self::META_REBUILD_HOOK, esc_url_raw( (string) $args['rebuild_webhook'] ) );
		}
		restore_current_blog();

		do_action( 'frs_papi_site_created', $blog_id, $args );
		return $blog_id;
	}

	/**
	 * Patch a site's metadata (screenshot URL, kind, rebuild webhook, name).
	 */
	public static function update( int $blog_id, array $patch ): bool {
		if ( ! is_multisite() || ! get_blog_details( $blog_id ) ) {
			return false;
		}
		switch_to_blog( $blog_id );
		if ( array_key_exists( 'name', $patch ) ) {
			update_option( 'blogname', (string) $patch['name'] );
		}
		if ( array_key_exists( 'description', $patch ) ) {
			update_option( 'blogdescription', (string) $patch['description'] );
		}
		if ( array_key_exists( 'kind', $patch ) ) {
			update_option( self::META_KIND, (string) $patch['kind'] );
		}
		if ( array_key_exists( 'screenshot_url', $patch ) ) {
			update_option( self::META_SCREENSHOT, esc_url_raw( (string) $patch['screenshot_url'] ) );
		}
		if ( array_key_exists( 'rebuild_status', $patch ) ) {
			update_option( self::META_REBUILD_STAT, (string) $patch['rebuild_status'] );
		}
		if ( array_key_exists( 'last_rebuilt_at', $patch ) ) {
			update_option( self::META_REBUILT_AT, (string) $patch['last_rebuilt_at'] );
		}
		if ( array_key_exists( 'rebuild_webhook', $patch ) ) {
			update_option( self::META_REBUILD_HOOK, esc_url_raw( (string) $patch['rebuild_webhook'] ) );
		}
		restore_current_blog();
		do_action( 'frs_papi_site_updated', $blog_id, $patch );
		return true;
	}

	/**
	 * Trigger a static-site rebuild. Reads the per-site `_frs_rebuild_webhook`,
	 * posts a JSON payload to it, and stamps `_frs_rebuild_status='queued'`.
	 *
	 * Actual fulfillment is async (n8n owns the build pipeline + screenshot).
	 * n8n will call back to PATCH /people/{id}/sites/{blog_id} with the
	 * `screenshot_url`, `rebuild_status='ready'`, and `last_rebuilt_at`.
	 */
	public static function trigger_rebuild( int $blog_id, array $extra_payload = [] ): array {
		if ( ! is_multisite() || ! get_blog_details( $blog_id ) ) {
			return [ 'queued' => false, 'reason' => 'site not found' ];
		}
		switch_to_blog( $blog_id );
		$hook = (string) get_option( self::META_REBUILD_HOOK, '' );
		restore_current_blog();
		if ( '' === $hook ) {
			return [ 'queued' => false, 'reason' => 'no rebuild webhook configured' ];
		}

		$payload = array_merge( [
			'blog_id'    => $blog_id,
			'triggered_at' => gmdate( 'c' ),
			'triggered_by' => get_current_user_id(),
		], $extra_payload );

		$resp = wp_remote_post( $hook, [
			'timeout' => 10,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $payload ),
		] );
		$ok = ! is_wp_error( $resp ) && (int) wp_remote_retrieve_response_code( $resp ) < 400;

		switch_to_blog( $blog_id );
		update_option( self::META_REBUILD_STAT, $ok ? 'queued' : 'failed' );
		restore_current_blog();

		do_action( 'frs_papi_site_rebuild_triggered', $blog_id, $payload, $ok );

		return [ 'queued' => $ok, 'webhook' => $hook ];
	}

	// ---------------------------------------------------------------------

	/**
	 * Scan every blog on the network for the option_name → value pair, return
	 * formatted Site arrays for each match.
	 */
	private static function scan_sites( string $option_name, int $value ): array {
		$blogs = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
		$out = [];
		foreach ( (array) $blogs as $bid ) {
			switch_to_blog( (int) $bid );
			$owner = (int) get_option( $option_name, 0 );
			restore_current_blog();
			if ( $owner === $value ) {
				$site = self::format( (int) $bid );
				if ( $site ) {
					$out[] = $site;
				}
			}
		}
		return $out;
	}
}
