<?php
// phpcs:ignoreFile -- Stub file for static analysis only.
/**
 * Minimal stubs for the WordPress PHPUnit test case.
 *
 * PHPStan cannot load the WordPress test suite. These declarations
 * let it resolve WP_UnitTestCase and its factory when analysing
 * integration tests.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

if ( ! class_exists( 'WP_UnitTest_Factory_For_Post' ) ) {
	/**
	 * Stub for the post factory.
	 */
	class WP_UnitTest_Factory_For_Post {
		/**
		 * @param array<string, mixed> $args Post args.
		 * @return int
		 */
		public function create( array $args = array() ): int {
			return 0;
		}
	}
}

if ( ! class_exists( 'WP_UnitTest_Factory_For_Attachment' ) ) {
	/**
	 * Stub for the attachment factory.
	 */
	class WP_UnitTest_Factory_For_Attachment {
		/**
		 * @param string $file           File path.
		 * @param int    $parent_post_id Parent post ID.
		 * @return int
		 */
		public function create_upload_object( string $file, int $parent_post_id = 0 ): int {
			return 0;
		}
	}
}

if ( ! class_exists( 'WP_UnitTest_Factory_For_Term' ) ) {
	/**
	 * Stub for the term factory.
	 */
	class WP_UnitTest_Factory_For_Term {
		/**
		 * @param array<string, mixed> $args Term args.
		 * @return int
		 */
		public function create( array $args = array() ): int {
			return 0;
		}
	}
}

if ( ! class_exists( 'WP_UnitTest_Factory_For_User' ) ) {
	/**
	 * Stub for the user factory.
	 */
	class WP_UnitTest_Factory_For_User {
		/**
		 * @param array<string, mixed> $args User args.
		 * @return int
		 */
		public function create( array $args = array() ): int {
			return 0;
		}
	}
}

if ( ! class_exists( 'WP_UnitTest_Factory' ) ) {
	/**
	 * Stub for the factory container.
	 */
	class WP_UnitTest_Factory {
		/**
		 * Post factory.
		 *
		 * @var WP_UnitTest_Factory_For_Post
		 */
		public $post;

		/**
		 * Attachment factory.
		 *
		 * @var WP_UnitTest_Factory_For_Attachment
		 */
		public $attachment;

		/**
		 * Term factory.
		 *
		 * @var WP_UnitTest_Factory_For_Term
		 */
		public $term;

		/**
		 * User factory.
		 *
		 * @var WP_UnitTest_Factory_For_User
		 */
		public $user;

		/**
		 * Initialise sub-factories.
		 */
		public function __construct() {
			$this->post       = new WP_UnitTest_Factory_For_Post();
			$this->attachment = new WP_UnitTest_Factory_For_Attachment();
			$this->term       = new WP_UnitTest_Factory_For_Term();
			$this->user       = new WP_UnitTest_Factory_For_User();
		}
	}
}

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	/**
	 * Stub for WP_UnitTestCase.
	 */
	class WP_UnitTestCase extends \PHPUnit\Framework\TestCase {
		/**
		 * Yoast polyfills hook.
		 *
		 * @return void
		 */
		public static function set_up_before_class(): void {}

		/**
		 * Yoast polyfills hook.
		 *
		 * @return void
		 */
		public static function tear_down_after_class(): void {}

		/**
		 * Return the factory container.
		 *
		 * @return WP_UnitTest_Factory
		 */
		public static function factory(): WP_UnitTest_Factory {
			return new WP_UnitTest_Factory();
		}
	}
}
