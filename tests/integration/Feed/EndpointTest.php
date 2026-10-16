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
use NWFF\Render\Yandex\GenreResolver;
use NWFF\Render\YandexRenderer;
use SimpleXMLElement;
use WP_UnitTestCase;

/**
 * Covers Endpoint::serve() against real Collector and YandexRenderer.
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
	 * Enabled settings for the yandex renderer.
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
				'yandex' => array(
					'enabled'            => true,
					'tag_interview'      => 0,
					'tag_article'        => 0,
					'tag_lenta'          => 0,
					'by_length'          => false,
					'include_taxonomies' => '',
					'exclude_taxonomies' => '',
				),
			),
		);

		$this->endpoint = new Endpoint(
			array(
				'yandex' => new YandexRenderer( new GenreResolver(), new Truncator() ),
			),
			new Collector( new MetaRepository(), new ImageExtractor(), new HtmlSanitizer() ),
			new ContentNormalizer( new HtmlSanitizer(), new Truncator() ),
			new TransientCache()
		);
	}

	/**
	 * Create a published post.
	 *
	 * @return int Post ID.
	 */
	private function make_post(): int {
		return (int) self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
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
	 * An unknown slug returns null.
	 *
	 * @return void
	 */
	public function test_unknown_slug_returns_null(): void {
		$this->assertNull( $this->endpoint->serve( 'unknown', $this->settings ) );
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
		$this->assertArrayHasKey( 'body', $response );
		$this->assertArrayHasKey( 'content_type', $response );
		$this->assertSame( 'application/rss+xml; charset=UTF-8', $response['content_type'] );

		$rss = $this->parse( $response['body'] );
		$this->assertSame( 'rss', $rss->getName() );
		$this->assertCount( 1, $rss->channel->item );
	}

	/**
	 * An empty feed returns valid RSS without items.
	 *
	 * @return void
	 */
	public function test_empty_feed_returns_valid_rss(): void {
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
	 * A disabled renderer returns null.
	 *
	 * @return void
	 */
	public function test_disabled_renderer_returns_null(): void {
		$this->settings['renderers']['yandex']['enabled'] = false;

		$this->assertNull( $this->endpoint->serve( 'yandex', $this->settings ) );
	}
}
