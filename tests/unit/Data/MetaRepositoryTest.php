<?php
/**
 * Unit tests for the feed metadata repository.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Tests\Unit\Data;

use NWFF\Data\MetaRepository;
use PHPUnit\Framework\TestCase;
use stdClass;

require_once __DIR__ . '/FakeWpdb.php';

/**
 * Covers MetaRepository::get_by_post_ids() against a $wpdb double.
 */
final class MetaRepositoryTest extends TestCase {

	/**
	 * Remove the $wpdb double between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Install a $wpdb double for the current test.
	 *
	 * Writing to $GLOBALS['wpdb'] is normally forbidden by WPCS
	 * (WordPress.WP.GlobalVariablesOverride.Prohibited) to protect
	 * production code. Unit tests deliberately swap the global for
	 * a double, so the assignment is centralised here with a
	 * scoped ignore rather than repeated at every call site.
	 *
	 * @param object $wpdb Double to install.
	 * @return void
	 */
	private function install_wpdb( object $wpdb ): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double; see method docblock.
		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Build a $wpdb double preloaded with result pages.
	 *
	 * @param array<int, array<int, stdClass>> $pages Pages of rows.
	 * @return FakeWpdb
	 */
	private function fake_wpdb( array $pages = array() ): FakeWpdb {
		$wpdb        = new FakeWpdb();
		$wpdb->pages = $pages;
		return $wpdb;
	}

	/**
	 * Build a single result row.
	 *
	 * @param int    $post_id    Post ID.
	 * @param int    $exclude    exclude_from_feeds raw value.
	 * @param string $updated_at MySQL datetime.
	 * @return stdClass
	 */
	private function row( int $post_id, int $exclude, string $updated_at ): stdClass {
		$row                     = new stdClass();
		$row->post_id            = (string) $post_id;
		$row->exclude_from_feeds = (string) $exclude;
		$row->updated_at         = $updated_at;
		return $row;
	}

	/**
	 * Empty input short-circuits without touching the database.
	 *
	 * @return void
	 */
	public function test_empty_input_returns_empty_array(): void {
		$wpdb = $this->fake_wpdb();
		$this->install_wpdb( $wpdb );

		$result = ( new MetaRepository() )->get_by_post_ids( array() );

		$this->assertSame( array(), $result );
		$this->assertSame( array(), $wpdb->queries );
		$this->assertSame( array(), $wpdb->prepare_args );
	}

	/**
	 * A single ID returns its metadata row keyed by post ID.
	 *
	 * @return void
	 */
	public function test_single_id_returns_meta(): void {
		$wpdb = $this->fake_wpdb(
			array(
				array( $this->row( 42, 0, '2026-10-13 12:00:00' ) ),
			)
		);
		$this->install_wpdb( $wpdb );

		$result = ( new MetaRepository() )->get_by_post_ids( array( 42 ) );

		$this->assertSame(
			array(
				42 => array(
					'exclude_from_feeds' => false,
					'updated_at'         => '2026-10-13 12:00:00',
				),
			),
			$result
		);
		$this->assertCount( 1, $wpdb->queries );
	}

	/**
	 * Multiple IDs return all matching rows.
	 *
	 * @return void
	 */
	public function test_multiple_ids_returns_all_meta(): void {
		$wpdb = $this->fake_wpdb(
			array(
				array(
					$this->row( 1, 0, '2026-10-13 10:00:00' ),
					$this->row( 2, 1, '2026-10-13 11:00:00' ),
					$this->row( 3, 0, '2026-10-13 12:00:00' ),
				),
			)
		);
		$this->install_wpdb( $wpdb );

		$result = ( new MetaRepository() )->get_by_post_ids( array( 1, 2, 3 ) );

		$this->assertCount( 3, $result );
		$this->assertFalse( $result[1]['exclude_from_feeds'] );
		$this->assertTrue( $result[2]['exclude_from_feeds'] );
		$this->assertFalse( $result[3]['exclude_from_feeds'] );
		$this->assertCount( 1, $wpdb->queries );
	}

	/**
	 * IDs without a matching row are absent from the result.
	 *
	 * @return void
	 */
	public function test_missing_ids_are_skipped(): void {
		$wpdb = $this->fake_wpdb(
			array(
				array( $this->row( 1, 0, '2026-10-13 10:00:00' ) ),
			)
		);
		$this->install_wpdb( $wpdb );

		$result = ( new MetaRepository() )->get_by_post_ids( array( 1, 2 ) );

		$this->assertArrayHasKey( 1, $result );
		$this->assertArrayNotHasKey( 2, $result );
	}

	/**
	 * Non-positive, non-integer and non-numeric IDs are filtered out.
	 *
	 * @return void
	 */
	public function test_non_positive_ids_are_filtered_out(): void {
		$wpdb = $this->fake_wpdb(
			array(
				array(
					$this->row( 5, 0, '2026-10-13 10:00:00' ),
					$this->row( 7, 0, '2026-10-13 10:00:00' ),
				),
			)
		);
		$this->install_wpdb( $wpdb );

		$result = ( new MetaRepository() )->get_by_post_ids(
			array( 0, -1, 5, 'abc', 5.5, '7' )
		);

		$this->assertArrayHasKey( 5, $result );
		$this->assertArrayHasKey( 7, $result );
		$this->assertCount( 1, $wpdb->queries );
		$this->assertCount( 2, $wpdb->prepare_args[0] );
	}

	/**
	 * Duplicate IDs collapse to a single placeholder.
	 *
	 * @return void
	 */
	public function test_duplicate_ids_are_deduplicated(): void {
		$wpdb = $this->fake_wpdb(
			array( array( $this->row( 1, 0, '2026-10-13 10:00:00' ) ) )
		);
		$this->install_wpdb( $wpdb );

		( new MetaRepository() )->get_by_post_ids( array( 1, 1, 1 ) );

		$this->assertCount( 1, $wpdb->prepare_args[0] );
	}

	/**
	 * The exclude flag is cast from int-like strings to bool.
	 *
	 * @return void
	 */
	public function test_exclude_flag_is_cast_to_bool(): void {
		$wpdb = $this->fake_wpdb(
			array(
				array(
					$this->row( 1, 0, '2026-10-13 10:00:00' ),
					$this->row( 2, 1, '2026-10-13 10:00:00' ),
				),
			)
		);
		$this->install_wpdb( $wpdb );

		$result = ( new MetaRepository() )->get_by_post_ids( array( 1, 2 ) );

		$this->assertFalse( $result[1]['exclude_from_feeds'] );
		$this->assertTrue( $result[2]['exclude_from_feeds'] );
	}

	/**
	 * Input over 500 IDs is split into multiple queries.
	 *
	 * @return void
	 */
	public function test_more_than_500_ids_are_chunked(): void {
		$wpdb = $this->fake_wpdb( array( array(), array() ) );
		$this->install_wpdb( $wpdb );

		( new MetaRepository() )->get_by_post_ids( range( 1, 501 ) );

		$this->assertCount( 2, $wpdb->queries );
		$this->assertCount( 2, $wpdb->prepare_args );
		$this->assertCount( 500, $wpdb->prepare_args[0] );
		$this->assertCount( 1, $wpdb->prepare_args[1] );
	}

	/**
	 * Exactly 500 IDs fit in a single chunk.
	 *
	 * @return void
	 */
	public function test_exactly_500_ids_are_one_chunk(): void {
		$wpdb = $this->fake_wpdb( array( array() ) );
		$this->install_wpdb( $wpdb );

		( new MetaRepository() )->get_by_post_ids( range( 1, 500 ) );

		$this->assertCount( 1, $wpdb->queries );
	}
	/**
	 * Deletes a row by post ID with a single table-scoped call.
	 *
	 * @return void
	 */
	public function test_delete_by_post_id_uses_delete_with_where(): void {
		$wpdb = $this->fake_wpdb();
		$this->install_wpdb( $wpdb );

		( new MetaRepository() )->delete_by_post_id( 42 );

		$this->assertCount( 1, $wpdb->deletes );
		$this->assertSame( 'wp_nwff_feed_meta', $wpdb->deletes[0]['table'] );
		$this->assertSame( array( 'post_id' => 42 ), $wpdb->deletes[0]['where'] );
		$this->assertSame( array( '%d' ), $wpdb->deletes[0]['format'] );
	}

	/**
	 * Deletes rows older than the given datetime.
	 *
	 * @return void
	 */
	public function test_delete_older_than_queries_by_threshold(): void {
		$wpdb = $this->fake_wpdb();
		$this->install_wpdb( $wpdb );

		$threshold = '2026-09-16 12:00:00';
		$deleted   = ( new MetaRepository() )->delete_older_than( $threshold );

		$this->assertSame( 1, $deleted );
		$this->assertCount( 1, $wpdb->query_calls );
		$this->assertStringContainsString( 'DELETE FROM wp_nwff_feed_meta', $wpdb->query_calls[0] );
		$this->assertStringContainsString( 'updated_at <', $wpdb->query_calls[0] );
		$this->assertSame( array( $threshold ), $wpdb->prepare_args[0] );
	}

	/**
	 * Save replaces the row with exclude = 1.
	 *
	 * @return void
	 */
	public function test_save_stores_exclude_true(): void {
		$wpdb = $this->fake_wpdb();
		$this->install_wpdb( $wpdb );

		( new MetaRepository() )->save( 42, true );

		$this->assertCount( 1, $wpdb->replaces );
		$call = $wpdb->replaces[0];

		$this->assertSame( 'wp_nwff_feed_meta', $call['table'] );
		$this->assertSame( 42, $call['data']['post_id'] );
		$this->assertSame( 1, $call['data']['exclude_from_feeds'] );
		$this->assertArrayHasKey( 'updated_at', $call['data'] );
		$this->assertSame( array( '%d', '%d', '%s' ), $call['format'] );
	}

	/**
	 * Save replaces the row with exclude = 0.
	 *
	 * @return void
	 */
	public function test_save_stores_exclude_false(): void {
		$wpdb = $this->fake_wpdb();
		$this->install_wpdb( $wpdb );

		( new MetaRepository() )->save( 7, false );

		$this->assertCount( 1, $wpdb->replaces );
		$this->assertSame( 0, $wpdb->replaces[0]['data']['exclude_from_feeds'] );
	}
}
