<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * Loads only the Composer autoloader. WordPress is not loaded.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
