<?php
/**
 * Tests for the cacheable per-promo stylesheet route.
 *
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo\Tests;

use WhimsicalPromo\CSS_Route;
use WhimsicalPromo\Styles;

/**
 * Class CSS_Route_Test
 */
class CSS_Route_Test extends Promo_TestCase {

	/**
	 * Clears request state between tests.
	 */
	public function tear_down(): void {
		unset( $_GET[ CSS_Route::QUERY_VAR ], $_GET[ CSS_Route::VERSION_VAR ] );

		parent::tear_down();
	}

	/**
	 * The URL names the promo and carries a fingerprint, which is what lets the
	 * response be cached forever.
	 */
	public function test_url_carries_the_promo_and_a_fingerprint(): void {
		$promo_id = $this->create_promo();
		$url      = CSS_Route::url( $promo_id );

		$this->assertStringContainsString( CSS_Route::QUERY_VAR . '=' . $promo_id, $url );
		$this->assertStringContainsString( CSS_Route::VERSION_VAR . '=' . Styles::version( $promo_id ), $url );
	}

	/**
	 * The fingerprint has to move when the design does, or an immutable URL would pin
	 * visitors to the old stylesheet.
	 */
	public function test_version_changes_when_the_design_changes(): void {
		$promo_id = $this->create_promo( [ 'whim_style_preset' => 'prime-time' ] );
		$before   = Styles::version( $promo_id );

		update_post_meta( $promo_id, Styles::META, '#whim-promo-ID .whim-promo__card { color: red }' );

		$this->assertNotSame( $before, Styles::version( $promo_id ) );
	}

	/**
	 * Two promos on the same template still get their own URL, because the CSS is
	 * scoped to each one's id.
	 */
	public function test_two_promos_get_different_urls(): void {
		$first  = $this->create_promo( [ 'whim_style_preset' => 'prime-time' ] );
		$second = $this->create_promo( [ 'whim_style_preset' => 'prime-time' ] );

		$this->assertNotSame( CSS_Route::url( $first ), CSS_Route::url( $second ) );
	}

	/**
	 * A published promo's stylesheet is served, scoped, and cacheable.
	 */
	public function test_serves_scoped_css_for_a_published_promo(): void {
		$promo_id = $this->create_promo( [ 'whim_style_preset' => 'prime-time' ] );

		$body = (string) CSS_Route::stylesheet( $promo_id );

		$this->assertStringContainsString( '#whim-promo-' . $promo_id, $body );
		$this->assertStringNotContainsString( '#whim-promo .', $body );

		// Minified on the way out, so none of the template's prose ships.
		$this->assertStringNotContainsString( '/*', $body );
	}

	/**
	 * A draft promo never renders, so its design must not be readable either.
	 */
	public function test_serves_nothing_for_an_unpublished_promo(): void {
		$promo_id = $this->create_promo( [], [ 'post_status' => 'draft' ] );

		// null, not empty CSS: the route is what turns that into a short-lived response.
		$this->assertNull( CSS_Route::stylesheet( $promo_id ) );
	}

	/**
	 * A post that is not a promo at all is not a way to read arbitrary meta.
	 */
	public function test_serves_nothing_for_another_post_type(): void {
		$post_id = self::factory()->post->create();

		$this->assertIsInt( $post_id );

		$this->assertNull( CSS_Route::stylesheet( $post_id ) );
	}

	/**
	 * Without the query argument the route is inert — every other page depends on it
	 * doing nothing.
	 */
	public function test_does_nothing_without_the_query_argument(): void {
		$this->create_promo();

		ob_start();
		CSS_Route::get_instance()->maybe_serve();
		$out = (string) ob_get_clean();

		$this->assertSame( '', $out );
	}

	/**
	 * The promo id is read as a number, so a crafted value cannot reach a lookup.
	 */
	public function test_a_non_numeric_id_is_ignored(): void {
		$_GET[ CSS_Route::QUERY_VAR ] = 'abc';

		ob_start();
		CSS_Route::get_instance()->maybe_serve();
		$out = (string) ob_get_clean();

		$this->assertSame( '', $out );
	}
}
