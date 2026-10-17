<?php
/**
 * Integration tests for the schema installer.
 *
 * @package NewsFeedFactory
 *
 * @group integration
 */

declare( strict_types=1 );

namespace NWFF\Tests\Integration\Install;

use NWFF\Install\Schema;
use WP_UnitTestCase;

/**
 * Covers Schema::activate().
 *
 * @group integration
 */
final class SchemaTest extends WP_UnitTestCase {

	/**
	 * Recreate the meta table after each test.
	 *
	 * WP_UnitTestCase does not roll back DDL (DROP/CREATE TABLE cause
	 * an implicit commit), so a test that drops the table must
	 * restore it for subsequent tests in the same run.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Schema::activate();
		parent::tearDown();
	}

	/**
	 * Activate creates the meta table when it is absent.
	 *
	 * Verifies dbDelta actually produced the table; a malformed
	 * CREATE TABLE statement would otherwise leave the plugin active
	 * without a schema and surface as an SQL error on the first
	 * feed request.
	 *
	 * @return void
	 */
	public function test_activate_creates_table(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'nwff_feed_meta';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper: drop the plugin's custom table to force Schema::activate() to recreate it.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		Schema::activate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test helper: verify the table exists after activation.
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		$this->assertSame( $table, $exists );
	}

	/**
	 * Activate is idempotent on an existing table.
	 *
	 * Calls Schema::activate() twice; dbDelta on an existing table
	 * must not warn or fail. The presence check at the end catches
	 * a second-call regression that silently dropped the table.
	 *
	 * @return void
	 */
	public function test_activate_is_idempotent(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'nwff_feed_meta';

		Schema::activate();
		Schema::activate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test helper: verify the table survived a repeated activation.
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		$this->assertSame( $table, $exists );
	}
}
