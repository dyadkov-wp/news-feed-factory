<?php
/**
 * Integration tests for HtmlSanitizer::sanitize().
 *
 * @package NewsFeedFactory
 *
 * @group integration
 */

declare( strict_types=1 );

namespace NWFF\Tests\Integration\Normalize;

use NWFF\Normalize\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Covers HtmlSanitizer::sanitize() against wp_kses.
 *
 * @group integration
 */
final class HtmlSanitizerTest extends TestCase {

	/**
	 * Sanitizer under test.
	 *
	 * @var HtmlSanitizer
	 */
	private HtmlSanitizer $sanitizer;

	/**
	 * Set up the sanitizer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->sanitizer = new HtmlSanitizer();
	}

	/**
	 * Null whitelist applies the default post whitelist.
	 *
	 * @return void
	 */
	public function test_null_whitelist_keeps_paragraph(): void {
		$this->assertSame(
			'<p>Hello</p>',
			$this->sanitizer->sanitize( '<p>Hello</p>', null )
		);
	}

	/**
	 * Null whitelist keeps allowed inline formatting.
	 *
	 * @return void
	 */
	public function test_null_whitelist_keeps_bold(): void {
		$this->assertSame(
			'<b>Bold</b>',
			$this->sanitizer->sanitize( '<b>Bold</b>', null )
		);
	}

	/**
	 * Null whitelist removes disallowed tags.
	 *
	 * @return void
	 */
	public function test_null_whitelist_strips_script_tag(): void {
		$result = $this->sanitizer->sanitize( '<script>alert(1)</script>', null );

		$this->assertStringNotContainsString( '<script', $result );
		$this->assertStringNotContainsString( '</script>', $result );
	}

	/**
	 * Custom whitelist keeps an allowed block tag.
	 *
	 * @return void
	 */
	public function test_custom_whitelist_keeps_paragraph(): void {
		$this->assertSame(
			'<p>Hello</p>',
			$this->sanitizer->sanitize( '<p>Hello</p>', array( 'p' => array() ) )
		);
	}

	/**
	 * Custom whitelist strips a tag not listed.
	 *
	 * @return void
	 */
	public function test_custom_whitelist_strips_inline_tag(): void {
		$this->assertSame(
			'<p>Hi</p>',
			$this->sanitizer->sanitize(
				'<p><a href="#">Hi</a></p>',
				array( 'p' => array() )
			)
		);
	}

	/**
	 * Custom whitelist strips disallowed attributes.
	 *
	 * @return void
	 */
	public function test_custom_whitelist_strips_disallowed_attributes(): void {
		$this->assertSame(
			'<a href="#">Hi</a>',
			$this->sanitizer->sanitize(
				'<a href="#" class="x">Hi</a>',
				array( 'a' => array( 'href' => true ) )
			)
		);
	}

	/**
	 * Empty whitelist strips all tags, keeping text.
	 *
	 * @return void
	 */
	public function test_empty_whitelist_strips_all_tags(): void {
		$this->assertSame(
			'Hello',
			$this->sanitizer->sanitize( '<p>Hello</p>', array() )
		);
	}

	/**
	 * Empty string input returns empty string.
	 *
	 * @return void
	 */
	public function test_empty_input_returns_empty_string(): void {
		$this->assertSame( '', $this->sanitizer->sanitize( '', null ) );
	}

	/**
	 * Plain text without tags passes through unchanged.
	 *
	 * @return void
	 */
	public function test_plain_text_passes_through(): void {
		$this->assertSame(
			'Hello world',
			$this->sanitizer->sanitize( 'Hello world', null )
		);
	}
}
