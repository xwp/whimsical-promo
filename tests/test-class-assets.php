<?php
/**
 * Tests for conditional asset loading.
 *
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo\Tests;

use WhimsicalPromo\Assets;
use WhimsicalPromo\Post_Type;
use WhimsicalPromo\Settings;
use WhimsicalPromo\Styles;

/**
 * Class Assets_Test
 */
class Assets_Test extends Promo_TestCase {

	/**
	 * Removes registered assets between tests.
	 */
	public function tear_down(): void {
		wp_dequeue_script( Assets::HANDLE );
		wp_dequeue_style( Assets::HANDLE );
		wp_deregister_script( Assets::HANDLE );
		wp_deregister_style( Assets::HANDLE );

		// Each promo now enqueues a stylesheet of its own, and leaving those queued
		// leaks into the next test's expectations.
		foreach ( array_keys( wp_styles()->registered ) as $handle ) {
			if ( 0 === strpos( (string) $handle, Assets::HANDLE . '-' ) ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			}
		}

		parent::tear_down();
	}

	/**
	 * Assets load on a singular view with an eligible promo.
	 */
	public function test_assets_enqueued_when_a_promo_renders(): void {
		$this->create_promo();
		$this->go_to_singular();

		Assets::get_instance()->enqueue();

		$this->assertTrue( wp_style_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( Assets::HANDLE, 'enqueued' ) );
	}

	/**
	 * No promos for this post type means no assets.
	 */
	public function test_assets_not_enqueued_without_eligible_promos(): void {
		$this->create_promo( [ 'whim_post_types' => [ 'page' ] ] );
		$this->go_to_singular();

		Assets::get_instance()->enqueue();

		$this->assertFalse( wp_style_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
	}

	/**
	 * Archive views never load promo assets.
	 */
	public function test_assets_not_enqueued_on_non_singular(): void {
		$this->create_promo();
		$this->visit( home_url( '/' ) );

		Assets::get_instance()->enqueue();

		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
	}

	/**
	 * Promo post type screens never load the front-end assets.
	 */
	public function test_assets_not_enqueued_on_a_promo_singular(): void {
		$promo_id = $this->create_promo();
		$this->visit( (string) get_permalink( $promo_id ) );

		Assets::get_instance()->enqueue();

		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertSame( Post_Type::POST_TYPE, get_post_type( $promo_id ) );
	}

	/**
	 * The promo's style ships as its own cacheable stylesheet, scoped to its wrapper id.
	 */
	public function test_style_css_is_served_and_scoped(): void {
		$promo_id = $this->create_promo( [ 'whim_style_preset' => 'prime-time' ] );
		$this->go_to_singular();

		Assets::get_instance()->enqueue();

		// Not inline any more: the promo gets a stylesheet of its own so the bytes are
		// cached once instead of re-sent with every HTML response.
		$this->assertTrue( wp_style_is( Assets::style_handle( $promo_id ), 'enqueued' ) );
		$this->assertSame( '', self::inline_style() );

		$served = Styles::css_for( $promo_id );

		$this->assertStringContainsString( '#whim-promo-' . $promo_id . ' .whim-promo__card', $served );
		$this->assertStringContainsString( 'whim-kf-' . $promo_id . '-bloom', $served );

		// The placeholder never reaches the page.
		$this->assertStringNotContainsString( '#whim-promo .', $served );
		$this->assertStringNotContainsString( ' whim-kf-bloom', $served );
	}

	/**
	 * Custom CSS replaces the template rather than adding to it.
	 */
	public function test_custom_css_replaces_the_template(): void {
		$promo_id = $this->create_promo(
			[
				'whim_style_preset' => 'prime-time',
				Styles::META        => '#whim-promo .whim-promo__card { outline: 3px dotted red; }',
			]
		);

		$this->go_to_singular();

		Assets::get_instance()->enqueue();

		$served = Styles::css_for( $promo_id );

		$this->assertStringContainsString( '#whim-promo-' . $promo_id . ' .whim-promo__card { outline: 3px dotted red; }', $served );
		$this->assertStringNotContainsString( 'on-air light', $served );
	}

	/**
	 * Two promos on one page get one style block each, scoped apart.
	 */
	public function test_each_promo_gets_its_own_scope(): void {
		$first  = $this->create_promo( [ 'whim_hook' => 'whim_test_hook' ] );
		$second = $this->create_promo( [ 'whim_hook' => 'whim_test_hook' ] );

		$this->go_to_singular();

		Assets::get_instance()->enqueue();

		// One stylesheet each, scoped apart, rather than one shared block.
		$this->assertTrue( wp_style_is( Assets::style_handle( $first ), 'enqueued' ) );
		$this->assertTrue( wp_style_is( Assets::style_handle( $second ), 'enqueued' ) );

		$this->assertStringContainsString( '#whim-promo-' . $first . ' ', Styles::css_for( $first ) );
		$this->assertStringContainsString( '#whim-promo-' . $second . ' ', Styles::css_for( $second ) );
	}

	/**
	 * An exit-intent promo's CSS is not loaded with the page: most page views never
	 * open it, so the script fetches it only once the promo can actually appear.
	 */
	public function test_exit_promo_css_is_not_enqueued(): void {
		$exit_id = $this->create_promo( [ 'whim_placement' => Post_Type::PLACEMENT_EXIT ] );

		$this->go_to_singular();

		Assets::get_instance()->enqueue();

		$this->assertTrue( wp_style_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Assets::style_handle( $exit_id ), 'enqueued' ) );
	}

	/**
	 * Per-promo stylesheets load without blocking the first paint, and still work with
	 * scripting off.
	 */
	public function test_promo_stylesheets_do_not_block_rendering(): void {
		$promo_id = $this->create_promo();

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- A literal tag fed to the filter under test, not an enqueue.
		$tag = "<link rel='stylesheet' id='" . Assets::style_handle( $promo_id ) . "-css' href='https://example.com/x.css' media='all' />";

		$filtered = Assets::get_instance()->make_non_blocking( $tag, Assets::style_handle( $promo_id ) );

		$this->assertStringContainsString( "media='print'", $filtered );
		$this->assertStringContainsString( "this.media='all'", $filtered );
		$this->assertStringContainsString( '<noscript>', $filtered );
	}

	/**
	 * Everything else keeps the tag it had — the base stylesheet is needed up front.
	 */
	public function test_other_stylesheets_are_left_alone(): void {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- A literal tag fed to the filter under test, not an enqueue.
		$tag = "<link rel='stylesheet' id='theme-css' href='https://example.com/t.css' media='all' />";

		$this->assertSame( $tag, Assets::get_instance()->make_non_blocking( $tag, 'theme' ) );
		$this->assertSame( $tag, Assets::get_instance()->make_non_blocking( $tag, Assets::HANDLE ) );
	}

	/**
	 * A dynamic stylesheet has no file on disk, so it must stay out of the platform's
	 * CSS concatenator.
	 */
	public function test_promo_stylesheets_are_not_concatenated(): void {
		$this->assertFalse( Assets::get_instance()->skip_concat( true, Assets::style_handle( 42 ) ) );
		$this->assertTrue( Assets::get_instance()->skip_concat( true, 'theme' ) );
	}

	/**
	 * The inline style attached to the promo stylesheet handle.
	 *
	 * @return string
	 */
	protected static function inline_style(): string {
		$after = wp_styles()->get_data( Assets::HANDLE, 'after' );

		return is_array( $after ) ? implode( '', $after ) : (string) $after;
	}

	/**
	 * The inline config mirrors the stored settings.
	 */
	public function test_inline_config_reflects_settings(): void {
		update_option(
			Settings::OPTION,
			[
				'tracking_enabled' => false,
				'delivery'         => 'gtag',
			]
		);

		$this->create_promo();
		$this->go_to_singular();

		Assets::get_instance()->enqueue();

		$before = wp_scripts()->get_data( Assets::HANDLE, 'before' );
		$inline = is_array( $before ) ? implode( '', $before ) : (string) $before;

		$this->assertStringContainsString( '"tracking":false', $inline );
		$this->assertStringContainsString( '"delivery":"gtag"', $inline );
	}
}
