<?php
/**
 * Collects eligible promos and renders their inert markup.
 *
 * Output is byte-identical for every visitor of a given URL: no cookie reads, no
 * user-agent or device branching. All per-visitor decisions happen in promo.js.
 *
 * @since 1.0.0
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo;

use WhimsicalPromo\Singleton\Singleton;
use WP_Post;
use WP_Query;

/**
 * Class Render
 *
 * @since   1.0.0
 * @package WhimsicalPromo
 */
class Render {
	use Singleton;

	/**
	 * Maximum number of promos considered per request.
	 *
	 * @var int
	 */
	const QUERY_LIMIT = 100;

	/**
	 * Hook exit-intent promos always render on.
	 *
	 * @var string
	 */
	const EXIT_HOOK = 'wp_footer';

	/**
	 * Eligible inline promos, keyed by hook name.
	 *
	 * @var array<string,WP_Post[]>
	 */
	protected array $inline = [];

	/**
	 * Eligible exit-intent promos.
	 *
	 * @var WP_Post[]
	 */
	protected array $exit_intent = [];

	/**
	 * Chains already printed this request, keyed by group.
	 *
	 * @var array<string,bool>
	 */
	protected array $printed = [];

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'wp', [ $this, 'collect' ], 20 );
	}

	/**
	 * Selects the promos eligible for this request and attaches render callbacks.
	 *
	 * @return void
	 */
	public function collect(): void {
		$this->reset();

		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$post_type = (string) get_post_type( get_queried_object_id() );

		if ( '' === $post_type || Post_Type::POST_TYPE === $post_type ) {
			return;
		}

		foreach ( $this->query_promos() as $promo ) {
			$post_types = (array) Meta_Box::get_value( (int) $promo->ID, 'whim_post_types' );

			if ( ! in_array( $post_type, $post_types, true ) ) {
				continue;
			}

			/**
			 * Filters whether an individual promo renders on this request.
			 *
			 * @since 1.0.0
			 *
			 * @param bool    $should_render Whether to render the promo.
			 * @param WP_Post $promo         The promo post.
			 */
			if ( ! apply_filters( 'whimsical_promo_should_render', true, $promo ) ) {
				continue;
			}

			$placement = (string) Meta_Box::get_value( (int) $promo->ID, 'whim_placement' );

			if ( Post_Type::PLACEMENT_EXIT === $placement ) {
				$this->exit_intent[] = $promo;
				continue;
			}

			$hook = Post_Type::sanitize_hook_name( (string) Meta_Box::get_value( (int) $promo->ID, 'whim_hook' ) );

			if ( '' === $hook ) {
				continue;
			}

			$this->inline[ $hook ][] = $promo;
		}

		$this->attach();
	}

	/**
	 * Attaches a render callback per distinct hook.
	 *
	 * @return void
	 */
	protected function attach(): void {
		foreach ( array_keys( $this->inline ) as $hook ) {
			if ( Post_Type::CONTENT_HOOK === $hook ) {
				add_filter( 'the_content', [ $this, 'append_to_content' ], 20 );
				continue;
			}

			// The value is accepted and handed straight back because add_action() and
			// add_filter() share one registry: on a filter name, returning nothing would
			// replace whatever it filters with null.
			add_action(
				$hook,
				// Left untyped: a native `mixed` param makes PHPStan's WordPress rules
				// flag the `return $value` below as invalid for an add_action() callback,
				// even though it's required for the add_filter() case this also serves.
				function ( $value = null ) use ( $hook ) {
					$this->render_inline_chain( $hook );

					return $value;
				},
				10,
				1
			);
		}

		if ( ! empty( $this->exit_intent ) ) {
			add_action( self::EXIT_HOOK, [ $this, 'render_exit_chain' ] );
		}
	}

	/**
	 * Appends the after-content chain to the post body.
	 *
	 * @param mixed $content Post content.
	 *
	 * @return mixed
	 */
	public function append_to_content( mixed $content ): mixed {
		// the_content also runs for excerpts, secondary queries and every post in an
		// archive loop, and a single-post feed satisfies all three loop checks.
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() || is_feed() ) {
			return $content;
		}

		return $content . $this->get_inline_chain( Post_Type::CONTENT_HOOK );
	}

	/**
	 * Clears collected state. Used between requests in tests.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->inline      = [];
		$this->exit_intent = [];
		$this->printed     = [];
	}

	/**
	 * Whether this request has any promo to render.
	 *
	 * @return bool
	 */
	public function has_promos(): bool {
		return ! empty( $this->inline ) || ! empty( $this->exit_intent );
	}

	/**
	 * Inline promos grouped by hook.
	 *
	 * @return array<string,WP_Post[]>
	 */
	public function get_inline_promos(): array {
		return $this->inline;
	}

	/**
	 * Exit-intent promos for this request.
	 *
	 * @return WP_Post[]
	 */
	public function get_exit_promos(): array {
		return $this->exit_intent;
	}

	/**
	 * Every promo this request will render, in no particular order.
	 *
	 * Assets uses this to build the one style block the page needs, which is why it
	 * has to be answerable before anything is printed.
	 *
	 * @return WP_Post[]
	 */
	public function all_promos(): array {
		$promos = $this->exit_intent;

		foreach ( $this->inline as $hooked ) {
			$promos = array_merge( $promos, $hooked );
		}

		return $promos;
	}

	/**
	 * Queries published promos ordered by chain position.
	 *
	 * Deliberately no meta_query: each OR clause would add a postmeta self-join,
	 * and the promo set is tiny enough to filter in PHP.
	 *
	 * @return WP_Post[]
	 */
	protected function query_promos(): array {
		$query = new WP_Query(
			[
				'post_type'              => Post_Type::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => self::QUERY_LIMIT,
				'orderby'                => [
					'menu_order' => 'ASC',
					'date'       => 'DESC',
				],
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
			]
		);

		return array_values(
			array_filter(
				$query->posts,
				static function ( mixed $promo ): bool {
					return $promo instanceof WP_Post;
				}
			)
		);
	}

	/**
	 * Deterministic group id for a chain — identical for every render of a URL.
	 *
	 * @param string $key Chain key (hook name, or the exit-intent marker).
	 *
	 * @return string
	 */
	public static function group_id( string $key ): string {
		return 'whim-' . substr( md5( $key ), 0, 8 );
	}

	/**
	 * Renders every inline promo attached to a hook, hidden and inert.
	 *
	 * @param string $hook Hook name.
	 *
	 * @return void
	 */
	public function render_inline_chain( string $hook ): void {
		echo $this->get_inline_chain( $hook ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in promo_markup(), body filtered through wp_kses().
	}

	/**
	 * The markup for every inline promo attached to a hook, hidden and inert.
	 *
	 * @param string $hook Hook name.
	 *
	 * @return string
	 */
	public function get_inline_chain( string $hook ): string {
		$promos = $this->inline[ $hook ] ?? [];
		$group  = self::group_id( 'inline:' . $hook );

		if ( empty( $promos ) || isset( $this->printed[ $group ] ) ) {
			return '';
		}

		$this->printed[ $group ] = true;

		$markup = sprintf( '<div class="whim-bogo-slot" data-whim-group="%s">', esc_attr( $group ) );

		foreach ( $promos as $promo ) {
			$markup .= $this->promo_markup( $promo, Post_Type::PLACEMENT_INLINE, $group );
		}

		return $markup . '</div>';
	}

	/**
	 * Renders every exit-intent promo, hidden and inert.
	 *
	 * @return void
	 */
	public function render_exit_chain(): void {
		echo $this->get_exit_chain(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in promo_markup(), body filtered through wp_kses().
	}

	/**
	 * The markup for every exit-intent promo, hidden and inert.
	 *
	 * @return string
	 */
	public function get_exit_chain(): string {
		$group = self::group_id( 'exit_intent' );

		if ( empty( $this->exit_intent ) || isset( $this->printed[ $group ] ) ) {
			return '';
		}

		$this->printed[ $group ] = true;

		$markup = sprintf( '<div class="whim-bogo-exit" data-whim-group="%s">', esc_attr( $group ) );

		foreach ( $this->exit_intent as $promo ) {
			$markup .= $this->promo_markup( $promo, Post_Type::PLACEMENT_EXIT, $group );
		}

		return $markup . '</div>';
	}

	/**
	 * The promo's front-end identity.
	 *
	 * Used for the frequency cookie, the analytics `bogo_id`, and the
	 * `?whim_preview=` target, so the editor screen has to be able to name the same
	 * value the markup carries.
	 *
	 * @param WP_Post $promo Promo post.
	 *
	 * @return string
	 */
	public static function promo_slug( WP_Post $promo ): string {
		return '' !== (string) $promo->post_name ? (string) $promo->post_name : 'promo-' . (int) $promo->ID;
	}

	/**
	 * The markup for a single promo wrapper.
	 *
	 * @param WP_Post $promo     Promo post.
	 * @param string  $placement Placement value.
	 * @param string  $group     Chain group id.
	 *
	 * @return string
	 */
	protected function promo_markup( WP_Post $promo, string $placement, string $group ): string {
		$post_id      = (int) $promo->ID;
		$is_exit      = Post_Type::PLACEMENT_EXIT === $placement;
		$gate         = Meta_Box::get_value( $post_id, 'whim_show_until_interacted' ) ? 'interact' : 'always';
		$days         = (int) Meta_Box::get_value( $post_id, 'whim_cookie_days' );
		$preset       = (string) Meta_Box::get_value( $post_id, 'whim_animation' );
		$presentation = (string) Meta_Box::get_value( $post_id, 'whim_presentation' );
		$style_preset = Styles::slug_for( $post_id );
		$slug         = self::promo_slug( $promo );

		$classes = [
			'whim-bogo',
			'whim-bogo--' . str_replace( '_', '-', $placement ),
			'whim-bogo--preset-' . $preset,
			'whim-bogo--style-' . $style_preset,
		];

		if ( $is_exit ) {
			$classes[] = 'whim-bogo--' . $presentation;
		}

		/**
		 * Filters the promo wrapper classes.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $classes Wrapper class names.
		 * @param WP_Post  $promo   The promo post.
		 */
		$classes = (array) apply_filters( 'whimsical_promo_wrapper_class', $classes, $promo );
		$classes = array_map( 'sanitize_html_class', array_map( 'strval', $classes ) );

		$attributes = [
			// The promo's own CSS is scoped to this id, and an id beats every class
			// in promo-base.css — see Styles::scope().
			'id'                  => Styles::wrapper_id( $post_id ),
			'class'               => implode( ' ', array_filter( $classes ) ),
			'data-whim-slug'      => $slug,
			'data-whim-style'     => $style_preset,
			'data-whim-group'     => $group,
			'data-whim-placement' => $placement,
			'data-whim-days'      => (string) max( 1, $days ),
			'data-whim-gate'      => $gate,
			'data-whim-preset'    => $preset,
		];

		if ( $is_exit ) {
			$attributes['data-whim-presentation'] = $presentation;

			// An exit promo may never open, so its stylesheet is not enqueued. The script
			// fetches this once the promo is armed — see Assets::enqueue().
			$attributes['data-whim-css'] = CSS_Route::url( $post_id );

			if ( Meta_Box::get_value( $post_id, 'whim_mobile_end' ) ) {
				$attributes['data-whim-mobile-end'] = '1';
			}
		}

		$style = $this->style_attribute( $post_id );

		if ( '' !== $style ) {
			$attributes['style'] = $style;
		}

		$markup = '<div';

		foreach ( $attributes as $name => $value ) {
			$markup .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}

		$markup .= ' hidden>';

		if ( $is_exit && 'modal' === $presentation ) {
			$markup .= '<div class="whim-bogo__backdrop" data-whim-close="1"></div>';
		}

		$dialog_attributes = '';

		if ( $is_exit ) {
			// tabindex allows the script to put focus on the dialog itself rather than on
			// a control, so the first Tab reaches the call to action and Enter is not
			// armed on anything the moment the promo opens.
			$dialog_attributes = ' role="dialog" tabindex="-1" aria-label="' . esc_attr( get_the_title( $promo ) ) . '"';

			if ( 'modal' === $presentation ) {
				$dialog_attributes .= ' aria-modal="true"';
			}
		}

		$markup .= sprintf( '<div class="whim-bogo__card"%s>', $dialog_attributes );
		$markup .= '<div class="whim-bogo__body">' . $this->render_body( $promo ) . '</div>';

		// Last in the card, though it sits top-right: dismiss is the last thing a
		// reader wants, so it should also be the last thing Tab offers. Styles position
		// it absolutely and none of them select it structurally.
		if ( $is_exit ) {
			$markup .= sprintf(
				'<button type="button" class="whim-bogo__close" data-whim-close="1" aria-label="%s">&times;</button>',
				esc_attr__( 'Dismiss', 'whimsical-promo' )
			);
		}

		return $markup . '</div></div>';
	}

	/**
	 * Builds the inline custom-property style attribute for a promo.
	 *
	 * @param int $post_id Promo ID.
	 *
	 * @return string
	 */
	protected function style_attribute( int $post_id ): string {
		$declarations = [];

		foreach ( Post_Type::STYLE_TOKENS as $token ) {
			$value = Post_Type::sanitize_style_token( (string) Meta_Box::get_value( $post_id, 'whim_style_' . $token ) );

			if ( '' === $value ) {
				continue;
			}

			$declarations[] = sprintf( '--whim-%s: %s', $token, $value );
		}

		return empty( $declarations ) ? '' : implode( '; ', $declarations ) . ';';
	}

	/**
	 * Renders promo body content: blocks, then kses, then shortcodes.
	 *
	 * Shortcodes run last so trusted plugin output (newsletter forms) is not
	 * stripped, while everything the editor typed is filtered. `the_content` is
	 * deliberately not used, to keep third-party content filters (ad injection,
	 * related posts) out of promo cards.
	 *
	 * @param WP_Post $promo Promo post.
	 *
	 * @return string
	 */
	public function render_body( WP_Post $promo ): string {
		// wpautop after do_blocks, the order `the_content` uses. Without it a promo
		// typed as plain lines rather than built from blocks has no paragraphs at all,
		// so promote_cta_links() finds nothing to promote and a standalone link stays a
		// bare link instead of becoming the button the styles draw.
		$content = wpautop( do_blocks( (string) $promo->post_content ) );

		// A trailing blank line or stray &nbsp; in the editor becomes an empty paragraph,
		// and the card lays its body out with flex gap — so an empty paragraph is a
		// visible hole rather than nothing. Matches only wpautop's own output shape.
		$content = (string) preg_replace( '#<p>(?:\s|&nbsp;|<br\s*/?>)*</p>#i', '', $content );

		/**
		 * Filters the kses allowlist applied to promo bodies.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $allowlist kses allowed HTML.
		 * @param WP_Post             $promo     The promo post.
		 */
		$allowlist = (array) apply_filters( 'whimsical_promo_kses_allowlist', wp_kses_allowed_html( 'post' ), $promo );

		$content = wp_kses( $content, $allowlist );

		// After kses, so the class survives; before shortcodes, so their own
		// markup is left alone.
		$content = self::promote_cta_links( $content );

		return do_shortcode( $content );
	}

	/**
	 * Marks paragraphs that hold nothing but a link as calls to action.
	 *
	 * The stylesheet cannot make this distinction: `p > a:only-child` also matches
	 * a link inside a sentence, because text nodes are not children for the
	 * purposes of `:only-child`. Comparing the paragraph's text to the link's is
	 * exact, and being a pure function of `post_content` it keeps the rendered
	 * HTML identical for every visitor.
	 *
	 * Opt out by giving the link the class `whim-link`.
	 *
	 * @param string $content Promo body HTML.
	 *
	 * @return string
	 */
	public static function promote_cta_links( string $content ): string {
		$result = preg_replace_callback(
			'#<p\b[^>]*>(.*?)</p>#is',
			static function ( array $matches ): string {
				$inner = $matches[1];

				// Exactly one link, and the paragraph says nothing else.
				if ( 1 !== preg_match_all( '#<a\b[^>]*>.*?</a>#is', $inner, $anchors ) ) {
					return $matches[0];
				}

				$anchor = $anchors[0][0];

				if ( self::visible_text( $inner ) !== self::visible_text( $anchor ) ) {
					return $matches[0];
				}

				if ( preg_match( '/\bclass\s*=\s*(["\'])[^"\']*\b(whim-link|whim-cta)\b[^"\']*\1/i', $anchor ) ) {
					return $matches[0];
				}

				$tagged = preg_match( '/\bclass\s*=\s*(["\'])(.*?)\1/i', $anchor )
					? preg_replace( '/\bclass\s*=\s*(["\'])(.*?)\1/i', 'class=$1whim-cta $2$1', $anchor, 1 )
					: preg_replace( '/^<a\b/i', '<a class="whim-cta"', $anchor, 1 );

				return is_string( $tagged ) ? str_replace( $anchor, $tagged, $matches[0] ) : $matches[0];
			},
			$content
		);

		return is_string( $result ) ? $result : $content;
	}

	/**
	 * The text a reader would see, normalised for comparison.
	 *
	 * Entities have to be decoded and the resulting whitespace collapsed: the
	 * block editor readily leaves a trailing `&nbsp;` after a link, which would
	 * otherwise read as "this paragraph says something else".
	 *
	 * @param string $html HTML fragment.
	 *
	 * @return string
	 */
	protected static function visible_text( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );

		// \s alone does not cover U+00A0 or a zero-width space.
		$text = preg_replace( '/[\s\x{00a0}\x{200b}]+/u', ' ', $text );

		return trim( is_string( $text ) ? $text : '' );
	}
}
