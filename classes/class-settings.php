<?php
/**
 * Plugin-level settings page: tracking configuration plus in-admin GTM/GA4 docs.
 *
 * @since 1.0.0
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo;

use WhimsicalPromo\Singleton\Singleton;

/**
 * Class Settings
 *
 * @since   1.0.0
 * @package WhimsicalPromo
 */
class Settings {
	use Singleton;

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'whimsical_promo_settings';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'whimsical-promo-settings';

	/**
	 * Analytics event name emitted by promo.js.
	 *
	 * @var string
	 */
	const EVENT_NAME = 'whimsical_promo';

	/**
	 * Allowed delivery modes.
	 *
	 * @var string[]
	 */
	const DELIVERY_MODES = [ 'datalayer', 'gtag' ];

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Default option values.
	 *
	 * @return array{tracking_enabled:bool,delivery:string}
	 */
	public static function defaults(): array {
		return [
			'tracking_enabled' => true,
			'delivery'         => 'datalayer',
		];
	}

	/**
	 * Current settings, merged over defaults.
	 *
	 * @return array{tracking_enabled:bool,delivery:string}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, null );

		// Nothing saved yet: use defaults rather than reading an absent checkbox as "off".
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return self::defaults();
		}

		return self::sanitize( $stored );
	}

	/**
	 * Config handed to promo.js.
	 *
	 * @return array{tracking:bool,delivery:string}
	 */
	public static function js_config(): array {
		$settings = self::get();

		return [
			'tracking' => $settings['tracking_enabled'],
			'delivery' => $settings['delivery'],
		];
	}

	/**
	 * Sanitizes the option array.
	 *
	 * @param mixed $input Raw option value.
	 *
	 * @return array{tracking_enabled:bool,delivery:string}
	 */
	public static function sanitize( $input ): array {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : [];

		$delivery = isset( $input['delivery'] ) && is_string( $input['delivery'] ) ? $input['delivery'] : '';

		return [
			'tracking_enabled' => ! empty( $input['tracking_enabled'] ),
			'delivery'         => in_array( $delivery, self::DELIVERY_MODES, true ) ? $delivery : $defaults['delivery'],
		];
	}

	/**
	 * Registers the option with the Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION,
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			]
		);
	}

	/**
	 * Adds the settings submenu under Promos.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::POST_TYPE,
			__( 'Promo Settings', 'whimsical-promo' ),
			__( 'Settings', 'whimsical-promo' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Sample event payload, used both on the settings page and in the README.
	 *
	 * @return string
	 */
	public static function payload_sample(): string {
		return "window.dataLayer.push( {\n"
			. "    event: 'whimsical_promo',\n"
			. "    promo_id: 'newsletter-inline',   // the promo post slug\n"
			. "    promo_placement: 'inline_hook',  // inline_hook | exit_intent\n"
			. "    promo_action: 'view',            // view | click | submit | dismiss\n"
			. "    promo_target: '/subscribe/'      // link href or form id, on click/submit\n"
			. '} );';
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Promo Settings', 'whimsical-promo' ); ?></h1>

			<form action="options.php" method="post">
				<?php settings_fields( self::OPTION ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Tracking', 'whimsical-promo' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[tracking_enabled]" value="1"
										<?php checked( $settings['tracking_enabled'] ); ?> />
									<?php esc_html_e( 'Send promo analytics events', 'whimsical-promo' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When off, promos still work — they just emit no analytics calls.', 'whimsical-promo' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Delivery', 'whimsical-promo' ); ?></th>
							<td>
								<fieldset>
									<label style="display:block;margin-bottom:.4em">
										<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[delivery]" value="datalayer"
											<?php checked( 'datalayer', $settings['delivery'] ); ?> />
										<?php esc_html_e( 'Push to dataLayer — use this with Google Tag Manager', 'whimsical-promo' ); ?>
									</label>
									<label style="display:block">
										<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[delivery]" value="gtag"
											<?php checked( 'gtag', $settings['delivery'] ); ?> />
										<?php esc_html_e( 'Call gtag() directly — use this on sites without GTM', 'whimsical-promo' ); ?>
									</label>
								</fieldset>
								<p class="description">
									<?php esc_html_e( 'Direct gtag() calls are skipped silently when no gtag function exists on the page.', 'whimsical-promo' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'What gets sent', 'whimsical-promo' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: analytics event name. */
					esc_html__( 'Every promo interaction emits a single event named %s. A view fires when a promo actually appears — not on page load — so impressions match what readers saw.', 'whimsical-promo' ),
					'<code>' . esc_html( self::EVENT_NAME ) . '</code>'
				);
				?>
			</p>
			<pre style="background:#fff;border:1px solid #dcdcde;padding:1em;overflow:auto"><code><?php echo esc_html( self::payload_sample() ); ?></code></pre>
			<p>
				<?php esc_html_e( 'Actions: view (promo revealed), click (link or button inside the promo), submit (form inside the promo), dismiss (exit-intent promo closed).', 'whimsical-promo' ); ?>
			</p>

			<h2><?php esc_html_e( 'Set it up in Google Tag Manager', 'whimsical-promo' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Variables → New → Data Layer Variable, once per field: promo_id, promo_placement, promo_action, promo_target. Name them dlv - promo_id and so on.', 'whimsical-promo' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: analytics event name. */
						esc_html__( 'Triggers → New → Custom Event. Event name: %s. Fire on: All Custom Events.', 'whimsical-promo' ),
						'<code>' . esc_html( self::EVENT_NAME ) . '</code>'
					);
					?>
				</li>
				<li>
					<?php
					printf(
						/* translators: %s: analytics event name. */
						esc_html__( 'Tags → New → Google Analytics: GA4 Event. Pick your GA4 configuration tag, set Event Name to %s, and add the four data layer variables as event parameters using the same names.', 'whimsical-promo' ),
						'<code>' . esc_html( self::EVENT_NAME ) . '</code>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Attach the Custom Event trigger to the tag, then Preview to confirm the event and its parameters arrive.', 'whimsical-promo' ); ?></li>
				<li><?php esc_html_e( 'Submit the container version.', 'whimsical-promo' ); ?></li>
			</ol>
			<p class="description">
				<?php esc_html_e( 'Consent gating stays in GTM: the plugin only pushes to dataLayer, it never decides whether a tag may fire.', 'whimsical-promo' ); ?>
			</p>

			<h2><?php esc_html_e( 'Make the data usable in GA4', 'whimsical-promo' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Admin → Custom definitions → Create custom dimension. Scope: Event. One per parameter: promo_id, promo_placement, promo_action, promo_target.', 'whimsical-promo' ); ?></li>
				<li><?php esc_html_e( 'Wait for data — custom dimensions only populate reports from the moment they are created.', 'whimsical-promo' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: analytics event name. */
						esc_html__( 'Admin → Events: find %s and toggle "Mark as key event" if promo submissions count as a conversion for you.', 'whimsical-promo' ),
						'<code>' . esc_html( self::EVENT_NAME ) . '</code>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Verify in Admin → DebugView (direct gtag mode) or GTM Preview (dataLayer mode) before relying on the numbers.', 'whimsical-promo' ); ?></li>
			</ol>
		</div>
		<?php
	}
}
