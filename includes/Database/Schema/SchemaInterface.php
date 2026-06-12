<?php
/**
 * Contract for a single canonical-store table.
 *
 * Each Schema class owns exactly one network-wide table (created with
 * $wpdb->base_prefix, never $wpdb->prefix). `up()` is idempotent: it creates
 * the table if missing and is safe to re-run on every load. `version()` is a
 * monotonic string recorded in the network option `frsos_schema_versions` so
 * Migrations::maybe_upgrade() can detect when a table needs re-checking.
 *
 * @package FRSOS\Database\Schema
 */

namespace FRSOS\Database\Schema;

defined( 'ABSPATH' ) || exit;

interface SchemaInterface {

	/** Create or upgrade the table. Idempotent. */
	public static function up(): void;

	/** Fully-qualified table name (with base_prefix). */
	public static function table_name(): string;

	/** Monotonic schema version string, e.g. "1.0.0". */
	public static function version(): string;
}
