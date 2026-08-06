<?php
/**
 * Serves each promo's CSS as a cacheable stylesheet.
 *
 * @since 1.5.0
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo;

use WhimsicalPromo\Singleton\Singleton;

/**
 * Class CSS_Route
 *
 * A promo's CSS is a pure function of its post meta, so it is identical for every
 * visitor and can be cached hard. Inlining it instead — which is what this replaces —
 * re-sent the same 3 KB with every HTML response and could never be reused across
 * pages, even though the same promo renders sitewide.
 *
 * Deliberately a query argument rather than a pretty rewrite rule: a rewrite needs a
 * flush to exist, and a missed flush turns every promo stylesheet into a 404 that the
 * edge then caches. A query argument works the moment the plugin is active.
 *
 * @since   1.5.0
 * @package WhimsicalPromo
 */
class CSS_Route {
	use Singleton;

	/**
	 * Query argument naming the promo whose CSS is wanted.
	 *
	 * @var string
	 */
	const QUERY_VAR = 'whim_bogo_css';

	/**
	 * Query argument carrying the CSS fingerprint.
	 *
	 * @var string
	 */
	const VERSION_VAR = 'whim_ver';

	/**
	 * How long a stylesheet may be cached, in seconds.
	 *
	 * @var int
	 */
	const MAX_AGE = 31536000;

	/**
	 * How long a miss may be cached, in seconds.
	 *
	 * @var int
	 */
	const MISS_MAX_AGE = 300;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		// `init` rather than `template_redirect`: there is nothing to answer here that
		// needs the main query, and skipping it keeps the response cheap.
		add_action( 'init', [ $this, 'maybe_serve' ] );
	}

	/**
	 * The stylesheet URL for one promo.
	 *
	 * @param int $post_id Promo ID.
	 *
	 * @return string
	 */
	public static function url( int $post_id ): string {
		return add_query_arg(
			[
				self::QUERY_VAR   => $post_id,
				self::VERSION_VAR => Styles::version( $post_id ),
			],
			home_url( '/' )
		);
	}

	/**
	 * Answers a stylesheet request and stops, or does nothing at all.
	 *
	 * @return void
	 */
	public function maybe_serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A public stylesheet, keyed only by a post id.
		$requested = isset( $_GET[ self::QUERY_VAR ] ) ? absint( $_GET[ self::QUERY_VAR ] ) : 0;

		if ( 0 === $requested ) {
			return;
		}

		$fingerprint = isset( $_GET[ self::VERSION_VAR ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A public stylesheet, keyed only by a post id.
			? sanitize_key( wp_unslash( $_GET[ self::VERSION_VAR ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A public stylesheet, keyed only by a post id.
			: '';

		$css = self::stylesheet( $requested );

		// Fingerprinted off the CSS already fetched above, not Styles::version(), which
		// would recompute css_for() a second time on every request.
		$fresh = null !== $css && '' !== $fingerprint && Styles::fingerprint( $css ) === $fingerprint;

		// A miss -- no CSS, or a fingerprint that does not match -- is answered with the
		// CSS anyway (empty when there is none) rather than a 404, and with the short
		// max-age so it heals. An arbitrary `whim_ver` cannot otherwise be used to fill
		// the edge cache with year-long immutable duplicates of the same stylesheet.
		$this->send(
			null === $css ? '' : $css,
			$fresh ? self::MAX_AGE : self::MISS_MAX_AGE,
			$fresh
		);

		exit;
	}

	/**
	 * The stylesheet for a requested id, or null when there is nothing to serve.
	 *
	 * Only published promos: a draft never renders on the front end, so its design
	 * should not be readable through here either.
	 *
	 * @param int $post_id Requested promo ID.
	 *
	 * @return string|null
	 */
	public static function stylesheet( int $post_id ): ?string {
		if ( $post_id <= 0 ) {
			return null;
		}

		$promo = get_post( $post_id );

		if ( ! $promo instanceof \WP_Post ) {
			return null;
		}

		if ( Post_Type::POST_TYPE !== $promo->post_type || 'publish' !== $promo->post_status ) {
			return null;
		}

		return Styles::css_for( $post_id );
	}

	/**
	 * Writes the response.
	 *
	 * VIP's edge honours an application's own Cache-Control rather than replacing it, so
	 * this is what decides the TTL. Nothing here may set a cookie: one Set-Cookie makes
	 * the whole response uncacheable.
	 *
	 * @param string $css       Stylesheet body.
	 * @param int    $max_age   Cache lifetime in seconds.
	 * @param bool   $immutable Whether the URL is fingerprinted.
	 *
	 * @return void
	 */
	protected function send( string $css, int $max_age, bool $immutable ): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'Content-Type: text/css; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header(
			sprintf(
				'Cache-Control: public, max-age=%d%s',
				$max_age,
				$immutable ? ', immutable' : ''
			)
		);

		echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized CSS, and escaping would corrupt it.
	}
}
