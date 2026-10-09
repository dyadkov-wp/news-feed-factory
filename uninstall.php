<?php
/**
 * Uninstall handler.
 *
 * Runs when the plugin is deleted from the WordPress admin. Removes
 * settings, transients, custom table and scheduled events.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'nwff_settings' );
delete_option( 'nwff_db_version' );

// Delete all plugin transients: values and timeouts.
$transient_like = $wpdb->esc_like( '_transient_nwff_' ) . '%';
$timeout_like   = $wpdb->esc_like( '_transient_timeout_nwff_' ) . '%';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required: Options API cannot delete transients by prefix. Caching is irrelevant during uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$transient_like,
		$timeout_like
	)
);

// Drop the custom meta table.
$table_name = $wpdb->prefix . 'nwff_feed_meta';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Intentional: dropping the plugin's custom table on uninstall. Table identifier cannot be parameterized with $wpdb->prepare().
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// Clear scheduled cleanup event.
wp_clear_scheduled_hook( 'nwff_cleanup_meta' );
