<?php
/**
 * Pure field-coercion helpers shared by the Darwin normalizers.
 *
 * Darwin mixes camelCase and PascalCase (e.g. `personId` vs `agent_PersonID`,
 * `propertyId` vs `propertyID`), so every accessor takes a list of candidate
 * keys and returns the first present, non-null value. No DB calls, no WP calls
 * beyond formatting — keep this deterministic and unit-testable.
 *
 * @package FRSOS\Ingest
 */

namespace FRSOS\Ingest;

defined( 'ABSPATH' ) || exit;

class Normalize {

	/** First present, non-null value among $keys (case-sensitive list). */
	public static function pick( array $row, array $keys, $default = null ) {
		foreach ( $keys as $k ) {
			if ( array_key_exists( $k, $row ) && null !== $row[ $k ] && '' !== $row[ $k ] ) {
				return $row[ $k ];
			}
		}
		return $default;
	}

	/** Trimmed string or null. */
	public static function str( $v ): ?string {
		if ( null === $v ) {
			return null;
		}
		$v = trim( (string) $v );
		return '' === $v ? null : $v;
	}

	/** Integer or null. */
	public static function intOrNull( $v ): ?int {
		if ( null === $v || '' === $v ) {
			return null;
		}
		return (int) $v;
	}

	/**
	 * Decimal dollars -> integer cents. Darwin returns money as decimal dollars
	 * (e.g. 379990, 1480.5); we store BIGINT cents. Round-half-up to the cent.
	 * Returns null only when the value is absent; a literal 0 maps to 0.
	 */
	public static function cents( $v ): ?int {
		if ( null === $v || '' === $v ) {
			return null;
		}
		return (int) round( ( (float) $v ) * 100 );
	}

	/** ISO date 'Y-m-d' from any parseable date/datetime, else null. */
	public static function date( $v ): ?string {
		$v = self::str( $v );
		if ( null === $v ) {
			return null;
		}
		$ts = strtotime( $v );
		if ( false === $ts ) {
			return null;
		}
		// Darwin's empty-date sentinel.
		if ( gmdate( 'Y', $ts ) <= '0001' ) {
			return null;
		}
		return gmdate( 'Y-m-d', $ts );
	}

	/** MySQL datetime 'Y-m-d H:i:s' from any parseable value, else null. */
	public static function datetime( $v ): ?string {
		$v = self::str( $v );
		if ( null === $v ) {
			return null;
		}
		$ts = strtotime( $v );
		if ( false === $ts || gmdate( 'Y', $ts ) <= '0001' ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/** Truthy -> 1, else 0. Accepts bools, 1/0, "true"/"false". */
	public static function boolint( $v ): int {
		if ( is_string( $v ) ) {
			$v = strtolower( trim( $v ) );
			return ( 'true' === $v || '1' === $v || 'yes' === $v ) ? 1 : 0;
		}
		return $v ? 1 : 0;
	}
}
