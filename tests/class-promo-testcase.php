<?php
/**
 * Shared test case for the Whimsical Promo plugin.
 *
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo\Tests;

use WhimsicalPromo\Post_Type;
use WhimsicalPromo\Render;
use WP_Post;
use WP_UnitTestCase;

/**
 * Class Promo_TestCase
 */
abstract class Promo_TestCase extends WP_UnitTestCase {

	/**
	 * Hook the tests place promos on, standing in for a theme's own hook.
	 *
	 * Nothing else listens to it, so firing it renders promos and nothing else.
	 *
	 * @var string
	 */
	const TEST_HOOK = 'whim_test_slot';

	/**
	 * The WP test case unregisters post types and meta keys in tear_down.
	 */
	public function set_up(): void {
		parent::set_up();

		add_filter( 'whimsical_promo_hooks', [ $this, 'filter_test_hook' ] );

		Post_Type::get_instance()->register_post_type();
		Post_Type::get_instance()->register_meta();
		Render::get_instance()->reset();
	}

	/**
	 * Supplies the test hook as the only suggestion, so it is also the default.
	 *
	 * @return string[]
	 */
	public function filter_test_hook(): array {
		return [ self::TEST_HOOK ];
	}

	/**
	 * Creates a published promo with the given meta overrides.
	 *
	 * @param array<string,mixed> $meta Meta overrides.
	 * @param array<string,mixed> $args Post arguments.
	 *
	 * @return int Promo ID.
	 */
	protected function create_promo( array $meta = [], array $args = [] ): int {
		// Store the body verbatim: promos are authored by trusted editors, and the
		// point of these tests is what the render pipeline filters, not what
		// wp_insert_post() filtered on the way in.
		$kses_was_active = (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' );

		kses_remove_filters();

		$promo_id = self::factory()->post->create(
			array_merge(
				[
					'post_type'    => Post_Type::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => 'Newsletter promo',
					'post_content' => '<p>Join the list</p>',
				],
				$args
			)
		);

		if ( $kses_was_active ) {
			kses_init_filters();
		}

		$this->assertIsInt( $promo_id );

		foreach ( array_merge( Post_Type::meta_defaults(), $meta ) as $key => $value ) {
			update_post_meta( $promo_id, $key, $value );
		}

		return $promo_id;
	}

	/**
	 * Creates a post to render promos on, and makes it the current query.
	 *
	 * @param string $post_type Post type.
	 *
	 * @return int Post ID.
	 */
	protected function go_to_singular( string $post_type = 'post' ): int {
		$post_id = self::factory()->post->create( [ 'post_type' => $post_type ] );

		$this->assertIsInt( $post_id );

		$this->visit( (string) get_permalink( $post_id ) );

		return $post_id;
	}

	/**
	 * Visits a URL, tolerating this environment's object-cache notice.
	 *
	 * @param string $url URL to visit.
	 *
	 * @return void
	 */
	protected function visit( string $url ): void {
		// Expected only where the object cache cannot flush its runtime cache: that is
		// what raises the notice go_to() triggers via flush_cache().
		if ( ! wp_cache_supports( 'flush_runtime' ) ) {
			$this->setExpectedIncorrectUsage( 'wp_cache_flush_runtime' );
		}

		$this->go_to( $url );
	}

	/**
	 * Creates a user and returns its ID.
	 *
	 * @param string $role User role.
	 *
	 * @return int
	 */
	protected function create_user( string $role = 'administrator' ): int {
		$user_id = self::factory()->user->create( [ 'role' => $role ] );

		$this->assertIsInt( $user_id );

		return $user_id;
	}

	/**
	 * Returns a promo as a WP_Post.
	 *
	 * @param int $promo_id Promo ID.
	 *
	 * @return WP_Post
	 */
	protected function get_promo( int $promo_id ): WP_Post {
		$promo = get_post( $promo_id );

		$this->assertInstanceOf( WP_Post::class, $promo );

		return $promo;
	}

	/**
	 * Runs the_content inside the main loop, which is where promos are appended.
	 *
	 * @param string $content Unfiltered content.
	 *
	 * @return string
	 */
	protected function filter_the_content( string $content ): string {
		global $wp_query;

		$wp_query->the_post();

		$filtered = (string) apply_filters( 'the_content', $content );

		wp_reset_postdata();

		return $filtered;
	}

	/**
	 * Captures everything printed on a hook.
	 *
	 * @param non-empty-string $hook Hook name.
	 *
	 * @return string
	 */
	protected function capture_hook( string $hook ): string {
		ob_start();
		do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.NotLowercase -- Hook name provided by the test.

		return (string) ob_get_clean();
	}
}
