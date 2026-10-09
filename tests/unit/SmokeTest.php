<?php
/**
 * Smoke test to verify PHPUnit and autoloading work.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Tests\Unit;

use NWFF\Cache\TransientCache;
use NWFF\Install\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the PHPUnit infrastructure and the plugin autoloader
 * are wired up correctly.
 */
final class SmokeTest extends TestCase {

	/**
	 * The PSR-4 autoloader resolves plugin classes.
	 *
	 * @return void
	 */
	public function test_autoloader_resolves_plugin_classes(): void {
		$this->assertTrue( class_exists( TransientCache::class ) );
		$this->assertTrue( class_exists( Schema::class ) );
	}
}
