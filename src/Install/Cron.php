<?php
/**
 * Scheduled meta cleanup.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Install;

use NWFF\Data\MetaRepository;

/**
 * Registers and handles the daily feed-meta cleanup
 * (ADR-ARCH-0001).
 *
 * The scheduled event is registered on activation and removed on
 * deactivation. Retention is a fixed 30-day window: rows older than
 * that cannot appear in any feed (maximum depth is 12 hours).
 */
final class Cron {

	/**
	 * Scheduled event hook name.
	 */
	public const HOOK = 'nwff_cleanup_meta';

	/**
	 * Retention window in seconds.
	 */
	private const RETENTION_SECONDS = 30 * DAY_IN_SECONDS;

	/**
	 * Register the cleanup event if it is not already scheduled.
	 *
	 * Called on plugin activation. Idempotent: a second call while
	 * an event is pending is a no-op.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_event( time(), 'daily', self::HOOK );
	}

	/**
	 * Remove the cleanup event.
	 *
	 * Called on plugin deactivation.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Delete feed-meta rows older than the retention window.
	 *
	 * Invoked by WP-Cron on the nwff_cleanup_meta hook. Logging of
	 * failures is deferred to the general logging work (BACKLOG
	 * TD-06).
	 *
	 * @return void
	 */
	public static function cleanup(): void {
		$threshold = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_SECONDS );

		( new MetaRepository() )->delete_older_than( $threshold );
	}
}
