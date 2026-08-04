<?php
/**
 * Tests for the promo meta box save path.
 *
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo\Tests;

use WhimsicalPromo\Meta_Box;
use WhimsicalPromo\Post_Type;
use WhimsicalPromo\Styles;

/**
 * Class Meta_Box_Test
 */
class Meta_Box_Test extends Promo_TestCase {

	/**
	 * Clears request state between tests.
	 */
	public function tear_down(): void {
		$_POST = [];

		parent::tear_down();
	}

	/**
	 * Builds a valid POST payload for a promo.
	 *
	 * @param int                 $promo_id  Promo ID.
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed>
	 */
	protected function post_payload( int $promo_id, array $overrides = [] ): array {
		return array_merge(
			[
				Meta_Box::NONCE_NAME         => wp_create_nonce( Meta_Box::NONCE_ACTION . $promo_id ),
				'whim_placement'             => Post_Type::PLACEMENT_INLINE,
				'whim_hook'                  => 'theme_after_content',
				'whim_post_types'            => [ 'post', 'bogus_type' ],
				'whim_show_until_interacted' => '1',
				'whim_cookie_days'           => '120',
				'whim_presentation'          => 'modal',
				'whim_animation'             => 'fade-rise',
				'whim_style_bg'              => 'var(--en-accent)',
				'whim_style_radius'          => '18px',
			],
			$overrides
		);
	}

	/**
	 * A valid, authorised POST persists every field.
	 */
	public function test_valid_post_persists_meta(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo();
		$_POST    = $this->post_payload( $promo_id );

		Meta_Box::get_instance()->save( $promo_id, $this->get_promo( $promo_id ) );

		$this->assertSame( 'inline_hook', get_post_meta( $promo_id, 'whim_placement', true ) );
		$this->assertSame( 'theme_after_content', get_post_meta( $promo_id, 'whim_hook', true ) );
		$this->assertSame( [ 'post' ], get_post_meta( $promo_id, 'whim_post_types', true ) );
		$this->assertSame( '1', get_post_meta( $promo_id, 'whim_show_until_interacted', true ) );
		$this->assertSame( '120', get_post_meta( $promo_id, 'whim_cookie_days', true ) );
		$this->assertSame( 'modal', get_post_meta( $promo_id, 'whim_presentation', true ) );
		$this->assertSame( 'fade-rise', get_post_meta( $promo_id, 'whim_animation', true ) );
		$this->assertSame( 'var(--en-accent)', get_post_meta( $promo_id, 'whim_style_bg', true ) );
		$this->assertSame( '18px', get_post_meta( $promo_id, 'whim_style_radius', true ) );
	}

	/**
	 * An unchecked checkbox clears the flag.
	 */
	public function test_missing_checkbox_stores_false(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo( [ 'whim_show_until_interacted' => true ] );
		$_POST    = $this->post_payload( $promo_id );
		unset( $_POST['whim_show_until_interacted'], $_POST['whim_post_types'] );

		Meta_Box::get_instance()->save( $promo_id, $this->get_promo( $promo_id ) );

		$this->assertSame( '', get_post_meta( $promo_id, 'whim_show_until_interacted', true ) );
		$this->assertSame( [], get_post_meta( $promo_id, 'whim_post_types', true ) );
	}

	/**
	 * Without a nonce nothing is written.
	 */
	public function test_missing_nonce_is_a_no_op(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo( [ 'whim_animation' => 'none' ] );
		$_POST    = $this->post_payload( $promo_id );
		unset( $_POST[ Meta_Box::NONCE_NAME ] );

		Meta_Box::get_instance()->save( $promo_id, $this->get_promo( $promo_id ) );

		$this->assertSame( 'none', get_post_meta( $promo_id, 'whim_animation', true ) );
	}

	/**
	 * A user without edit rights cannot write meta.
	 */
	public function test_insufficient_capability_is_a_no_op(): void {
		wp_set_current_user( $this->create_user( 'subscriber' ) );

		$promo_id = $this->create_promo( [ 'whim_animation' => 'none' ] );
		$_POST    = $this->post_payload( $promo_id );

		Meta_Box::get_instance()->save( $promo_id, $this->get_promo( $promo_id ) );

		$this->assertSame( 'none', get_post_meta( $promo_id, 'whim_animation', true ) );
	}

	/**
	 * A rejected style token keeps the stored value and queues a notice.
	 */
	public function test_invalid_style_token_is_not_persisted(): void {
		$user_id = $this->create_user();
		wp_set_current_user( $user_id );

		$promo_id = $this->create_promo( [ 'whim_style_bg' => '#ffffff' ] );
		$_POST    = $this->post_payload( $promo_id, [ 'whim_style_bg' => 'red; position: fixed' ] );

		Meta_Box::get_instance()->save( $promo_id, $this->get_promo( $promo_id ) );

		$this->assertSame( '#ffffff', get_post_meta( $promo_id, 'whim_style_bg', true ) );
		$this->assertSame(
			[ 'bg' ],
			get_transient( Meta_Box::NOTICE_TRANSIENT . $promo_id . '_' . $user_id )
		);
	}

	/**
	 * An empty style field clears the stored value.
	 */
	public function test_empty_style_token_clears_value(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo( [ 'whim_style_bg' => '#ffffff' ] );
		$_POST    = $this->post_payload( $promo_id, [ 'whim_style_bg' => '' ] );

		Meta_Box::get_instance()->save( $promo_id, $this->get_promo( $promo_id ) );

		$this->assertSame( '', get_post_meta( $promo_id, 'whim_style_bg', true ) );
	}

	/**
	 * An empty days field falls back to the placement default.
	 */
	public function test_cookie_days_defaults_per_placement(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo();
		$_POST    = $this->post_payload(
			$promo_id,
			[
				'whim_placement'   => Post_Type::PLACEMENT_EXIT,
				'whim_cookie_days' => '',
			]
		);

		Meta_Box::get_instance()->save( $promo_id, $this->get_promo( $promo_id ) );

		$this->assertSame( '30', get_post_meta( $promo_id, 'whim_cookie_days', true ) );
	}

	/**
	 * Unset meta falls back to registered defaults, with per-placement days.
	 */
	public function test_get_value_falls_back_to_defaults(): void {
		$promo_id = self::factory()->post->create( [ 'post_type' => Post_Type::POST_TYPE ] );

		$this->assertIsInt( $promo_id );
		$this->assertSame( 'inline_hook', Meta_Box::get_value( $promo_id, 'whim_placement' ) );
		$this->assertSame( self::TEST_HOOK, Meta_Box::get_value( $promo_id, 'whim_hook' ) );
		$this->assertSame( 30, Meta_Box::get_value( $promo_id, 'whim_cookie_days' ) );

		update_post_meta( $promo_id, 'whim_placement', Post_Type::PLACEMENT_EXIT );

		$this->assertSame( 30, Meta_Box::get_value( $promo_id, 'whim_cookie_days' ) );
	}

	/**
	 * Hook suggestions are filterable and sanitized.
	 */
	public function test_hook_suggestions_filter(): void {
		// Unfiltered, the plugin names no theme hook: the default is its own placement.
		remove_all_filters( 'whimsical_promo_hooks' );

		$this->assertSame( [ Post_Type::CONTENT_HOOK ], Meta_Box::hook_suggestions() );
		$this->assertSame( Post_Type::CONTENT_HOOK, Meta_Box::default_hook() );

		// A theme cannot suggest a hook the plugin refuses to save.
		add_filter(
			'whimsical_promo_hooks',
			static function () {
				return [ 'wp_footer', 'theme_promo_slot' ];
			}
		);

		$this->assertSame( [ 'theme_promo_slot' ], Meta_Box::hook_suggestions() );

		add_filter(
			'whimsical_promo_hooks',
			static function () {
				return [ 'En_After_Content', '<script>', 'en_after_content' ];
			}
		);

		$this->assertSame( [ 'en_after_content', 'script' ], Meta_Box::hook_suggestions() );
	}

	/**
	 * The meta box renders its fields with a nonce.
	 */
	public function test_render_outputs_fields(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo();

		ob_start();
		Meta_Box::get_instance()->render( $this->get_promo( $promo_id ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( Meta_Box::NONCE_NAME, $output );
		$this->assertStringContainsString( 'name="whim_placement"', $output );
		$this->assertStringContainsString( 'list="whim-hook-suggestions"', $output );
		$this->assertStringContainsString( 'name="whim_post_types[]"', $output );
		$this->assertStringContainsString( 'name="whim_cookie_days"', $output );
		$this->assertStringContainsString( 'name="whim_style_bg"', $output );
	}

	/**
	 * The editor is shown the preview parameter for this exact promo, so nobody has
	 * to guess the slug or clear a cookie by hand.
	 */
	public function test_render_outputs_the_preview_parameter(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo( [], [ 'post_name' => 'spring-signup' ] );

		ob_start();
		Meta_Box::get_instance()->render( $this->get_promo( $promo_id ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="whim_preview=spring-signup"', $output );
		$this->assertStringContainsString( 'id="whim-copy-preview"', $output );
	}

	/**
	 * A draft is never placed on the site, so it is offered no link to open.
	 */
	public function test_render_withholds_the_preview_parameter_from_a_draft(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo( [], [ 'post_status' => 'draft' ] );

		ob_start();
		Meta_Box::get_instance()->render( $this->get_promo( $promo_id ) );
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'id="whim-preview-arg"', $output );
		$this->assertStringNotContainsString( 'id="whim-copy-preview"', $output );
		$this->assertStringContainsString( 'Publish this promo to get a preview link', $output );
	}

	/**
	 * An administrator gets the CSS field, the Load button, and the templates the
	 * button loads from.
	 */
	public function test_render_outputs_the_css_editor(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo();

		ob_start();
		Meta_Box::get_instance()->render( $this->get_promo( $promo_id ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="whim_custom_css"', $output );
		$this->assertStringContainsString( 'id="whim-load-template"', $output );
		$this->assertStringContainsString( '#whim-promo-' . $promo_id, $output );

		// The templates travel as JSON with tags hex-encoded, so a template can never
		// close the script element it rides in.
		$this->assertStringContainsString( '"editorial-insert"', $output );
		$this->assertStringNotContainsString( '</script></script>', $output );
	}

	/**
	 * An editor sees no CSS field, and saving without one leaves stored CSS alone.
	 */
	public function test_css_field_is_capability_gated(): void {
		wp_set_current_user( $this->create_user( 'editor' ) );

		$promo_id = $this->create_promo();

		update_post_meta( $promo_id, Styles::META, '#whim-promo a { color: red }' );

		ob_start();
		Meta_Box::get_instance()->render( $this->get_promo( $promo_id ) );
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'name="whim_custom_css"', $output );

		// The field is absent from the POST, which must not read as "cleared".
		$_POST = $this->post_payload( $promo_id );

		Meta_Box::get_instance()->save( $promo_id, $this->get_promo( $promo_id ) );

		$this->assertSame( '#whim-promo a { color: red }', get_post_meta( $promo_id, Styles::META, true ) );
	}

	/**
	 * An administrator's CSS is stored, sanitized.
	 */
	public function test_css_is_saved_and_sanitized(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo();

		$_POST = $this->post_payload(
			$promo_id,
			[ 'whim_custom_css' => "#whim-promo-{$promo_id} .whim-promo__card { color: red }</style><script>x</script>" ]
		);

		Meta_Box::get_instance()->save( $promo_id, $this->get_promo( $promo_id ) );

		$stored = (string) get_post_meta( $promo_id, Styles::META, true );

		$this->assertStringContainsString( '.whim-promo__card { color: red }', $stored );
		$this->assertStringNotContainsString( '</', $stored );
	}

	/**
	 * The script shows and hides the lifetime row by this id, so renaming it in the
	 * markup without updating the script would silently strand the row.
	 */
	public function test_render_outputs_the_row_id_the_script_binds_to(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		ob_start();
		Meta_Box::get_instance()->render( $this->get_promo( $this->create_promo() ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'whim-cookie-days-row', $output );
	}

	/**
	 * 0 is a meaningful answer now, not a validation error, so the input has to accept
	 * it for the save path below to ever be reached.
	 */
	public function test_cookie_days_input_accepts_zero(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		ob_start();
		Meta_Box::get_instance()->render( $this->get_promo( $this->create_promo() ) );
		$output = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<input[^>]*min="0"[^>]*name="whim_cookie_days"/', $output );
	}

	/**
	 * Zeroing the lifetime is the way back to an always-visible promo, and it has to
	 * work on the server too — the browser is not the only thing that posts this form.
	 */
	public function test_zero_days_clears_the_interaction_gate(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo( [ 'whim_show_until_interacted' => true ] );

		$_POST = $this->post_payload(
			$promo_id,
			[
				'whim_show_until_interacted' => '1',
				'whim_cookie_days'           => '0',
			]
		);
		Meta_Box::get_instance()->save( $promo_id, $this->get_promo( $promo_id ) );

		$this->assertFalse( (bool) get_post_meta( $promo_id, 'whim_show_until_interacted', true ) );
		$this->assertSame( Post_Type::DEFAULT_DAYS_INLINE, (int) get_post_meta( $promo_id, 'whim_cookie_days', true ) );
	}

	/**
	 * Same for an emptied field, which is what "delete it and save" posts.
	 */
	public function test_empty_days_clears_the_interaction_gate(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo( [ 'whim_show_until_interacted' => true ] );

		$_POST = $this->post_payload(
			$promo_id,
			[
				'whim_show_until_interacted' => '1',
				'whim_cookie_days'           => '',
			]
		);
		Meta_Box::get_instance()->save( $promo_id, $this->get_promo( $promo_id ) );

		$this->assertFalse( (bool) get_post_meta( $promo_id, 'whim_show_until_interacted', true ) );
	}

	/**
	 * The common state on disk once someone has cleared the field: the row is hidden but
	 * still submits, so an already-off gate posts an empty lifetime.
	 */
	public function test_gate_off_with_an_empty_lifetime_stores_the_default(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo( [ 'whim_show_until_interacted' => true ] );

		$_POST = $this->post_payload( $promo_id, [ 'whim_cookie_days' => '' ] );
		unset( $_POST['whim_show_until_interacted'] );

		Meta_Box::get_instance()->save( $promo_id, $this->get_promo( $promo_id ) );

		$this->assertFalse( (bool) get_post_meta( $promo_id, 'whim_show_until_interacted', true ) );
		$this->assertSame( Post_Type::DEFAULT_DAYS_INLINE, (int) get_post_meta( $promo_id, 'whim_cookie_days', true ) );
	}

	/**
	 * A real lifetime still keeps the gate on.
	 */
	public function test_a_real_lifetime_keeps_the_gate_on(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo();

		$_POST = $this->post_payload(
			$promo_id,
			[
				'whim_show_until_interacted' => '1',
				'whim_cookie_days'           => '14',
			]
		);
		Meta_Box::get_instance()->save( $promo_id, $this->get_promo( $promo_id ) );

		$this->assertTrue( (bool) get_post_meta( $promo_id, 'whim_show_until_interacted', true ) );
		$this->assertSame( 14, (int) get_post_meta( $promo_id, 'whim_cookie_days', true ) );
	}

	/**
	 * The remembered lifetime must survive toggling the gate off and on, which it only
	 * does while the input keeps submitting a value.
	 */
	public function test_cookie_days_input_is_never_disabled(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo( [ 'whim_show_until_interacted' => false ] );

		ob_start();
		Meta_Box::get_instance()->render( $this->get_promo( $promo_id ) );
		$output = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<input[^>]*id="whim_cookie_days"(?![^>]*disabled)[^>]*>/', $output );
	}
}
