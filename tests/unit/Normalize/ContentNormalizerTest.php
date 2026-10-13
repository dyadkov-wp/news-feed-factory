<?php
/**
 * Unit tests for the content normalizer facade.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Tests\Unit\Normalize;

use DateTimeImmutable;
use NWFF\Normalize\ContentNormalizer;
use NWFF\Normalize\HtmlSanitizer;
use NWFF\Normalize\Truncator;
use PHPUnit\Framework\TestCase;

/**
 * Covers ContentNormalizer::normalize() across pipelines and edge cases.
 */
final class ContentNormalizerTest extends TestCase {

	/**
	 * Normalizer under test.
	 *
	 * @var ContentNormalizer
	 */
	private ContentNormalizer $normalizer;

	/**
	 * Set up the normalizer with real collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->normalizer = new ContentNormalizer(
			new HtmlSanitizer(),
			new Truncator()
		);
	}

	/**
	 * Build a fully populated raw item.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function raw( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => 'https://example.com/?p=1',
				'link'         => 'https://example.com/post/1',
				'title'        => 'Hello world',
				'content'      => '<p>Full body</p>',
				'excerpt'      => '<p>Short teaser</p>',
				'published'    => '2026-10-13 12:00:00',
				'image_url'    => null,
				'image_width'  => null,
				'image_height' => null,
				'terms'        => array(),
			),
			$overrides
		);
	}

	/**
	 * Build a config array with defaults.
	 *
	 * @param array<string, mixed> $overrides Config overrides.
	 * @return array<string, mixed>
	 */
	private function config( array $overrides = array() ): array {
		return array_merge(
			array(
				'truncate_mode'  => 'none',
				'truncate_limit' => 0,
				'include_terms'  => false,
			),
			$overrides
		);
	}

	/**
	 * A fully populated raw item normalizes to the expected array.
	 *
	 * @return void
	 */
	public function test_happy_path_returns_normalized_array(): void {
		$result = $this->normalizer->normalize( $this->raw(), $this->config() );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/?p=1', $result['id'] );
		$this->assertSame( 'https://example.com/post/1', $result['link'] );
		$this->assertSame( 'Hello world', $result['title'] );
		$this->assertSame( '<p>Full body</p>', $result['content'] );
		$this->assertSame( 'Short teaser', $result['excerpt'] );
		$this->assertInstanceOf( DateTimeImmutable::class, $result['published'] );
		$this->assertNull( $result['image_url'] );
		$this->assertNull( $result['image_width'] );
		$this->assertNull( $result['image_height'] );
		$this->assertSame( array(), $result['terms'] );
	}

	/**
	 * Empty id skips the record.
	 *
	 * @return void
	 */
	public function test_empty_id_returns_null(): void {
		$this->assertNull(
			$this->normalizer->normalize( $this->raw( array( 'id' => '' ) ), $this->config() )
		);
	}

	/**
	 * Missing id key skips the record.
	 *
	 * @return void
	 */
	public function test_missing_id_returns_null(): void {
		$raw = $this->raw();
		unset( $raw['id'] );

		$this->assertNull( $this->normalizer->normalize( $raw, $this->config() ) );
	}

	/**
	 * Non-string id skips the record.
	 *
	 * @return void
	 */
	public function test_non_string_id_returns_null(): void {
		$this->assertNull(
			$this->normalizer->normalize( $this->raw( array( 'id' => 123 ) ), $this->config() )
		);
	}

	/**
	 * Empty link skips the record.
	 *
	 * @return void
	 */
	public function test_empty_link_returns_null(): void {
		$this->assertNull(
			$this->normalizer->normalize( $this->raw( array( 'link' => '' ) ), $this->config() )
		);
	}

	/**
	 * Empty title skips the record.
	 *
	 * @return void
	 */
	public function test_empty_title_returns_null(): void {
		$this->assertNull(
			$this->normalizer->normalize( $this->raw( array( 'title' => '' ) ), $this->config() )
		);
	}

	/**
	 * Empty published skips the record.
	 *
	 * @return void
	 */
	public function test_empty_published_returns_null(): void {
		$this->assertNull(
			$this->normalizer->normalize( $this->raw( array( 'published' => '' ) ), $this->config() )
		);
	}

	/**
	 * MySQL zero date skips the record.
	 *
	 * @return void
	 */
	public function test_zero_datetime_published_returns_null(): void {
		$this->assertNull(
			$this->normalizer->normalize(
				$this->raw( array( 'published' => '0000-00-00 00:00:00' ) ),
				$this->config()
			)
		);
	}

	/**
	 * Missing published key skips the record.
	 *
	 * @return void
	 */
	public function test_missing_published_returns_null(): void {
		$raw = $this->raw();
		unset( $raw['published'] );

		$this->assertNull( $this->normalizer->normalize( $raw, $this->config() ) );
	}

	/**
	 * Unparseable published skips the record.
	 *
	 * @return void
	 */
	public function test_invalid_published_returns_null(): void {
		$this->assertNull(
			$this->normalizer->normalize(
				$this->raw( array( 'published' => 'not a date' ) ),
				$this->config()
			)
		);
	}

	/**
	 * A valid published string parses as a UTC DateTimeImmutable.
	 *
	 * @return void
	 */
	public function test_published_parsed_as_utc(): void {
		$result = $this->normalizer->normalize(
			$this->raw( array( 'published' => '2026-10-13 12:00:00' ) ),
			$this->config()
		);

		$this->assertInstanceOf( DateTimeImmutable::class, $result['published'] );
		$this->assertSame( 'UTC', $result['published']->getTimezone()->getName() );
		$this->assertSame( '2026-10-13 12:00:00', $result['published']->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Title entities are decoded.
	 *
	 * @return void
	 */
	public function test_title_entities_decoded(): void {
		$result = $this->normalizer->normalize(
			$this->raw( array( 'title' => 'Tom &amp; Jerry' ) ),
			$this->config()
		);

		$this->assertSame( 'Tom & Jerry', $result['title'] );
	}

	/**
	 * Title is trimmed of surrounding whitespace.
	 *
	 * @return void
	 */
	public function test_title_trimmed(): void {
		$result = $this->normalizer->normalize(
			$this->raw( array( 'title' => '   Hello   ' ) ),
			$this->config()
		);

		$this->assertSame( 'Hello', $result['title'] );
	}

	/**
	 * Content is passed through unchanged (already sanitized upstream).
	 *
	 * @return void
	 */
	public function test_content_kept_as_html(): void {
		$result = $this->normalizer->normalize(
			$this->raw( array( 'content' => '<p>Full <b>body</b> here</p>' ) ),
			$this->config()
		);

		$this->assertSame( '<p>Full <b>body</b> here</p>', $result['content'] );
	}

	/**
	 * Excerpt is converted to plain text.
	 *
	 * @return void
	 */
	public function test_excerpt_plain_text_without_tags(): void {
		$result = $this->normalizer->normalize(
			$this->raw( array( 'excerpt' => '<p>Hello <b>world</b> &amp; friends</p>' ) ),
			$this->config()
		);

		$this->assertSame( 'Hello world & friends', $result['excerpt'] );
	}

	/**
	 * Excerpt is truncated when chars mode is configured.
	 *
	 * @return void
	 */
	public function test_excerpt_truncated_with_chars_mode(): void {
		$result = $this->normalizer->normalize(
			$this->raw( array( 'excerpt' => '<p>alpha beta gamma delta</p>' ) ),
			$this->config(
				array(
					'truncate_mode'  => 'chars',
					'truncate_limit' => 12,
				)
			)
		);

		$this->assertSame( 'alpha beta', $result['excerpt'] );
	}

	/**
	 * Excerpt is not truncated when none mode is configured.
	 *
	 * @return void
	 */
	public function test_excerpt_not_truncated_with_none_mode(): void {
		$result = $this->normalizer->normalize(
			$this->raw( array( 'excerpt' => '<p>alpha beta gamma delta</p>' ) ),
			$this->config(
				array(
					'truncate_mode'  => 'none',
					'truncate_limit' => 5,
				)
			)
		);

		$this->assertSame( 'alpha beta gamma delta', $result['excerpt'] );
	}

	/**
	 * Terms are dropped when include_terms is false.
	 *
	 * @return void
	 */
	public function test_include_terms_false_returns_empty_array(): void {
		$terms = array(
			array(
				'taxonomy' => 'category',
				'id'       => 12,
				'name'     => 'Politics',
				'slug'     => 'politics',
			),
		);

		$result = $this->normalizer->normalize(
			$this->raw( array( 'terms' => $terms ) ),
			$this->config( array( 'include_terms' => false ) )
		);

		$this->assertSame( array(), $result['terms'] );
	}

	/**
	 * Terms are preserved when include_terms is true.
	 *
	 * @return void
	 */
	public function test_include_terms_true_preserves_terms(): void {
		$terms = array(
			array(
				'taxonomy' => 'category',
				'id'       => 12,
				'name'     => 'Politics',
				'slug'     => 'politics',
			),
			array(
				'taxonomy' => 'post_tag',
				'id'       => 686,
				'name'     => 'Interview',
				'slug'     => 'interview',
			),
		);

		$result = $this->normalizer->normalize(
			$this->raw( array( 'terms' => $terms ) ),
			$this->config( array( 'include_terms' => true ) )
		);

		$this->assertSame( $terms, $result['terms'] );
	}

	/**
	 * Missing terms default to an empty array.
	 *
	 * @return void
	 */
	public function test_missing_terms_defaults_to_empty_array(): void {
		$raw = $this->raw();
		unset( $raw['terms'] );

		$result = $this->normalizer->normalize(
			$raw,
			$this->config( array( 'include_terms' => true ) )
		);

		$this->assertSame( array(), $result['terms'] );
	}

	/**
	 * Image fields pass through when present.
	 *
	 * @return void
	 */
	public function test_image_fields_preserved(): void {
		$result = $this->normalizer->normalize(
			$this->raw(
				array(
					'image_url'    => 'https://example.com/image.jpg',
					'image_width'  => 1200,
					'image_height' => 800,
				)
			),
			$this->config()
		);

		$this->assertSame( 'https://example.com/image.jpg', $result['image_url'] );
		$this->assertSame( 1200, $result['image_width'] );
		$this->assertSame( 800, $result['image_height'] );
	}

	/**
	 * Missing image fields default to null.
	 *
	 * @return void
	 */
	public function test_missing_image_fields_default_to_null(): void {
		$raw = $this->raw();
		unset( $raw['image_url'], $raw['image_width'], $raw['image_height'] );

		$result = $this->normalizer->normalize( $raw, $this->config() );

		$this->assertNull( $result['image_url'] );
		$this->assertNull( $result['image_width'] );
		$this->assertNull( $result['image_height'] );
	}

	/**
	 * Empty content becomes an empty string.
	 *
	 * @return void
	 */
	public function test_empty_content_returns_empty_string(): void {
		$result = $this->normalizer->normalize(
			$this->raw( array( 'content' => '' ) ),
			$this->config()
		);

		$this->assertSame( '', $result['content'] );
	}

	/**
	 * Empty excerpt becomes an empty string.
	 *
	 * @return void
	 */
	public function test_empty_excerpt_returns_empty_string(): void {
		$result = $this->normalizer->normalize(
			$this->raw( array( 'excerpt' => '' ) ),
			$this->config()
		);

		$this->assertSame( '', $result['excerpt'] );
	}

	/**
	 * Missing excerpt key becomes an empty string.
	 *
	 * @return void
	 */
	public function test_missing_excerpt_returns_empty_string(): void {
		$raw = $this->raw();
		unset( $raw['excerpt'] );

		$result = $this->normalizer->normalize( $raw, $this->config() );

		$this->assertSame( '', $result['excerpt'] );
	}
}
