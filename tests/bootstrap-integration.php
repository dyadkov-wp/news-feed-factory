<?php
/**
 * PHPUnit bootstrap for News Feed Factory.
 *
 * Loads the WordPress test suite, registers the plugin as a mu-plugin
 * so it is active during tests, and hands control to the WordPress
 * PHPUnit bootstrap.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/var/www/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/news-feed-factory.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
