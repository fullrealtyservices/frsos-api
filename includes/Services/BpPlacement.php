<?php
/**
 * Place a resolved agent user into the existing BuddyPress structure:
 * their office group and, by inheritance, the parent region group, with the
 * appropriate member type.
 *
 * Region groups + office subgroups already exist in BP; this only adds
 * memberships (idempotent — BP no-ops if already a member). The region parent
 * comes from the matched office group's parent_id (see OfficeProjector), so no
 * office->region map is required.
 *
 * @package FRSOS\Services
 */

namespace FRSOS\Services;

defined( 'ABSPATH' ) || exit;

class BpPlacement {

	const MEMBER_TYPE_AGENT = 'sales_associate';

	/**
	 * @param int      $user_id         WP user
	 * @param int|null $office_group_id  BP office group (from OfficeProjector)
	 * @param int|null $region_group_id  BP parent region group
	 * @param string   $person_type      Darwin personType (to pick member type)
	 */
	public static function place_agent( int $user_id, ?int $office_group_id, ?int $region_group_id, string $person_type = 'agent' ): void {
		if ( $user_id <= 0 || ! function_exists( 'groups_join_group' ) ) {
			return;
		}

		if ( $office_group_id ) {
			self::ensure_member( $office_group_id, $user_id );
		}
		if ( $region_group_id ) {
			self::ensure_member( $region_group_id, $user_id );
		}

		// Member type: agents are sales associates. Append (don't clobber other
		// types like staff/broker the user may already carry).
		if ( self::is_agent( $person_type ) && function_exists( 'bp_set_member_type' ) ) {
			bp_set_member_type( $user_id, self::MEMBER_TYPE_AGENT, true );
		}
	}

	private static function ensure_member( int $group_id, int $user_id ): void {
		if ( function_exists( 'groups_is_user_member' ) && groups_is_user_member( $user_id, $group_id ) ) {
			return;
		}
		groups_join_group( $group_id, $user_id );
	}

	private static function is_agent( string $person_type ): bool {
		return false !== stripos( $person_type, 'agent' );
	}
}
