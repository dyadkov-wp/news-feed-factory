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
	 * The interface exposes exactly four methods.
	 *
	 * @return void
	 */
	public function test_interface_method_count(): void {
		$reflection = new ReflectionClass( RendererInterface::class );

		$this->assertCount( 4, $reflection->getMethods() );
	}
}
