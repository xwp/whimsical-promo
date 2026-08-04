<?php
/**
 * Tests for the promo post type, meta registration, and sanitizers.
 *
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo\Tests;

use WhimsicalPromo\Post_Type;

/**
 * Class Post_Type_Test
 */
class Post_Type_Test extends Promo_TestCase {

	/**
	 * The post type is registered and not publicly queryable.
	 */
	public function test_post_type_is_registered(): void {
		$object = get_post_type_object( Post_Type::POST_TYPE );

		$this->assertNotNull( $object );
		$this->assertFalse( $object->public );
		$this->assertTrue( $object->show_ui );
		$this->assertTrue( $object->show_in_rest );
		$this->assertTrue( post_type_supports( Post_Type::POST_TYPE, 'editor' ) );
		$this->assertTrue( post_type_supports( Post_Type::POST_TYPE, 'revisions' ) );
		$this->assertTrue( post_type_supports( Post_Type::POST_TYPE, 'page-attributes' ) );
	}

	/**
	 * Every meta key is registered for the post type.
	 */
	public function test_all_meta_keys_are_registered(): void {
		$registered = get_registered_meta_keys( 'post', Post_Type::POST_TYPE );

		foreach ( array_keys( Post_Type::meta_defaults() ) as $key ) {
			$this->assertArrayHasKey( $key, $registered, "Meta key {$key} is not registered." );
			$this->assertTrue( $registered[ $key ]['single'] );
		}
	}

	/**
	 * Choice meta falls back to its default when given an unknown value.
	 */
	public function test_choice_meta_falls_back_to_default(): void {
		$this->assertSame( 'exit_intent', Post_Type::sanitize_meta( 'whim_placement', 'exit_intent' ) );
		$this->assertSame( 'inline_hook', Post_Type::sanitize_meta( 'whim_placement', 'javascript:alert(1)' ) );
		$this->assertSame( 'modal', Post_Type::sanitize_meta( 'whim_presentation', 'modal' ) );
		$this->assertSame( 'slide-down', Post_Type::sanitize_meta( 'whim_presentation', 'nope' ) );
		$this->assertSame( 'none', Post_Type::sanitize_meta( 'whim_animation', 'none' ) );
		$this->assertSame( 'slide-up-spring', Post_Type::sanitize_meta( 'whim_animation', '<script>' ) );

		// The downward spring is what a slide-down bar wants; without it in choices the
		// select would not offer it and a save would silently fall back.
		$this->assertSame( 'slide-down-spring', Post_Type::sanitize_meta( 'whim_animation', 'slide-down-spring' ) );
	}

	/**
	 * Hook names keep only hook-safe characters.
	 */
	public function test_hook_name_sanitization(): void {
		$this->assertSame( 'theme_after_content', Post_Type::sanitize_hook_name( ' Theme_After_Content ' ) );
		$this->assertSame( 'my/hook-name_2', Post_Type::sanitize_hook_name( 'my/hook-name_2' ) );
		$this->assertSame( 'scriptalert1/script', Post_Type::sanitize_hook_name( '<script>alert(1)</script>' ) );
		$this->assertSame( '', Post_Type::sanitize_hook_name( '  ' ) );
	}

	/**
	 * Hooks that break the page are cleared on save, whatever the casing.
	 */
	public function test_blocked_hook_names_are_cleared(): void {
		foreach ( Post_Type::BLOCKED_HOOKS as $hook ) {
			$this->assertSame( '', Post_Type::sanitize_hook_name( $hook ), $hook . ' should be refused' );
		}

		$this->assertSame( '', Post_Type::sanitize_hook_name( ' The_Content ' ) );
		$this->assertSame( Post_Type::CONTENT_HOOK, Post_Type::sanitize_hook_name( Post_Type::CONTENT_HOOK ) );
	}

	/**
	 * Post types are intersected against registered public post types.
	 */
	public function test_post_types_sanitization(): void {
		$this->assertSame( [ 'post', 'page' ], Post_Type::sanitize_post_types( [ 'post', 'page' ] ) );
		$this->assertSame( [ 'post' ], Post_Type::sanitize_post_types( [ 'post', 'not_a_post_type' ] ) );
		$this->assertSame( [], Post_Type::sanitize_post_types( 'post' ) );
		$this->assertSame( [], Post_Type::sanitize_post_types( [ Post_Type::POST_TYPE ] ) );
	}

	/**
	 * Cookie days is a positive integer.
	 */
	public function test_cookie_days_sanitization(): void {
		$this->assertSame( 30, Post_Type::sanitize_meta( 'whim_cookie_days', '30' ) );
		$this->assertSame( 1, Post_Type::sanitize_meta( 'whim_cookie_days', 0 ) );
		$this->assertSame( 1, Post_Type::sanitize_meta( 'whim_cookie_days', -5 ) );
		$this->assertSame( 1, Post_Type::sanitize_meta( 'whim_cookie_days', 'abc' ) );
	}

	/**
	 * Style tokens accept CSS values including var() and gradients.
	 *
	 * @dataProvider data_valid_style_tokens
	 *
	 * @param string $value Token value.
	 */
	public function test_style_token_accepts_valid_values( string $value ): void {
		$this->assertSame( $value, Post_Type::sanitize_style_token( $value ) );
	}

	/**
	 * Valid style token values.
	 *
	 * @return array<string,array{string}>
	 */
	public function data_valid_style_tokens(): array {
		return [
			'hex'          => [ '#ff5500' ],
			'rgba'         => [ 'rgba(0, 0, 0, 0.5)' ],
			'custom prop'  => [ 'var(--en-accent)' ],
			'prop w/ dflt' => [ 'var(--en-accent, #ff5500)' ],
			'gradient'     => [ 'linear-gradient(135deg, #fff 0%, var(--en-accent) 100%)' ],
			'shadow'       => [ '0 10px 30px rgba(0, 0, 0, 0.15)' ],
			'radius'       => [ '12px 12px 0 0' ],
			'border'       => [ '1px solid var(--en-border)' ],
		];
	}

	/**
	 * Style tokens reject anything that could escape the declaration.
	 *
	 * @dataProvider data_invalid_style_tokens
	 *
	 * @param string $value Token value.
	 */
	public function test_style_token_rejects_attacks( string $value ): void {
		$this->assertSame( '', Post_Type::sanitize_style_token( $value ) );
	}

	/**
	 * Invalid style token values.
	 *
	 * @return array<string,array{string}>
	 */
	public function data_invalid_style_tokens(): array {
		return [
			'declaration break' => [ 'red; position: fixed' ],
			'rule block'        => [ 'red } body { display: none' ],
			'at rule'           => [ '@import "evil.css"' ],
			'markup'            => [ '<script>alert(1)</script>' ],
			'url'               => [ 'url(https://evil.example/x.png)' ],
			'url mixed case'    => [ 'URL(https://evil.example/x.png)' ],
			'expression'        => [ 'expression(alert(1))' ],
			'css escape'        => [ '\\75 rl(https://evil.example/x.png)' ],
			'comment'           => [ 'red /* hide */' ],
			'newline'           => [ "red\nposition: fixed" ],
			'too long'          => [ str_repeat( 'a', 201 ) ],
		];
	}

	/**
	 * Promo columns are inserted before the date column.
	 */
	public function test_admin_columns_are_registered(): void {
		$columns = Post_Type::get_instance()->filter_admin_columns(
			[
				'title' => 'Title',
				'date'  => 'Date',
			]
		);

		$this->assertSame(
			[ 'title', 'whim_placement', 'whim_hook', 'whim_order', 'whim_gate', 'date' ],
			array_keys( $columns )
		);
	}

	/**
	 * Column values come from promo meta, with wp_footer shown for exit promos.
	 */
	public function test_admin_column_values(): void {
		$promo_id = $this->create_promo(
			[
				'whim_hook'                  => 'my_hook',
				'whim_show_until_interacted' => true,
			],
			[ 'menu_order' => 3 ]
		);

		$this->assertSame( 'inline_hook', $this->capture_column( 'whim_placement', $promo_id ) );
		$this->assertSame( 'my_hook', $this->capture_column( 'whim_hook', $promo_id ) );
		$this->assertSame( '3', $this->capture_column( 'whim_order', $promo_id ) );
		$this->assertSame( 'Yes', $this->capture_column( 'whim_gate', $promo_id ) );

		$exit_id = $this->create_promo(
			[
				'whim_placement'             => Post_Type::PLACEMENT_EXIT,
				'whim_hook'                  => 'my_hook',
				'whim_show_until_interacted' => false,
			]
		);

		$this->assertSame( 'wp_footer', $this->capture_column( 'whim_hook', $exit_id ) );
		$this->assertSame( 'No', $this->capture_column( 'whim_gate', $exit_id ) );
	}

	/**
	 * Captures a rendered admin column.
	 *
	 * @param string $column   Column key.
	 * @param int    $promo_id Promo ID.
	 *
	 * @return string
	 */
	protected function capture_column( string $column, int $promo_id ): string {
		ob_start();
		Post_Type::get_instance()->render_admin_column( $column, $promo_id );

		return (string) ob_get_clean();
	}
}
