<?php
/**
 * Tests for the AI agent design brief.
 *
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo\Tests;

use WhimsicalPromo\Agent_Brief;
use WhimsicalPromo\Post_Type;
use WhimsicalPromo\Styles;

/**
 * Class Agent_Brief_Test
 */
class Agent_Brief_Test extends Promo_TestCase {

	/**
	 * The brief teaches the placeholder, never a concrete id: a real id in the brief
	 * would read as part of the contract, and the rewrite would then look like magic
	 * when the same CSS is pasted into a different promo.
	 */
	public function test_brief_teaches_the_placeholder_not_a_real_id(): void {
		$promo_id = $this->create_promo();
		$brief    = Agent_Brief::text( $promo_id );

		$this->assertStringContainsString( '#whim-bogo-' . Styles::ID_PLACEHOLDER, $brief );
		$this->assertStringContainsString( 'whim-kf-' . Styles::ID_PLACEHOLDER . '-', $brief );

		// No concrete id in either position. Not a bare digit check: a test post id like
		// 26 collides with values such as `26px` in the worked example.
		$this->assertDoesNotMatchRegularExpression( '/#whim-bogo-\d/', $brief );
		$this->assertDoesNotMatchRegularExpression( '/\bwhim-kf-\d/', $brief );

		// A bare `#whim-bogo` would still scope, but it teaches the wrong shape.
		$this->assertDoesNotMatchRegularExpression( '/#whim-bogo(?![\w-])/', $brief );
	}

	/**
	 * Every element of the fixed skeleton is described, since the agent cannot add to
	 * it and has no other way to learn it.
	 */
	public function test_brief_describes_the_whole_markup_contract(): void {
		$brief = Agent_Brief::text( $this->create_promo() );

		foreach ( [ 'whim-bogo-slot', 'whim-bogo__card', 'whim-bogo__body', 'whim-bogo__close', 'whim-bogo__backdrop' ] as $part ) {
			$this->assertStringContainsString( $part, $brief, $part . ' is missing from the brief.' );
		}

		foreach ( [ 'is-revealed', 'is-dismissing', 'is-clicked', '[hidden]' ] as $state ) {
			$this->assertStringContainsString( $state, $brief, $state . ' is missing from the brief.' );
		}
	}

	/**
	 * Both placements, all three presentations and every registered animation are
	 * offered, so a design covers what an editor can actually pick.
	 */
	public function test_brief_lists_every_choice_an_editor_can_make(): void {
		$brief   = Agent_Brief::text( $this->create_promo() );
		$choices = Post_Type::choices();

		$this->assertStringContainsString( 'whim-bogo--inline-hook', $brief );
		$this->assertStringContainsString( 'whim-bogo--exit-intent', $brief );

		foreach ( $choices['whim_presentation'] as $presentation ) {
			$this->assertStringContainsString( 'whim-bogo--' . $presentation, $brief, $presentation . ' is missing.' );
		}

		foreach ( $choices['whim_animation'] as $preset ) {
			$this->assertStringContainsString( 'whim-bogo--preset-' . $preset, $brief, $preset . ' is missing.' );
		}
	}

	/**
	 * Every token the editor can override is listed, or a design would quietly ignore
	 * those fields.
	 */
	public function test_brief_lists_every_style_token(): void {
		$brief = Agent_Brief::text( $this->create_promo() );

		foreach ( Post_Type::STYLE_TOKENS as $token ) {
			$this->assertStringContainsString( '--whim-' . $token, $brief, $token . ' is missing.' );
		}
	}

	/**
	 * The worked example is the promo's own style, in full, in the same placeholder form
	 * the agent is asked to write — one dialect throughout, not two.
	 */
	public function test_brief_includes_the_selected_style_as_a_worked_example(): void {
		$promo_id = $this->create_promo( [ 'whim_style_preset' => 'prime-time' ] );
		$brief    = Agent_Brief::text( $promo_id );
		$expected = Styles::to_placeholder( Styles::template( 'prime-time' ) );

		$this->assertStringContainsString( $expected, $brief );
	}

	/**
	 * The rules that keep a pasted design working are stated, not implied: the keyframe
	 * prefix, the :where() entry offsets, and both state classes an entrance uses.
	 */
	public function test_brief_states_the_rules_that_break_a_design_silently(): void {
		$brief = Agent_Brief::text( $this->create_promo() );

		$this->assertStringContainsString( 'whim-kf-', $brief );
		$this->assertStringContainsString( ':where(', $brief );
		$this->assertStringContainsString( 'is-lit', $brief );
		$this->assertStringContainsString( 'prefers-reduced-motion', $brief );
	}

	/**
	 * No template may ship a scroll-driven entrance: an ad script makes <body> a scroll
	 * container, so `view()` binds to it and the card sticks at its end state.
	 */
	public function test_no_template_uses_a_scroll_driven_timeline(): void {
		foreach ( array_keys( Styles::all() ) as $slug ) {
			$this->assertStringNotContainsString(
				'animation-timeline',
				Styles::template( (string) $slug ),
				$slug . ' uses a scroll-driven timeline, which is inert on this site.'
			);
		}
	}

	/**
	 * The copy button's script finds the brief and the button by these ids, so they are
	 * a contract rather than markup detail.
	 */
	public function test_meta_box_offers_the_brief_to_a_css_editor(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$promo_id = $this->create_promo();

		ob_start();
		\WhimsicalPromo\Meta_Box::get_instance()->render( $this->get_promo( $promo_id ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="whim-agent-brief"', $output );
		$this->assertStringContainsString( 'id="whim-copy-brief"', $output );
	}
}
