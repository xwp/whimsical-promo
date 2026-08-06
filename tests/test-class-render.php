<?php
/**
 * Tests for promo collection and rendering.
 *
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo\Tests;

use WhimsicalPromo\Meta_Box;
use WhimsicalPromo\Post_Type;
use WhimsicalPromo\Render;
use WhimsicalPromo\Styles;
use WP_Post;

/**
 * Class Render_Test
 */
class Render_Test extends Promo_TestCase {

	/**
	 * Only published promos targeting the current post type are collected.
	 */
	public function test_collect_filters_by_status_and_post_type(): void {
		$eligible = $this->create_promo();
		$this->create_promo( [], [ 'post_status' => 'draft' ] );
		$this->create_promo( [ 'whim_post_types' => [ 'page' ] ] );

		$this->go_to_singular();

		$inline = Render::get_instance()->get_inline_promos();

		$this->assertArrayHasKey( Meta_Box::default_hook(), $inline );
		$this->assertSame( [ $eligible ], wp_list_pluck( $inline[ Meta_Box::default_hook() ], 'ID' ) );
	}

	/**
	 * Nothing is collected outside singular views.
	 */
	public function test_collect_skips_non_singular(): void {
		$this->create_promo();
		$this->visit( home_url( '/' ) );

		$this->assertFalse( Render::get_instance()->has_promos() );
	}

	/**
	 * Promos are grouped by hook and ordered by menu_order.
	 */
	public function test_collect_groups_by_hook_and_orders_by_menu_order(): void {
		$second = $this->create_promo( [], [ 'menu_order' => 2 ] );
		$first  = $this->create_promo( [], [ 'menu_order' => 1 ] );
		$other  = $this->create_promo( [ 'whim_hook' => 'custom_theme_hook' ] );

		$this->go_to_singular();

		$inline = Render::get_instance()->get_inline_promos();

		$this->assertSame( [ $first, $second ], wp_list_pluck( $inline[ Meta_Box::default_hook() ], 'ID' ) );
		$this->assertSame( [ $other ], wp_list_pluck( $inline['custom_theme_hook'], 'ID' ) );
	}

	/**
	 * The should-render filter can veto an individual promo.
	 */
	public function test_should_render_filter_vetoes_promo(): void {
		$vetoed = $this->create_promo();
		$kept   = $this->create_promo( [ 'whim_hook' => 'other_hook' ] );

		add_filter(
			'whimsical_promo_should_render',
			static function ( $should, WP_Post $promo ) use ( $vetoed ) {
				return (int) $promo->ID !== $vetoed ? $should : false;
			},
			10,
			2
		);

		$this->go_to_singular();

		$inline = Render::get_instance()->get_inline_promos();

		$this->assertArrayNotHasKey( Meta_Box::default_hook(), $inline );
		$this->assertSame( [ $kept ], wp_list_pluck( $inline['other_hook'], 'ID' ) );
	}

	/**
	 * Promos render on whatever hook the editor typed.
	 */
	public function test_renders_at_arbitrary_hook(): void {
		$this->create_promo( [ 'whim_hook' => 'totally_custom_hook' ] );
		$this->go_to_singular();

		$output = $this->capture_hook( 'totally_custom_hook' );

		$this->assertStringContainsString( 'class="whim-bogo-slot"', $output );
		$this->assertStringContainsString( 'whim-bogo--inline-hook', $output );
	}

	/**
	 * The default placement appends the chain to the post body.
	 */
	public function test_after_content_hook_appends_to_the_content(): void {
		$this->create_promo( [ 'whim_hook' => Post_Type::CONTENT_HOOK ] );
		$this->go_to_singular();

		$filtered = $this->filter_the_content( 'Body copy.' );

		$this->assertStringContainsString( 'class="whim-bogo-slot"', $filtered );

		// Appended, not prepended: priority 20 runs after wpautop, so the chain lands
		// past the body rather than above it.
		$this->assertLessThan(
			strpos( $filtered, 'whim-bogo-slot' ),
			strpos( $filtered, 'Body copy.' )
		);
	}

	/**
	 * A single-post feed passes every loop check, so it is excluded by name.
	 */
	public function test_after_content_hook_skips_feeds(): void {
		$this->create_promo( [ 'whim_hook' => Post_Type::CONTENT_HOOK ] );
		$this->go_to_singular();

		$GLOBALS['wp_query']->is_feed = true;

		$filtered = $this->filter_the_content( 'Body copy.' );

		$this->assertStringContainsString( 'Body copy.', $filtered );
		$this->assertStringNotContainsString( 'whim-bogo-slot', $filtered );
	}

	/**
	 * A hook name that turns out to be a filter must not eat the filtered value:
	 * add_action() and add_filter() share one registry, so a callback returning
	 * nothing used to blank whatever it was attached to.
	 */
	public function test_a_filter_hook_keeps_its_value(): void {
		$this->create_promo( [ 'whim_hook' => 'whim_test_filter' ] );
		$this->go_to_singular();

		ob_start();
		$value  = apply_filters( 'whim_test_filter', 'kept' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Test hook.
		$output = (string) ob_get_clean();

		$this->assertSame( 'kept', $value );
		$this->assertStringContainsString( 'class="whim-bogo-slot"', $output );
	}

	/**
	 * The chosen style travels as a wrapper class and a data attribute.
	 */
	public function test_style_class_and_attribute(): void {
		$this->create_promo( [ 'whim_style_preset' => 'prime-time' ] );
		$this->go_to_singular();

		$output = $this->capture_hook( Meta_Box::default_hook() );

		$this->assertStringContainsString( 'whim-bogo--style-prime-time', $output );
		$this->assertStringContainsString( 'data-whim-style="prime-time"', $output );
	}

	/**
	 * A promo holding an unknown style renders the default.
	 */
	public function test_style_falls_back_to_default(): void {
		$this->create_promo( [ 'whim_style_preset' => 'nope-not-a-style' ] );
		$this->go_to_singular();

		$output = $this->capture_hook( Meta_Box::default_hook() );

		$this->assertStringContainsString( 'whim-bogo--style-basic-1', $output );
		$this->assertStringNotContainsString( 'nope-not-a-style', $output );
	}

	/**
	 * A style registered by a theme is accepted; a malformed slug is not.
	 */
	public function test_style_filter(): void {
		add_filter(
			'whimsical_promo_styles',
			static function ( $styles ) {
				$styles['winter-holiday'] = [
					'label' => 'Winter holiday',
					'file'  => Styles::directory() . 'basic-1.css',
				];

				$styles['Not A Slug'] = [
					'label' => 'Rejected',
					'file'  => Styles::directory() . 'basic-1.css',
				];

				// No file: also rejected.
				$styles['no-file'] = [ 'label' => 'Rejected' ];

				return $styles;
			}
		);

		$slugs = Styles::slugs();

		$this->assertContains( 'winter-holiday', $slugs );
		$this->assertNotContains( 'Not A Slug', $slugs );
		$this->assertNotContains( 'no-file', $slugs );
		$this->assertSame( 'winter-holiday', Post_Type::sanitize_meta( 'whim_style_preset', 'winter-holiday' ) );
	}

	/**
	 * The wrapper carries the id a promo's CSS is scoped to.
	 */
	public function test_wrapper_id(): void {
		$promo_id = $this->create_promo();
		$this->go_to_singular();

		$this->assertStringContainsString(
			'id="whim-bogo-' . $promo_id . '"',
			$this->capture_hook( Meta_Box::default_hook() )
		);
	}

	/**
	 * The slug the markup carries is the one the editor screen shows as the
	 * `?whim_preview=` target, so both have to come from the same place.
	 */
	public function test_promo_slug_matches_the_rendered_attribute(): void {
		$promo_id = $this->create_promo( [], [ 'post_name' => 'spring-signup' ] );
		$this->go_to_singular();

		$this->assertSame( 'spring-signup', Render::promo_slug( $this->get_promo( $promo_id ) ) );
		$this->assertStringContainsString(
			'data-whim-slug="spring-signup"',
			$this->capture_hook( Meta_Box::default_hook() )
		);
	}

	/**
	 * A promo with no post name still gets a stable slug — drafts and
	 * auto-drafts have not been given one yet.
	 */
	public function test_promo_slug_falls_back_to_the_id(): void {
		$promo_id = $this->create_promo();
		$promo    = $this->get_promo( $promo_id );

		$promo->post_name = '';

		$this->assertSame( 'promo-' . $promo_id, Render::promo_slug( $promo ) );
	}

	/**
	 * Every runtime setting travels as a data attribute on the wrapper.
	 */
	public function test_data_attributes(): void {
		$this->create_promo(
			[
				'whim_hook'                  => 'my_hook',
				'whim_cookie_days'           => 90,
				'whim_show_until_interacted' => true,
				'whim_animation'             => 'fade-rise',
				'whim_style_bg'              => 'var(--en-accent)',
				'whim_style_radius'          => '20px',
			],
			[ 'post_name' => 'newsletter-inline' ]
		);

		$this->go_to_singular();

		$output = $this->capture_hook( 'my_hook' );

		$this->assertStringContainsString( 'data-whim-slug="newsletter-inline"', $output );
		$this->assertStringContainsString( 'data-whim-placement="inline_hook"', $output );
		$this->assertStringContainsString( 'data-whim-days="90"', $output );
		$this->assertStringContainsString( 'data-whim-gate="interact"', $output );
		$this->assertStringContainsString( 'data-whim-preset="fade-rise"', $output );
		$this->assertStringContainsString( '--whim-bg: var(--en-accent); --whim-radius: 20px;', $output );
		$this->assertStringContainsString( ' hidden>', $output );
	}

	/**
	 * A promo that always shows reports gate=always.
	 */
	public function test_gate_always_when_show_until_interacted_is_off(): void {
		$this->create_promo(
			[
				'whim_hook'                  => 'my_hook',
				'whim_show_until_interacted' => false,
			]
		);

		$this->go_to_singular();

		$this->assertStringContainsString( 'data-whim-gate="always"', $this->capture_hook( 'my_hook' ) );
	}

	/**
	 * Rejected style tokens never reach the style attribute.
	 */
	public function test_invalid_style_token_is_omitted(): void {
		$this->create_promo(
			[
				'whim_hook'     => 'my_hook',
				'whim_style_bg' => 'red; position: fixed',
			]
		);

		$this->go_to_singular();

		$output = $this->capture_hook( 'my_hook' );

		$this->assertStringNotContainsString( 'position', $output );

		// Leading space, so this does not match `data-whim-style=`.
		$this->assertStringNotContainsString( ' style="', $output );
	}

	/**
	 * Chain members all render, hidden, inside one slot.
	 */
	public function test_chain_members_render_hidden_in_one_slot(): void {
		$this->create_promo( [ 'whim_hook' => 'my_hook' ], [ 'menu_order' => 1 ] );
		$this->create_promo( [ 'whim_hook' => 'my_hook' ], [ 'menu_order' => 2 ] );

		$this->go_to_singular();

		$output = $this->capture_hook( 'my_hook' );

		$this->assertSame( 1, substr_count( $output, 'whim-bogo-slot' ) );
		$this->assertSame( 2, substr_count( $output, 'class="whim-bogo ' ) );
		$this->assertSame( 2, substr_count( $output, ' hidden>' ) );
	}

	/**
	 * Chain group ids are stable across renders — page caches stay valid.
	 */
	public function test_group_id_is_deterministic(): void {
		$this->assertSame( Render::group_id( 'inline:my_hook' ), Render::group_id( 'inline:my_hook' ) );
		$this->assertNotSame( Render::group_id( 'inline:a' ), Render::group_id( 'inline:b' ) );
	}

	/**
	 * A hook that fires twice prints its chain once.
	 */
	public function test_duplicate_hook_fire_prints_once(): void {
		$this->create_promo( [ 'whim_hook' => 'my_hook' ] );
		$this->go_to_singular();

		$output = $this->capture_hook( 'my_hook' ) . $this->capture_hook( 'my_hook' );

		$this->assertSame( 1, substr_count( $output, 'whim-bogo-slot' ) );
	}

	/**
	 * The once-per-page guard is per hook, not global.
	 */
	public function test_two_hooks_both_render(): void {
		$this->create_promo( [ 'whim_hook' => 'hook_one' ] );
		$this->create_promo( [ 'whim_hook' => 'hook_two' ] );

		$this->go_to_singular();

		$this->assertStringContainsString( 'whim-bogo-slot', $this->capture_hook( 'hook_one' ) );
		$this->assertStringContainsString( 'whim-bogo-slot', $this->capture_hook( 'hook_two' ) );
	}

	/**
	 * Authored markup is filtered; shortcode output is not.
	 */
	public function test_body_pipeline_filters_authored_markup_but_keeps_shortcode_output(): void {
		add_shortcode(
			'whim_test_form',
			static function () {
				return '<form id="signup"><input type="email" name="email" /></form>';
			}
		);

		$this->create_promo(
			[ 'whim_hook' => 'my_hook' ],
			[ 'post_content' => '<script>alert(1)</script><form id="typed"><input name="x" /></form><p class="lead">Hi</p>[whim_test_form]' ]
		);

		$this->go_to_singular();

		$output = $this->capture_hook( 'my_hook' );

		remove_shortcode( 'whim_test_form' );

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringNotContainsString( 'id="typed"', $output );
		$this->assertStringContainsString( '<p class="lead">Hi</p>', $output );
		$this->assertStringContainsString( '<form id="signup">', $output );
		$this->assertStringContainsString( '<input type="email" name="email" />', $output );
	}

	/**
	 * Block markup survives the kses pass with its classes and links intact.
	 */
	public function test_block_body_survives_kses(): void {
		$content = '<!-- wp:paragraph {"className":"lead"} --><p class="lead">Read on</p><!-- /wp:paragraph -->'
			. '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button -->'
			. '<div class="wp-block-button"><a class="wp-block-button__link" href="/subscribe/">Subscribe</a></div>'
			. '<!-- /wp:button --></div><!-- /wp:buttons -->';

		$this->create_promo( [ 'whim_hook' => 'my_hook' ], [ 'post_content' => $content ] );
		$this->go_to_singular();

		$output = $this->capture_hook( 'my_hook' );

		$this->assertStringContainsString( '<p class="lead">Read on</p>', $output );
		$this->assertStringContainsString( 'wp-block-buttons', $output );
		$this->assertStringContainsString( 'class="wp-block-button__link" href="/subscribe/"', $output );
		$this->assertStringNotContainsString( 'wp:paragraph', $output );
	}

	/**
	 * A paragraph holding nothing but a link becomes a call to action; the same
	 * link inside a sentence stays prose.
	 */
	public function test_promotes_only_standalone_links(): void {
		$content = '<p><a href="/subscribe/">Subscribe now</a></p>'
			// The block editor readily leaves a trailing nbsp behind.
			. '<p><a href="/join/">Join&nbsp;the list</a>&nbsp;</p>'
			. '<p>Already a subscriber? <a href="/account/">Manage alerts</a>.</p>';

		$this->create_promo( [ 'whim_hook' => 'my_hook' ], [ 'post_content' => $content ] );
		$this->go_to_singular();

		$output = $this->capture_hook( 'my_hook' );

		$this->assertStringContainsString( '<p><a class="whim-cta" href="/subscribe/">Subscribe now</a></p>', $output );
		$this->assertStringContainsString( '<a class="whim-cta" href="/join/">Join&nbsp;the list</a>', $output );
		$this->assertStringContainsString( '<a href="/account/">Manage alerts</a>', $output );
		$this->assertStringNotContainsString( 'whim-cta" href="/account/"', $output );
	}

	/**
	 * A promo typed as plain lines gets paragraphs, so its standalone link is
	 * promoted like any other. Without wpautop there is no paragraph to match and the
	 * link renders bare, which is how a promo silently loses its button.
	 */
	public function test_plain_text_body_gets_paragraphs_and_a_cta(): void {
		$content = "Get the morning briefing.\n\n<a href=\"/subscribe/\">Sign me up</a>";

		$this->create_promo( [ 'whim_hook' => 'my_hook' ], [ 'post_content' => $content ] );
		$this->go_to_singular();

		$output = $this->capture_hook( 'my_hook' );

		$this->assertStringContainsString( '<p>Get the morning briefing.</p>', $output );
		$this->assertStringContainsString( '<a class="whim-cta" href="/subscribe/">Sign me up</a>', $output );
	}

	/**
	 * Blank trailing lines do not become empty paragraphs the card has to space out.
	 */
	public function test_blank_lines_do_not_become_empty_paragraphs(): void {
		$content = "Get the morning briefing.\n\n&nbsp;\n\n<a href=\"/subscribe/\">Sign me up</a>\n\n&nbsp;";

		$this->create_promo( [ 'whim_hook' => 'my_hook' ], [ 'post_content' => $content ] );
		$this->go_to_singular();

		$output = $this->capture_hook( 'my_hook' );

		$this->assertStringNotContainsString( '<p>&nbsp;</p>', $output );
		$this->assertStringNotContainsString( '<p></p>', $output );
		$this->assertStringContainsString( '<a class="whim-cta" href="/subscribe/">Sign me up</a>', $output );
	}

	/**
	 * `whim-link` opts a standalone link out, and an existing class list survives.
	 */
	public function test_promotion_respects_whim_link_and_keeps_classes(): void {
		$content = '<p><a class="whim-link" href="/rules/">Read the rules</a></p>'
			. '<p><a class="is-style-outline" href="/join/">Join</a></p>';

		$this->create_promo( [ 'whim_hook' => 'my_hook' ], [ 'post_content' => $content ] );
		$this->go_to_singular();

		$output = $this->capture_hook( 'my_hook' );

		$this->assertStringContainsString( '<a class="whim-link" href="/rules/">', $output );
		$this->assertStringContainsString( '<a class="whim-cta is-style-outline" href="/join/">', $output );
	}

	/**
	 * A paragraph with two links is prose, not a call to action.
	 */
	public function test_promotion_skips_multi_link_paragraph(): void {
		$content = '<p><a href="/a/">One</a> <a href="/b/">Two</a></p>';

		$this->create_promo( [ 'whim_hook' => 'my_hook' ], [ 'post_content' => $content ] );
		$this->go_to_singular();

		$this->assertStringNotContainsString( 'whim-cta', $this->capture_hook( 'my_hook' ) );
	}

	/**
	 * The kses allowlist filter can widen what promo bodies may contain.
	 */
	public function test_kses_allowlist_filter(): void {
		add_filter(
			'whimsical_promo_kses_allowlist',
			static function ( $allowlist ) {
				$allowlist['form']  = [ 'id' => true ];
				$allowlist['input'] = [
					'type' => true,
					'name' => true,
				];

				return $allowlist;
			}
		);

		$this->create_promo(
			[ 'whim_hook' => 'my_hook' ],
			[ 'post_content' => '<form id="typed"><input type="email" name="email" /></form>' ]
		);

		$this->go_to_singular();

		$this->assertStringContainsString( 'id="typed"', $this->capture_hook( 'my_hook' ) );
	}

	/**
	 * The wrapper class filter can add classes.
	 */
	public function test_wrapper_class_filter(): void {
		add_filter(
			'whimsical_promo_wrapper_class',
			static function ( $classes ) {
				$classes[] = 'en-theme-promo';

				return $classes;
			}
		);

		$this->create_promo( [ 'whim_hook' => 'my_hook' ] );
		$this->go_to_singular();

		$this->assertStringContainsString( 'en-theme-promo', $this->capture_hook( 'my_hook' ) );
	}

	/**
	 * Exit-intent promos ignore their hook meta and render in the footer.
	 */
	public function test_exit_intent_renders_only_in_footer(): void {
		$this->setExpectedDeprecated( 'the_block_template_skip_link' );

		$this->create_promo(
			[
				'whim_placement'    => Post_Type::PLACEMENT_EXIT,
				'whim_hook'         => 'my_hook',
				'whim_presentation' => 'modal',
				'whim_cookie_days'  => 30,
			]
		);

		$this->go_to_singular();

		$this->assertSame( '', $this->capture_hook( 'my_hook' ) );

		$footer = $this->capture_hook( 'wp_footer' );

		$this->assertStringContainsString( 'whim-bogo-exit', $footer );
		$this->assertStringContainsString( 'data-whim-placement="exit_intent"', $footer );
		$this->assertStringContainsString( 'data-whim-presentation="modal"', $footer );
		$this->assertStringContainsString( 'data-whim-days="30"', $footer );
		$this->assertStringContainsString( 'whim-bogo__close', $footer );
		$this->assertStringContainsString( 'whim-bogo__backdrop', $footer );
		$this->assertStringContainsString( 'role="dialog"', $footer );

		// The dialog takes focus itself, so it has to be reachable programmatically.
		$this->assertStringContainsString( 'tabindex="-1"', $footer );

		// Dismiss comes after the body, so the first Tab out of the dialog reaches the
		// promo's own controls rather than the × button.
		$this->assertGreaterThan(
			(int) strpos( $footer, 'whim-bogo__body' ),
			(int) strpos( $footer, 'whim-bogo__close' ),
			'The close button must come after the body in the markup.'
		);
	}

	/**
	 * The mobile end-of-content trigger is opt-in, and travels as an attribute so the
	 * decision stays out of the cached HTML's variability.
	 */
	public function test_mobile_end_trigger_is_opt_in(): void {
		$this->setExpectedDeprecated( 'the_block_template_skip_link' );

		$promo_id = $this->create_promo( [ 'whim_placement' => Post_Type::PLACEMENT_EXIT ] );

		$this->go_to_singular();

		$this->assertStringNotContainsString( 'data-whim-mobile-end', $this->capture_hook( 'wp_footer' ) );

		update_post_meta( $promo_id, 'whim_mobile_end', true );
		Render::get_instance()->collect();

		$this->assertStringContainsString( 'data-whim-mobile-end="1"', $this->capture_hook( 'wp_footer' ) );
	}

	/**
	 * Non-modal exit promos get no backdrop.
	 */
	public function test_non_modal_exit_promo_has_no_backdrop(): void {
		$this->setExpectedDeprecated( 'the_block_template_skip_link' );

		$this->create_promo(
			[
				'whim_placement'    => Post_Type::PLACEMENT_EXIT,
				'whim_presentation' => 'slide-up',
			]
		);

		$this->go_to_singular();

		$footer = $this->capture_hook( 'wp_footer' );

		$this->assertStringContainsString( 'whim-bogo--slide-up', $footer );
		$this->assertStringNotContainsString( 'whim-bogo__backdrop', $footer );
	}

	/**
	 * Output does not vary by user agent or visitor cookies.
	 */
	public function test_output_is_byte_identical_across_user_agents_and_cookies(): void {
		$this->create_promo(
			[
				'whim_hook'      => 'my_hook',
				'whim_placement' => Post_Type::PLACEMENT_INLINE,
			]
		);
		$this->create_promo( [ 'whim_placement' => Post_Type::PLACEMENT_EXIT ] );

		$this->go_to_singular();

		$desktop = $this->capture_hook( 'my_hook' );

		Render::get_instance()->reset();

		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables -- The point of this test is that the render path ignores these.
		$_SERVER['HTTP_USER_AGENT']             = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15';
		$_COOKIE['whim_seen_newsletter-inline'] = '1234567890';
		$_COOKIE['wordpress_logged_in_abc']     = 'someone';

		Render::get_instance()->collect();

		$mobile = $this->capture_hook( 'my_hook' );

		unset( $_SERVER['HTTP_USER_AGENT'], $_COOKIE['whim_seen_newsletter-inline'], $_COOKIE['wordpress_logged_in_abc'] );
		// phpcs:enable WordPressVIPMinimum.Variables.RestrictedVariables

		$this->assertSame( $desktop, $mobile );
		$this->assertNotSame( '', $desktop );
	}
}
