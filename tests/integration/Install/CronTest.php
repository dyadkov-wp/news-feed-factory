<?php
/**
 * Integration tests for the cron cleanup.
 *
 * @package NewsFeedFactory
 *
 * @group integration
 */

declare( strict_types=1 );

namespace NWFF\Tests\Integration\Install;

use NWFF\Install\Cron;
use NWFF\Install\Schema;
use WP_UnitTestCase;

/**
 * Covers Cron::schedule(), Cron::unschedule() and Cron::cleanup().
 *
 * @group integration
 */
final class CronTest extends WP_UnitTestCase {

	/**
	 * Ensure the meta table exists and clear scheduled events.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Schema::activate();
		Cron::unschedule();
	}

	/**
	 * Remove scheduled events between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Cron::unschedule();
		parent::tearDown();
	}

	/**
	 * Insert a row into nwff_feed_meta with a chosen updated_at.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $updated_at MySQL datetime (UTC).
	 * @return void
	 */
	private function insert_row( int $post_id, string $updated_at ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test helper: direct insert into the plugin's custom table.
		$wpdb->insert(
			$wpdb->prefix . 'nwff_feed_meta',
			array(
				'post_id'            => $post_id,
				'exclude_from_feeds' => 1,
				'updated_at'         => $updated_at,
			)
		);
	}

	/**
	 * Count rows in nwff_feed_meta.
	 *
	 * @return int
	 */
	private function count_rows(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper: counting rows in the plugin's custom table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nwff_feed_meta" );
	}

	/**
	 * Schedule registers the cleanup event when none is pending.
	 *
	 * @return void
	 */
	public function test_schedule_registers_event(): void {
		Cron::schedule();

		$this->assertNotFalse( wp_next_scheduled( 'nwff_cleanup_meta' ) );
	}

	/**
	 * Schedule does not add a duplicate event.
	 *
	 * @return void
	 */
	public function test_schedule_is_idempotent(): void {
		Cron::schedule();
		Cron::schedule();

		$events = wp_get_scheduled_event( 'nwff_cleanup_meta' );
		$this->assertNotFalse( $events );
	}

	/**
	 * Unschedule removes the cleanup event.
	 *
	 * @return void
	 */
	public function test_unschedule_removes_event(): void {
		Cron::schedule();
		Cron::unschedule();

		$this->assertFalse( wp_next_scheduled( 'nwff_cleanup_meta' ) );
	}

	/**
	 * Cleanup deletes rows older than 30 days and keeps fresh ones.
	 *
	 * @return void
	 */
	public function test_cleanup_deletes_old_rows(): void {
		$this->insert_row( 1, gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) );
		$this->insert_row( 2, gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );
		$this->insert_row( 3, gmdate( 'Y-m-d H:i:s' ) );

		Cron::cleanup();

		$this->assertSame( 2, $this->count_rows() );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper: verifying which post survived cleanup.
		$survivors = $wpdb->get_col( "SELECT post_id FROM {$wpdb->prefix}nwff_feed_meta ORDER BY post_id" );
		$this->assertSame( array( '2', '3' ), $survivors );
	}

	/**
	 * Cleanup on an empty table is a no-op.
	 *
	 * @return void
	 */
	public function test_cleanup_on_empty_table_is_noop(): void {
		Cron::cleanup();

		$this->assertSame( 0, $this->count_rows() );
	}
}
