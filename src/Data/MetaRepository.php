<?php
/**
 * Feed metadata repository.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Data;

/**
 * Reads and writes rows of the custom feed metadata table
 * (ADR-ARCH-0001).
 *
 * No top-level side effects; see ADR-PROC-0005.
 */
final class MetaRepository {

	/**
	 * Maximum number of post IDs per SQL query.
	 *
	 * Keeps the IN (...) list short enough for MySQL to plan and
	 * execute efficiently, and well below max_allowed_packet.
	 */
	private const CHUNK_SIZE = 500;

	/**
	 * Table suffix without the WordPress prefix.
	 */
	private const TABLE_SUFFIX = 'nwff_feed_meta';

	/**
	 * Fetch metadata for the given post IDs.
	 *
	 * @param array<int, mixed> $post_ids Post IDs (ints or numeric strings).
	 * @return array<int, array{exclude_from_feeds: bool, updated_at: string}>
	 *         Keyed by post ID. Missing IDs are absent from the result.
	 */
	/**
	 * Fetch metadata for the given post IDs.
	 *
	 * @param array<int, mixed> $post_ids Post IDs (ints or numeric strings).
	 * @return array<int, array{exclude_from_feeds: bool, updated_at: string}>
	 *         Keyed by post ID. Missing IDs are absent from the result.
	 */
	public function get_by_post_ids( array $post_ids ): array {
		$ids = $this->normalize_ids( $post_ids );
		if ( array() === $ids ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$out   = array();

		foreach ( array_chunk( $ids, self::CHUNK_SIZE ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$sql          = "SELECT post_id, exclude_from_feeds, updated_at FROM {$table} WHERE post_id IN ({$placeholders})";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built above with %d placeholders, one per ID, and passed through $wpdb->prepare() on the next line.
			$prepared = $wpdb->prepare( $sql, ...$chunk );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Custom table; no WP API for a batched read. Result caching is handled by TransientCache upstream (ADR-ARCH-0001).
			$rows = $wpdb->get_results( $prepared );

			foreach ( $rows as $row ) {
				$row = (array) $row;
				$id  = (int) $row['post_id'];

				$out[ $id ] = array(
					'exclude_from_feeds' => (bool) (int) $row['exclude_from_feeds'],
					'updated_at'         => (string) $row['updated_at'],
				);
			}
		}

		return $out;
	}

	/**
	 * Deletes the metadata row for the given post ID.
	 *
	 * Called on deleted_post; missing rows are a no-op.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function delete_by_post_id( int $post_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; no WP API for a single-row delete.
		$wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * Deletes rows older than the given UTC datetime.
	 *
	 * Called by the daily cron cleanup with a threshold of
	 * "now minus retention window" (ADR-ARCH-0001).
	 *
	 * @param string $threshold MySQL datetime in UTC.
	 * @return int Number of rows deleted.
	 */
	public function delete_older_than( string $threshold ): int {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$sql   = "DELETE FROM {$table} WHERE updated_at < %s";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built above with a single %s placeholder and passed through $wpdb->prepare() on the next line.
		$prepared = $wpdb->prepare( $sql, $threshold );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Custom table; no WP API for a bulk delete. Result is not cached.
		$deleted = $wpdb->query( $prepared );

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Insert or update the feed-meta row for a post.
	 *
	 * Uses REPLACE: post_id is the primary key, so a single
	 * statement covers both cases. updated_at is refreshed on every
	 * save so an actively edited post never ages out of the table
	 * before the daily cron window (ADR-ARCH-0001).
	 *
	 * The timestamp is produced with gmdate, not current_time, so
	 * this method stays unit-testable without WordPress loaded. Both
	 * calls yield the same UTC string.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $exclude True to exclude the post from feeds.
	 * @return void
	 */
	public function save( int $post_id, bool $exclude ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; no WP API for a replace.
		$wpdb->replace(
			$table,
			array(
				'post_id'            => $post_id,
				'exclude_from_feeds' => $exclude ? 1 : 0,
				'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s' )
		);
	}

	/**
	 * Filter input to unique positive integers, preserving order.
	 *
	 * @param array<int, mixed> $post_ids Raw input.
	 * @return int[]
	 */
	private function normalize_ids( array $post_ids ): array {
		$clean = array();

		foreach ( $post_ids as $id ) {
			if ( is_int( $id ) ) {
				$value = $id;
			} elseif ( is_string( $id ) && ctype_digit( $id ) ) {
				$value = (int) $id;
			} else {
				continue;
			}

			if ( $value <= 0 ) {
				continue;
			}

			$clean[ $value ] = true;
		}

		return array_keys( $clean );
	}
}
