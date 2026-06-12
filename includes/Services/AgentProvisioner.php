<?php
/**
 * Resolve a Darwin agent to a WordPress user, creating one when necessary.
 *
 * Identity policy (2026-06-12): Darwin is the source of truth for users today,
 * so unmatched agents are auto-created as wp_users. The creation target is
 * isolated in provision_create_user() — when Darwin -> Entra provisioning lands,
 * swap that one method for a Graph/WPO365 call; matching + crosswalk are
 * unchanged.
 *
 * Resolution order:
 *   1. existing user carrying meta darwin_id = personId   -> matched
 *   2. existing user with the same email                  -> matched (+ stamp crosswalk)
 *   3. existing user carrying meta agent_number           -> matched (+ stamp crosswalk)
 *   4. email present                                       -> create user (created)
 *   5. no email                                            -> needs_email (user_id NULL)
 *
 * @package FRSOS\Services
 */

namespace FRSOS\Services;

defined( 'ABSPATH' ) || exit;

class AgentProvisioner {

	const ROLE          = 'frs_agent';
	const META_DARWIN   = 'darwin_id';
	const META_ATGUID   = 'darwin_atguid';
	const META_AGENT_NO = 'agent_number';

	/**
	 * @param array $agent Canonical agent row (DarwinAgentNormalizer output).
	 * @return array{user_id:?int,provision_status:string}
	 */
	public static function provision( array $agent ): array {
		$person_id = (string) ( $agent['vendor_record_id'] ?? '' );
		$email     = $agent['email'] ?? null;
		$atguid    = $agent['darwin_atguid'] ?? null;
		$agent_no  = $agent['agent_number'] ?? null;

		// 1) Already crosswalked by darwin_id.
		$uid = self::find_by_meta( self::META_DARWIN, $person_id );
		if ( $uid ) {
			self::stamp_crosswalk( $uid, $person_id, $atguid, $agent_no );
			return [ 'user_id' => $uid, 'provision_status' => 'matched' ];
		}

		// 2) Match by email.
		if ( $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				self::stamp_crosswalk( (int) $user->ID, $person_id, $atguid, $agent_no );
				return [ 'user_id' => (int) $user->ID, 'provision_status' => 'matched' ];
			}
		}

		// 3) Match by agent number.
		if ( $agent_no ) {
			$uid = self::find_by_meta( self::META_AGENT_NO, $agent_no );
			if ( $uid ) {
				self::stamp_crosswalk( $uid, $person_id, $atguid, $agent_no );
				return [ 'user_id' => $uid, 'provision_status' => 'matched' ];
			}
		}

		// 4) No match: create (only possible with an email).
		if ( $email ) {
			$uid = self::provision_create_user( $agent );
			if ( $uid ) {
				self::stamp_crosswalk( $uid, $person_id, $atguid, $agent_no );
				return [ 'user_id' => $uid, 'provision_status' => 'created' ];
			}
		}

		// 5) Cannot create without an email.
		return [ 'user_id' => null, 'provision_status' => 'needs_email' ];
	}

	/**
	 * Create the WP user. THIS is the swap point for Darwin -> Entra: replace
	 * the body with a Graph/WPO365 provisioning call (which then syncs the user
	 * down) and keep returning the resolved local user_id.
	 *
	 * @return int|null new user ID, or null on failure
	 */
	private static function provision_create_user( array $agent ): ?int {
		self::ensure_role();

		$email = (string) $agent['email'];
		$login = self::unique_login( $email, (string) ( $agent['agent_number'] ?? $agent['vendor_record_id'] ) );

		$user_id = wp_insert_user( [
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'display_name' => (string) ( $agent['full_name'] ?? $login ),
			'first_name'   => (string) ( $agent['first_name'] ?? '' ),
			'last_name'    => (string) ( $agent['last_name'] ?? '' ),
			'role'         => self::ROLE,
		] );

		if ( is_wp_error( $user_id ) ) {
			error_log( 'FRSOS AgentProvisioner: wp_insert_user failed for ' . $email . ' — ' . $user_id->get_error_message() );
			return null;
		}

		do_action( 'frsos_agent_user_created', (int) $user_id, $agent );
		return (int) $user_id;
	}

	/** Ensure the frs_agent role exists. Data-only by default (no login caps). */
	private static function ensure_role(): void {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'FRS Agent', [ 'read' => false ] );
		}
	}

	/** Write/refresh the vendor crosswalk meta on a user. */
	private static function stamp_crosswalk( int $user_id, string $person_id, ?string $atguid, ?string $agent_no ): void {
		update_user_meta( $user_id, self::META_DARWIN, $person_id );
		if ( $atguid ) {
			update_user_meta( $user_id, self::META_ATGUID, $atguid );
		}
		if ( $agent_no ) {
			update_user_meta( $user_id, self::META_AGENT_NO, $agent_no );
		}
	}

	private static function find_by_meta( string $key, string $value ): ?int {
		global $wpdb;
		$uid = $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			$key,
			$value
		) );
		return $uid ? (int) $uid : null;
	}

	/** Build a unique user_login from the email local part, falling back to agent id. */
	private static function unique_login( string $email, string $fallback ): string {
		$base = sanitize_user( current( explode( '@', $email ) ), true );
		if ( '' === $base ) {
			$base = 'agent_' . sanitize_user( $fallback, true );
		}
		$login = $base;
		$n     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . '_' . ( ++$n );
		}
		return $login;
	}
}
