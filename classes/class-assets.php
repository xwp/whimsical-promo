<?php
/**
 * Conditional front-end asset loading.
 *
 * @since 1.0.0
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo;

use WhimsicalPromo\Singleton\Singleton;
use WP_Post;

/**
 * Class Assets
 *
 * @since   1.0.0
 * @package WhimsicalPromo
 */
class Assets {
	use Singleton;

	/**
	 * Asset handle used for both the style and the script.
	 *
	 * @var string
	 */
	const HANDLE = 'whimsical-bogo';

	/**
	 * Asset version.
	 *
	 * @var string
	 */
	const VERSION = '1.7.0';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'style_loader_tag', [ $this, 'make_non_blocking' ], 10, 2 );
		add_filter( 'css_do_concat', [ $this, 'skip_concat' ], 10, 2 );
	}

	/**
	 * Enqueues promo assets only when this request renders at least one promo.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$render = Render::get_instance();

		if ( ! $render->has_promos() ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			plugins_url( 'assets/promo-base.css', PLUGIN_FILE ),
			[],
			self::VERSION
		);

		// Only inline promos get their CSS at page load. An exit-intent promo may never
		// open, so its stylesheet is fetched by the script if and when it arms — see
		// Render for the URL it carries.
		foreach ( self::inline_promo_ids( $render->get_inline_promos() ) as $post_id ) {
			wp_enqueue_style(
				self::style_handle( $post_id ),
				CSS_Route::url( $post_id ),
				[ self::HANDLE ],
				null // Fingerprinted in the URL already; a ?ver would only fight it.
			);
		}

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/promo.js', PLUGIN_FILE ),
			[],
			self::VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		// Plugin-level config, identical for every visitor — safe under page caching.
		wp_add_inline_script(
			self::HANDLE,
			'window.whimBogoCfg = ' . wp_json_encode( Settings::js_config() ) . ';',
			'before'
		);
	}

	/**
	 * The style handle for one promo.
	 *
	 * @param int $post_id Promo ID.
	 *
	 * @return string
	 */
	public static function style_handle( int $post_id ): string {
		return self::HANDLE . '-' . $post_id;
	}

	/**
	 * The ids of the inline promos rendering on this request, deduplicated.
	 *
	 * @param array<string,WP_Post[]> $hooked Inline promos grouped by hook.
	 *
	 * @return int[]
	 */
	protected static function inline_promo_ids( array $hooked ): array {
		$ids = [];

		foreach ( $hooked as $promos ) {
			foreach ( $promos as $promo ) {
				if ( $promo instanceof WP_Post ) {
					$ids[ (int) $promo->ID ] = (int) $promo->ID;
				}
			}
		}

		return array_values( $ids );
	}

	/**
	 * Loads a promo stylesheet without blocking the first paint.
	 *
	 * A promo sits below the fold and stays hidden until the script reveals it, so its
	 * design is never needed for the initial render. `media="print"` makes the browser
	 * fetch at a low priority without blocking, and the onload swap applies it. WordPress
	 * has no native async-stylesheet API, so the tag is rewritten here.
	 *
	 * @param string $tag    The link tag.
	 * @param string $handle Style handle.
	 *
	 * @return string
	 */
	public function make_non_blocking( string $tag, string $handle ): string {
		if ( 0 !== strpos( $handle, self::HANDLE . '-' ) ) {
			return $tag;
		}

		$swapped = str_replace(
			"media='all'",
			"media='print' onload=\"this.media='all';this.onload=null\"",
			$tag
		);

		if ( $swapped === $tag ) {
			return $tag;
		}

		// Without JS the onload never fires, so the same sheet is offered plainly.
		return $swapped . '<noscript>' . $tag . '</noscript>' . "\n";
	}

	/**
	 * Keeps per-promo stylesheets out of VIP's CSS concatenator.
	 *
	 * The concatenator resolves handles to files on disk, which these have not got, and
	 * concatenating them would also undo the non-blocking load above.
	 *
	 * @param bool   $do_concat Whether to concatenate.
	 * @param string $handle    Style handle.
	 *
	 * @return bool
	 */
	public function skip_concat( $do_concat, $handle ) {
		if ( is_string( $handle ) && 0 === strpos( $handle, self::HANDLE . '-' ) ) {
			return false;
		}

		return $do_concat;
	}
}
