<?php
/**
 * Tests for style templates, scoping and CSS sanitizing.
 *
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo\Tests;

use WhimsicalPromo\Styles;

/**
 * Class Styles_Test
 */
class Styles_Test extends Promo_TestCase {

	/**
	 * The template cache must not leak a filtered registry between tests.
	 */
	public function tear_down(): void {
		Styles::flush();

		parent::tear_down();
	}

	/**
	 * Every bundled style has a readable template.
	 */
	public function test_bundled_templates_are_readable(): void {
		foreach ( Styles::slugs() as $slug ) {
			$this->assertNotSame( '', Styles::template( $slug ), $slug . ' has no template CSS' );
		}
	}

	/**
	 * basic-1 stays the default, so an existing promo keeps the look it had.
	 */
	public function test_default_style(): void {
		$this->assertSame( 'basic-1', Styles::default_slug() );
	}

	/**
	 * Every bundled template scopes itself to the placeholder rather than to a
	 * bare class, otherwise it would leak onto every promo on the page.
	 */
	public function test_templates_use_the_placeholder(): void {
		foreach ( Styles::slugs() as $slug ) {
			$css = Styles::template( $slug );

			$this->assertStringContainsString( '#whim-promo', $css, $slug );

			// A selector starting at `.whim-promo` would apply to every promo on the
			// page, not just this one.
			foreach ( explode( "\n", $css ) as $number => $line ) {
				$this->assertStringStartsNotWith(
					'.whim-promo',
					ltrim( $line ),
					$slug . '.css line ' . ( $number + 1 ) . ' is scoped to a class instead of #whim-promo'
				);
			}
		}
	}

	/**
	 * Minifying a bundled template must not change what it means: the braces still
	 * balance, nothing is left of the comments, and the Google chip's data URI — the
	 * one string in the corpus that a careless regex would corrupt — is byte-identical.
	 */
	public function test_minify_preserves_every_template(): void {
		foreach ( Styles::slugs() as $slug ) {
			$raw = Styles::template( $slug );
			$min = Styles::minify( $raw );

			// Compared against the comment-stripped source, not the raw file: a template
			// docblock may itself contain braces, and losing those is the point.
			$stripped = (string) preg_replace( '#/\*.*?\*/#s', '', $raw );

			$this->assertSame(
				substr_count( $stripped, '{' ),
				substr_count( $min, '{' ),
				$slug . ' lost or gained a block'
			);
			$this->assertSame( substr_count( $min, '{' ), substr_count( $min, '}' ), $slug . ' braces do not balance' );
			$this->assertStringNotContainsString( '/*', $min, $slug . ' kept a comment' );
			$this->assertLessThan( strlen( $raw ), strlen( $min ), $slug . ' did not get smaller' );

			preg_match( '#url\("(data:image/svg\+xml,[^"]+)"\)#', $raw, $found );

			if ( isset( $found[1] ) ) {
				$this->assertStringContainsString( $found[1], $min, $slug . ' corrupted a data URI' );
			}

			// Declarations survive with their values intact, whitespace aside.
			$this->assertMatchesRegularExpression( '/#whim-promo[^{]*\{/', $min, $slug . ' lost its scoping' );
		}
	}

	/**
	 * A space before a colon is a descendant combinator, not a separator, so it has to
	 * survive — `.body :is(h1)` and `.body:is(h1)` select different elements.
	 */
	public function test_minify_keeps_descendant_combinators_before_pseudo_classes(): void {
		$min = Styles::minify( '#whim-promo .whim-promo__body :is(h1, h2) { color: red; }' );

		$this->assertStringContainsString( '__body :is(h1,h2)', $min );
		$this->assertStringNotContainsString( '__body:is', $min );
	}

	/**
	 * Author CSS is never minified — a comment holding `*​/` inside a string would be
	 * mangled, and there is no corpus to test that against.
	 */
	public function test_author_css_is_not_minified(): void {
		$promo_id = $this->create_promo();
		$authored = "#whim-promo-ID .whim-promo__card {\n\t/* keep me */\n\tcolor: red;\n}";

		update_post_meta( $promo_id, Styles::META, $authored );

		$css = Styles::css_for( $promo_id );

		$this->assertStringContainsString( '/* keep me */', $css );
		$this->assertStringContainsString( "\n", $css );
	}

	/**
	 * A promo on a bundled template gets the minified form.
	 */
	public function test_template_css_is_minified_on_output(): void {
		$promo_id = $this->create_promo( [ 'whim_style_preset' => 'prime-time' ] );

		$css = Styles::css_for( $promo_id );

		$this->assertStringNotContainsString( '/*', $css );
		$this->assertStringContainsString( '#whim-promo-' . $promo_id, $css );
	}

	/**
	 * Every @keyframes in a bundled template carries the rewritable prefix.
	 */
	public function test_template_keyframes_are_prefixed(): void {
		foreach ( Styles::slugs() as $slug ) {
			preg_match_all( '/@keyframes\s+([\w-]+)/', Styles::template( $slug ), $matches );

			foreach ( $matches[1] as $name ) {
				$this->assertStringStartsWith( 'whim-kf-', $name, $slug . ' defines ' . $name );
			}
		}
	}

	/**
	 * Templates go through the same sanitizer as pasted CSS, so none of them may
	 * contain anything the sanitizer would strip.
	 */
	public function test_templates_survive_sanitizing_unchanged(): void {
		foreach ( Styles::slugs() as $slug ) {
			$css = Styles::template( $slug );

			$this->assertSame(
				trim( $css ),
				Styles::sanitize_css( $css ),
				$slug . '.css is altered by sanitizing'
			);
		}
	}

	/**
	 * Scoping rewrites the placeholder and the animation prefix together.
	 */
	public function test_scope_rewrites_placeholder_and_keyframes(): void {
		$css = '#whim-promo .whim-promo__card { animation: whim-kf-pop 1s; } @keyframes whim-kf-pop { to { opacity: 1; } }';

		$scoped = Styles::scope( $css, 412 );

		$this->assertStringContainsString( '#whim-promo-412 .whim-promo__card', $scoped );
		$this->assertStringContainsString( 'animation: whim-kf-412-pop 1s', $scoped );
		$this->assertStringContainsString( '@keyframes whim-kf-412-pop', $scoped );
	}

	/**
	 * The `ID` placeholder is the form the brief teaches, so it has to scope.
	 */
	public function test_scope_rewrites_the_id_placeholder(): void {
		$css = '#whim-promo-ID .whim-promo__card { animation: whim-kf-ID-flash 1s; } @keyframes whim-kf-ID-flash { to { opacity: 1; } }';

		$scoped = Styles::scope( $css, 4940657 );

		$this->assertStringContainsString( '#whim-promo-4940657 .whim-promo__card', $scoped );
		$this->assertStringContainsString( 'animation: whim-kf-4940657-flash 1s', $scoped );
		$this->assertStringContainsString( '@keyframes whim-kf-4940657-flash', $scoped );
		$this->assertStringNotContainsString( 'ID', $scoped );
	}

	/**
	 * A keyframe name that merely starts with the placeholder's letters is not one.
	 */
	public function test_scope_does_not_eat_names_beginning_with_id(): void {
		$scoped = Styles::scope( '#whim-promo .whim-promo__card { animation: whim-kf-idle 1s; }', 9 );

		$this->assertStringContainsString( 'whim-kf-9-idle', $scoped );
	}

	/**
	 * to_placeholder() is the inverse, so a real stylesheet can be shown as an example
	 * without teaching a concrete id.
	 */
	public function test_to_placeholder_generalises_a_scoped_stylesheet(): void {
		$scoped = Styles::scope( '#whim-promo .whim-promo__card { animation: whim-kf-pop 1s; }', 412 );
		$back   = Styles::to_placeholder( $scoped );

		$this->assertStringContainsString( '#whim-promo-ID .whim-promo__card', $back );
		$this->assertStringContainsString( 'whim-kf-ID-pop', $back );
		$this->assertStringNotContainsString( '412', $back );

		// And it round-trips back onto any promo.
		$this->assertSame( $scoped, Styles::scope( $back, 412 ) );
	}

	/**
	 * The element's own classes stay untouched — only the id root is rewritten.
	 */
	public function test_scope_leaves_class_names_alone(): void {
		$scoped = Styles::scope( '#whim-promo.whim-promo--modal .whim-promo__body a {}', 7 );

		$this->assertStringContainsString( '#whim-promo-7.whim-promo--modal .whim-promo__body a', $scoped );
	}

	/**
	 * Scoping is idempotent, so CSS duplicated from another promo lands on this one
	 * rather than compounding ids and prefixes.
	 */
	public function test_scope_is_idempotent(): void {
		$once  = Styles::scope( '#whim-promo .whim-promo__card { animation: whim-kf-pop 1s; }', 11 );
		$twice = Styles::scope( $once, 11 );

		$this->assertSame( $once, $twice );

		// Pasted from promo 11 into promo 22.
		$moved = Styles::scope( $once, 22 );

		$this->assertStringContainsString( '#whim-promo-22 ', $moved );
		$this->assertStringNotContainsString( '#whim-promo-11', $moved );
		$this->assertStringContainsString( 'whim-kf-22-pop', $moved );
		$this->assertStringNotContainsString( 'whim-kf-11-pop', $moved );
	}

	/**
	 * Confining is a no-op on every bundled template, byte for byte. That is both the
	 * proof it does not mangle correct CSS and the guard against a future template
	 * shipping a stray global rule.
	 */
	public function test_confine_leaves_bundled_templates_untouched(): void {
		foreach ( Styles::slugs() as $slug ) {
			$scoped = Styles::scope( Styles::minify( Styles::template( $slug ) ), 412 );

			$this->assertSame( $scoped, Styles::confine( $scoped, 412 ), $slug . ' was altered by confining' );
		}
	}

	/**
	 * Nothing an author can write escapes the wrapper.
	 *
	 * @dataProvider data_unconfined_css
	 *
	 * @param string $css      CSS as written.
	 * @param string $expected CSS as served.
	 */
	public function test_confine_contains_every_selector( string $css, string $expected ): void {
		$this->assertSame( $expected, Styles::confine( $css, 412 ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function data_unconfined_css(): array {
		return [
			'bare type selector'    => [ 'p{font-weight:bold}', '#whim-promo-412 p{font-weight:bold}' ],
			'body'                  => [ 'body{overflow:hidden}', '#whim-promo-412 body{overflow:hidden}' ],
			'root custom property'  => [ ':root{--x:red}', '#whim-promo-412 :root{--x:red}' ],
			'universal'             => [ '*{margin:0}', '#whim-promo-412 *{margin:0}' ],

			// Every part of the list, not just the first.
			'selector list'         => [ 'p,.sidebar{color:red}', '#whim-promo-412 p,#whim-promo-412 .sidebar{color:red}' ],

			// A foreign promo id is not this promo's scope.
			'another promo id'      => [ '#whim-promo-999 p{color:red}', '#whim-promo-412 #whim-promo-999 p{color:red}' ],

			// An id that merely starts with ours.
			'id with a longer tail' => [ '#whim-promo-4120 p{color:red}', '#whim-promo-412 #whim-promo-4120 p{color:red}' ],

			'inside a media query'  => [ '@media screen{p{color:red}}', '@media screen{#whim-promo-412 p{color:red}}' ],
			'inside @supports'      => [ '@supports (display:grid){p{color:red}}', '@supports (display:grid){#whim-promo-412 p{color:red}}' ],
			'inside @container'     => [ '@container (inline-size < 24rem){p{color:red}}', '@container (inline-size < 24rem){#whim-promo-412 p{color:red}}' ],

			// Offsets, not elements.
			'keyframe offsets'      => [ '@keyframes whim-kf-412-x{from{opacity:0}to{opacity:1}}', '@keyframes whim-kf-412-x{from{opacity:0}to{opacity:1}}' ],

			// Already scoped, in each of the forms a selector can continue with.
			'scoped descendant'     => [ '#whim-promo-412 .whim-promo__card{color:red}', '#whim-promo-412 .whim-promo__card{color:red}' ],
			'scoped compound'       => [ '#whim-promo-412.whim-promo--modal{color:red}', '#whim-promo-412.whim-promo--modal{color:red}' ],
			'scoped pseudo'         => [ '#whim-promo-412:where(.x) p{color:red}', '#whim-promo-412:where(.x) p{color:red}' ],
			'the wrapper itself'    => [ '#whim-promo-412{color:red}', '#whim-promo-412{color:red}' ],
		];
	}

	/**
	 * A comma inside `:is()` or an attribute value is not a list separator.
	 */
	public function test_confine_does_not_split_inside_functions_or_attributes(): void {
		$this->assertSame(
			'#whim-promo-412 :is(h1,h2){color:red}',
			Styles::confine( ':is(h1,h2){color:red}', 412 )
		);

		$this->assertSame(
			'#whim-promo-412 input[type="a,b"],#whim-promo-412 button{color:red}',
			Styles::confine( 'input[type="a,b"],button{color:red}', 412 )
		);
	}

	/**
	 * A brace inside a comment or a `url()` string must not be read as a block.
	 */
	public function test_confine_ignores_braces_in_comments_and_strings(): void {
		$this->assertSame(
			'/* } */#whim-promo-412 p{color:red}',
			Styles::confine( '/* } */p{color:red}', 412 )
		);

		$this->assertSame(
			'#whim-promo-412 p{background:url("data:image/svg+xml,<svg>{}</svg>")}',
			Styles::confine( 'p{background:url("data:image/svg+xml,<svg>{}</svg>")}', 412 )
		);
	}

	/**
	 * A nested block is contained by its confined parent, so its own selectors are left
	 * as written rather than double-scoped.
	 */
	public function test_confine_leaves_nested_selectors_alone(): void {
		$this->assertSame(
			'#whim-promo-412 .whim-promo__card{& p{color:red}}',
			Styles::confine( '.whim-promo__card{& p{color:red}}', 412 )
		);
	}

	/**
	 * Rendered CSS is confined, whatever the author wrote.
	 */
	public function test_css_for_confines_stored_meta(): void {
		$promo_id = $this->create_promo();

		update_post_meta( $promo_id, Styles::META, 'p { font-weight: bold }' );

		$css = Styles::css_for( $promo_id );

		$this->assertStringContainsString( '#whim-promo-' . $promo_id . ' p', $css );
		$this->assertStringNotContainsString( "\np {", "\n" . $css );
	}

	/**
	 * A `</` sequence cannot escape the style element it is printed in.
	 */
	public function test_sanitize_css_cannot_close_the_style_element(): void {
		$safe = Styles::sanitize_css( 'a { color: red }</style><script>alert(1)</script>' );

		$this->assertStringNotContainsString( '</', $safe );
		$this->assertStringNotContainsString( '</style>', $safe );
	}

	/**
	 * Range syntax survives: `<` is only dangerous as part of `</`.
	 */
	public function test_sanitize_css_keeps_range_queries(): void {
		$css = '@container (inline-size < 24rem) { a { color: red } }';

		$this->assertSame( $css, Styles::sanitize_css( $css ) );
	}

	/**
	 * @import is a third-party render-blocking fetch, so it goes.
	 */
	public function test_sanitize_css_drops_imports(): void {
		$safe = Styles::sanitize_css( '@import url("https://example.com/x.css"); a { color: red }' );

		$this->assertStringNotContainsString( '@import', $safe );
		$this->assertStringContainsString( 'color: red', $safe );
	}

	/**
	 * The field is length-capped.
	 */
	public function test_sanitize_css_caps_length(): void {
		$safe = Styles::sanitize_css( str_repeat( 'a', Styles::MAX_LENGTH + 500 ) );

		$this->assertSame( Styles::MAX_LENGTH, strlen( $safe ) );
	}

	/**
	 * Picking a style and saving nothing else is a complete choice: the template is
	 * what renders.
	 */
	public function test_css_for_falls_back_to_the_template(): void {
		$promo_id = $this->create_promo( [ 'whim_style_preset' => 'editorial-insert' ] );

		$css = Styles::css_for( $promo_id );

		$this->assertStringContainsString( '#whim-promo-' . $promo_id, $css );
		$this->assertStringContainsString( 'whim-kf-' . $promo_id . '-ping', $css );
	}

	/**
	 * Meta written outside the meta box — REST, WP-CLI, an import — is sanitized on
	 * the way out as well.
	 */
	public function test_css_for_sanitizes_stored_meta(): void {
		$promo_id = $this->create_promo();

		update_post_meta( $promo_id, Styles::META, '#whim-promo a { color: red }</style><script>x</script>' );

		$css = Styles::css_for( $promo_id );

		$this->assertStringContainsString( '#whim-promo-' . $promo_id . ' a { color: red }', $css );
		$this->assertStringNotContainsString( '</', $css );
	}
}
