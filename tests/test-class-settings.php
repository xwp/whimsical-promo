<?php
/**
 * Tests for the plugin settings page and option handling.
 *
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo\Tests;

use WhimsicalPromo\Settings;

/**
 * Class Settings_Test
 */
class Settings_Test extends Promo_TestCase {

	/**
	 * Clears the option between tests.
	 */
	public function tear_down(): void {
		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	/**
	 * Defaults apply when nothing is stored.
	 */
	public function test_defaults(): void {
		$this->assertSame(
			[
				'tracking_enabled'  => true,
				'delivery'          => 'datalayer',
				'content_selectors' => Settings::DEFAULT_CONTENT_SELECTORS,
			],
			Settings::get()
		);
	}

	/**
	 * An unknown delivery mode falls back to dataLayer.
	 */
	public function test_sanitize_rejects_unknown_delivery(): void {
		$sanitized = Settings::sanitize(
			[
				'tracking_enabled' => '1',
				'delivery'         => 'carrier_pigeon',
			]
		);

		$this->assertSame( 'datalayer', $sanitized['delivery'] );
		$this->assertTrue( $sanitized['tracking_enabled'] );
	}

	/**
	 * Valid input is preserved; a missing checkbox turns tracking off.
	 */
	public function test_sanitize_accepts_valid_input(): void {
		$sanitized = Settings::sanitize( [ 'delivery' => 'gtag' ] );

		$this->assertSame( 'gtag', $sanitized['delivery'] );
		$this->assertFalse( $sanitized['tracking_enabled'] );
	}

	/**
	 * Junk input degrades to defaults rather than exploding.
	 */
	public function test_sanitize_handles_non_array_input(): void {
		$this->assertSame(
			[
				'tracking_enabled'  => false,
				'delivery'          => 'datalayer',
				'content_selectors' => Settings::DEFAULT_CONTENT_SELECTORS,
			],
			Settings::sanitize( 'nope' )
		);
	}

	/**
	 * Selectors are normalised, and an emptied field goes back to the default rather
	 * than leaving the mobile trigger with nothing to watch.
	 */
	public function test_selector_sanitization(): void {
		$this->assertSame( '.article, main', Settings::sanitize_selectors( "  .article ,, main  \n" ) );
		$this->assertSame( Settings::DEFAULT_CONTENT_SELECTORS, Settings::sanitize_selectors( '   ' ) );
		$this->assertSame( Settings::DEFAULT_CONTENT_SELECTORS, Settings::sanitize_selectors( str_repeat( 'a', 501 ) ) );

		update_option( Settings::OPTION, [ 'content_selectors' => '.article, main' ] );

		$this->assertSame( [ '.article', 'main' ], Settings::selector_list() );
	}

	/**
	 * Stored values reach the JS config.
	 */
	public function test_js_config(): void {
		update_option(
			Settings::OPTION,
			[
				'tracking_enabled' => true,
				'delivery'         => 'gtag',
			]
		);

		$this->assertSame(
			[
				'tracking'         => true,
				'delivery'         => 'gtag',
				'contentSelectors' => [ '.entry-content', '.post-content', 'article', 'main' ],
			],
			Settings::js_config()
		);
	}

	/**
	 * The option is registered with a sanitize callback.
	 */
	public function test_option_is_registered_with_sanitizer(): void {
		Settings::get_instance()->register_settings();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( Settings::OPTION, $registered );
		$this->assertSame( [ Settings::class, 'sanitize' ], $registered[ Settings::OPTION ]['sanitize_callback'] );

		// Sanitization runs through the Settings API, not only on direct calls.
		update_option( Settings::OPTION, [ 'delivery' => 'carrier_pigeon' ] );

		$this->assertSame( 'datalayer', Settings::get()['delivery'] );
	}

	/**
	 * The page renders for administrators only.
	 */
	public function test_page_render_requires_manage_options(): void {
		wp_set_current_user( $this->create_user( 'subscriber' ) );

		ob_start();
		Settings::get_instance()->render_page();

		$this->assertSame( '', (string) ob_get_clean() );

		wp_set_current_user( $this->create_user( 'administrator' ) );

		ob_start();
		Settings::get_instance()->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="' . Settings::OPTION . '[delivery]"', $output );
		$this->assertStringContainsString( 'name="' . Settings::OPTION . '[tracking_enabled]"', $output );
	}

	/**
	 * The page carries the GTM/GA4 documentation the README describes.
	 */
	public function test_page_includes_setup_documentation(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		ob_start();
		Settings::get_instance()->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( Settings::EVENT_NAME, $output );
		$this->assertStringContainsString( 'bogo_placement', $output );

		// The client's analytics naming: no parameter on this page may still say promo.
		$this->assertStringNotContainsString( 'promo_id', $output );
		$this->assertStringNotContainsString( 'promo_action', $output );
		$this->assertStringContainsString( 'Custom Event', $output );
		$this->assertStringContainsString( 'GA4 Event', $output );
		$this->assertStringContainsString( 'custom dimension', $output );
		$this->assertStringContainsString( 'key event', $output );
		$this->assertStringContainsString( 'DebugView', $output );
	}
}
