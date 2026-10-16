<?php
/**
 * Transient cache wrapper.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Cache;

/**
 * Thin wrapper around WordPress transients.
 *
 * Centralizes cache access so that key naming, TTL handling and
 * bulk invalidation live in one place. Callers work with plain
 * keys; the wrapper prepends the plugin prefix internally.
 */
final class TransientCache {

	/**
	 * Prefix shared by all feed cache keys.
	 *
	 * Referenced by both the producer (Endpoint) and the invalidator
	 * (Plugin) so the two never drift.
	 */
	public const FEED_PREFIX = 'nwff_feed_';

	/**
	 * Retrieves a cached value.
	 *
	 * @param string $key Cache key.
	 * @return mixed Cached value, or false if missing/expired.
	 */
	public function get( string $key ) {
		return get_transient( $key );
	}

	/**
	 * Stores a value in cache.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Time to live, in seconds.
	 * @return bool True on success, false on failure.
	 */
	public function set( string $key, $value, int $ttl ): bool {
		return set_transient( $key, $value, $ttl );
	}

	/**
	 * Deletes a single cached value.
	 *
	 * @param string $key Cache key.
	 * @return bool True on success, false on failure.
	 */
	public function delete( string $key ): bool {
		return delete_transient( $key );
	}

	/**
	 * Deletes all plugin transients whose key starts with a prefix.
	 *
	 * Goes through delete_transient() for every matching option so
	 * the WordPress object cache is evicted alongside the database
	 * row. A raw SQL DELETE leaves cached values in the 'transient'
	 * cache group, and the next get_transient() returns stale data
	 * until the cache expires.
	 *
	 * @param string $prefix Transient-name prefix, as it appears after "_transient_". Example: "nwff_feed_".
	 * @return void
	 */
	public function delete_by_prefix( string $prefix ): void {
		global $wpdb;

		$transient_prefix = $wpdb->esc_like( '_transient_' . $prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prefix scan has no Options API equivalent; the result feeds delete_transient(), which evicts the object cache.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$transient_prefix
			)
		);

		foreach ( $names as $option_name ) {
			$key = substr( (string) $option_name, strlen( '_transient_' ) );
			delete_transient( $key );
		}
	}
}
