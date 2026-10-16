<?php
/**
 * Plugin bootstrap class.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF;

use NWFF\Cache\TransientCache;
use NWFF\Data\Collector;
use NWFF\Data\MetaRepository;
use NWFF\Feed\Endpoint;
use NWFF\Install\Cron;
use NWFF\Install\Schema;
use NWFF\Normalize\ContentNormalizer;
use NWFF\Normalize\HtmlSanitizer;
use NWFF\Normalize\ImageExtractor;
use NWFF\Normalize\Truncator;
use NWFF\Render\Yandex\GenreResolver;
use NWFF\Render\YandexRenderer;
use NWFF\Settings\Page;
use NWFF\Settings\Store;

/**
 * Registers plugin hooks and wires collaborators (ADR-ARCH-0001).
 *
 * Stateless: everything is static. The renderer map is the single
 * extension point for new formats.
 */
final class Plugin {

	private const QUERY_VAR = 'nwff_feed_slug';

	/**
	 * Cached store instance, built on first access.
	 *
	 * @var Store|null
	 */
	private static ?Store $store = null;

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_rewrite_rules' ) );
		add_action( 'template_redirect', array( self::class, 'handle_feed_request' ) );
		add_action( 'save_post_post', array( self::class, 'on_save_post' ) );
		add_action( 'deleted_post', array( self::class, 'on_deleted_post' ), 10, 2 );
		add_action( 'set_object_terms', array( self::class, 'on_set_object_terms' ) );
		add_action( 'admin_init', array( self::class, 'register_settings_page' ) );
		add_action( 'admin_menu', array( self::class, 'register_settings_page' ) );
		add_action( 'update_option_nwff_settings', array( self::class, 'on_settings_updated' ), 10, 2 );
		add_action( Cron::HOOK, array( Cron::class, 'cleanup' ) );
	}

	/**
	 * Runs on plugin activation.
	 *
	 * Creates (or upgrades) the custom feed-meta table, registers the
	 * feed rewrite rules in the current process, and flushes the
	 * rewrite_rules option so they persist without waiting for the
	 * next init. flush_rewrite_rules() reads the current WP_Rewrite
	 * state, so register_rewrite_rules() must run first.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Schema::activate();
		self::register_rewrite_rules();
		flush_rewrite_rules();
		Cron::schedule();
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * Removes the scheduled cleanup event. Does not drop tables or
	 * options: uninstall.php handles removal, deactivation must be
	 * reversible.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Cron::unschedule();
	}

	/**
	 * Invalidates the feed cache when a post is saved.
	 *
	 * Hooked to save_post_post, so revisions and autosaves (both
	 * post_type=revision) never reach this method.
	 *
	 * @return void
	 */
	public static function on_save_post(): void {
		self::invalidate_feed_cache();
	}

	/**
	 * Cleans up feed metadata when a post is deleted.
	 *
	 * Skips non-post types: revisions, attachments and menu items
	 * also fire deleted_post and would each trigger a DELETE.
	 *
	 * @param int      $post_id Deleted post ID.
	 * @param \WP_Post $post    Deleted post object.
	 * @return void
	 */
	public static function on_deleted_post( int $post_id, \WP_Post $post ): void {
		if ( 'post' !== $post->post_type ) {
			return;
		}

		( new MetaRepository() )->delete_by_post_id( $post_id );
		self::invalidate_feed_cache();
	}

	/**
	 * Invalidates the feed cache when post terms change.
	 *
	 * Fires for term hierarchies and other object types as well;
	 * only post objects can affect the feed.
	 *
	 * @param int $object_id Object ID whose terms changed.
	 * @return void
	 */
	public static function on_set_object_terms( int $object_id ): void {
		if ( 'post' !== get_post_type( $object_id ) ) {
			return;
		}

		self::invalidate_feed_cache();
	}

	/**
	 * Deletes all feed transients.
	 *
	 * Shared invalidation entry point for the three hooks above.
	 *
	 * @return void
	 */
	public static function invalidate_feed_cache(): void {
		( new TransientCache() )->delete_by_prefix( TransientCache::FEED_PREFIX );
	}
	/**
	 * Register the settings page.
	 *
	 * Hooked to both admin_init and admin_menu. The admin_init pass
	 * does the actual registration (so register_setting runs before
	 * options.php handles a POST). The admin_menu pass exists for
	 * hook discovery and is a no-op.
	 *
	 * @return void
	 */
	public static function register_settings_page(): void {
		if ( 'admin_init' !== current_filter() ) {
			return;
		}

		( new Page( self::store() ) )->register();
	}

	/**
	 * Flush rewrite rules when the feed slug changes.
	 *
	 * Hooked to update_option_nwff_settings. The feed slug is part of
	 * the rewrite regex, so a change requires a flush. Any other
	 * change is a no-op — the flush is expensive.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $value     New option value.
	 * @return void
	 */
	public static function on_settings_updated( $old_value, $value ): void {
		$old_slug = is_array( $old_value ) ? ( $old_value['core']['feed_slug'] ?? null ) : null;
		$new_slug = is_array( $value ) ? ( $value['core']['feed_slug'] ?? null ) : null;

		if ( $old_slug === $new_slug ) {
			return;
		}

		flush_rewrite_rules();
	}
	/**
	 * Return the renderer map keyed by slug.
	 *
	 * Delegates to Store, the single owner of renderer instances.
	 *
	 * @return array<string, \NWFF\Render\RendererInterface>
	 */
	public static function renderers(): array {
		return self::store()->renderers();
	}

	/**
	 * Register feed rewrite rules and the feed query var.
	 *
	 * @return void
	 */
	public static function register_rewrite_rules(): void {
		$slug = preg_quote( self::store()->feed_slug(), '/' );

		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([^/]+)' );
		add_rewrite_rule(
			'^' . $slug . '/([^/]+)\.xml$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Serve a feed on template_redirect.
	 *
	 * Returns 404 when the slug is unknown. Echoes the response body
	 * and exits, so the theme is never loaded.
	 *
	 * @return void
	 */
	public static function handle_feed_request(): void {
		$slug = get_query_var( self::QUERY_VAR );
		if ( ! is_string( $slug ) || '' === $slug ) {
			return;
		}

		$response = self::make_endpoint()->serve( $slug, self::store()->get() );

		if ( null === $response ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		header( 'Content-Type: ' . $response['content_type'] );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Body is XML already escaped by the renderer.
		echo $response['body'];
		exit;
	}

	/**
	 * Build the renderer map.
	 *
	 * Private: instances are owned by Store.
	 *
	 * @return array<string, \NWFF\Render\RendererInterface>
	 */
	private static function make_renderers(): array {
		return array(
			'yandex' => new YandexRenderer( new GenreResolver(), new Truncator() ),
		);
	}

	/**
	 * Return the store, building it on first access.
	 *
	 * @return Store
	 */
	private static function store(): Store {
		if ( null === self::$store ) {
			self::$store = new Store( self::make_renderers() );
		}

		return self::$store;
	}

	/**
	 * Build the feed endpoint with all collaborators wired.
	 *
	 * @return Endpoint
	 */
	private static function make_endpoint(): Endpoint {
		$sanitizer = new HtmlSanitizer();

		return new Endpoint(
			self::renderers(),
			new Collector( new MetaRepository(), new ImageExtractor(), $sanitizer ),
			new ContentNormalizer( $sanitizer, new Truncator() ),
			new TransientCache()
		);
	}
}
