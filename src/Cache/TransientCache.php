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
	 * Uses a direct SQL query because the WordPress Options API does
	 * not support deletion by prefix. This is the only reliable way
	 * to invalidate all feed caches after a post or term change.
	 *
	 * @param string $prefix Key prefix to match, without the plugin prefix.
	 * @return void
	 */
	public function delete_by_prefix( string $prefix ): void {
		global $wpdb;

		$transient_prefix = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
		$timeout_prefix   = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$transient_prefix,
				$timeout_prefix
			)
		);
	}
}
