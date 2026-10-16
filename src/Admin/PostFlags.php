<?php
/**
 * Per-post feed flags metabox.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Admin;

use NWFF\Data\MetaRepository;
use WP_Post;

/**
 * Metabox on the post edit screen (ADR-ARCH-0001).
 *
 * Single checkbox, inverted on save: checked means "include in
 * feeds" and is stored as exclude_from_feeds = 0; unchecked is
 * stored as 1.
 */
final class PostFlags {

	private const NONCE_ACTION = 'nwff_save_post_flags';
	private const NONCE_NAME   = 'nwff_post_flags_nonce';
	private const PRESENT_KEY  = 'nwff_post_flags_present';
	private const FIELD_KEY    = 'nwff_post_flags';
	private const METABOX_ID   = 'nwff_post_flags';

	/**
	 * Register hooks.
	 *
	 * Called from Plugin::init().
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add_metabox' ) );
	}

	/**
	 * Add the metabox for the post type.
	 *
	 * @param string $post_type Post type being edited.
	 * @return void
	 */
	public static function add_metabox( string $post_type ): void {
		if ( 'post' !== $post_type ) {
			return;
		}

		add_meta_box(
			self::METABOX_ID,
			__( 'News Feed Factory', 'news-feed-factory' ),
			array( self::class, 'render' ),
			'post',
			'side',
			'default'
		);
	}

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public static function render( WP_Post $post ): void {
		$post_id = (int) $post->ID;
		$rows    = ( new MetaRepository() )->get_by_post_ids( array( $post_id ) );
		$exclude = $rows[ $post_id ]['exclude_from_feeds'] ?? false;

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		printf(
			'<input type="hidden" name="%s" value="1" />',
			esc_attr( self::PRESENT_KEY )
		);

		printf(
			'<label><input type="checkbox" name="%s[include]" value="1" %s /> %s</label>',
			esc_attr( self::FIELD_KEY ),
			checked( ! $exclude, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a WP-generated attribute string.
			esc_html__( 'Include in feeds', 'news-feed-factory' )
		);
	}

	/**
	 * Persist the metabox value.
	 *
	 * Hooked to save_post_post. Skips REST requests, Quick Edit and
	 * other paths where the metabox form is not submitted: the
	 * presence marker is only emitted by our own form.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function save( int $post_id ): void {
		if ( ! isset( $_POST[ self::PRESENT_KEY ] ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] ) && is_string( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$field   = isset( $_POST[ self::FIELD_KEY ] ) && is_array( $_POST[ self::FIELD_KEY ] )
			? wp_unslash( $_POST[ self::FIELD_KEY ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values are cast to bool below.
			: array();
		$include = ! empty( $field['include'] );

		( new MetaRepository() )->save( $post_id, ! $include );
	}
}
