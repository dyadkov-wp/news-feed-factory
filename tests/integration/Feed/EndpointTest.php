<?php
/**
 * Integration tests for the feed endpoint.
 *
 * @package NewsFeedFactory
 *
 * @group integration
 */

declare( strict_types=1 );

namespace NWFF\Tests\Integration\Feed;

use NWFF\Cache\TransientCache;
use NWFF\Data\Collector;
use NWFF\Data\MetaRepository;
use NWFF\Feed\Endpoint;
use NWFF\Normalize\ContentNormalizer;
use NWFF\Normalize\HtmlSanitizer;
use NWFF\Normalize\ImageExtractor;
use NWFF\Normalize\Truncator;
use NWFF\Plugin;
use SimpleXMLElement;
use WP_UnitTestCase;

/**
 * Covers Endpoint::serve() against the real Collector and both renderers.
 *
 * @group integration
 */
final class EndpointTest extends WP_UnitTestCase {

	/**
	 * Endpoint under test.
	 *
	 * @var Endpoint
	 */
	private Endpoint $endpoint;

	/**
	 * Enabled settings for both renderers.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * Set up the endpoint with real collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->settings = array(
			'core'      => array(
				'feed_slug'   => 'feed',
				'depth_hours' => 4,
				'cache_ttl'   => 5,
			),
			'renderers' => array(
				'yandex'      => array(
					'enabled'            => true,
					'tag_interview'      => 0,
					'tag_article'        => 0,
					'tag_lenta'          => 0,
					'by_length'          => false,
					'include_taxonomies' => '',
					'exclude_taxonomies' => '',
				),
				'google-news' => array(
					'enabled'               => true,
					'news_publication_name' => 'Test Publication',
					'news_language'         => 'ru',
					'include_taxonomies'    => '',
					'exclude_taxonomies'    => '',
				),
			),
		);

		$this->endpoint = new Endpoint(
			Plugin::renderers(),
			new Collector( new MetaRepository(), new ImageExtractor(), new HtmlSanitizer() ),
			new ContentNormalizer( new HtmlSanitizer(), new Truncator() ),
			new TransientCache()
		);
	}

	/**
	 * Create a published post.
	 *
	 * @param int $age_seconds Age of the post, in seconds. Default one hour.
	 * @return int Post ID.
	 */
	private function make_post( int $age_seconds = HOUR_IN_SECONDS ): int {
		return (int) self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $age_seconds ),
				'post_content'  => '<p>Body</p>',
				'post_title'    => 'Title',
				'post_excerpt'  => 'Teaser',
			)
		);
	}

	/**
	 * Parse a rendered feed.
	 *
	 * @param string $xml Rendered XML.
	 * @return SimpleXMLElement
	 */
	private function parse( string $xml ): SimpleXMLElement {
		$previous = libxml_use_internal_errors( true );
		$element  = simplexml_load_string( $xml );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$this->assertInstanceOf( SimpleXMLElement::class, $element );

		return $element;
	}

	/**
	 * Plugin::renderers() registers both renderers under their slugs.
	 *
	 * @return void
	 */
	public function test_plugin_registers_yandex_and_google_news(): void {
		$renderers = Plugin::renderers();

		$this->assertArrayHasKey( 'yandex', $renderers );
		$this->assertArrayHasKey( 'google-news', $renderers );
		$this->assertSame( 'yandex', $renderers['yandex']->get_slug() );
		$this->assertSame( 'google-news', $renderers['google-news']->get_slug() );
	}

	/**
	 * An unknown slug returns null.
	 *
	 * @return void
	 */
	public function test_unknown_slug_returns_null(): void {
		$this->assertNull( $this->endpoint->serve( 'unknown', $this->settings ) );
	}

	/**
	 * A disabled renderer returns null.
	 *
	 * @return void
	 */
	public function test_disabled_renderer_returns_null(): void {
		$this->settings['renderers']['yandex']['enabled'] = false;

		$this->assertNull( $this->endpoint->serve( 'yandex', $this->settings ) );
	}

	/**
	 * The yandex slug returns RSS with a content type.
	 *
	 * @return void
	 */
	public function test_yandex_slug_returns_rss(): void {
		$this->make_post();

		$response = $this->endpoint->serve( 'yandex', $this->settings );

		$this->assertIsArray( $response );
		$this->assertSame( 'application/rss+xml; charset=UTF-8', $response['content_type'] );

		$rss = $this->parse( $response['body'] );
		$this->assertSame( 'rss', $rss->getName() );
		$this->assertCount( 1, $rss->channel->item );
	}

	/**
	 * An empty yandex feed returns valid RSS without items.
	 *
	 * @return void
	 */
	public function test_empty_yandex_feed_returns_valid_rss(): void {
		$response = $this->endpoint->serve( 'yandex', $this->settings );

		$this->assertIsArray( $response );
		$rss = $this->parse( $response['body'] );
		$this->assertCount( 0, $rss->channel->item );
	}

	/**
	 * The second call returns the same body from the cache.
	 *
	 * @return void
	 */
	public function test_cache_hit_returns_same_body(): void {
		$this->make_post();

		$first  = $this->endpoint->serve( 'yandex', $this->settings );
		$second = $this->endpoint->serve( 'yandex', $this->settings );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertSame( $first['body'], $second['body'] );
	}

	/**
	 * The google-news slug returns a sitemap with one <url> per post.
	 *
	 * @return void
	 */
	public function test_google_news_slug_returns_sitemap(): void {
		$this->make_post();

		$response = $this->endpoint->serve( 'google-news', $this->settings );

		$this->assertIsArray( $response );
		$this->assertSame( 'application/xml; charset=UTF-8', $response['content_type'] );

		$doc = $this->parse( $response['body'] );
		$this->assertSame( 'urlset', $doc->getName() );
		$this->assertCount( 1, $doc->url );
	}

	/**
	 * An empty google-news feed returns a valid urlset without <url> blocks.
	 *
	 * @return void
	 */
	public function test_google_news_empty_feed_returns_empty_urlset(): void {
		$response = $this->endpoint->serve( 'google-news', $this->settings );

		$this->assertIsArray( $response );
		$doc = $this->parse( $response['body'] );
		$this->assertSame( 'urlset', $doc->getName() );
		$this->assertCount( 0, $doc->url );
	}

	/**
	 * A disabled google-news renderer returns null.
	 *
	 * @return void
	 */
	public function test_google_news_disabled_returns_null(): void {
		$this->settings['renderers']['google-news']['enabled'] = false;

		$this->assertNull( $this->endpoint->serve( 'google-news', $this->settings ) );
	}

	/**
	 * Posts older than core depth_hours are excluded from the sitemap.
	 *
	 * @return void
	 */
	public function test_google_news_excludes_posts_outside_depth_hours(): void {
		$this->make_post( HOUR_IN_SECONDS );     // within 4h window.
		$this->make_post( 6 * HOUR_IN_SECONDS ); // outside 4h window.

		$response = $this->endpoint->serve( 'google-news', $this->settings );

		$this->assertIsArray( $response );
		$doc = $this->parse( $response['body'] );
		$this->assertCount( 1, $doc->url );
	}
}
