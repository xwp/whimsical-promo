<?php
/**
 * Classic meta box for promo settings.
 *
 * @since 1.0.0
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo;

use WhimsicalPromo\Singleton\Singleton;
use WP_Post;

/**
 * Class Meta_Box
 *
 * @since   1.0.0
 * @package WhimsicalPromo
 */
class Meta_Box {
	use Singleton;

	/**
	 * Nonce action prefix.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'whim_save_promo_';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'whim_promo_nonce';

	/**
	 * Transient prefix for the rejected-style-token notice.
	 *
	 * @var string
	 */
	const NOTICE_TRANSIENT = 'whim_rejected_tokens_';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'add_meta_boxes_' . Post_Type::POST_TYPE, [ $this, 'add_meta_box' ] );
		add_action( 'save_post_' . Post_Type::POST_TYPE, [ $this, 'save' ], 10, 2 );
		add_action( 'admin_notices', [ $this, 'render_rejected_token_notice' ] );
	}

	/**
	 * Hook name suggestions offered in the datalist.
	 *
	 * @return string[]
	 */
	public static function hook_suggestions(): array {
		/**
		 * Filters the hook names suggested in the promo editor.
		 *
		 * Suggestions only — the field accepts any hook name.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $hooks Suggested hook names.
		 */
		$hooks = (array) apply_filters(
			'whimsical_promo_hooks',
			[
				Post_Type::CONTENT_HOOK,
			]
		);

		$hooks = array_map( [ Post_Type::class, 'sanitize_hook_name' ], array_map( 'strval', $hooks ) );

		return array_values( array_unique( array_filter( $hooks ) ) );
	}

	/**
	 * Hook a new promo starts on.
	 *
	 * The first suggestion, so a theme that filters its own hooks to the front owns
	 * the default without the plugin naming a theme hook.
	 *
	 * @return string
	 */
	public static function default_hook(): string {
		return self::hook_suggestions()[0] ?? '';
	}

	/**
	 * Registers the promo settings meta box.
	 *
	 * @return void
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'whimsical-promo-settings',
			__( 'Promo Settings', 'whimsical-promo' ),
			[ $this, 'render' ],
			Post_Type::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Returns a promo meta value, falling back to the registered default.
	 *
	 * @param int    $post_id Promo ID.
	 * @param string $key     Meta key.
	 *
	 * @return mixed
	 */
	public static function get_value( int $post_id, string $key ) {
		$defaults = Post_Type::meta_defaults();

		if ( ! metadata_exists( 'post', $post_id, $key ) ) {
			if ( 'whim_cookie_days' === $key ) {
				return self::default_cookie_days( (string) get_post_meta( $post_id, 'whim_placement', true ) );
			}

			return $defaults[ $key ] ?? '';
		}

		return get_post_meta( $post_id, $key, true );
	}

	/**
	 * Default cookie lifetime for a placement.
	 *
	 * @param string $placement Placement value.
	 *
	 * @return int
	 */
	public static function default_cookie_days( string $placement ): int {
		return Post_Type::PLACEMENT_EXIT === $placement
			? Post_Type::DEFAULT_DAYS_EXIT
			: Post_Type::DEFAULT_DAYS_INLINE;
	}

	/**
	 * Human labels for the style token fields.
	 *
	 * @return array<string,string>
	 */
	public static function token_labels(): array {
		return [
			'bg'     => __( 'Background', 'whimsical-promo' ),
			'accent' => __( 'Accent', 'whimsical-promo' ),
			'border' => __( 'Border', 'whimsical-promo' ),
			'text'   => __( 'Text color', 'whimsical-promo' ),
			'shadow' => __( 'Shadow', 'whimsical-promo' ),
			'radius' => __( 'Corner radius', 'whimsical-promo' ),
		];
	}

	/**
	 * Renders the meta box.
	 *
	 * @param mixed $post Promo being edited, as passed by add_meta_box().
	 *
	 * @return void
	 */
	public function render( $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$post_id     = (int) $post->ID;
		$placement   = (string) self::get_value( $post_id, 'whim_placement' );
		$is_exit     = Post_Type::PLACEMENT_EXIT === $placement;
		$choices     = Post_Type::choices();
		$post_types  = (array) self::get_value( $post_id, 'whim_post_types' );
		$registered  = get_post_types( [ 'public' => true ], 'objects' );
		$suggestions = self::hook_suggestions();
		$labels      = self::token_labels();
		$styles      = Styles::all();
		$can_edit    = Styles::can_edit();
		$wrapper_id  = Styles::wrapper_id( $post_id );
		$custom_css  = (string) self::get_value( $post_id, Styles::META );
		$preview_arg = 'whim_preview=' . Render::promo_slug( $post );

		// Only published promos are ever placed, so a preview link for a draft would
		// open nothing at all.
		$is_published = 'publish' === $post->post_status;

		// Templates travel to the browser unscoped; the button below swaps
		// `#whim-bogo` for this promo's id as it fills the field, so the editor
		// reads exactly what will ship.
		$templates = [];

		if ( $can_edit ) {
			foreach ( array_keys( $styles ) as $slug ) {
				$templates[ $slug ] = Styles::template( $slug );
			}
		}

		wp_nonce_field( self::NONCE_ACTION . $post_id, self::NONCE_NAME );
		?>
		<style>
			/* The admin floats <select>, which would otherwise fall out of the field and collide with the next field's label. */
			.whim-field { display: flow-root; margin: 0 0 1.25em; }
			.whim-field .description { clear: both; }
			.whim-field > label, .whim-field > .whim-legend { display: block; font-weight: 600; margin-bottom: .35em; }
			.whim-field input[type="text"], .whim-field input[type="number"] { max-width: 32em; }
			/* `display` on .whim-field would otherwise beat the UA's [hidden] rule. */
			.whim-field[hidden] { display: none; }
			.whim-tokens { display: grid; gap: .75em 1.5em; grid-template-columns: repeat(auto-fit, minmax(20em, 1fr)); }
			.whim-token label { display: block; font-weight: 600; margin-bottom: .25em; }
			.whim-token-row { display: flex; align-items: center; gap: .5em; }
			.whim-token-row input[type="text"] { flex: 1 1 auto; }
			.whim-post-types label { display: inline-block; margin: 0 1.25em .35em 0; }
			.whim-css-actions { display: flex; align-items: center; gap: 1em; margin: 0 0 .5em; }
			#whim_custom_css, #whim-agent-brief { width: 100%; font-family: Menlo, Consolas, monospace; font-size: 12px; line-height: 1.5; white-space: pre; }
			.whim-agent-brief > summary { cursor: pointer; font-weight: 600; }
			.whim-agent-brief[open] > summary { margin-bottom: .75em; }
		</style>

		<div class="whim-field">
			<label for="whim_placement"><?php esc_html_e( 'Placement', 'whimsical-promo' ); ?></label>
			<select name="whim_placement" id="whim_placement">
				<option value="<?php echo esc_attr( Post_Type::PLACEMENT_INLINE ); ?>" <?php selected( $placement, Post_Type::PLACEMENT_INLINE ); ?>>
					<?php esc_html_e( 'Inline — at a theme hook', 'whimsical-promo' ); ?>
				</option>
				<option value="<?php echo esc_attr( Post_Type::PLACEMENT_EXIT ); ?>" <?php selected( $placement, Post_Type::PLACEMENT_EXIT ); ?>>
					<?php esc_html_e( 'Exit intent — when the cursor leaves the page', 'whimsical-promo' ); ?>
				</option>
			</select>
		</div>

		<div class="whim-field">
			<span class="whim-legend"><?php esc_html_e( 'Preview', 'whimsical-promo' ); ?></span>
			<?php if ( $is_published ) : ?>
				<div class="whim-token-row">
					<input type="text" id="whim-preview-arg" class="code" readonly
						value="<?php echo esc_attr( $preview_arg ); ?>"
						onfocus="this.select()" />
					<button type="button" class="button" id="whim-copy-preview">
						<?php esc_html_e( 'Copy', 'whimsical-promo' ); ?>
					</button>
				</div>
				<p class="description">
					<?php esc_html_e( 'Add this to the query string of any page this promo appears on and it opens straight away — no waiting, no cursor gesture, and it works on a phone. Safe to share: it ignores the frequency cookie, sets none of its own, and records no analytics, so previewing does not use up the promo or skew reporting.', 'whimsical-promo' ); ?>
				</p>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'Publish this promo to get a preview link. Only published promos are placed on the site, so there would be nothing for the link to open.', 'whimsical-promo' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<div class="whim-field" id="whim-hook-row"<?php echo $is_exit ? ' style="display:none"' : ''; ?>>
			<label for="whim_hook"><?php esc_html_e( 'Hook name', 'whimsical-promo' ); ?></label>
			<input type="text" name="whim_hook" id="whim_hook" list="whim-hook-suggestions"
				value="<?php echo esc_attr( (string) self::get_value( $post_id, 'whim_hook' ) ); ?>" />
			<datalist id="whim-hook-suggestions">
				<?php foreach ( $suggestions as $suggestion ) : ?>
					<option value="<?php echo esc_attr( $suggestion ); ?>"></option>
				<?php endforeach; ?>
			</datalist>
			<p class="description">
				<?php
				printf(
					/* translators: %s: the after-content hook name. */
					esc_html__( 'Leave it as %s to render straight after the post body. Otherwise any action hook your theme fires inside the post. Exit-intent promos ignore this and always render in the footer.', 'whimsical-promo' ),
					'<code>' . esc_html( Post_Type::CONTENT_HOOK ) . '</code>'
				);
				?>
			</p>
			<p class="description">
				<?php esc_html_e( 'A few hooks are refused because rendering there breaks the page — wp_head, wp_footer, template_redirect, wp_enqueue_scripts, the_content, the_title and the_excerpt. Saving one of those clears the field.', 'whimsical-promo' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Promos sharing a hook form a chain: only one shows, the first that the visitor has not interacted with yet, in Order (Page Attributes) sequence. Put a promo on its own hook and it shows independently of the others.', 'whimsical-promo' ); ?>
			</p>
		</div>

		<div class="whim-field whim-post-types">
			<span class="whim-legend"><?php esc_html_e( 'Show on these post types', 'whimsical-promo' ); ?></span>
			<?php foreach ( $registered as $type ) : ?>
				<label>
					<input type="checkbox" name="whim_post_types[]" value="<?php echo esc_attr( $type->name ); ?>"
						<?php checked( in_array( $type->name, $post_types, true ) ); ?> />
					<?php echo esc_html( $type->labels->singular_name ); ?>
				</label>
			<?php endforeach; ?>
		</div>

		<div class="whim-field">
			<label>
				<input type="checkbox" name="whim_show_until_interacted" value="1"
					<?php checked( (bool) self::get_value( $post_id, 'whim_show_until_interacted' ) ); ?> />
				<?php esc_html_e( 'Stop showing once the visitor clicks or submits', 'whimsical-promo' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'Unchecked means the promo shows on every visit and never hands off to the next promo in the chain — this is how you make an always-visible fallback. Put it last in the chain by Order, because an always-visible promo ends the chain.', 'whimsical-promo' ); ?>
			</p>
		</div>

		<div class="whim-field" id="whim-cookie-days-row">
			<label for="whim_cookie_days"><?php esc_html_e( 'Remember for (days)', 'whimsical-promo' ); ?></label>
			<input type="number" min="0" step="1" name="whim_cookie_days" id="whim_cookie_days"
				value="<?php echo esc_attr( (string) self::get_value( $post_id, 'whim_cookie_days' ) ); ?>" />
			<p class="description">
				<?php esc_html_e( 'Counted from the interaction. Clear it, or set it to 0, and the promo goes back to always showing — the box above unticks itself.', 'whimsical-promo' ); ?>
			</p>
		</div>

		<div class="whim-field" id="whim-presentation-row"<?php echo $is_exit ? '' : ' style="display:none"'; ?>>
			<label for="whim_presentation"><?php esc_html_e( 'Exit-intent presentation', 'whimsical-promo' ); ?></label>
			<select name="whim_presentation" id="whim_presentation">
				<?php foreach ( $choices['whim_presentation'] as $option ) : ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( (string) self::get_value( $post_id, 'whim_presentation' ), $option ); ?>>
						<?php echo esc_html( $option ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="whim-field" id="whim-mobile-end-row"<?php echo $is_exit ? '' : ' style="display:none"'; ?>>
			<label>
				<input type="checkbox" name="whim_mobile_end" value="1"
					<?php checked( (bool) self::get_value( $post_id, 'whim_mobile_end' ) ); ?> />
				<?php esc_html_e( 'Also trigger on mobile when reaching the end of the content.', 'whimsical-promo' ); ?>
			</label>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to the plugin settings screen, labelled "Promos → Settings". */
					esc_html__( 'A phone has no cursor to leave the page. With this on, the promo opens once the end of the article scrolls into view instead. Which element counts as the article is set in %s.', 'whimsical-promo' ),
					'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Post_Type::POST_TYPE . '&page=' . Settings::PAGE_SLUG ) ) . '">' . esc_html__( 'Promos → Settings', 'whimsical-promo' ) . '</a>'
				);
				?>
			</p>
		</div>

		<div class="whim-field">
			<label for="whim_animation"><?php esc_html_e( 'Animation', 'whimsical-promo' ); ?></label>
			<select name="whim_animation" id="whim_animation">
				<?php foreach ( $choices['whim_animation'] as $option ) : ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( (string) self::get_value( $post_id, 'whim_animation' ), $option ); ?>>
						<?php echo esc_html( $option ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php esc_html_e( 'Visitors who ask for reduced motion always get a plain crossfade.', 'whimsical-promo' ); ?></p>
		</div>

		<div class="whim-field">
			<label for="whim_style_preset"><?php esc_html_e( 'Style', 'whimsical-promo' ); ?></label>
			<select name="whim_style_preset" id="whim_style_preset">
				<?php foreach ( $choices['whim_style_preset'] as $option ) : ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( (string) self::get_value( $post_id, 'whim_style_preset' ), $option ); ?>>
						<?php echo esc_html( $styles[ $option ]['label'] ?? $option ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description">
				<?php esc_html_e( 'Leave Custom CSS empty and this style ships as designed. Fill it in and it takes over completely.', 'whimsical-promo' ); ?>
			</p>
		</div>

		<?php if ( $can_edit ) : ?>
			<div class="whim-field">
				<label for="whim_custom_css"><?php esc_html_e( 'Custom CSS', 'whimsical-promo' ); ?></label>
				<p class="whim-css-actions">
					<button type="button" class="button" id="whim-load-template">
						<?php esc_html_e( 'Load the selected style into the editor', 'whimsical-promo' ); ?>
					</button>
					<button type="button" class="button-link" id="whim-clear-css">
						<?php esc_html_e( 'Clear and go back to the style as shipped', 'whimsical-promo' ); ?>
					</button>
				</p>
				<textarea name="whim_custom_css" id="whim_custom_css" rows="18" spellcheck="false"
					placeholder="<?php esc_attr_e( 'Empty — this promo renders with the style selected above.', 'whimsical-promo' ); ?>"><?php echo esc_textarea( $custom_css ); ?></textarea>
				<p class="description">
					<?php
					printf(
						/* translators: 1: the placeholder selector #whim-bogo-ID. 2: this promo's real selector, e.g. #whim-bogo-412. */
						esc_html__( 'Scope every selector to %1$s — that beats the plugin\'s own base styles without !important, and keeps this promo\'s CSS off the rest of the page. ID is a placeholder: it is replaced with this promo\'s own id (%2$s) when the page renders, so the same stylesheet can be pasted into any promo. A real id works too.', 'whimsical-promo' ),
						'<code>#whim-bogo-' . esc_html( Styles::ID_PLACEHOLDER ) . '</code>',
						'<code>#' . esc_html( $wrapper_id ) . '</code>'
					);
					?>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: the keyframe naming pattern, e.g. whim-kf-ID-fade. */
						esc_html__( 'Paste in anything — hand-written, or generated from the promo markup contract. Name animations %s, using the same placeholder, so two promos cannot overwrite each other\'s keyframes. The card markup itself is fixed: style it, but do not expect to add to it.', 'whimsical-promo' ),
						'<code>whim-kf-' . esc_html( Styles::ID_PLACEHOLDER ) . '-something</code>'
					);
					?>
				</p>
			</div>

			<div class="whim-field">
				<details class="whim-agent-brief">
					<summary><?php esc_html_e( 'Create more designs using AI agents?', 'whimsical-promo' ); ?></summary>
					<p class="description">
						<?php esc_html_e( 'Copy the block below into an AI agent\'s instructions. It describes the markup the plugin generates, the classes and custom properties a design can use, the entry animations and placements on offer, and includes the current style in full as a worked example — everything needed to design a promo without access to this codebase.', 'whimsical-promo' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( 'The agent hands back two blocks: the HTML sample goes in the editor above, the CSS goes in Custom CSS. Nothing here is sent anywhere — copying is up to you.', 'whimsical-promo' ); ?>
					</p>
					<p class="whim-css-actions">
						<button type="button" class="button button-primary" id="whim-copy-brief">
							<?php esc_html_e( 'Copy the brief', 'whimsical-promo' ); ?>
						</button>
					</p>
					<textarea id="whim-agent-brief" rows="14" spellcheck="false" readonly
						onfocus="this.select()"><?php echo esc_textarea( Agent_Brief::text( $post_id ) ); ?></textarea>
				</details>
			</div>
		<?php endif; ?>

		<div class="whim-field">
			<span class="whim-legend"><?php esc_html_e( 'Colour and shape overrides', 'whimsical-promo' ); ?></span>
			<div class="whim-tokens">
				<?php foreach ( Post_Type::STYLE_TOKENS as $token ) : ?>
					<?php
					$key   = 'whim_style_' . $token;
					$value = (string) self::get_value( $post_id, $key );
					?>
					<div class="whim-token">
						<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $labels[ $token ] ?? $token ); ?></label>
						<div class="whim-token-row">
							<input type="text" name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>"
								value="<?php echo esc_attr( $value ); ?>"
								placeholder="<?php esc_attr_e( 'CSS value, e.g. var(--accent)', 'whimsical-promo' ); ?>" />
							<?php if ( in_array( $token, [ 'bg', 'accent', 'border', 'text' ], true ) ) : ?>
								<input type="color" class="whim-token-picker" data-whim-target="<?php echo esc_attr( $key ); ?>"
									value="<?php echo esc_attr( preg_match( '/^#[0-9a-f]{6}$/i', $value ) ? $value : '#ffffff' ); ?>"
									aria-label="<?php esc_attr_e( 'Pick a color', 'whimsical-promo' ); ?>" />
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="description">
				<?php esc_html_e( 'Any CSS value works, including var(--token) and gradients. Leave blank to inherit the style\'s own. These land in the wrapper\'s style attribute, so they win over everything above — including Custom CSS.', 'whimsical-promo' ); ?>
			</p>
		</div>

		<script>
			( function () {
				var placement  = document.getElementById( 'whim_placement' );
				var hookRow    = document.getElementById( 'whim-hook-row' );
				var presentRow = document.getElementById( 'whim-presentation-row' );
				var mobileRow  = document.getElementById( 'whim-mobile-end-row' );
				var cookieDays = document.getElementById( 'whim_cookie_days' );
				var dayDefault = {
					inline: '<?php echo esc_js( (string) Post_Type::DEFAULT_DAYS_INLINE ); ?>',
					exit: '<?php echo esc_js( (string) Post_Type::DEFAULT_DAYS_EXIT ); ?>'
				};

				var gate      = document.querySelector( 'input[name="whim_show_until_interacted"]' );
				var cookieRow = document.getElementById( 'whim-cookie-days-row' );

				// The lifetime only means anything while the gate is on, so it is only on
				// screen then — and emptying it is the way back off, in both directions.
				function syncGate() {
					if ( ! gate || ! cookieRow ) {
						return;
					}

					cookieRow.hidden = ! gate.checked;

					if ( gate.checked && '' === cookieDays.value ) {
						cookieDays.value = currentDefault();
					}
				}

				function currentDefault() {
					return placement && 'exit_intent' === placement.value ? dayDefault.exit : dayDefault.inline;
				}

				if ( gate && cookieDays ) {
					gate.addEventListener( 'change', syncGate );

					function meansAlways() {
						return '' === cookieDays.value.trim() || 0 === Number( cookieDays.value );
					}

					// Unticked as you type, but the row is not pulled out from under the
					// caret until you leave the field. Typing a real number again re-ticks,
					// so correcting a 0 in place cannot leave the two contradicting.
					cookieDays.addEventListener( 'input', function () {
						gate.checked = ! meansAlways();
					} );

					cookieDays.addEventListener( 'blur', function () {
						if ( meansAlways() ) {
							gate.checked = false;
							syncGate();
						}
					} );

					syncGate();
				}

				var copyBtn = document.getElementById( 'whim-copy-preview' );
				var copyArg = document.getElementById( 'whim-preview-arg' );

				if ( copyBtn && copyArg ) {
					copyBtn.addEventListener( 'click', function () {
						copyArg.select();

						var done = function () {
							var label = copyBtn.textContent;
							copyBtn.textContent = <?php echo wp_json_encode( __( 'Copied', 'whimsical-promo' ) ); ?>;
							window.setTimeout( function () {
								copyBtn.textContent = label;
							}, 1500 );
						};

						if ( navigator.clipboard && navigator.clipboard.writeText ) {
							navigator.clipboard.writeText( copyArg.value ).then( done, function () {} );
							return;
						}

						// http:// admin, or an older browser: the text is selected either way.
						try {
							if ( document.execCommand( 'copy' ) ) {
								done();
							}
						} catch ( e ) {}
					} );
				}

				if ( ! placement ) {
					return;
				}

				placement.addEventListener( 'change', function () {
					var isExit = 'exit_intent' === placement.value;

					if ( hookRow ) {
						hookRow.style.display = isExit ? 'none' : '';
					}
					if ( presentRow ) {
						presentRow.style.display = isExit ? '' : 'none';
					}
					if ( mobileRow ) {
						mobileRow.style.display = isExit ? '' : 'none';
					}

					// Only swap the value while it still holds the other placement's default.
					var other = isExit ? dayDefault.inline : dayDefault.exit;
					if ( cookieDays && cookieDays.value === other ) {
						cookieDays.value = isExit ? dayDefault.exit : dayDefault.inline;
					}
				} );

				Array.prototype.forEach.call( document.querySelectorAll( '.whim-token-picker' ), function ( picker ) {
					picker.addEventListener( 'input', function () {
						var target = document.getElementById( picker.getAttribute( 'data-whim-target' ) );
						if ( target ) {
							target.value = picker.value;
						}
					} );
				} );
			}() );
		</script>

		<?php if ( $can_edit ) : ?>
			<script>
				( function () {
					var templates = <?php echo wp_json_encode( $templates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
					var wrapperId = <?php echo wp_json_encode( $wrapper_id ); ?>;
					var postId    = <?php echo wp_json_encode( $post_id ); ?>;
					var field     = document.getElementById( 'whim_custom_css' );
					var style     = document.getElementById( 'whim_style_preset' );
					var load      = document.getElementById( 'whim-load-template' );
					var clear     = document.getElementById( 'whim-clear-css' );

					if ( ! field || ! style || ! load ) {
						return;
					}

					load.addEventListener( 'click', function () {
						var template = templates[ style.value ];

						if ( ! template ) {
							window.alert( <?php echo wp_json_encode( __( 'That style has no template file to load.', 'whimsical-promo' ) ); ?> );
							return;
						}

						if ( field.value.trim() && ! window.confirm( <?php echo wp_json_encode( __( 'Replace everything currently in Custom CSS with this style\'s template?', 'whimsical-promo' ) ); ?> ) ) {
							return;
						}

						// Same substitution the server does on output (Styles::scope), so the
						// editor is reading the real thing rather than a placeholder.
						field.value = template
							.replace( /#whim-bogo(?:-(?:\d+|ID))?(?![\w-])/gi, '#' + wrapperId )
							.replace( /\bwhim-kf-(?:(?:\d+|ID)-)?/gi, 'whim-kf-' + postId + '-' );
						field.focus();
					} );

					var briefBtn = document.getElementById( 'whim-copy-brief' );
					var briefBox = document.getElementById( 'whim-agent-brief' );

					if ( briefBtn && briefBox ) {
						briefBtn.addEventListener( 'click', function () {
							briefBox.select();

							var done = function () {
								var label = briefBtn.textContent;
								briefBtn.textContent = <?php echo wp_json_encode( __( 'Copied', 'whimsical-promo' ) ); ?>;
								window.setTimeout( function () {
									briefBtn.textContent = label;
								}, 1500 );
							};

							if ( navigator.clipboard && navigator.clipboard.writeText ) {
								navigator.clipboard.writeText( briefBox.value ).then( done, function () {} );
								return;
							}

							try {
								if ( document.execCommand( 'copy' ) ) {
									done();
								}
							} catch ( e ) {}
						} );
					}

					if ( clear ) {
						clear.addEventListener( 'click', function () {
							if ( ! field.value.trim() || window.confirm( <?php echo wp_json_encode( __( 'Discard this Custom CSS and go back to the selected style as shipped?', 'whimsical-promo' ) ); ?> ) ) {
								field.value = '';
							}
						} );
					}
				}() );
			</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * Saves promo meta.
	 *
	 * @param int          $post_id Promo ID.
	 * @param WP_Post|null $post    Promo object.
	 *
	 * @return void
	 */
	public function save( $post_id, $post = null ): void {
		$post_id = (int) $post_id;

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( $post instanceof WP_Post && Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$placement = Post_Type::sanitize_meta(
			'whim_placement',
			isset( $_POST['whim_placement'] ) ? sanitize_text_field( wp_unslash( $_POST['whim_placement'] ) ) : ''
		);

		update_post_meta( $post_id, 'whim_placement', $placement );

		update_post_meta(
			$post_id,
			'whim_hook',
			Post_Type::sanitize_meta(
				'whim_hook',
				isset( $_POST['whim_hook'] ) ? sanitize_text_field( wp_unslash( $_POST['whim_hook'] ) ) : ''
			)
		);

		$raw_types = isset( $_POST['whim_post_types'] ) && is_array( $_POST['whim_post_types'] )
			? array_map(
				'sanitize_text_field',
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every scalar survivor is sanitized immediately above via array_map( 'sanitize_text_field', ... ); array_filter() only drops non-scalars before that.
				array_filter( wp_unslash( $_POST['whim_post_types'] ), 'is_scalar' )
			)
			: [];

		update_post_meta( $post_id, 'whim_post_types', Post_Type::sanitize_post_types( $raw_types ) );

		$raw_days = isset( $_POST['whim_cookie_days'] ) ? sanitize_text_field( wp_unslash( $_POST['whim_cookie_days'] ) ) : '';
		$gate     = isset( $_POST['whim_show_until_interacted'] );

		// An emptied or zeroed lifetime is how the screen lets you say "always show", so
		// it clears the gate rather than being rejected. The script does the same live;
		// this is the half that holds without it.
		if ( $gate && ( '' === $raw_days || 0 === (int) $raw_days ) ) {
			$gate     = false;
			$raw_days = '';
		}

		update_post_meta( $post_id, 'whim_show_until_interacted', $gate );

		update_post_meta(
			$post_id,
			'whim_cookie_days',
			'' === $raw_days
				? self::default_cookie_days( $placement )
				: Post_Type::sanitize_meta( 'whim_cookie_days', $raw_days )
		);

		update_post_meta(
			$post_id,
			'whim_presentation',
			Post_Type::sanitize_meta(
				'whim_presentation',
				isset( $_POST['whim_presentation'] ) ? sanitize_text_field( wp_unslash( $_POST['whim_presentation'] ) ) : ''
			)
		);

		update_post_meta( $post_id, 'whim_mobile_end', isset( $_POST['whim_mobile_end'] ) );

		update_post_meta(
			$post_id,
			'whim_animation',
			Post_Type::sanitize_meta(
				'whim_animation',
				isset( $_POST['whim_animation'] ) ? sanitize_text_field( wp_unslash( $_POST['whim_animation'] ) ) : ''
			)
		);

		update_post_meta(
			$post_id,
			'whim_style_preset',
			Post_Type::sanitize_meta(
				'whim_style_preset',
				isset( $_POST['whim_style_preset'] ) ? sanitize_text_field( wp_unslash( $_POST['whim_style_preset'] ) ) : ''
			)
		);

		// Gated separately from `edit_post`: the field is not rendered for editors who
		// cannot write CSS, and an absent field must not wipe what an administrator
		// stored.
		if ( Styles::can_edit() ) {
			update_post_meta(
				$post_id,
				Styles::META,
				Styles::sanitize_css(
					isset( $_POST['whim_custom_css'] ) ? (string) wp_unslash( $_POST['whim_custom_css'] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Styles::sanitize_css(); sanitize_text_field() would flatten the CSS.
				)
			);
		}

		$rejected = [];

		foreach ( Post_Type::STYLE_TOKENS as $token ) {
			$key  = 'whim_style_' . $token;
			$raw  = isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
			$safe = Post_Type::sanitize_style_token( $raw );

			if ( '' !== $raw && '' === $safe ) {
				// Keep whatever was stored before and tell the editor why.
				$rejected[] = $token;
				continue;
			}

			update_post_meta( $post_id, $key, $safe );
		}

		if ( ! empty( $rejected ) ) {
			set_transient( self::NOTICE_TRANSIENT . $post_id . '_' . get_current_user_id(), $rejected, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Shows a notice listing style tokens that were rejected on save.
	 *
	 * @return void
	 */
	public function render_rejected_token_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.

		if ( ! $post_id ) {
			return;
		}

		$transient = self::NOTICE_TRANSIENT . $post_id . '_' . get_current_user_id();
		$rejected  = get_transient( $transient );

		if ( ! is_array( $rejected ) || empty( $rejected ) ) {
			return;
		}

		delete_transient( $transient );

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of style field names. */
					__( 'Whimsical Promo: these style values were not saved because they contain characters that are not allowed in a CSS value: %s', 'whimsical-promo' ),
					implode( ', ', array_map( 'strval', $rejected ) )
				)
			)
		);
	}
}
