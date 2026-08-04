<?php
/**
 * Registers the promo post type, its meta, and admin list columns.
 *
 * @since 1.0.0
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo;

use WhimsicalPromo\Singleton\Singleton;
use WP_Post;

/**
 * Class Post_Type
 *
 * @since   1.0.0
 * @package WhimsicalPromo
 */
class Post_Type {
	use Singleton;

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'whimsical_promo';

	/**
	 * Placement values.
	 *
	 * @var string
	 */
	const PLACEMENT_INLINE = 'inline_hook';

	/**
	 * Placement values.
	 *
	 * @var string
	 */
	const PLACEMENT_EXIT = 'exit_intent';

	/**
	 * Not a real hook: asks Render to append the chain to the post body instead.
	 *
	 * @var string
	 */
	const CONTENT_HOOK = 'whim_after_content';

	/**
	 * Hook names refused on save, because rendering there breaks the page.
	 *
	 * Every other name is allowed — the render callback hands filtered values back
	 * untouched, so an unknown name can only ever be a no-op.
	 *
	 * @var string[]
	 */
	const BLOCKED_HOOKS = [
		// Card markup inside <head>; the browser relocates it.
		'wp_head',
		// Render::EXIT_HOOK — an inline chain here lands on top of the exit chain.
		'wp_footer',
		// Both fire before the template opens, so output would precede the doctype.
		'template_redirect',
		'wp_enqueue_scripts',
		// Filters whose value is printed after the hook runs, so the card lands above
		// the text. `the_title` also runs once per title, menus included.
		'the_content',
		'the_title',
		'the_excerpt',
	];

	/**
	 * Default cookie lifetime for inline promos, in days.
	 *
	 * @var int
	 */
	const DEFAULT_DAYS_INLINE = 30;

	/**
	 * Default cookie lifetime for exit-intent promos, in days.
	 *
	 * @var int
	 */
	const DEFAULT_DAYS_EXIT = 30;

	/**
	 * Maximum accepted length of a style token value.
	 *
	 * @var int
	 */
	const STYLE_TOKEN_MAX_LENGTH = 200;

	/**
	 * Style token meta keys, without the `whim_style_` prefix.
	 *
	 * @var string[]
	 */
	const STYLE_TOKENS = [ 'bg', 'accent', 'border', 'text', 'shadow', 'radius' ];

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'register_meta' ] );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'filter_admin_columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'render_admin_column' ], 10, 2 );
	}

	/**
	 * Allowed values for the choice-based meta keys.
	 *
	 * @return array<string,string[]>
	 */
	public static function choices(): array {
		return [
			'whim_placement'    => [ self::PLACEMENT_INLINE, self::PLACEMENT_EXIT ],
			'whim_presentation' => [ 'slide-down', 'slide-up', 'modal' ],
			'whim_animation'    => [ 'slide-up-spring', 'slide-down-spring', 'fade-rise', 'none' ],
			'whim_style_preset' => Styles::slugs(),
		];
	}

	/**
	 * Full meta key list with defaults.
	 *
	 * @return array<string,string|int|bool|array<int,string>>
	 */
	public static function meta_defaults(): array {
		$defaults = [
			'whim_placement'             => self::PLACEMENT_INLINE,
			'whim_hook'                  => Meta_Box::default_hook(),
			'whim_post_types'            => [ 'post' ],
			'whim_show_until_interacted' => true,
			'whim_cookie_days'           => self::DEFAULT_DAYS_INLINE,
			'whim_presentation'          => 'slide-down',
			'whim_animation'             => 'slide-up-spring',
			'whim_style_preset'          => Styles::default_slug(),
			Styles::META                 => '',
		];

		foreach ( self::STYLE_TOKENS as $token ) {
			$defaults[ 'whim_style_' . $token ] = '';
		}

		return $defaults;
	}

	/**
	 * Registers the promo post type.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'          => [
					'name'               => __( 'Promos', 'whimsical-promo' ),
					'singular_name'      => __( 'Promo', 'whimsical-promo' ),
					'add_new_item'       => __( 'Add New Promo', 'whimsical-promo' ),
					'edit_item'          => __( 'Edit Promo', 'whimsical-promo' ),
					'new_item'           => __( 'New Promo', 'whimsical-promo' ),
					'view_item'          => __( 'View Promo', 'whimsical-promo' ),
					'search_items'       => __( 'Search Promos', 'whimsical-promo' ),
					'not_found'          => __( 'No promos found', 'whimsical-promo' ),
					'not_found_in_trash' => __( 'No promos found in Trash', 'whimsical-promo' ),
					'menu_name'          => __( 'Promos', 'whimsical-promo' ),
				],
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'show_in_rest'    => true,
				'menu_icon'       => 'dashicons-megaphone',
				'supports'        => [ 'title', 'editor', 'revisions', 'page-attributes' ],
				'has_archive'     => false,
				'rewrite'         => false,
				'query_var'       => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			]
		);
	}

	/**
	 * Registers all promo meta with sanitizers and an edit capability check.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$auth_callback = static function ( $allowed, $meta_key, $object_id ) {
			return current_user_can( 'edit_post', (int) $object_id );
		};

		foreach ( self::meta_defaults() as $key => $default ) {
			$args = [
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => $auth_callback,
				'sanitize_callback' => static function ( $value ) use ( $key ) {
					return self::sanitize_meta( $key, $value );
				},
			];

			if ( 'whim_post_types' === $key ) {
				$args['type']         = 'array';
				$args['show_in_rest'] = [
					'schema' => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
				];
			} elseif ( 'whim_show_until_interacted' === $key ) {
				$args['type'] = 'boolean';
			} elseif ( 'whim_cookie_days' === $key ) {
				$args['type'] = 'integer';
			} elseif ( Styles::META === $key ) {
				$args['type'] = 'string';
				// Deliberately not in REST: writing CSS is gated on a capability the
				// meta auth callback does not check (Styles::can_edit()).
				$args['show_in_rest'] = false;
			} else {
				$args['type'] = 'string';
			}

			register_post_meta( self::POST_TYPE, $key, $args );
		}
	}

	/**
	 * Sanitizes a single meta value by key.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Raw value.
	 *
	 * @return mixed Sanitized value.
	 */
	public static function sanitize_meta( string $key, $value ) {
		$choices = self::choices();

		if ( isset( $choices[ $key ] ) ) {
			$value = is_string( $value ) ? $value : '';
			return in_array( $value, $choices[ $key ], true ) ? $value : self::meta_defaults()[ $key ];
		}

		if ( 'whim_hook' === $key ) {
			return self::sanitize_hook_name( is_string( $value ) ? $value : '' );
		}

		if ( 'whim_post_types' === $key ) {
			return self::sanitize_post_types( $value );
		}

		if ( 'whim_show_until_interacted' === $key ) {
			return (bool) $value;
		}

		if ( 'whim_cookie_days' === $key ) {
			return max( 1, is_numeric( $value ) ? (int) $value : 0 );
		}

		if ( Styles::META === $key ) {
			return Styles::sanitize_css( is_string( $value ) ? $value : '' );
		}

		if ( 0 === strpos( $key, 'whim_style_' ) ) {
			return self::sanitize_style_token( is_string( $value ) ? $value : '' );
		}

		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}

	/**
	 * Strips anything that cannot appear in a WordPress hook name.
	 *
	 * @param string $value Raw hook name.
	 *
	 * @return string Sanitized hook name, empty when nothing survives.
	 */
	public static function sanitize_hook_name( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9_\-\/]/', '', $value );

		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = substr( $value, 0, 100 );

		return in_array( $value, self::BLOCKED_HOOKS, true ) ? '' : $value;
	}

	/**
	 * Keeps only currently registered public post types.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string[]
	 */
	public static function sanitize_post_types( $value ): array {
		if ( ! is_array( $value ) ) {
			$value = [];
		}

		$registered = get_post_types( [ 'public' => true ] );
		$value      = array_map( 'strval', $value );

		return array_values( array_intersect( $value, array_keys( $registered ) ) );
	}

	/**
	 * Validates a CSS custom-property value.
	 *
	 * Deliberately a conservative denylist rather than `safecss_filter_attr()`,
	 * which rejects gradients and `var()` inconsistently. The field can only ever
	 * become a single custom-property value, so blocking declaration/at-rule/URL
	 * syntax and CSS escapes is sufficient.
	 *
	 * @param string $value Raw token value.
	 *
	 * @return string Sanitized value, empty when rejected.
	 */
	public static function sanitize_style_token( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( strlen( $value ) > self::STYLE_TOKEN_MAX_LENGTH ) {
			return '';
		}

		// Control characters (including newlines) are never valid here.
		if ( preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
			return '';
		}

		$forbidden = [ ';', '{', '}', '<', '>', '@', '\\', '/*', '*/', 'url(', 'expression(' ];

		foreach ( $forbidden as $needle ) {
			if ( false !== stripos( $value, $needle ) ) {
				return '';
			}
		}

		return $value;
	}

	/**
	 * Adds promo columns to the admin list table.
	 *
	 * @param mixed $columns Existing columns.
	 *
	 * @return array<string,string>
	 */
	public function filter_admin_columns( $columns ): array {
		$columns = is_array( $columns ) ? $columns : [];
		$date    = $columns['date'] ?? null;
		unset( $columns['date'] );

		$columns['whim_placement'] = __( 'Placement', 'whimsical-promo' );
		$columns['whim_hook']      = __( 'Hook', 'whimsical-promo' );
		$columns['whim_order']     = __( 'Order', 'whimsical-promo' );
		$columns['whim_gate']      = __( 'Until interacted', 'whimsical-promo' );

		if ( null !== $date ) {
			$columns['date'] = $date;
		}

		return $columns;
	}

	/**
	 * Renders a promo admin column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Promo ID.
	 *
	 * @return void
	 */
	public function render_admin_column( $column, $post_id ): void {
		$post_id = (int) $post_id;

		switch ( $column ) {
			case 'whim_placement':
				echo esc_html( (string) get_post_meta( $post_id, 'whim_placement', true ) );
				break;
			case 'whim_hook':
				$placement = (string) get_post_meta( $post_id, 'whim_placement', true );
				echo esc_html(
					self::PLACEMENT_EXIT === $placement
						? 'wp_footer'
						: (string) get_post_meta( $post_id, 'whim_hook', true )
				);
				break;
			case 'whim_order':
				$promo = get_post( $post_id );
				echo esc_html( $promo instanceof WP_Post ? (string) $promo->menu_order : '0' );
				break;
			case 'whim_gate':
				echo get_post_meta( $post_id, 'whim_show_until_interacted', true )
					? esc_html__( 'Yes', 'whimsical-promo' )
					: esc_html__( 'No', 'whimsical-promo' );
				break;
		}
	}
}
