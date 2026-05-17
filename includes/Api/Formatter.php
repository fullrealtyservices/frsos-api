<?php
/**
 * Backend-For-Frontend formatter.
 *
 * Reads user_meta + BP xprofile + BP groups in a small fixed number of WP API
 * calls and assembles the canonical AgentProfile / Place response.
 *
 * Naming policy: native WP/BP key when one exists; industry-standard otherwise;
 * vendor names only as link-back metadata; never `frs_` prefix.
 *
 * @package FRSPapi\Api
 */

namespace FRSPapi\Api;

defined( 'ABSPATH' ) || exit;

class Formatter {

	/**
	 * Assemble the AgentProfile for a user_id.
	 *
	 * @param int                    $uid     wp_user_id
	 * @param \WP_REST_Request|null  $request optional — used for `fields` projection
	 * @return array                          AgentProfile
	 */
	public static function profile( int $uid, $request = null ): array {
		$user = get_userdata( $uid );
		if ( ! $user ) {
			return [];
		}

		$meta_keys = self::all_meta_keys();
		$meta = self::read_meta( $uid, $meta_keys );

		$types = self::member_types( $uid );
		$is_sales = in_array( 'sales_associate', $types, true );
		$is_lo    = in_array( 'loan_originator', $types, true );

		$profile = [
			'id'              => (int) $user->ID,
			'user_login'      => (string) $user->user_login,
			'user_nicename'   => (string) $user->user_nicename,
			'user_email'      => (string) $user->user_email,
			'user_registered' => (string) $user->user_registered,
			'user_url'        => (string) $user->user_url,
			'display_name'    => (string) $user->display_name,
			'member_types'    => $types,
			'avatar'          => self::avatar( $uid, $meta ),

			'name'        => self::nonempty( [
				'first'    => $meta['first_name'],
				'last'     => $meta['last_name'],
				'middle'   => $meta['middle_name'],
				'nickname' => $meta['nickname'],
				'display'  => $user->display_name,
			] ),
			'description' => (string) ( $meta['description'] ?: '' ),

			'contact'     => self::nonempty( [
				'phone_number'   => $meta['phone_number'],
				'mobile_number'  => $meta['mobile_number'],
				'business_email' => $meta['business_email'],
				'emails'         => self::json_list( $meta['emails'] ),
				'phones'         => self::json_list( $meta['phones'] ),
				'date_of_birth'  => $meta['date_of_birth'],
			] ),
			'address'     => self::nonempty( [
				'street_address' => $meta['street_address'],
				'city'           => $meta['city'],
				'state'          => $meta['state'],
				'zip'            => $meta['zip'],
				'city_state'     => $meta['city_state'],
			] ),
			'social'      => self::nonempty( [
				'facebook'  => $meta['facebook'],
				'instagram' => $meta['instagram'],
				'linkedin'  => $meta['linkedin'],
				'twitter'   => $meta['twitter'],
				'youtube'   => $meta['youtube'],
				'tiktok'    => $meta['tiktok'],
				'mastodon'  => $meta['mastodon'],
				'vimeo'     => $meta['vimeo'],
				'pinterest' => $meta['pinterest'],
			] ),
			'credentials' => self::nonempty( [
				'nmls'                => $meta['nmls'],
				'dre_license'         => $meta['dre_license'],
				'license_number'      => $meta['license_number'],
				'license_state'       => $meta['license_state'],
				'license_type'        => $meta['license_type'],
				'mls_id'              => $meta['mls_id'],
				'mls_short_name'      => $meta['mls_short_name'],
				'member_mls_id'       => $meta['member_mls_id'],
				'namb_certifications' => self::json_list( $meta['namb_certifications'] ),
				'nar_designations'    => self::json_list( $meta['nar_designations'] ),
			] ),
			'employment'  => self::nonempty( [
				'office'                   => $meta['office'],
				'region'                   => $meta['region'],
				'department'               => $meta['department'],
				'brand'                    => $meta['brand'],
				'job_title'                => $meta['job_title'],
				'employer_name'            => $meta['employer_name'],
				'employer_nmls'            => $meta['employer_nmls'],
				'branch_nmls'              => $meta['branch_nmls'],
				'company_type'             => $meta['company_type'],
				'time_at_current_employer' => $meta['time_at_current_employer'],
				'industry_tenure'          => $meta['industry_tenure'],
				'jobs_last_10yr'           => $meta['jobs_last_10yr'],
				'company_name'             => $meta['company_name'],
				'company_website'          => $meta['company_website'],
				'company_logo_id'          => $meta['company_logo_id'],
				'aor_regional_director'    => $meta['aor_regional_director'],
				'aor_regional_advisor'     => $meta['aor_regional_advisor'],
			] ),
			'regulatory'  => self::nonempty( [
				'federally_registered'  => self::to_bool( $meta['federally_registered'] ),
				'regulators'            => $meta['regulators'],
				'licensed_states_count' => $meta['licensed_states_count'],
			] ),

			're_production' => $is_sales ? self::re_production( $meta ) : null,
			'lo_production' => $is_lo    ? self::lo_production( $meta ) : null,

			'predictive'        => self::nonempty( [
				'likelihood_to_move'              => $meta['likelihood_to_move'],
				'future_growth_perc'              => $meta['future_growth_perc'],
				'sales_volume_prediction'         => $meta['sales_volume_prediction'],
				'sales_volume_prediction_low'     => $meta['sales_volume_prediction_low'],
				'sales_volume_prediction_high'    => $meta['sales_volume_prediction_high'],
				'abs_diff_predicted_sales_volume' => $meta['abs_diff_predicted_sales_volume'],
			] ),
			'external_profiles' => self::nonempty( [
				'century21_url'  => $meta['century21_url'],
				'zillow_url'     => $meta['zillow_url'],
				'realtor_url'    => $meta['realtor_url'],
				'ylopo_domain'   => $meta['ylopo_domain'],
				'moxi_domain'    => $meta['moxi_domain'],
				'website'        => $meta['website'],
				'other_websites' => self::json_list( $meta['other_websites'] ),
			] ),
			'tools' => self::nonempty( [
				'canva_folder_link'        => $meta['canva_folder_link'],
				'realsatisfied_vanity'     => $meta['realsatisfied_vanity'],
				'arrive_link'              => $meta['arrive_link'],
				'telegram_username'        => $meta['telegram_username'],
				'booking_url'              => $meta['booking_url'],
				'qr_code_data'             => $meta['qr_code_data'],
				'vcard_settings'           => $meta['vcard_settings'],
				'custom_links'             => self::json_list( $meta['custom_links'] ),
				'directory_button_type'    => $meta['directory_button_type'],
				'profile_theme'            => $meta['profile_theme'],
				'profile_visibility'       => $meta['profile_visibility'],
				'profile_slug'             => $meta['profile_slug'],
				'profile_headline'         => $meta['profile_headline'],
				'personal_branding_images' => self::json_list( $meta['personal_branding_images'] ),
				'niche_bio_content'        => $meta['niche_bio_content'],
			] ),
			'vendor_ids' => self::nonempty( [
				'courted_id'           => $meta['courted_id'],
				'courted_url'          => $meta['courted_url'],
				'modex_id'             => $meta['modex_id'],
				'modex_url'            => $meta['modex_url'],
				'modex_score'          => $meta['modex_score'],
				'modex_lists'          => self::json_list( $meta['modex_lists'] ),
				'modex_attributes'     => self::json_list( $meta['modex_attributes'] ),
				'twenty_crm_id'        => $meta['twenty_crm_id'],
				'twenty_crm_last_sync' => $meta['twenty_crm_last_sync'],
				'fluentcrm_synced_at'  => $meta['fluentcrm_synced_at'],
			] ),
			'identity_provider' => self::nonempty( [
				'aad_object_id'       => $meta['aadObjectId'],
				'aad_tenant_id'       => $meta['aadTenantId'],
				'user_principal_name' => $meta['userPrincipalName'],
			] ),
			'lifecycle' => self::nonempty( [
				'status'      => $meta['status'],
				'first_login' => $meta['first_login'],
				'is_active'   => self::to_bool( $meta['is_active'] ),
				'updated_at'  => $meta['updated_at'],
			] ),
			'places' => self::places_for_user( $uid ),
			'sites'  => Sites::for_user( $uid ),
		];

		// `fields` projection (CSV)
		if ( $request ) {
			$fields = (string) $request->get_param( 'fields' );
			if ( '' !== $fields ) {
				$keep   = array_map( 'trim', explode( ',', $fields ) );
				$always = [ 'id', 'user_login', 'display_name', 'member_types' ];
				$profile = array_intersect_key( $profile, array_flip( array_merge( $always, $keep ) ) );
			}
		}

		return apply_filters( 'frs_papi_profile', $profile, $uid, $request );
	}

	/**
	 * Place / group projection.
	 *
	 * @param object $g BP group object
	 */
	public static function place( $g ): array {
		$type = function_exists( 'bp_groups_get_group_type' )
			? (string) bp_groups_get_group_type( $g->id )
			: '';
		$parent = null;
		if ( ! empty( $g->parent_id ) && function_exists( 'groups_get_group' ) ) {
			$p = groups_get_group( (int) $g->parent_id );
			if ( $p && ! empty( $p->id ) ) {
				$parent = [
					'id'   => (int) $p->id,
					'name' => (string) $p->name,
					'type' => function_exists( 'bp_groups_get_group_type' ) ? (string) bp_groups_get_group_type( $p->id ) : '',
				];
			}
		}
		$address         = function_exists( 'groups_get_groupmeta' ) ? (string) groups_get_groupmeta( $g->id, 'frs_address' ) : '';
		$phone           = function_exists( 'groups_get_groupmeta' ) ? (string) groups_get_groupmeta( $g->id, 'frs_phone' ) : '';
		$photo_attach_id = function_exists( 'groups_get_groupmeta' ) ? (int) groups_get_groupmeta( $g->id, 'frs_photo_attachment_id' ) : 0;
		$photo_url       = $photo_attach_id ? (string) wp_get_attachment_url( $photo_attach_id ) : '';
		$member_count    = function_exists( 'groups_get_total_member_count' ) ? (int) groups_get_total_member_count( $g->id ) : 0;

		return [
			'id'           => (int) $g->id,
			'name'         => (string) $g->name,
			'slug'         => (string) $g->slug,
			'type'         => $type,
			'parent'       => $parent,
			'description'  => (string) ( $g->description ?? '' ),
			'address'      => $address,
			'phone'        => $phone,
			'photo_url'    => $photo_url,
			'member_count' => $member_count,
			'members_url'  => rest_url( Bootstrap::NAMESPACE_V1 . '/places/' . (int) $g->id . '/people' ),
			'sites'        => Sites::for_group( (int) $g->id ),
		];
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	private static function avatar( int $uid, array $meta ): array {
		$thumb = function_exists( 'get_avatar_url' ) ? (string) get_avatar_url( $uid, [ 'size' => 96  ] ) : '';
		$full  = function_exists( 'get_avatar_url' ) ? (string) get_avatar_url( $uid, [ 'size' => 512 ] ) : '';
		return [
			'thumb'         => $thumb,
			'full'          => $full,
			'attachment_id' => (int) ( $meta['headshot_id'] ?? 0 ),
		];
	}

	private static function member_types( int $uid ): array {
		if ( ! function_exists( 'bp_get_member_type' ) ) {
			return [];
		}
		$t = bp_get_member_type( $uid, false );
		if ( ! is_array( $t ) ) {
			$t = $t ? [ (string) $t ] : [];
		}
		return array_values( array_filter( array_map( 'strval', $t ) ) );
	}

	private static function re_production( array $meta ): array {
		return self::nonempty( [
			'ltm_sales_volume'             => $meta['ltm_sales_volume'],
			'ltm_sales_volume_buy'         => $meta['ltm_sales_volume_buy'],
			'ltm_sales_volume_list'        => $meta['ltm_sales_volume_list'],
			'ltm_closed_transactions'      => $meta['ltm_closed_transactions'],
			'ltm_closed_units'             => $meta['ltm_closed_units'],
			'ltm_closed_units_buy_side'    => $meta['ltm_closed_units_buy_side'],
			'ltm_closed_units_list_side'   => $meta['ltm_closed_units_list_side'],
			'ltm_avg_sale_price'           => $meta['ltm_avg_sale_price'],
			'ltm_est_gci'                  => $meta['ltm_est_gci'],
			'ltm_rental_count'             => $meta['ltm_rental_count'],
			'ltm_avg_rental_price'         => $meta['ltm_avg_rental_price'],
			'prev_ltm_sales_volume'        => $meta['prev_ltm_sales_volume'],
			'prev_ltm_closed_transactions' => $meta['prev_ltm_closed_transactions'],
			'prev_ltm_closed_units'        => $meta['prev_ltm_closed_units'],
			'ltm_sales_volume_change'      => $meta['ltm_sales_volume_change'],
			'active_listings'              => $meta['active_listings'],
			'pending_listings'             => $meta['pending_listings'],
			'agent_tenure'                 => $meta['agent_tenure'],
			'time_at_current_office'       => $meta['time_at_current_office'],
			'avg_time_at_office'           => $meta['avg_time_at_office'],
			'office_rank'                  => $meta['office_rank'],
			'office_roster_count'          => $meta['office_roster_count'],
			'most_transacted_city'         => $meta['most_transacted_city'],
			'most_transacted_zip'          => $meta['most_transacted_zip'],
			'transacted_cities'            => self::json_list( $meta['transacted_cities'] ),
			'transacted_zips'              => self::json_list( $meta['transacted_zips'] ),
			'agent_type_tags'              => self::json_list( $meta['agent_type_tags'] ),
		] );
	}

	private static function lo_production( array $meta ): array {
		return self::nonempty( [
			'ltm_loan_volume'            => $meta['ltm_loan_volume'],
			'ltm_loan_units'             => $meta['ltm_loan_units'],
			'ltm_avg_loan_amount'        => $meta['ltm_avg_loan_amount'],
			'ltm_avg_monthly_volume'     => $meta['ltm_avg_monthly_volume'],
			'loan_types'                 => self::json_list( $meta['loan_types'] ),
			'transaction_types'          => self::json_list( $meta['transaction_types'] ),
			'property_types'             => self::json_list( $meta['property_types'] ),
			'banked_or_brokered'         => $meta['banked_or_brokered'],
			'does_reverse_mortgage'      => self::to_bool( $meta['does_reverse_mortgage'] ),
			'lender_beneficiaries'       => self::json_list( $meta['lender_beneficiaries'] ),
			'ltm_nonqm_volume'           => $meta['ltm_nonqm_volume'],
			'ltm_nonqm_units'            => $meta['ltm_nonqm_units'],
			'nonqm_loan_types'           => self::json_list( $meta['nonqm_loan_types'] ),
			'nonqm_transaction_types'    => self::json_list( $meta['nonqm_transaction_types'] ),
			'nonqm_transacted_cities'    => self::json_list( $meta['nonqm_transacted_cities'] ),
			'nonqm_lender_beneficiaries' => self::json_list( $meta['nonqm_lender_beneficiaries'] ),
			'ltm_population_majority'    => $meta['ltm_population_majority'],
			'ltm_income_level'           => $meta['ltm_income_level'],
			'ltm_distressed_pct'         => $meta['ltm_distressed_pct'],
			'ltm_poverty_pct'            => $meta['ltm_poverty_pct'],
			'ltm_rural_pct'              => $meta['ltm_rural_pct'],
			'ltm_unemployment_pct'       => $meta['ltm_unemployment_pct'],
		] );
	}

	/**
	 * One get_user_meta() call returns all meta rows; we just pluck the keys
	 * we care about. O(1) DB call regardless of how many keys.
	 *
	 * @return array<string,mixed>
	 */
	private static function read_meta( int $uid, array $keys ): array {
		$out = [];
		$all = get_user_meta( $uid );
		foreach ( $keys as $k ) {
			$out[ $k ] = isset( $all[ $k ][0] ) ? maybe_unserialize( $all[ $k ][0] ) : '';
		}
		return $out;
	}

	private static function places_for_user( int $uid ): array {
		if ( ! function_exists( 'bp_get_user_groups' ) ) {
			return [];
		}
		$out = [];
		$memberships = bp_get_user_groups( $uid, [ 'is_confirmed' => true, 'is_banned' => false, 'is_admin' => null ] );
		if ( ! is_array( $memberships ) ) {
			return [];
		}
		foreach ( $memberships as $m ) {
			$gid = (int) ( $m->group_id ?? 0 );
			if ( ! $gid ) continue;
			$g = groups_get_group( $gid );
			if ( ! $g || empty( $g->id ) ) continue;
			$out[] = [
				'id'     => $gid,
				'name'   => (string) $g->name,
				'type'   => function_exists( 'bp_groups_get_group_type' ) ? (string) bp_groups_get_group_type( $gid ) : '',
				'parent' => ! empty( $g->parent_id ) ? (string) ( groups_get_group( (int) $g->parent_id )->name ?? '' ) : null,
			];
		}
		return $out;
	}

	private static function nonempty( array $assoc ): array {
		$out = [];
		foreach ( $assoc as $k => $v ) {
			if ( $v === null || $v === '' || $v === [] ) {
				continue;
			}
			$out[ $k ] = $v;
		}
		return $out;
	}

	private static function json_list( $val ): array {
		if ( is_array( $val ) ) {
			return $val;
		}
		if ( ! is_string( $val ) || '' === $val ) {
			return [];
		}
		$dec = json_decode( $val, true );
		return is_array( $dec ) ? $dec : [];
	}

	private static function to_bool( $val ): ?bool {
		if ( $val === null || $val === '' ) return null;
		if ( is_bool( $val ) ) return $val;
		$s = strtolower( (string) $val );
		if ( in_array( $s, [ '1', 'true', 'yes', 'y', 'on' ],  true ) ) return true;
		if ( in_array( $s, [ '0', 'false', 'no', 'n', 'off' ], true ) ) return false;
		return null;
	}

	/**
	 * Master list of user_meta keys we care about. Edit this when new fields
	 * are added — the rest of the formatter picks them up automatically (any
	 * key not in the formatted shape just stays in $meta unused).
	 */
	private static function all_meta_keys(): array {
		return [
			// identity
			'first_name', 'last_name', 'middle_name', 'nickname', 'description',
			// contact
			'phone_number', 'mobile_number', 'business_email', 'emails', 'phones', 'date_of_birth',
			// address
			'street_address', 'city', 'state', 'zip', 'city_state',
			// social
			'facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'tiktok',
			'mastodon', 'vimeo', 'pinterest',
			// credentials
			'nmls', 'dre_license', 'license_number', 'license_state', 'license_type',
			'namb_certifications', 'nar_designations', 'mls_id', 'mls_short_name', 'member_mls_id',
			// employment
			'office', 'region', 'department', 'brand', 'job_title',
			'employer_name', 'employer_nmls', 'branch_nmls', 'company_type',
			'time_at_current_employer', 'industry_tenure', 'jobs_last_10yr',
			'company_name', 'company_website', 'company_logo_id',
			'aor_regional_director', 'aor_regional_advisor',
			// regulatory
			'federally_registered', 'regulators', 'licensed_states_count',
			// RE production
			'ltm_sales_volume', 'ltm_sales_volume_buy', 'ltm_sales_volume_list',
			'ltm_closed_transactions', 'ltm_closed_units', 'ltm_closed_units_buy_side', 'ltm_closed_units_list_side',
			'ltm_avg_sale_price', 'ltm_est_gci', 'ltm_rental_count', 'ltm_avg_rental_price',
			'prev_ltm_sales_volume', 'prev_ltm_closed_transactions', 'prev_ltm_closed_units',
			'ltm_sales_volume_change',
			'active_listings', 'pending_listings',
			'agent_tenure', 'time_at_current_office', 'avg_time_at_office',
			'office_rank', 'office_roster_count',
			'most_transacted_city', 'most_transacted_zip', 'transacted_cities', 'transacted_zips',
			'agent_type_tags',
			// LO production
			'ltm_loan_volume', 'ltm_loan_units', 'ltm_avg_loan_amount', 'ltm_avg_monthly_volume',
			'loan_types', 'transaction_types', 'property_types',
			'banked_or_brokered', 'does_reverse_mortgage', 'lender_beneficiaries',
			'ltm_nonqm_volume', 'ltm_nonqm_units', 'nonqm_loan_types', 'nonqm_transaction_types',
			'nonqm_transacted_cities', 'nonqm_lender_beneficiaries',
			'ltm_population_majority', 'ltm_income_level',
			'ltm_distressed_pct', 'ltm_poverty_pct', 'ltm_rural_pct', 'ltm_unemployment_pct',
			// predictive
			'likelihood_to_move', 'future_growth_perc',
			'sales_volume_prediction', 'sales_volume_prediction_low', 'sales_volume_prediction_high',
			'abs_diff_predicted_sales_volume',
			// external profiles
			'century21_url', 'zillow_url', 'realtor_url', 'ylopo_domain', 'moxi_domain',
			'website', 'other_websites',
			// tools
			'canva_folder_link', 'realsatisfied_vanity', 'arrive_link',
			'telegram_username', 'booking_url', 'qr_code_data', 'vcard_settings',
			'custom_links', 'directory_button_type', 'profile_theme', 'profile_visibility', 'profile_slug',
			'profile_headline', 'personal_branding_images', 'niche_bio_content',
			// avatar
			'headshot_id', 'headshot_url',
			// vendor ids
			'courted_id', 'courted_url', 'modex_id', 'modex_url', 'modex_score',
			'modex_lists', 'modex_attributes', 'twenty_crm_id', 'twenty_crm_last_sync',
			'fluentcrm_synced_at',
			// Entra / Microsoft
			'aadObjectId', 'aadTenantId', 'userPrincipalName',
			// lifecycle
			'status', 'first_login', 'is_active', 'updated_at',
		];
	}
}
