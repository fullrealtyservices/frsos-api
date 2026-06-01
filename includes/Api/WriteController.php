<?php
/**
 * Write endpoints. Admin-auth only (capability `edit_users`).
 *
 * Safety rules enforced on every write:
 *   - Microsoft-auth users (`aadObjectId` set) → identity fields are immutable
 *     (no user_email / user_login / display_name changes). Meta backfill OK.
 *   - Avatar: never overwrite an existing one.
 *   - Member types: append-only via bp_set_member_type($uid, $type, true).
 *   - Phone numbers: normalized to US `(NNN) NNN-NNNN` on write.
 *   - Group memberships: additive only (we never remove on write).
 *   - Writes go to canonical meta keys; MetaKeyAlias mirrors to legacy frs_*.
 *
 * Accepted body shape mirrors the AgentProfile but is partial — supply only
 * the keys you want to change. Nested objects (contact, social, etc.) are
 * supported; flat keys also accepted.
 *
 * @package FRSOS\Api
 */

namespace FRSOS\Api;

defined( 'ABSPATH' ) || exit;

class WriteController {

	/** POST /people — create a new user from a partial AgentProfile body. */
	public static function create_person( $request ) {
		$body = self::flatten_payload( (array) $request->get_json_params() );
		$email = strtolower( trim( (string) ( $body['user_email'] ?? '' ) ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'frs_papi_bad_request', 'user_email is required and must be valid.', [ 'status' => 400 ] );
		}
		if ( get_user_by( 'email', $email ) ) {
			return new \WP_Error( 'frs_papi_conflict', 'A user with that email already exists.', [ 'status' => 409 ] );
		}

		$login = self::pick_login( $body, $email );
		$uid   = wp_create_user( $login, wp_generate_password( 24, true, true ), $email );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		$uid = (int) $uid;

		// One-time identity on a brand-new user.
		$update = [ 'ID' => $uid ];
		foreach ( [ 'first_name', 'last_name', 'display_name', 'user_url', 'nickname' ] as $k ) {
			if ( ! empty( $body[ $k ] ) ) {
				$update[ $k ] = (string) $body[ $k ];
			}
		}
		if ( count( $update ) > 1 ) {
			wp_update_user( $update );
		}

		self::apply_meta_writes( $uid, $body, true );
		self::apply_member_types( $uid, $body, true );
		self::apply_groups( $uid, $body );

		return rest_ensure_response( Formatter::profile( $uid, $request ) );
	}

	/** PATCH /people/{id} — partial update. */
	public static function update_person( $request ) {
		$uid = (int) $request['id'];
		if ( ! get_userdata( $uid ) ) {
			return new \WP_Error( 'frs_papi_not_found', 'Person not found.', [ 'status' => 404 ] );
		}
		$body = self::flatten_payload( (array) $request->get_json_params() );

		// Identity fields: blocked for Microsoft-auth users (Entra-owned).
		$is_microsoft = (bool) get_user_meta( $uid, 'aadObjectId', true );
		if ( ! $is_microsoft ) {
			$update = [ 'ID' => $uid ];
			foreach ( [ 'first_name', 'last_name', 'display_name', 'user_url', 'nickname' ] as $k ) {
				if ( array_key_exists( $k, $body ) && '' !== $body[ $k ] ) {
					$update[ $k ] = (string) $body[ $k ];
				}
			}
			if ( count( $update ) > 1 ) {
				wp_update_user( $update );
			}
			// user_email is sensitive — only update if explicitly provided AND no Microsoft auth.
			if ( ! empty( $body['user_email'] ) ) {
				$new_email = strtolower( trim( (string) $body['user_email'] ) );
				if ( is_email( $new_email ) ) {
					$existing = get_user_by( 'email', $new_email );
					if ( ! $existing || (int) $existing->ID === $uid ) {
						wp_update_user( [ 'ID' => $uid, 'user_email' => $new_email ] );
					}
				}
			}
		}

		self::apply_meta_writes( $uid, $body, false );
		self::apply_member_types( $uid, $body, false );
		self::apply_groups( $uid, $body );

		return rest_ensure_response( Formatter::profile( $uid, $request ) );
	}

	/** PATCH /people/me — self-update by authenticated user. */
	public static function update_me( $request ) {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return new \WP_Error( 'frs_papi_unauth', 'Not authenticated.', [ 'status' => 401 ] );
		}
		// Self-update intentionally re-uses update_person with the current user id.
		$request->set_param( 'id', $uid );
		return self::update_person( $request );
	}

	/** PATCH /places/{id} — update group meta (address, phone, photo_attachment_id). */
	public static function update_place( $request ) {
		$id = (int) $request['id'];
		if ( ! function_exists( 'groups_get_group' ) ) {
			return new \WP_Error( 'frs_papi_bp_missing', 'BuddyPress not loaded.', [ 'status' => 503 ] );
		}
		$g = groups_get_group( $id );
		if ( ! $g || empty( $g->id ) ) {
			return new \WP_Error( 'frs_papi_not_found', 'Place not found.', [ 'status' => 404 ] );
		}
		$body = (array) $request->get_json_params();

		foreach ( [ 'address' => 'frs_address', 'phone' => 'frs_phone' ] as $key => $meta_key ) {
			if ( array_key_exists( $key, $body ) ) {
				if ( function_exists( 'groups_update_groupmeta' ) ) {
					groups_update_groupmeta( $id, $meta_key, (string) $body[ $key ] );
				}
			}
		}
		if ( array_key_exists( 'photo_attachment_id', $body ) ) {
			$att = (int) $body['photo_attachment_id'];
			if ( $att > 0 && function_exists( 'groups_update_groupmeta' ) ) {
				groups_update_groupmeta( $id, 'frs_photo_attachment_id', $att );
			}
		}
		if ( ! empty( $body['name'] ) || ! empty( $body['description'] ) ) {
			if ( function_exists( 'groups_edit_base_group_details' ) ) {
				groups_edit_base_group_details( [
					'group_id'    => $id,
					'name'        => isset( $body['name'] ) ? (string) $body['name'] : $g->name,
					'slug'        => $g->slug,
					'description' => isset( $body['description'] ) ? (string) $body['description'] : $g->description,
				] );
			}
		}
		$g = groups_get_group( $id );
		return rest_ensure_response( Formatter::place( $g ) );
	}

	// ---------------------------------------------------------------------
	// Sites (nested under People + Places)
	// ---------------------------------------------------------------------

	/** POST /people/{id}/sites — create a new multisite blog owned by this person. */
	public static function create_person_site( $request ) {
		$uid = (int) $request['id'];
		if ( ! get_userdata( $uid ) ) {
			return new \WP_Error( 'frs_papi_not_found', 'Person not found.', [ 'status' => 404 ] );
		}
		$body = (array) $request->get_json_params();
		$args = [
			'name'             => (string) ( $body['name'] ?? '' ),
			'path'             => (string) ( $body['path'] ?? '' ),
			'kind'             => (string) ( $body['kind'] ?? 'agent' ),
			'rebuild_webhook'  => (string) ( $body['rebuild_webhook'] ?? '' ),
			'owner_user_id'    => $uid,
		];
		$blog_id = Sites::create( $args );
		if ( is_wp_error( $blog_id ) ) {
			return $blog_id;
		}
		return rest_ensure_response( Sites::format( (int) $blog_id ) );
	}

	/** POST /places/{id}/sites — create a new multisite blog owned by this place. */
	public static function create_place_site( $request ) {
		$gid = (int) $request['id'];
		if ( ! function_exists( 'groups_get_group' ) || ! groups_get_group( $gid )->id ) {
			return new \WP_Error( 'frs_papi_not_found', 'Place not found.', [ 'status' => 404 ] );
		}
		$body = (array) $request->get_json_params();
		$args = [
			'name'             => (string) ( $body['name'] ?? '' ),
			'path'             => (string) ( $body['path'] ?? '' ),
			'kind'             => (string) ( $body['kind'] ?? 'office' ),
			'rebuild_webhook'  => (string) ( $body['rebuild_webhook'] ?? '' ),
			'owner_group_id'   => $gid,
		];
		$blog_id = Sites::create( $args );
		if ( is_wp_error( $blog_id ) ) {
			return $blog_id;
		}
		return rest_ensure_response( Sites::format( (int) $blog_id ) );
	}

	/** PATCH /sites/{blog_id} — update site meta (name, screenshot_url, rebuild_status, etc.). */
	public static function update_site( $request ) {
		$blog_id = (int) $request['blog_id'];
		$body    = (array) $request->get_json_params();
		$ok      = Sites::update( $blog_id, $body );
		if ( ! $ok ) {
			return new \WP_Error( 'frs_papi_not_found', 'Site not found.', [ 'status' => 404 ] );
		}
		return rest_ensure_response( Sites::format( $blog_id ) );
	}

	/** POST /sites/{blog_id}/rebuild — fire the per-site n8n webhook. */
	public static function trigger_site_rebuild( $request ) {
		$blog_id = (int) $request['blog_id'];
		$body    = (array) $request->get_json_params();
		$result  = Sites::trigger_rebuild( $blog_id, $body );
		return rest_ensure_response( $result );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private static function pick_login( array $body, string $email ): string {
		$candidate = (string) ( $body['user_login'] ?? '' );
		if ( '' === $candidate ) {
			$candidate = sanitize_user( current( explode( '@', $email ) ), true );
		} else {
			$candidate = sanitize_user( $candidate, true );
		}
		if ( '' === $candidate ) {
			$candidate = 'user' . wp_generate_password( 6, false );
		}
		$base = $candidate;
		$i = 1;
		while ( username_exists( $candidate ) ) {
			$candidate = $base . $i++;
			if ( $i > 50 ) {
				$candidate = $base . wp_generate_password( 4, false );
				break;
			}
		}
		return $candidate;
	}

	/**
	 * Flatten the AgentProfile nested shape into a flat key=>value array.
	 * Accepts both flat ('phone_number'=>'...') and nested ('contact'=>['phone_number'=>'...']).
	 */
	private static function flatten_payload( array $body ): array {
		$out = [];
		foreach ( $body as $k => $v ) {
			if ( is_array( $v ) && self::is_assoc( $v ) ) {
				foreach ( $v as $kk => $vv ) {
					if ( ! array_key_exists( $kk, $out ) ) {
						$out[ $kk ] = $vv;
					}
				}
			} else {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	private static function is_assoc( array $arr ): bool {
		if ( $arr === [] ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Write meta keys to the user. Phone fields normalized to US format.
	 * For existing users we never overwrite a non-empty value via this path —
	 * use the explicit `force` query param to override (admin-only, future).
	 *
	 * @param int   $uid
	 * @param array $body  Flat key=>value payload.
	 * @param bool  $is_new_user
	 */
	private static function apply_meta_writes( int $uid, array $body, bool $is_new_user ): void {
		// Avatar: never overwrite if user already has one.
		if ( ! $is_new_user && isset( $body['headshot_id'] ) && function_exists( 'get_user_meta' ) ) {
			$existing = (int) get_user_meta( $uid, 'headshot_id', true );
			if ( $existing > 0 ) {
				unset( $body['headshot_id'] );
			}
		}

		// Phone normalization on write.
		foreach ( [ 'phone_number', 'mobile_number' ] as $pk ) {
			if ( ! empty( $body[ $pk ] ) ) {
				$body[ $pk ] = self::normalize_us_phone( (string) $body[ $pk ] );
			}
		}
		if ( ! empty( $body['phones'] ) && is_array( $body['phones'] ) ) {
			$body['phones'] = array_values( array_filter( array_map( [ __CLASS__, 'normalize_us_phone' ], $body['phones'] ) ) );
		}

		// Skip core / identity / system keys (handled in caller).
		$reserved = [
			'id', 'user_login', 'user_email', 'user_url', 'user_registered',
			'user_nicename', 'display_name', 'first_name', 'last_name', 'nickname',
			'member_types', 'member_type', 'places', 'avatar', 'identity_provider',
		];

		foreach ( $body as $key => $val ) {
			if ( in_array( $key, $reserved, true ) ) {
				continue;
			}
			if ( $val === null ) {
				continue;
			}
			if ( is_array( $val ) || is_object( $val ) ) {
				$val = wp_json_encode( $val );
			}
			$val = (string) $val;
			if ( '' === $val ) {
				continue;
			}
			// Append-only on existing users: don't overwrite a non-empty value.
			if ( ! $is_new_user ) {
				$existing = get_user_meta( $uid, $key, true );
				if ( '' !== (string) $existing ) {
					continue;
				}
			}
			update_user_meta( $uid, $key, $val );
		}
	}

	/**
	 * Append member types via bp_set_member_type($uid, $type, true).
	 * Existing types preserved; only ADDS new types from the payload.
	 */
	private static function apply_member_types( int $uid, array $body, bool $is_new_user ): void {
		if ( ! function_exists( 'bp_set_member_type' ) ) {
			return;
		}
		$incoming = [];
		if ( ! empty( $body['member_types'] ) && is_array( $body['member_types'] ) ) {
			$incoming = array_values( array_filter( array_map( 'strval', $body['member_types'] ) ) );
		} elseif ( ! empty( $body['member_type'] ) ) {
			$incoming = [ (string) $body['member_type'] ];
		}
		if ( empty( $incoming ) ) {
			return;
		}
		$existing = (array) bp_get_member_type( $uid, false );
		foreach ( $incoming as $type ) {
			if ( '' === $type ) continue;
			if ( in_array( $type, $existing, true ) ) continue;
			bp_set_member_type( $uid, $type, true ); // 3rd arg = append
		}
	}

	/**
	 * Apply group membership writes. Accepts:
	 *   - `places`: list of {id} or {slug} — joins all of them
	 *   - `office`/`region` strings (group name) — resolves to a group and joins
	 *
	 * Additive only: never removes existing memberships.
	 */
	private static function apply_groups( int $uid, array $body ): void {
		if ( ! function_exists( 'groups_join_group' ) || ! function_exists( 'groups_is_user_member' ) ) {
			return;
		}
		$gids = [];
		if ( ! empty( $body['places'] ) && is_array( $body['places'] ) ) {
			foreach ( $body['places'] as $p ) {
				$gid = is_array( $p ) ? (int) ( $p['id'] ?? 0 ) : (int) $p;
				if ( $gid > 0 ) {
					$gids[] = $gid;
				}
			}
		}
		foreach ( [ 'office', 'region', 'department' ] as $k ) {
			if ( empty( $body[ $k ] ) || ! class_exists( '\BP_Groups_Group' ) ) {
				continue;
			}
			$slug = sanitize_title( (string) $body[ $k ] );
			$gid = (int) \BP_Groups_Group::group_exists( $slug );
			if ( ! $gid ) {
				$gid = (int) \BP_Groups_Group::get_id_from_slug( $slug );
			}
			if ( $gid ) {
				$gids[] = $gid;
			}
		}
		$gids = array_unique( array_filter( $gids ) );
		foreach ( $gids as $gid ) {
			if ( ! groups_is_user_member( $uid, $gid ) ) {
				groups_join_group( $gid, $uid );
			}
		}
	}

	/**
	 * Normalize phone to US `(NNN) NNN-NNNN`. Preserves trailing labels/extensions.
	 */
	public static function normalize_us_phone( $val ): string {
		if ( $val === null || $val === '' ) {
			return '';
		}
		$s = trim( (string) $val );
		if ( '' === $s ) {
			return '';
		}
		$digits = preg_replace( '/\D/', '', $s );
		$suffix = '';
		if ( preg_match( '/(ext\.?|x|extension)\s*\d+/i', $s, $m ) ) {
			$suffix = ' ' . $m[0];
		}
		if ( preg_match( '/\(\s*(Work|Home|Mobile|Cell|Office|Fax)\s*\)/i', $s, $m ) && false === strpos( $suffix, $m[0] ) ) {
			$suffix = trim( $suffix . ' ' . $m[0] );
		}
		if ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
			$digits = substr( $digits, 1 );
		}
		if ( strlen( $digits ) !== 10 ) {
			return $s; // not US 10-digit; leave alone
		}
		$out = sprintf( '(%s) %s-%s', substr( $digits, 0, 3 ), substr( $digits, 3, 3 ), substr( $digits, 6, 4 ) );
		return $suffix ? trim( $out . ' ' . $suffix ) : $out;
	}
}
