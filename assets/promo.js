/**
 * Whimsical Promo front-end behaviour.
 *
 * Everything visitor-specific happens here: the server always ships the same
 * inert markup, so page caches stay intact.
 *
 * `?whim_preview=1` (or `=<slug>`) forces a promo open for review: it ignores the
 * frequency cookie, writes no cookie of its own and reports no analytics, so it can
 * be run repeatedly and shared as a link without spending the promo or skewing
 * numbers. Exit-intent promos additionally skip the gesture, the dwell and the
 * pointer check; inline promos still animate in on scroll, which is the thing worth
 * looking at.
 */
( function () {
	'use strict';

	const cfg = window.whimBogoCfg || { tracking: false, delivery: 'datalayer' };
	const EVENT_NAME = 'whimsical_bogo';
	const COOKIE_PREFIX = 'whim_seen_';
	const EXIT_MIN_DWELL = 5000;
	const EXIT_DEBOUNCE = 300;
	const SPARKLE_MS = 700;

	// How long the site stays quiet after any overlay opens, so a reader moving quickly
	// through several articles gets one overlay rather than one per page view.
	const EXIT_QUIET_PERIOD = 900000;
	const EXIT_QUIET_COOKIE = 'whim_exit_at';

	const ENTER_MARGIN = '0px 0px -12% 0px';

	// Symmetric -50% collapses the root box to the viewport's centre line.
	const MIDLINE_MARGIN = '-50% 0px -50% 0px';

	// Where the reading line sits, as a fraction of the viewport height. The last tenth
	// is left over deliberately: a line pinned to the very bottom edge is only crossed
	// on a page that can scroll past the end of its article, and a page whose article
	// runs to the final pixel never can.
	const END_LINE_RATIO = 0.9;

	// How long to let a resize settle before the reading line is measured again.
	const RESIZE_SETTLE = 150;

	// Used when the page ships no list of its own — see Settings::DEFAULT_CONTENT_SELECTORS.
	const CONTENT_SELECTORS = [ '.entry-content', '.post-content', 'article', 'main' ];

	// How long to wait for an on-demand stylesheet before showing the promo anyway.
	const CSS_TIMEOUT = 1500;

	// Keyed by href, so two promos sharing a stylesheet share one request.
	const cssLoads = {};

	const pageLoadedAt = Date.now();
	let lastFocused = null;

	/**
	 * Preview target from the URL: `?whim_preview=1` for every promo on the page, or
	 * `?whim_preview=<slug>` for one.
	 *
	 * Read here rather than passed in from PHP so the served HTML is identical with
	 * and without the parameter, and page caches are untouched.
	 */
	const previewTarget = ( function () {
		const match = /[?&]whim_preview=([^&#]*)/.exec( window.location.search );

		if ( ! match ) {
			return '';
		}

		try {
			// Only ever compared against a slug, so anything outside that charset goes.
			return decodeURIComponent( match[ 1 ] ).replace( /[^\w-]/g, '' );
		} catch {
			return '';
		}
	}() );

	function isPreview( promo ) {
		if ( '' === previewTarget ) {
			return false;
		}

		return '1' === previewTarget ||
			'all' === previewTarget ||
			previewTarget === attr( promo, 'slug' );
	}

	function readCookie( name ) {
		const parts = document.cookie ? document.cookie.split( ';' ) : [];
		const encoded = encodeURIComponent( name );

		for ( const part of parts ) {
			const pair = part.split( '=' );
			const key = pair[ 0 ].trim();

			// Names are compared raw. Decoding them would throw URIError on any unrelated
			// cookie that is not valid percent-encoding — a third-party `100%off` would
			// take init() down with it, and with it every promo on the page.
			if ( key !== encoded && key !== name ) {
				continue;
			}

			if ( pair.length < 2 ) {
				return '';
			}

			const value = pair.slice( 1 ).join( '=' );

			try {
				return decodeURIComponent( value );
			} catch {
				return value;
			}
		}

		return null;
	}

	function writeCookie( name, days ) {
		const maxAge = Math.max( 1, parseInt( days, 10 ) || 1 ) * 86400;

		try {
			document.cookie = `${ encodeURIComponent( name ) }=${ Date.now() }; max-age=${ maxAge }; path=/; SameSite=Lax`;
		} catch {
			// Cookies unavailable: the promo simply shows again next visit.
		}
	}

	function attr( el, name ) {
		return el.getAttribute( `data-whim-${ name }` ) || '';
	}

	function hasSeen( promo ) {
		if ( isPreview( promo ) ) {
			return false;
		}

		return null !== readCookie( COOKIE_PREFIX + attr( promo, 'slug' ) );
	}

	function remember( promo ) {
		// Preview writes no state, so it stays repeatable and cannot spend the promo
		// for whoever is looking at it.
		if ( isPreview( promo ) ) {
			return;
		}

		writeCookie( COOKIE_PREFIX + attr( promo, 'slug' ), attr( promo, 'days' ) );
	}

	/** Whether an overlay opened recently enough that this page view sits out. */
	function inQuietPeriod() {
		const last = parseInt( readCookie( EXIT_QUIET_COOKIE ), 10 );

		if ( isNaN( last ) ) {
			return false;
		}

		const elapsed = Date.now() - last;

		// A negative gap means the clock moved backwards, not that an overlay is due.
		return elapsed >= 0 && elapsed < EXIT_QUIET_PERIOD;
	}

	function pushEvent( promo, action, target ) {
		// A preview is not an impression.
		if ( ! cfg.tracking || isPreview( promo ) ) {
			return;
		}

		const params = {
			bogo_id: attr( promo, 'slug' ),
			bogo_placement: attr( promo, 'placement' ),
			bogo_action: action
		};

		if ( target ) {
			params.bogo_target = String( target );
		}

		if ( 'gtag' === cfg.delivery ) {
			if ( 'function' === typeof window.gtag ) {
				window.gtag( 'event', EVENT_NAME, params );
			}

			return;
		}

		window.dataLayer = window.dataLayer || [];

		window.dataLayer.push( { event: EVENT_NAME, ...params } );
	}

	/**
	 * First promo in a chain that is still allowed to show.
	 *
	 * @param {Element} container Chain wrapper.
	 * @return {Element|null} Winning promo, or null when every promo is spent.
	 */
	function chainWinner( container ) {
		const promos = container.querySelectorAll( '.whim-bogo' );

		// A promo named in the URL wins outright, wherever it sits in the chain —
		// otherwise a chain could only ever preview its first entry.
		for ( const promo of promos ) {
			if ( '' !== previewTarget && previewTarget === attr( promo, 'slug' ) ) {
				return promo;
			}
		}

		for ( const promo of promos ) {
			if ( 'interact' !== attr( promo, 'gate' ) || ! hasSeen( promo ) ) {
				return promo;
			}
		}

		return null;
	}

	function markInteracted( promo ) {
		if ( 'interact' === attr( promo, 'gate' ) ) {
			remember( promo );
		}
	}

	/** Takes the promo out of `hidden` so it reserves layout space, still invisible. */
	function occupy( promo ) {
		promo.hidden = false;
	}

	/** Releases the card into layout and lets its entrance play. */
	function play( promo ) {
		if ( promo.classList.contains( 'is-revealed' ) ) {
			return;
		}

		occupy( promo );

		// Flush the un-revealed style before the class lands: without this the
		// un-hide and the class add can coalesce into one recalc, and the
		// entrance transition has no start value to animate from.
		void promo.offsetHeight;

		window.requestAnimationFrame( () => {
			promo.classList.add( 'is-revealed' );
		} );
	}

	/** Lights the accent details, on their own beat from the entrance. */
	function light( promo ) {
		promo.classList.add( 'is-lit' );
	}

	/** Plays the entrance animation and reports the impression. */
	function reveal( promo ) {
		if ( promo.classList.contains( 'is-revealed' ) ) {
			return;
		}

		play( promo );
		light( promo );
		pushEvent( promo, 'view' );
	}

	function sparkle( el ) {
		if ( ! el ) {
			return;
		}

		el.classList.add( 'is-clicked' );
		window.setTimeout( () => {
			el.classList.remove( 'is-clicked' );
		}, SPARKLE_MS );
	}

	/* ---------------------------------------------------------------- inline */

	function setupInlineSlot( slot ) {
		const winner = chainWinner( slot );

		if ( ! winner ) {
			// Nothing to show: the slot keeps zero height, so there is no gap.
			return;
		}

		slot.classList.add( 'has-promo' );

		// Claim the space up front so the card never shifts the article later.
		occupy( winner );

		if ( ! ( 'IntersectionObserver' in window ) ) {
			play( winner );
			light( winner );
			pushEvent( winner, 'view' );

			return;
		}

		// Not a `view()` timeline: the ad script writes an inline `overflow` onto <body>,
		// which makes body a scroll container with nothing to scroll, so `view()` binds
		// to it and sticks at the end state.
		observeOnce( winner, ENTER_MARGIN, () => {
			play( winner );
			pushEvent( winner, 'view' );
		} );

		observeOnce( winner, MIDLINE_MARGIN, () => {
			light( winner );
		} );
	}

	function observeOnce( target, rootMargin, callback ) {
		const observer = new IntersectionObserver( ( entries ) => {
			for ( const entry of entries ) {
				if ( entry.isIntersecting ) {
					observer.disconnect();
					callback();
					return;
				}
			}
		}, { rootMargin } );

		observer.observe( target );
	}

	/* ----------------------------------------------------------- exit intent */

	/**
	 * Loads a promo's stylesheet on demand and calls back once it is applied.
	 *
	 * Exit-intent CSS is not enqueued at page load, because most page views never open
	 * the promo. Callbacks always run — a promo wearing only the base styles is better
	 * than one that never appears — so a blocked or slow sheet cannot swallow it.
	 */
	function ensureCss( promo, done ) {
		const href = attr( promo, 'css' );

		if ( ! href ) {
			if ( done ) {
				done();
			}

			return;
		}

		let state = cssLoads[ href ];

		if ( state && state.settled ) {
			if ( done ) {
				done();
			}

			return;
		}

		if ( ! state ) {
			state = { settled: false, waiting: [], timer: null };
			cssLoads[ href ] = state;

			const settle = () => {
				if ( state.settled ) {
					return;
				}

				state.settled = true;
				window.clearTimeout( state.timer );

				while ( state.waiting.length ) {
					state.waiting.shift()();
				}
			};

			const link = document.createElement( 'link' );

			link.rel = 'stylesheet';
			link.href = href;
			link.addEventListener( 'load', settle );
			link.addEventListener( 'error', settle );
			state.timer = window.setTimeout( settle, CSS_TIMEOUT );

			document.head.appendChild( link );
		}

		if ( done ) {
			state.waiting.push( done );
		}
	}

	function setupExitIntent( container ) {
		const winner = chainWinner( container );

		if ( ! winner ) {
			return;
		}

		// Preview skips the gesture, the dwell and the pointer check, so the state can
		// be reviewed straight away and on a touch device.
		if ( isPreview( winner ) ) {
			ensureCss( winner, () => {
				showExit( winner );
			} );

			return;
		}

		// Nothing is written here, so the promo is not spent — it simply waits for a page
		// view outside the window.
		if ( inQuietPeriod() ) {
			return;
		}

		const pointerQuery = window.matchMedia && window.matchMedia( '(hover: hover) and (pointer: fine)' );

		if ( ! pointerQuery || ! pointerQuery.matches ) {
			// Touch and coarse-pointer devices have no exit intent to detect, so the end
			// of the article stands in for the gesture when the editor asked for it.
			armContentEnd( winner );

			return;
		}

		// This promo can now plausibly open, so fetch its design while the reader is
		// still reading rather than at the moment it has to appear. Pages where the
		// cookie already suppressed it never get here, and never pay for the CSS.
		ensureCss( winner );

		const root = document.documentElement;
		let timer = null;
		let fired = false;

		function onLeave( event ) {
			if ( fired || null !== timer ) {
				return;
			}

			if ( event.clientY > 0 ) {
				return;
			}

			if ( Date.now() - pageLoadedAt < EXIT_MIN_DWELL ) {
				return;
			}

			timer = window.setTimeout( () => {
				fired = true;
				root.removeEventListener( 'mouseleave', onLeave );

				// Already in flight from arming, so this is normally instant.
				ensureCss( winner, () => {
					showExit( winner );
				} );
			}, EXIT_DEBOUNCE );
		}

		root.addEventListener( 'mouseleave', onLeave );
	}

	/* ------------------------------------------------------- end of content */

	/**
	 * The element whose end counts as "finished reading", first match wins.
	 *
	 * @return {Element|null} Content element, or null when the page has none of them.
	 */
	function contentElement() {
		const selectors = cfg.contentSelectors && cfg.contentSelectors.length
			? cfg.contentSelectors
			: CONTENT_SELECTORS;

		for ( const selector of selectors ) {
			let found = null;

			try {
				// One at a time: a comma-joined list returns whichever match comes first
				// in the document, not the first selector the site listed.
				found = document.querySelector( selector );
			} catch {
				// One unusable selector in the setting is not a reason to give up on the rest.
			}

			if ( found ) {
				return found;
			}
		}

		return null;
	}

	/** Distance from the top of the viewport to the reading line, in pixels. */
	function readingLine() {
		return Math.round( window.innerHeight * END_LINE_RATIO );
	}

	/**
	 * Calls back once the end of an element has scrolled up past the reading line near
	 * the bottom of the viewport.
	 *
	 * The root box is collapsed onto that line, so each entry carries the element's own
	 * bottom measured against it. No sentinel element has to be injected at the end of
	 * the article, and no scroll handler runs.
	 *
	 * The margin is in pixels, not a percentage: engines disagree over which axis a
	 * percentage rootMargin resolves against, and this line has to be a fraction of the
	 * height on a phone specifically. Pixels do mean the line has to be measured again
	 * when the viewport changes, so the observer is rebuilt after a resize or a rotation.
	 *
	 * @param {Element}  target   Element to watch.
	 * @param {Function} callback Called once, then the observer disconnects.
	 */
	function observeEndOnce( target, callback ) {
		let observer = null;
		let settle = null;
		let done = false;

		function onChange( entries ) {
			const entry = entries[ entries.length - 1 ];

			// rootBounds is null in some cross-origin framing cases, where the margin
			// cannot be read back — the line we asked for is the closest honest stand-in.
			const line = entry.rootBounds ? entry.rootBounds.bottom : readingLine();

			if ( done || entry.boundingClientRect.bottom > line ) {
				return;
			}

			done = true;
			observer.disconnect();
			window.removeEventListener( 'resize', onResize );
			callback();
		}

		function connect() {
			const line = readingLine();

			if ( observer ) {
				observer.disconnect();
			}

			// Top edge pulled down to the line, bottom edge pulled up to meet it.
			observer = new IntersectionObserver( onChange, {
				rootMargin: `${ -line }px 0px ${ line - window.innerHeight }px 0px`
			} );

			observer.observe( target );
		}

		function onResize() {
			window.clearTimeout( settle );
			settle = window.setTimeout( () => {
				if ( ! done ) {
					connect();
				}
			}, RESIZE_SETTLE );
		}

		window.addEventListener( 'resize', onResize );
		connect();
	}

	/** Runs a callback once the page has been open as long as exit intent waits out. */
	function afterDwell( callback ) {
		const waited = Date.now() - pageLoadedAt;

		if ( waited >= EXIT_MIN_DWELL ) {
			callback();
			return;
		}

		window.setTimeout( callback, EXIT_MIN_DWELL - waited );
	}

	/**
	 * Mobile stand-in for the cursor gesture: the promo opens once the reader reaches
	 * the end of the article.
	 *
	 * @param {Element} promo Winning exit promo.
	 */
	function armContentEnd( promo ) {
		if ( '1' !== attr( promo, 'mobile-end' ) || ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

		const content = contentElement();

		if ( ! content ) {
			return;
		}

		// Same reasoning as the desktop arming: fetch the design while the reader is
		// still reading, not at the moment the card has to appear.
		ensureCss( promo );

		observeEndOnce( content, () => {
			afterDwell( () => {
				ensureCss( promo, () => {
					showExit( promo );
				} );
			} );
		} );
	}

	/**
	 * Where a modal should put focus on open: the dialog itself, which the close
	 * button used to take. Focusing a control would arm Enter on it the instant the
	 * promo appears — on dismiss, or on a signup nobody has read yet. From the
	 * dialog, one Tab reaches the call to action, and the close button is last in
	 * the card so Tab never offers it first.
	 */
	function focusTarget( promo ) {
		return promo.querySelector( '.whim-bogo__card' ) ||
			promo.querySelector( '.whim-bogo__close' );
	}

	const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	/** Keeps Tab inside a modal promo, so it never reaches page content behind it. */
	function trapTab( promo, event ) {
		const items = promo.querySelectorAll( FOCUSABLE );

		if ( ! items.length ) {
			event.preventDefault();
			return;
		}

		const first = items[ 0 ];
		const last = items[ items.length - 1 ];
		const active = document.activeElement;

		// Membership in `items`, not mere containment: the card itself sits inside
		// `promo` but carries tabindex="-1" and so is never one of the stops, which
		// `promo.contains()` alone would miss on the very first Shift+Tab after open.
		const inside = Array.from( items ).includes( active );

		if ( event.shiftKey && ( ! inside || active === first ) ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && ( ! inside || active === last ) ) {
			event.preventDefault();
			first.focus();
		}
	}

	function showExit( promo ) {
		lastFocused = document.activeElement;

		reveal( promo );
		remember( promo );

		// A preview must not start the quiet period, or reviewing one overlay would hide
		// the real ones from whoever is reviewing.
		if ( ! isPreview( promo ) ) {
			writeCookie( EXIT_QUIET_COOKIE, 1 );
		}

		document.body.classList.add( 'whim-has-overlay' );

		if ( 'modal' === attr( promo, 'presentation' ) ) {
			document.body.classList.add( 'whim-has-modal' );

			const target = focusTarget( promo );

			if ( target ) {
				target.focus();
			}
		}

		document.addEventListener( 'keydown', onKeydown );
	}

	function onKeydown( event ) {
		const open = document.querySelector( '.whim-bogo--exit-intent.is-revealed' );

		if ( ! open ) {
			return;
		}

		if ( 'Tab' === event.key && 'modal' === attr( open, 'presentation' ) ) {
			trapTab( open, event );
			return;
		}

		if ( 'Escape' !== event.key && 'Esc' !== event.key ) {
			return;
		}

		dismiss( open );
	}

	function dismiss( promo ) {
		document.removeEventListener( 'keydown', onKeydown );
		document.body.classList.remove( 'whim-has-overlay' );
		document.body.classList.remove( 'whim-has-modal' );

		promo.classList.remove( 'is-revealed' );
		promo.classList.add( 'is-dismissing' );

		window.setTimeout( () => {
			promo.hidden = true;
			promo.classList.remove( 'is-dismissing' );
		}, 400 );

		remember( promo );
		pushEvent( promo, 'dismiss' );

		if ( lastFocused && 'function' === typeof lastFocused.focus ) {
			lastFocused.focus();
		}

		lastFocused = null;
	}

	/* ------------------------------------------------------------ delegation */

	function onClick( event ) {
		const target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		const promo = target.closest( '.whim-bogo' );

		if ( ! promo ) {
			return;
		}

		if ( target.closest( '[data-whim-close]' ) ) {
			dismiss( promo );
			return;
		}

		const action = target.closest( 'a, button, input[type="submit"]' );

		if ( ! action ) {
			return;
		}

		markInteracted( promo );
		pushEvent( promo, 'click', action.getAttribute( 'href' ) || action.id || action.name || '' );
		sparkle( action );
	}

	function onSubmit( event ) {
		const form = event.target;

		if ( ! form || ! form.closest ) {
			return;
		}

		const promo = form.closest( '.whim-bogo' );

		if ( ! promo ) {
			return;
		}

		markInteracted( promo );
		pushEvent( promo, 'submit', form.id || form.getAttribute( 'action' ) || '' );
	}

	/* ------------------------------------------------------------------ init */

	function init() {
		for ( const slot of document.querySelectorAll( '.whim-bogo-slot' ) ) {
			setupInlineSlot( slot );
		}

		for ( const exit of document.querySelectorAll( '.whim-bogo-exit' ) ) {
			setupExitIntent( exit );
		}

		document.addEventListener( 'click', onClick );
		document.addEventListener( 'submit', onSubmit );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
