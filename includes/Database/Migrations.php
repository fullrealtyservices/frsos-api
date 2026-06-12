<?php
/**
 * Central, idempotent migration runner for the FRSOS canonical store.
 *
 * The canonical Darwin mirror lives in custom network-wide tables (raw buffer,
 * agents, listings), NOT in post-meta. This runner:
 *
 *   - is invoked from the activation hook (full run), and
 *   - re-checks on every `plugins_loaded` via maybe_upgrade(), which only does
 *     work when a Schema class bumps its version() (cheap option compare).
 *
 * Versions are stored in the NETWORK option `frsos_schema_versions`
 * (get_site_option) because the tables are network-wide (base_prefix). On a
 * single-site install get_site_option transparently falls back to get_option.
 *
 * @package FRSOS\Database
 */

namespace FRSOS\Database;

use FRSOS\Database\Schema\RawBufferDarwin;
use FRSOS\Database\Schema\Agents;
use FRSOS\Database\Schema\Listings;

defined( 'ABSPATH' ) || exit;

class Migrations {

	const VERSION_OPTION = 'frsos_schema_versions';

	/**
	 * Registered schema classes, in dependency order (raw buffer first, then
	 * canonical tables). Each implements Schema\SchemaInterface.
	 *
	 * @return string[]
	 */
	public static function schemas(): array {
		return [
			RawBufferDarwin::class,
			Agents::class,
			Listings::class,
		];
	}

	/**
	 * Run every migration unconditionally (activation hook). Records each
	 * table's current version() so subsequent maybe_upgrade() calls are no-ops
	 * until a version bumps.
	 */
	public static function run(): void {
		$versions = self::stored_versions();
		foreach ( self::schemas() as $schema ) {
			$schema::up();
			$versions[ $schema ] = $schema::version();
		}
		update_site_option( self::VERSION_OPTION, $versions );
	}

	/**
	 * Cheap per-load guard: only call up() for schemas whose recorded version
	 * differs from the code's current version(). Keeps plugins_loaded fast.
	 */
	public static function maybe_upgrade(): void {
		$stored  = self::stored_versions();
		$changed = false;

		foreach ( self::schemas() as $schema ) {
			$current = $schema::version();
			if ( ( $stored[ $schema ] ?? null ) !== $current ) {
				$schema::up();
				$stored[ $schema ] = $current;
				$changed           = true;
			}
		}

		if ( $changed ) {
			update_site_option( self::VERSION_OPTION, $stored );
		}
	}

	/** @return array<string,string> class => recorded version */
	private static function stored_versions(): array {
		$v = get_site_option( self::VERSION_OPTION, [] );
		return is_array( $v ) ? $v : [];
	}
}
