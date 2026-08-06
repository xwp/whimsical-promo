<?php
/**
 * Style templates and the per-promo CSS they seed.
 *
 * A promo's look is one block of CSS scoped to that promo's wrapper id. It comes
 * either from the editor's own Custom CSS field or, when that is empty, from the
 * selected style's template file. Either way it goes through the same scoping pass,
 * so both paths behave identically and there is only one thing to debug.
 *
 * Scoping is what makes the layering work. `#whim-bogo-412 …` is 1-0-0, so a style
 * always outranks promo-base.css (0-2-0 at most) without `!important`, and the
 * inline `style` attribute the Style token fields produce still outranks the style.
 * Weakest to strongest: base, style CSS, token fields.
 *
 * @since 1.0.0
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo;

/**
 * Class Styles
 *
 * @since   1.0.0
 * @package WhimsicalPromo
 */
class Styles {

	/**
	 * Meta key holding the editor's own CSS.
	 *
	 * @var string
	 */
	const META = 'whim_custom_css';

	/**
	 * Maximum accepted length of the Custom CSS field, in bytes.
	 *
	 * Comfortably above the largest built-in template.
	 *
	 * @var int
	 */
	const MAX_LENGTH = 100000;

	/**
	 * Template CSS keyed by absolute path. Populated on first read.
	 *
	 * @var array<string,string>
	 */
	protected static array $cache = [];

	/**
	 * Built-in styles, in editor order. The first entry is the default.
	 *
	 * @return array<string,string> Slug => human label.
	 */
	public static function built_in(): array {
		return [
			'basic-1'          => __( 'basic-1 — Clean (white card, accent hairline, ink pill)', 'whimsical-promo' ),
			'editorial-insert' => __( 'Editorial insert (paper, print asterism, sonar ping)', 'whimsical-promo' ),
			'prime-time'       => __( 'Prime time (broadcast gradient, glass ring, lens bloom)', 'whimsical-promo' ),
		];
	}

	/**
	 * Every selectable style.
	 *
	 * @return array<string,array{label:string,file:string}>
	 */
	public static function all(): array {
		$styles = self::bundled();

		/**
		 * Filters the selectable styles.
		 *
		 * A theme adds a style by pointing at its own template file:
		 *
		 *     add_filter( 'whimsical_promo_styles', function ( $styles ) {
		 *         $styles['house-style'] = [
		 *             'label' => 'House style',
		 *             'file'  => get_stylesheet_directory() . '/promo-styles/house-style.css',
		 *         ];
		 *         return $styles;
		 *     } );
		 *
		 * The template must scope its selectors to `#whim-bogo` and name every
		 * `@keyframes` with a `whim-kf-` prefix — see assets/styles/basic-1.css.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,array{label:string,file:string}> $styles Styles, default first.
		 */
		$styles = (array) apply_filters( 'whimsical_promo_styles', $styles );

		$valid = [];

		foreach ( $styles as $slug => $style ) {
			$slug = (string) $slug;

			// The slug becomes part of a CSS class and a data attribute.
			if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
				continue;
			}

			if ( ! is_array( $style ) || empty( $style['file'] ) ) {
				continue;
			}

			$valid[ $slug ] = [
				'label' => isset( $style['label'] ) ? (string) $style['label'] : $slug,
				'file'  => (string) $style['file'],
			];
		}

		// Never hand back an empty set: the default slug has to keep resolving.
		return empty( $valid ) ? self::bundled() : $valid;
	}

	/**
	 * The built-in styles as a full registry.
	 *
	 * @return array<string,array{label:string,file:string}>
	 */
	protected static function bundled(): array {
		$styles = [];

		foreach ( self::built_in() as $slug => $label ) {
			$styles[ $slug ] = [
				'label' => $label,
				'file'  => self::directory() . $slug . '.css',
			];
		}

		return $styles;
	}

	/**
	 * Directory holding the built-in templates, with a trailing slash.
	 *
	 * @return string
	 */
	public static function directory(): string {
		return plugin_dir_path( PLUGIN_FILE ) . 'assets/styles/';
	}

	/**
	 * Style slugs, in editor order.
	 *
	 * @return string[]
	 */
	public static function slugs(): array {
		return array_keys( self::all() );
	}

	/**
	 * The default style slug.
	 *
	 * @return string
	 */
	public static function default_slug(): string {
		$slugs = self::slugs();

		return (string) reset( $slugs );
	}

	/**
	 * Reads a style's template CSS.
	 *
	 * @param string $slug Style slug.
	 *
	 * @return string Template CSS, empty when the style or its file is missing.
	 */
	public static function template( string $slug ): string {
		$styles = self::all();

		if ( ! isset( $styles[ $slug ] ) ) {
			return '';
		}

		$file = $styles[ $slug ]['file'];

		if ( isset( self::$cache[ $file ] ) ) {
			return self::$cache[ $file ];
		}

		$css = '';

		if ( is_readable( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A stylesheet on local disk, and cached in self::$cache for the request.
			$contents = file_get_contents( $file );
			$css      = is_string( $contents ) ? $contents : '';
		}

		self::$cache[ $file ] = $css;

		return $css;
	}

	/**
	 * Clears the template cache. Used between requests in tests.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache = [];
	}

	/**
	 * The wrapper id a promo's CSS is scoped to.
	 *
	 * @param int $post_id Promo ID.
	 *
	 * @return string
	 */
	public static function wrapper_id( int $post_id ): string {
		return 'whim-bogo-' . $post_id;
	}

	/**
	 * Makes stored CSS safe to print inside a `<style>` element.
	 *
	 * Only `</` can end a raw-text element, so that is what has to go; a bare `<`
	 * or `>` is left alone because range syntax needs them — `@container
	 * (inline-size < 24rem)` is in the default template. `@import` goes because it
	 * is a render-blocking third-party fetch, not because it is unsafe.
	 *
	 * This is not a sandbox. The field is capability-gated (see can_edit()) for the
	 * same reason core gates Additional CSS: `url()` in CSS can reach a third-party
	 * host, so writing it is an administrator-level decision.
	 *
	 * @param string $css Raw CSS.
	 *
	 * @return string
	 */
	public static function sanitize_css( string $css ): string {
		if ( '' === trim( $css ) ) {
			return '';
		}

		$css = substr( $css, 0, self::MAX_LENGTH );

		// Control characters, keeping the whitespace CSS actually uses.
		$css = preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', (string) $css );
		$css = str_replace( '</', '', (string) $css );
		$css = preg_replace( '/@import\b[^;{}]*;?/i', '', (string) $css );

		return trim( is_string( $css ) ? $css : '' );
	}

	/**
	 * The id placeholder an author writes, in place of a real promo id.
	 *
	 * @var string
	 */
	const ID_PLACEHOLDER = 'ID';

	/**
	 * Rewrites a template's placeholders for one specific promo.
	 *
	 * `#whim-bogo-ID` becomes `#whim-bogo-<id>`, and `whim-kf-ID-` gains the same id.
	 * A bare `#whim-bogo` / `whim-kf-` and an already-rewritten id both match too, so
	 * running this twice — or on CSS copied out of one promo and into another — lands on
	 * the current promo rather than compounding.
	 *
	 * @param string $css     CSS to scope.
	 * @param int    $post_id Promo ID.
	 *
	 * @return string
	 */
	public static function scope( string $css, int $post_id ): string {
		if ( '' === $css ) {
			return '';
		}

		$css = preg_replace(
			'/#whim-bogo(?:-(?:\d+|' . self::ID_PLACEHOLDER . '))?(?![\w-])/i',
			'#' . self::wrapper_id( $post_id ),
			$css
		);

		$css = preg_replace(
			'/\bwhim-kf-(?:(?:\d+|' . self::ID_PLACEHOLDER . ')-)?/i',
			'whim-kf-' . $post_id . '-',
			(string) $css
		);

		return is_string( $css ) ? $css : '';
	}

	/**
	 * Rewrites scoped CSS back into the placeholder form an author writes.
	 *
	 * The inverse of scope(), for showing a real stylesheet as a worked example without
	 * teaching a concrete id as if it were part of the contract.
	 *
	 * @param string $css CSS to generalise.
	 *
	 * @return string
	 */
	public static function to_placeholder( string $css ): string {
		if ( '' === $css ) {
			return '';
		}

		$css = preg_replace(
			'/#whim-bogo(?:-(?:\d+|' . self::ID_PLACEHOLDER . '))?(?![\w-])/i',
			'#whim-bogo-' . self::ID_PLACEHOLDER,
			$css
		);

		$css = preg_replace(
			'/\bwhim-kf-(?:(?:\d+|' . self::ID_PLACEHOLDER . ')-)?/i',
			'whim-kf-' . self::ID_PLACEHOLDER . '-',
			(string) $css
		);

		return is_string( $css ) ? $css : '';
	}

	/**
	 * At-rules whose body holds style rules, so confining has to recurse into them.
	 *
	 * Anything else with a body is passed through untouched: `@keyframes` selectors are
	 * offsets, not elements, and `from` must never gain a prefix.
	 *
	 * @var string[]
	 */
	const NESTING_AT_RULES = [ 'media', 'supports', 'container', 'layer', 'scope', 'starting-style' ];

	/**
	 * Confines every top-level selector to the promo's own wrapper.
	 *
	 * A selector that does not already start at `#whim-bogo-<id>` is prefixed with it,
	 * so CSS written as `p { font-weight: bold }` styles paragraphs inside the card
	 * instead of every paragraph on the page. Run after scope(), which is what makes the
	 * id concrete enough to compare against.
	 *
	 * This closes the accidental leak, not a hostile one. `@font-face` and `@property`
	 * register globally because they have no selector to confine, and `url()` can still
	 * reach a third-party host — can_edit() remains the actual boundary.
	 *
	 * @param string $css     Scoped CSS.
	 * @param int    $post_id Promo ID.
	 *
	 * @return string
	 */
	public static function confine( string $css, int $post_id ): string {
		if ( '' === trim( $css ) ) {
			return '';
		}

		return self::confine_block( $css, '#' . self::wrapper_id( $post_id ) );
	}

	/**
	 * Confines the top-level rules of one block of CSS.
	 *
	 * @param string $css   CSS to walk.
	 * @param string $scope Wrapper id selector, including the `#`.
	 *
	 * @return string
	 */
	protected static function confine_block( string $css, string $scope ): string {
		$out     = '';
		$prelude = '';
		$length  = strlen( $css );
		$i       = 0;

		while ( $i < $length ) {
			$char = $css[ $i ];

			// Comments and strings can hold braces, so they move the cursor, never the depth.
			if ( '/' === $char && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$end      = strpos( $css, '*/', $i + 2 );
				$stop     = false === $end ? $length : $end + 2;
				$prelude .= substr( $css, $i, $stop - $i );
				$i        = $stop;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$stop     = self::end_of_string( $css, $i );
				$prelude .= substr( $css, $i, $stop - $i );
				$i        = $stop;
				continue;
			}

			// A statement at-rule such as `@layer base;` — no body, nothing to confine.
			if ( ';' === $char ) {
				$out    .= $prelude . ';';
				$prelude = '';
				++$i;
				continue;
			}

			// Unbalanced: dropping it is safer than letting it close a block further out.
			if ( '}' === $char ) {
				$out    .= $prelude;
				$prelude = '';
				++$i;
				continue;
			}

			if ( '{' === $char ) {
				$close = self::end_of_block( $css, $i );
				$body  = substr( $css, $i + 1, $close - $i - 1 );
				$tail  = $close < $length ? '}' : '';
				// Leading comments and whitespace either side are kept where the author put
				// them, so confined CSS is still the file they wrote.
				preg_match( '#^((?:\s|/\*.*?\*/)*)(.*?)(\s*)$#s', $prelude, $parts );

				$lead  = $parts[1] ?? '';
				$rule  = $parts[2] ?? '';
				$trail = $parts[3] ?? '';

				if ( '' !== $rule && '@' === $rule[0] ) {
					if ( self::at_rule_holds_rules( $rule ) ) {
						$body = self::confine_block( $body, $scope );
					}

					$out .= $prelude . '{' . $body . $tail;
				} else {
					$out .= $lead . self::confine_selectors( $rule, $scope ) . $trail . '{' . $body . $tail;
				}

				$prelude = '';
				$i       = $close + 1;
				continue;
			}

			$prelude .= $char;
			++$i;
		}

		return $out . $prelude;
	}

	/**
	 * Prefixes each selector in a comma-separated list that is not already scoped.
	 *
	 * @param string $selectors Selector list.
	 * @param string $scope     Wrapper id selector, including the `#`.
	 *
	 * @return string
	 */
	protected static function confine_selectors( string $selectors, string $scope ): string {
		$confined = [];

		// Every part independently: `p, .sidebar` has to confine both, not just the first.
		foreach ( self::split_selectors( $selectors ) as $part ) {
			preg_match( '/^(\s*)(.*?)(\s*)$/s', $part, $around );

			$before   = $around[1] ?? '';
			$selector = $around[2] ?? '';
			$after    = $around[3] ?? '';

			if ( '' === $selector ) {
				continue;
			}

			// Exact, not a prefix match: a hand-written `#whim-bogo-999` is a foreign id
			// and has to be confined like anything else.
			$scoped = 1 === preg_match( '/^' . preg_quote( $scope, '/' ) . '(?![\w-])/', $selector );

			// The part's own whitespace is put back, so a list written one selector per
			// line is served that way.
			$confined[] = $before . ( $scoped ? $selector : $scope . ' ' . $selector ) . $after;
		}

		return implode( ',', $confined );
	}

	/**
	 * Splits a selector list on its top-level commas.
	 *
	 * @param string $selectors Selector list.
	 *
	 * @return string[]
	 */
	protected static function split_selectors( string $selectors ): array {
		$parts  = [];
		$buffer = '';
		$depth  = 0;
		$length = strlen( $selectors );
		$i      = 0;

		while ( $i < $length ) {
			$char = $selectors[ $i ];

			// `:is(h1, h2)` and `input[type="a"], button` both die to a plain explode().
			if ( '/' === $char && '*' === ( $selectors[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $selectors, '*/', $i + 2 );
				$i   = false === $end ? $length : $end + 2;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$stop    = self::end_of_string( $selectors, $i );
				$buffer .= substr( $selectors, $i, $stop - $i );
				$i       = $stop;
				continue;
			}

			if ( '(' === $char || '[' === $char ) {
				++$depth;
			}

			if ( ')' === $char || ']' === $char ) {
				--$depth;
			}

			if ( ',' === $char && $depth <= 0 ) {
				$parts[] = $buffer;
				$buffer  = '';
				++$i;
				continue;
			}

			$buffer .= $char;
			++$i;
		}

		$parts[] = $buffer;

		return $parts;
	}

	/**
	 * Whether an at-rule's body holds style rules rather than declarations or offsets.
	 *
	 * @param string $prelude At-rule prelude, starting with `@`.
	 *
	 * @return bool
	 */
	protected static function at_rule_holds_rules( string $prelude ): bool {
		if ( ! preg_match( '/^@([\w-]+)/', $prelude, $found ) ) {
			return false;
		}

		return in_array( strtolower( $found[1] ), self::NESTING_AT_RULES, true );
	}

	/**
	 * Position just past the string literal starting at $start.
	 *
	 * @param string $css   CSS being walked.
	 * @param int    $start Index of the opening quote.
	 *
	 * @return int
	 */
	protected static function end_of_string( string $css, int $start ): int {
		$quote  = $css[ $start ];
		$length = strlen( $css );
		$i      = $start + 1;

		while ( $i < $length ) {
			if ( '\\' === $css[ $i ] ) {
				$i += 2;
				continue;
			}

			if ( $quote === $css[ $i ] ) {
				return $i + 1;
			}

			++$i;
		}

		return $length;
	}

	/**
	 * Position of the `}` matching the `{` at $open, or the length when unterminated.
	 *
	 * @param string $css  CSS being walked.
	 * @param int    $open Index of the opening brace.
	 *
	 * @return int
	 */
	protected static function end_of_block( string $css, int $open ): int {
		$length = strlen( $css );
		$depth  = 0;
		$i      = $open;

		while ( $i < $length ) {
			$char = $css[ $i ];

			if ( '/' === $char && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = false === $end ? $length : $end + 2;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$i = self::end_of_string( $css, $i );
				continue;
			}

			if ( '{' === $char ) {
				++$depth;
			}

			if ( '}' === $char ) {
				--$depth;

				if ( 0 === $depth ) {
					return $i;
				}
			}

			++$i;
		}

		return $length;
	}

	/**
	 * The CSS a promo renders with.
	 *
	 * The Custom CSS field wins when it holds anything; otherwise the selected
	 * style's template is used verbatim, so picking a style and saving nothing else
	 * is a complete choice.
	 *
	 * @param int $post_id Promo ID.
	 *
	 * @return string
	 */
	public static function css_for( int $post_id ): string {
		$custom = (string) get_post_meta( $post_id, self::META, true );

		// Only the bundled templates are minified. Author CSS is passed through as
		// written: stripping comments with a regex corrupts any string literal holding
		// `*/`, and unlike the templates there is no corpus to test that against.
		$css = '' !== trim( $custom )
			? $custom
			: self::minify( self::template( self::slug_for( $post_id ) ) );

		// Sanitized on the way out whichever source it came from. Stored meta can be
		// written by REST, WP-CLI or an import, none of which go through the meta box;
		// a template can come from a theme via the `whimsical_promo_styles` filter.
		// Confined after scoping, so an unscoped rule cannot reach past the promo. Kept
		// separate from scope(), which is idempotent and reversible via to_placeholder().
		return self::confine( self::scope( self::sanitize_css( $css ), $post_id ), $post_id );
	}

	/**
	 * A cache-busting fingerprint of a promo's rendered CSS.
	 *
	 * Generating it costs the same as the CSS itself, which is what used to be inlined
	 * on every request anyway. It changes whenever the meta, the selected style or the
	 * bundled template changes, so the URL that carries it can be immutable.
	 *
	 * @param int $post_id Promo ID.
	 *
	 * @return string
	 */
	public static function version( int $post_id ): string {
		return self::fingerprint( self::css_for( $post_id ) );
	}

	/**
	 * The fingerprint of an already-computed stylesheet.
	 *
	 * Split out from version() so a caller that already holds the CSS — such as
	 * CSS_Route::maybe_serve() — can fingerprint it without paying for css_for()
	 * a second time.
	 *
	 * @param string $css Rendered CSS.
	 *
	 * @return string
	 */
	public static function fingerprint( string $css ): string {
		return substr( md5( $css ), 0, 12 );
	}

	/**
	 * Strips comments and slack from CSS this plugin ships.
	 *
	 * Only ever run on the bundled templates — see css_for(). Roughly halves the
	 * compressed weight, because the templates document themselves heavily.
	 *
	 * @param string $css Template CSS.
	 *
	 * @return string
	 */
	public static function minify( string $css ): string {
		if ( '' === $css ) {
			return '';
		}

		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = preg_replace( '/\s+/', ' ', (string) $css );

		// Space is only meaningful between selector parts and inside values, never
		// around these. `>` and `~` are combinators here, not part of any value the
		// templates use.
		$css = preg_replace( '/\s*([{};,>~])\s*/', '$1', (string) $css );

		// A colon is a declaration separator in a block but a structural character in a
		// selector, so only the followed-by-space form is safe to tighten.
		$css = preg_replace( '/:\s+/', ':', (string) $css );
		$css = str_replace( ';}', '}', (string) $css );

		return trim( is_string( $css ) ? $css : '' );
	}

	/**
	 * A promo's selected style slug, falling back to the default.
	 *
	 * @param int $post_id Promo ID.
	 *
	 * @return string
	 */
	public static function slug_for( int $post_id ): string {
		return (string) Post_Type::sanitize_meta(
			'whim_style_preset',
			Meta_Box::get_value( $post_id, 'whim_style_preset' )
		);
	}

	/**
	 * Whether the current user may write raw CSS.
	 *
	 * `unfiltered_html` would be the natural capability but VIP strips it, so the
	 * gate is the one that survives there. Everyone who can edit a promo can still
	 * pick a style and set the Style token fields.
	 *
	 * @return bool
	 */
	public static function can_edit(): bool {
		/**
		 * Filters whether the current user may edit a promo's raw CSS.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $can_edit Whether the Custom CSS field is editable.
		 */
		return (bool) apply_filters( 'whimsical_promo_can_edit_css', current_user_can( 'manage_options' ) );
	}
}
