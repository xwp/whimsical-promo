<?php
/**
 * Keeps the static JS fixture honest.
 *
 * fixtures/promo-chain.html is a hand-written copy of the skeleton Render emits, so
 * promo.js can be tested without WordPress. The risk that buys is drift: rename an
 * attribute in Render and the browser tests keep passing against markup that no longer
 * ships. These tests fail instead.
 *
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo\Tests;

use WhimsicalPromo\Post_Type;
use WhimsicalPromo\Render;

/**
 * Class Fixture_Markup_Test
 */
class Fixture_Markup_Test extends Promo_TestCase {

	/**
	 * The fixture's markup.
	 *
	 * @return string
	 */
	protected static function fixture(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A test fixture on local disk.
		$html = file_get_contents( __DIR__ . '/fixtures/promo-chain.html' );

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Everything Render actually emits, inline and exit together.
	 *
	 * Both are needed: the close button and the backdrop exist only on exit promos, so
	 * comparing the fixture against inline output alone would report false drift.
	 *
	 * @return string
	 */
	protected function rendered(): string {
		$this->create_promo( [ 'whim_hook' => 'whim_fixture_hook' ] );
		$this->create_promo(
			[
				'whim_placement'    => Post_Type::PLACEMENT_EXIT,
				'whim_presentation' => 'modal',
			]
		);

		$this->go_to_singular();

		ob_start();
		do_action( 'whim_fixture_hook' );

		// The exit renderer directly rather than the whole `wp_footer` action, which
		// would also fire core's deprecated block-template skip link.
		Render::get_instance()->render_exit_chain();

		return (string) ob_get_clean();
	}

	/**
	 * The fixture exists and is not empty.
	 */
	public function test_fixture_is_readable(): void {
		$this->assertNotSame( '', self::fixture(), 'fixtures/promo-chain.html is missing or empty' );
	}

	/**
	 * Every data attribute the fixture carries is one Render still emits. A renamed
	 * attribute would otherwise leave the browser tests green against dead markup.
	 */
	public function test_fixture_attributes_still_exist_in_rendered_output(): void {
		$rendered = $this->rendered();

		// Comments stripped first: the fixture's own explanatory comment names
		// `data-whim-css` in prose, and that mention is not an attribute to verify.
		$markup = (string) preg_replace( '/<!--.*?-->/s', '', self::fixture() );

		preg_match_all( '/data-whim-[a-z-]+/', $markup, $found );

		$attributes = array_unique( $found[0] );

		$this->assertNotEmpty( $attributes, 'the fixture carries no data-whim-* attributes' );

		foreach ( $attributes as $attribute ) {
			$this->assertStringContainsString(
				$attribute,
				$rendered,
				$attribute . ' is in the fixture but no longer rendered — the fixture has drifted'
			);
		}
	}

	/**
	 * The structural classes and hooks promo.js selects on.
	 */
	public function test_fixture_structure_matches_rendered_output(): void {
		$rendered = $this->rendered();
		$fixture  = self::fixture();

		foreach ( [ 'whim-promo-slot', 'whim-promo__card', 'whim-promo__body', 'whim-promo__close', 'data-whim-close' ] as $part ) {
			$this->assertStringContainsString( $part, $fixture, $part . ' is missing from the fixture' );
			$this->assertStringContainsString( $part, $rendered, $part . ' is no longer rendered' );
		}
	}

	/**
	 * The fixture's placement and gate values have to be ones the plugin actually uses.
	 */
	public function test_fixture_uses_real_placement_and_gate_values(): void {
		$fixture = self::fixture();

		$this->assertStringContainsString( 'data-whim-placement="' . Post_Type::PLACEMENT_INLINE . '"', $fixture );

		// chainWinner() treats anything other than `interact` as always-visible, so the
		// fixture needs one of each for the hand-off test to mean anything.
		$this->assertStringContainsString( 'data-whim-gate="interact"', $fixture );
		$this->assertStringContainsString( 'data-whim-gate="always"', $fixture );
	}

	/**
	 * The config global promo.js reads is the one Assets prints.
	 */
	public function test_fixture_declares_the_real_config_global(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Plugin source on local disk.
		$script = (string) file_get_contents( dirname( __DIR__ ) . '/assets/promo.js' );

		$this->assertStringContainsString( 'window.whimPromoCfg', $script );
		$this->assertStringContainsString( 'window.whimPromoCfg', self::fixture() );
	}

	/**
	 * The path the Playwright spec navigates to is hardcoded there, because Playwright
	 * cannot ask WordPress where the plugin lives. This is the other half of that
	 * contract: if plugins_url() ever resolves somewhere else — a renamed directory, a
	 * different content path on a host — this fails here rather than as an unexplained
	 * 404 in a browser run.
	 */
	public function test_playwright_fixture_path_matches_plugins_url(): void {
		$spec = dirname( __DIR__, 3 ) . '/tests/playwright/tests/whimsical-promo.spec.ts';

		if ( ! is_readable( $spec ) ) {
			$this->markTestSkipped( 'The Playwright suite is not present in this checkout.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A spec file on local disk, guarded by is_readable() above.
		$contents = (string) file_get_contents( $spec );

		preg_match( "/const FIXTURE = '([^']+)'/", $contents, $found );

		$this->assertNotEmpty( $found[1] ?? '', 'could not find the FIXTURE constant in the spec' );

		$expected = wp_make_link_relative(
			plugins_url( 'tests/fixtures/promo-chain.html', \WhimsicalPromo\PLUGIN_FILE )
		);

		$this->assertSame(
			$expected,
			$found[1],
			'the spec navigates to a path the plugin no longer lives at'
		);
	}

	/**
	 * Fixture slugs must not be able to collide with a real promo's cookie, since
	 * promo.js writes them at `path=/`.
	 */
	public function test_fixture_slugs_cannot_collide_with_real_promos(): void {
		preg_match_all( '/data-whim-slug="([^"]+)"/', self::fixture(), $found );

		$this->assertNotEmpty( $found[1] );

		foreach ( $found[1] as $slug ) {
			// A post_name can never begin with an underscore, so this space is ours.
			$this->assertStringStartsWith( '__whim_fixture', $slug, $slug . ' could collide with a real promo slug' );
		}
	}
}
