<?php
/**
 * Database schema installer.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Install;

/**
 * Creates and updates the custom feed meta table.
 *
 * Called on plugin activation. Uses dbDelta() to create or update
 * the table without data loss, then stores the current schema
 * version so future activations can skip redundant work.
 */
final class Schema {

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'nwff_feed_meta';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			post_id bigint(20) unsigned NOT NULL,
			exclude_from_feeds tinyint(1) NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (post_id),
			KEY updated_at (updated_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'nwff_db_version', NWFF_DB_VERSION );

		// dbDelta fails silently if the CREATE TABLE statement does
		// not match its expected format. Verify the table exists so
		// the failure is visible instead of surfacing as an SQL error
		// on the first feed request.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Activation-time existence check; result is used once, never cached.
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);
		if ( $exists !== $table_name ) {
			wp_trigger_error(
				__METHOD__,
				sprintf(
					'Table %s was not created. Check the CREATE TABLE syntax in Schema::activate() against dbDelta requirements.',
					$table_name
				),
				E_USER_WARNING
			);
		}
	}
}
