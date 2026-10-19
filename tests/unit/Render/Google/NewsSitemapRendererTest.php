<?php
/**
 * Unit tests for the Google News sitemap renderer.
 *
 * @package NWFF
 */

declare( strict_types=1 );

namespace NWFF\Tests\Unit\Render\Google;

use DateTimeImmutable;
use NWFF\Render\Google\NewsSitemapRenderer;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Tests for the Google News sitemap renderer.
 *
 * @covers \NWFF\Render\Google\NewsSitemapRenderer
 */
final class NewsSitemapRendererTest extends TestCase {

	private const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	private const NS_NEWS    = 'http://www.google.com/schemas/sitemap-news/0.9';

	/**
	 * Build a renderer wired with default settings for most tests.
	 *
	 * @param \Closure|null $logger Optional logger.
	 */
	private function make_renderer( ?\Closure $logger = null ): NewsSitemapRenderer {
		return new NewsSitemapRenderer(
			static fn(): array => array(
				'renderers' => array(
					'google-news' => array(
						'news_publication_name' => 'Областная газета',
						'news_language'         => 'ru',
					),
				),
			),
			$logger
		);
	}

	/**
	 * Tests that get_slug() returns the expected slug.
	 */
	public function test_get_slug(): void {
		$this->assertSame( 'google-news', $this->make_renderer()->get_slug() );
	}

	/**
	 * Tests that get_content_type() returns XML.
	 */
	public function test_get_content_type(): void {
		$this->assertSame( 'application/xml; charset=UTF-8', $this->make_renderer()->get_content_type() );
	}

	/**
	 * Tests that the normalization config disables content and terms.
	 */
	public function test_normalization_config(): void {
		$config = $this->make_renderer()->get_normalization_config();
		$this->assertSame( 'none', $config['truncate_mode'] );
		$this->assertSame( 0, $config['truncate_limit'] );
		$this->assertNull( $config['max_content_length'] );
		$this->assertNull( $config['allowed_html'] );
		$this->assertNull( $config['image_min_width'] );
		$this->assertNull( $config['image_min_height'] );
		$this->assertFalse( $config['include_terms'] );
	}

	/**
	 * Tests that the settings schema exposes all required keys.
	 */
	public function test_settings_schema_has_required_keys(): void {
		$schema = $this->make_renderer()->get_settings_schema();
		foreach ( array( 'enabled', 'news_publication_name', 'news_language', 'include_taxonomies', 'exclude_taxonomies' ) as $key ) {
			$this->assertArrayHasKey( $key, $schema, "Missing schema key: {$key}" );
		}
		$this->assertSame( 'checkbox', $schema['enabled']['type'] );
		$this->assertFalse( $schema['enabled']['default'] );
	}

	/**
	 * Tests that is_enabled() defaults to false when no settings provided.
	 */
	public function test_is_enabled_default_false(): void {
		$this->assertFalse( $this->make_renderer()->is_enabled( array( 'renderers' => array() ) ) );
	}

	/**
	 * Tests that is_enabled() returns true when the renderer is enabled.
	 */
	public function test_is_enabled_true(): void {
		$settings = array( 'renderers' => array( 'google-news' => array( 'enabled' => true ) ) );
		$this->assertTrue( $this->make_renderer()->is_enabled( $settings ) );
	}

	/**
	 * Tests that is_enabled() returns false when the renderer is disabled.
	 */
	public function test_is_enabled_false(): void {
		$settings = array( 'renderers' => array( 'google-news' => array( 'enabled' => false ) ) );
		$this->assertFalse( $this->make_renderer()->is_enabled( $settings ) );
	}

	/**
	 * Tests that get_collector_args() defaults depth_hours to 4.
	 */
	public function test_collector_args_depth_hours_default(): void {
		$args = $this->make_renderer()->get_collector_args( array( 'core' => array() ) );
		$this->assertSame( 4, $args['depth_hours'] );
	}

	/**
	 * Tests that get_collector_args() reads depth_hours from core settings.
	 */
	public function test_collector_args_depth_hours_from_core(): void {
		$args = $this->make_renderer()->get_collector_args( array( 'core' => array( 'depth_hours' => 8 ) ) );
		$this->assertSame( 8, $args['depth_hours'] );
	}

	/**
	 * Tests that get_collector_args() hardcodes limit to 1000.
	 */
	public function test_collector_args_limit_is_hardcoded(): void {
		$args = $this->make_renderer()->get_collector_args( array( 'core' => array( 'depth_hours' => 4 ) ) );
		$this->assertSame( 1000, $args['limit'] );
	}

	/**
	 * Tests that taxonomy strings are parsed and garbage is rejected.
	 */
	public function test_collector_args_taxonomies_parsed(): void {
		$settings = array(
			'core'      => array( 'depth_hours' => 4 ),
			'renderers' => array(
				'google-news' => array(
					'include_taxonomies' => 'category:12,15|post_tag:100',
					'exclude_taxonomies' => 'category:999,abc,0,-1',
				),
			),
		);
		$args     = $this->make_renderer()->get_collector_args( $settings );
		$this->assertSame(
			array(
				'category' => array( 12, 15 ),
				'post_tag' => array( 100 ),
			),
			$args['taxonomy_include']
		);
		$this->assertSame( array( 'category' => array( 999 ) ), $args['taxonomy_exclude'] );
	}

	/**
	 * Tests that empty taxonomy settings yield empty arrays.
	 */
	public function test_collector_args_taxonomies_empty(): void {
		$args = $this->make_renderer()->get_collector_args(
			array(
				'core'      => array(),
				'renderers' => array(),
			)
		);
		$this->assertSame( array(), $args['taxonomy_include'] );
		$this->assertSame( array(), $args['taxonomy_exclude'] );
	}

	/**
	 * Tests that an empty item set yields a valid urlset with both namespaces.
	 */
	public function test_render_empty_is_valid_urlset_with_namespaces(): void {
		$xml = $this->make_renderer()->render( array(), array() );

		$doc = $this->load_xml( $xml );
		$this->assertSame( 'urlset', $doc->getName() );
		$this->assertCount( 0, $doc->url );

		// Both namespace declarations must be present in the raw XML even
		// when the set is empty. SimpleXML drops unused prefixed namespaces
		// from getNamespaces(), so assert against the source string.
		$this->assertStringContainsString( 'xmlns="' . self::NS_SITEMAP . '"', $xml );
		$this->assertStringContainsString( 'xmlns:news="' . self::NS_NEWS . '"', $xml );
	}

	/**
	 * Tests that a single item renders with all required news fields.
	 */
	public function test_render_single_item(): void {
		$doc = $this->load_xml( $this->make_renderer()->render( array( $this->make_item() ), array() ) );
		$this->assertCount( 1, $doc->url );

		$url = $doc->url[0];
		$this->assertSame( 'https://example.com/post/1', (string) $url->loc );

		$news = $url->children( self::NS_NEWS )->news;
		$this->assertNotNull( $news );
		$this->assertSame( 'Областная газета', (string) $news->publication->name );
		$this->assertSame( 'ru', (string) $news->publication->language );
		$this->assertSame( '2026-10-17T15:10:08+00:00', (string) $news->publication_date );
		$this->assertSame( 'Заголовок', (string) $news->title );
	}

	/**
	 * Tests that multiple items render as multiple <url> blocks.
	 */
	public function test_render_multiple_items(): void {
		$items = array( $this->make_item( 1 ), $this->make_item( 2 ), $this->make_item( 3 ) );
		$doc   = $this->load_xml( $this->make_renderer()->render( $items, array() ) );
		$this->assertCount( 3, $doc->url );
	}

	/**
	 * Tests that special characters are XML-escaped in title and loc.
	 */
	public function test_render_escapes_special_chars(): void {
		$item          = $this->make_item();
		$item['title'] = 'A & B < C > D';
		$item['link']  = 'https://example.com/a?x=1&y=2';

		$xml = $this->make_renderer()->render( array( $item ), array() );

		$this->assertStringContainsString( 'A &amp; B &lt; C &gt; D', $xml );
		$this->assertStringContainsString( 'x=1&amp;y=2', $xml );
		$this->load_xml( $xml );
	}

	/**
	 * Tests that missing publication name and site title yields an empty urlset.
	 */
	public function test_render_empty_name_and_no_site_title_emits_empty_urlset(): void {
		$renderer = new NewsSitemapRenderer( static fn(): array => array() );
		$doc      = $this->load_xml( $renderer->render( array( $this->make_item() ), array() ) );
		$this->assertCount( 0, $doc->url );
	}

	/**
	 * Tests that language falls back to 'ru' without get_bloginfo.
	 */
	public function test_render_language_fallback_to_ru_without_bloginfo(): void {
		$renderer = new NewsSitemapRenderer(
			static fn(): array => array(
				'renderers' => array(
					'google-news' => array(
						'news_publication_name' => 'X',
						'news_language'         => '',
					),
				),
			)
		);
		$doc      = $this->load_xml( $renderer->render( array( $this->make_item() ), array() ) );
		$news     = $doc->url[0]->children( self::NS_NEWS )->news;
		$this->assertSame( 'ru', (string) $news->publication->language );
	}

	/**
	 * Tests that items without a title are skipped.
	 */
	public function test_render_skips_item_without_title(): void {
		$item          = $this->make_item();
		$item['title'] = '';
		$doc           = $this->load_xml( $this->make_renderer()->render( array( $item ), array() ) );
		$this->assertCount( 0, $doc->url );
	}

	/**
	 * Tests that items without a link are skipped.
	 */
	public function test_render_skips_item_without_link(): void {
		$item         = $this->make_item();
		$item['link'] = '';
		$doc          = $this->load_xml( $this->make_renderer()->render( array( $item ), array() ) );
		$this->assertCount( 0, $doc->url );
	}

	/**
	 * Tests that items with null published are skipped.
	 */
	public function test_render_skips_item_without_published(): void {
		$item              = $this->make_item();
		$item['published'] = null;
		$doc               = $this->load_xml( $this->make_renderer()->render( array( $item ), array() ) );
		$this->assertCount( 0, $doc->url );
	}

	/**
	 * Tests that items with non-DateTimeImmutable published are skipped.
	 */
	public function test_render_skips_item_with_non_datetime_published(): void {
		$item              = $this->make_item();
		$item['published'] = '2026-10-17 15:10:08';
		$doc               = $this->load_xml( $this->make_renderer()->render( array( $item ), array() ) );
		$this->assertCount( 0, $doc->url );
	}

	/**
	 * Tests that the injected logger receives a message for a broken item.
	 */
	public function test_render_invokes_logger_for_broken_item(): void {
		$logged = array();
		$logger = static function ( string $message ) use ( &$logged ): void {
			$logged[] = $message;
		};

		$item          = $this->make_item();
		$item['title'] = '';

		$this->make_renderer( $logger )->render( array( $item ), array() );

		$this->assertCount( 1, $logged );
		$this->assertStringContainsString( 'missing title', $logged[0] );
	}

	/**
	 * Tests that the logger receives a message when the publication name is empty.
	 */
	public function test_render_invokes_logger_when_name_empty(): void {
		$logged = array();
		$logger = static function ( string $message ) use ( &$logged ): void {
			$logged[] = $message;
		};

		$renderer = new NewsSitemapRenderer( static fn(): array => array(), $logger );
		$renderer->render( array( $this->make_item() ), array() );

		$this->assertCount( 1, $logged );
		$this->assertStringContainsString( 'publication name is empty', $logged[0] );
	}

	/**
	 * Tests that the logger is not invoked for valid items.
	 */
	public function test_render_does_not_invoke_logger_for_valid_items(): void {
		$logged = array();
		$logger = static function ( string $message ) use ( &$logged ): void {
			$logged[] = $message;
		};

		$this->make_renderer( $logger )->render( array( $this->make_item() ), array() );

		$this->assertSame( array(), $logged );
	}

	/**
	 * Load an XML string and assert it is well-formed.
	 *
	 * @param string $xml Raw XML.
	 */
	private function load_xml( string $xml ): SimpleXMLElement {
		$previous = libxml_use_internal_errors( true );
		$doc      = simplexml_load_string( $xml );
		$errors   = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$this->assertInstanceOf( SimpleXMLElement::class, $doc, 'Malformed XML: ' . implode( '; ', array_map( static fn( $e ): string => trim( $e->message ), $errors ) ) );

		return $doc;
	}

	/**
	 * Build a normalized item fixture.
	 *
	 * @param int $seed Unique seed for ID/link.
	 * @return array<string, mixed>
	 */
	private function make_item( int $seed = 1 ): array {
		return array(
			'id'           => 'https://example.com/post/' . $seed,
			'link'         => 'https://example.com/post/' . $seed,
			'title'        => 'Заголовок',
			'content'      => '',
			'excerpt'      => '',
			'published'    => new DateTimeImmutable( '2026-10-17T15:10:08+00:00' ),
			'author'       => '',
			'image_url'    => null,
			'image_width'  => null,
			'image_height' => null,
			'terms'        => array(),
		);
	}
}
