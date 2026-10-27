<?php
/**
 * Unit tests for the renderer interface contract.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Tests\Unit\Render;

use NWFF\Render\RendererInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Verifies RendererInterface shape per ADR-ARCH-0001.
 */
final class RendererInterfaceTest extends TestCase {

	/**
	 * The interface is declared.
	 *
	 * @return void
	 */
	public function test_interface_is_declared(): void {
		$this->assertTrue( interface_exists( RendererInterface::class ) );
	}

	/**
	 * Slug getter returns a string and takes no arguments.
	 *
	 * @return void
	 */
	public function test_get_slug_signature(): void {
		$method = new ReflectionMethod( RendererInterface::class, 'get_slug' );

		$this->assertSame( 0, $method->getNumberOfParameters() );
		$this->assertSame( 'string', (string) $method->getReturnType() );
	}

	/**
	 * Content type getter returns a string and takes no arguments.
	 *
	 * @return void
	 */
	public function test_get_content_type_signature(): void {
		$method = new ReflectionMethod( RendererInterface::class, 'get_content_type' );

		$this->assertSame( 0, $method->getNumberOfParameters() );
		$this->assertSame( 'string', (string) $method->getReturnType() );
	}

	/**
	 * Normalization config getter returns an array and takes no arguments.
	 *
	 * @return void
	 */
	public function test_get_normalization_config_signature(): void {
		$method = new ReflectionMethod( RendererInterface::class, 'get_normalization_config' );

		$this->assertSame( 0, $method->getNumberOfParameters() );
		$this->assertSame( 'array', (string) $method->getReturnType() );
	}

	/**
	 * Render takes two array arguments and returns a string.
	 *
	 * @return void
	 */
	public function test_render_signature(): void {
		$method = new ReflectionMethod( RendererInterface::class, 'render' );
		$params = $method->getParameters();

		$this->assertSame( 2, $method->getNumberOfParameters() );
		$this->assertSame( 'array', (string) $params[0]->getType() );
		$this->assertSame( 'array', (string) $params[1]->getType() );
		$this->assertSame( 'string', (string) $method->getReturnType() );
	}

	/**
	 * The interface exposes exactly eight methods.
	 *
	 * @return void
	 */
	public function test_interface_method_count(): void {
		$reflection = new ReflectionClass( RendererInterface::class );

		$this->assertCount( 8, $reflection->getMethods() );
	}

	/**
	 * Settings schema getter returns an array and takes no arguments.
	 *
	 * @return void
	 */
	public function test_get_settings_schema_signature(): void {
		$method = new ReflectionMethod( RendererInterface::class, 'get_settings_schema' );

		$this->assertSame( 0, $method->getNumberOfParameters() );
		$this->assertSame( 'array', (string) $method->getReturnType() );
	}

	/**
	 * Enabled predicate takes an array and returns a bool.
	 *
	 * @return void
	 */
	public function test_is_enabled_signature(): void {
		$method = new ReflectionMethod( RendererInterface::class, 'is_enabled' );
		$params = $method->getParameters();

		$this->assertSame( 1, $method->getNumberOfParameters() );
		$this->assertSame( 'array', (string) $params[0]->getType() );
		$this->assertSame( 'bool', (string) $method->getReturnType() );
	}

	/**
	 * Collector args getter takes an array and returns an array.
	 *
	 * @return void
	 */
	public function test_get_collector_args_signature(): void {
		$method = new ReflectionMethod( RendererInterface::class, 'get_collector_args' );
		$params = $method->getParameters();

		$this->assertSame( 1, $method->getNumberOfParameters() );
		$this->assertSame( 'array', (string) $params[0]->getType() );
		$this->assertSame( 'array', (string) $method->getReturnType() );
	}

	/**
	 * Default feed path takes a string and returns a string.
	 *
	 * @return void
	 */
	public function test_get_default_feed_path_signature(): void {
		$method = new ReflectionMethod( RendererInterface::class, 'get_default_feed_path' );
		$params = $method->getParameters();

		$this->assertSame( 1, $method->getNumberOfParameters() );
		$this->assertSame( 'string', (string) $params[0]->getType() );
		$this->assertSame( 'string', (string) $method->getReturnType() );
	}
}
