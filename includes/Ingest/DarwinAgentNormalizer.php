<?php
/**
 * Pure transform: one Darwin Person (agent) payload -> canonical agent row
 * for `wp_frsos_agents`. No DB or WP calls — deterministic and fixture-testable.
 *
 * Source: GET /api/person/?personId={id} (Person Details/Report). Accepts both
 * camelCase and PascalCase keys via Normalize::pick.
 *
 * @package FRSOS\Ingest
 */

namespace FRSOS\Ingest;

defined( 'ABSPATH' ) || exit;

class DarwinAgentNormalizer {

	/**
	 * @return array|null Canonical row (vendor-derived columns only), or null
	 *                    when the payload lacks a usable personId.
	 */
	public static function normalize( array $p ): ?array {
		$person_id = Normalize::str( Normalize::pick( $p, [ 'personId', 'PersonID', 'agent_PersonID', 'personID' ] ) );
		if ( null === $person_id ) {
			return null;
		}

		$first = Normalize::str( Normalize::pick( $p, [ 'firstName', 'FirstName' ] ) );
		$last  = Normalize::str( Normalize::pick( $p, [ 'lastName', 'LastName' ] ) );
		$full  = Normalize::str( Normalize::pick( $p, [ 'personName', 'fullName', 'agent_FullName' ] ) );
		if ( null === $full ) {
			$full = trim( (string) $first . ' ' . (string) $last );
			$full = '' === $full ? null : $full;
		}

		return [
			'source_vendor'     => 'darwin',
			'vendor_record_id'  => $person_id,
			'darwin_atguid'     => Normalize::str( Normalize::pick( $p, [ 'guid', 'atGUID', 'atGuid', 'agent_ATGUID' ] ) ),
			'agent_number'      => Normalize::str( Normalize::pick( $p, [ 'agentNumber', 'AgentNumber' ] ) ),
			'first_name'        => $first,
			'last_name'         => $last,
			'full_name'         => $full,
			'email'             => self::email( Normalize::pick( $p, [ 'emailAddress', 'email', 'Email' ] ) ),
			'phone'             => Normalize::str( Normalize::pick( $p, [ 'phone', 'mobilePhone', 'cellPhone', 'phoneNumber' ] ) ),
			'person_type'       => Normalize::str( Normalize::pick( $p, [ 'personType', 'PersonType' ] ) ),
			'office_darwin_id'  => Normalize::str( Normalize::pick( $p, [ 'officeId', 'officeID', 'agent_officeID' ] ) ),
			'office_name'       => Normalize::str( Normalize::pick( $p, [ 'office', 'officeName', 'agent_officeName' ] ) ),
			'company_id'        => Normalize::str( Normalize::pick( $p, [ 'companyId', 'companyID' ] ) ),
			'active'            => Normalize::boolint( Normalize::pick( $p, [ 'active', 'Active' ], true ) ),
			'start_date'        => Normalize::date( Normalize::pick( $p, [ 'startDate', 'StartDate' ] ) ),
			'terminated_date'   => Normalize::date( Normalize::pick( $p, [ 'terminatedDate', 'terminationDate' ] ) ),
			'vendor_updated_at' => Normalize::datetime( Normalize::pick( $p, [ 'modifyDate', 'ModifyDate' ] ) ),
		];
	}

	/** Lightly validate an email; return null if it can't be a WP account email. */
	private static function email( $v ): ?string {
		$v = Normalize::str( $v );
		if ( null === $v ) {
			return null;
		}
		return is_email( $v ) ? strtolower( $v ) : null;
	}
}
