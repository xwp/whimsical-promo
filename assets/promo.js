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

	var cfg = window.whimPromoCfg || { tracking: false, delivery: 'datalayer' };
	var EVENT_NAME = 'whimsical_promo';
	var COOKIE_PREFIX = 'whim_seen_';
	var EXIT_MIN_DWELL = 5000;
	var EXIT_DEBOUNCE = 300;
	var SPARKLE_MS = 700;

	var ENTER_MARGIN = '0px 0px -12% 0px';

	// Symmetric -50% collapses the root box to the viewport's centre line.
	var MIDLINE_MARGIN = '-50% 0px -50% 0px';

	// How long to wait for an on-demand stylesheet before showing the promo anyway.
	var CSS_TIMEOUT = 1500;

	// Keyed by href, so two promos sharing a stylesheet share one request.
	var cssLoads = {};

	var pageLoadedAt = new Date().getTime();
	var lastFocused = null;

	/**
	 * Preview target from the URL: `?whim_preview=1` for every promo on the page, or
	 * `?whim_preview=<slug>` for one.
	 *
	 * Read here rather than passed in from PHP so the served HTML is identical with
	 * and without the parameter, and page caches are untouched.
	 */
	var previewTarget = ( function () {
		var match = /[?&]whim_preview=([^&#]*)/.exec( window.location.search );

		if ( ! match ) {
			return '';
		}

		try {
			// Only ever compared against a slug, so anything outside that charset goes.
			return decodeURIComponent( match[ 1 ] ).replace( /[^\w-]/g, '' );
		} catch ( e ) {
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
		var parts = document.cookie ? document.cookie.split( ';' ) : [];
		var encoded = encodeURIComponent( name );

		for ( var i = 0; i < parts.length; i++ ) {
			var pair = parts[ i ].split( '=' );
			var key = pair[ 0 ].trim();

			// Names are compared raw. Decoding them would throw URIError on any unrelated
			// cookie that is not valid percent-encoding — a third-party `100%off` would
			// take init() down with it, and with it every promo on the page.
			if ( key !== encoded && key !== name ) {
				continue;
			}

			if ( pair.length < 2 ) {
				return '';
			}

			var value = pair.slice( 1 ).join( '=' );

			try {
				return decodeURIComponent( value );
			} catch ( e ) {
				return value;
			}
		}

		return null;
	}

	function writeCookie( name, days ) {
		var maxAge = Math.max( 1, parseInt( days, 10 ) || 1 ) * 86400;

		try {
			document.cookie = encodeURIComponent( name ) + '=' + new Date().getTime() +
				'; max-age=' + maxAge + '; path=/; SameSite=Lax';
		} catch ( e ) {
			// Cookies unavailable: the promo simply shows again next visit.
		}
	}

	function attr( el, name ) {
		return el.getAttribute( 'data-whim-' + name ) || '';
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

	function pushEvent( promo, action, target ) {
		// A preview is not an impression.
		if ( ! cfg.tracking || isPreview( promo ) ) {
			return;
		}

		var params = {
			promo_id: attr( promo, 'slug' ),
			promo_placement: attr( promo, 'placement' ),
			promo_action: action
		};

		if ( target ) {
			params.promo_target = String( target );
		}

		if ( 'gtag' === cfg.delivery ) {
			if ( 'function' === typeof window.gtag ) {
				window.gtag( 'event', EVENT_NAME, params );
			}

			return;
		}

		window.dataLayer = window.dataLayer || [];

		var payload = { event: EVENT_NAME };

		for ( var key in params ) {
			if ( Object.prototype.hasOwnProperty.call( params, key ) ) {
				payload[ key ] = params[ key ];
			}
		}

		window.dataLayer.push( payload );
	}

	/**
	 * First promo in a chain that is still allowed to show.
	 *
	 * @param {Element} container Chain wrapper.
	 * @return {Element|null} Winning promo, or null when every promo is spent.
	 */
	function chainWinner( container ) {
		var promos = container.querySelectorAll( '.whim-promo' );
		var i;

		// A promo named in the URL wins outright, wherever it sits in the chain —
		// otherwise a chain could only ever preview its first entry.
		for ( i = 0; i < promos.length; i++ ) {
			if ( '' !== previewTarget && previewTarget === attr( promos[ i ], 'slug' ) ) {
				return promos[ i ];
			}
		}

		for ( i = 0; i < promos.length; i++ ) {
			if ( 'interact' !== attr( promos[ i ], 'gate' ) || ! hasSeen( promos[ i ] ) ) {
				return promos[ i ];
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

		window.requestAnimationFrame( function () {
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
		window.setTimeout( function () {
			el.classList.remove( 'is-clicked' );
		}, SPARKLE_MS );
	}

	/* ---------------------------------------------------------------- inline */

	function setupInlineSlot( slot ) {
		var winner = chainWinner( slot );

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
		observeOnce( winner, ENTER_MARGIN, function () {
			play( winner );
			pushEvent( winner, 'view' );
		} );

		observeOnce( winner, MIDLINE_MARGIN, function () {
			light( winner );
		} );
	}

	function observeOnce( target, rootMargin, callback ) {
		var observer = new IntersectionObserver( function ( entries ) {
			for ( var i = 0; i < entries.length; i++ ) {
				if ( entries[ i ].isIntersecting ) {
					observer.disconnect();
					callback();
					return;
				}
			}
		}, { rootMargin: rootMargin } );

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
		var href = attr( promo, 'css' );

		if ( ! href ) {
			if ( done ) {
				done();
			}

			return;
		}

		var state = cssLoads[ href ];

		if ( state && state.settled ) {
			if ( done ) {
				done();
			}

			return;
		}

		if ( ! state ) {
			state = { settled: false, waiting: [], timer: null };
			cssLoads[ href ] = state;

			var settle = function () {
				if ( state.settled ) {
					return;
				}

				state.settled = true;
				window.clearTimeout( state.timer );

				while ( state.waiting.length ) {
					state.waiting.shift()();
				}
			};

			var link = document.createElement( 'link' );

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
		var winner = chainWinner( container );

		if ( ! winner ) {
			return;
		}

		// Preview skips the gesture, the dwell and the pointer check, so the state can
		// be reviewed straight away and on a touch device.
		if ( isPreview( winner ) ) {
			ensureCss( winner, function () {
				showExit( winner );
			} );

			return;
		}

		var pointerQuery = window.matchMedia && window.matchMedia( '(hover: hover) and (pointer: fine)' );

		if ( ! pointerQuery || ! pointerQuery.matches ) {
			// Touch and coarse-pointer devices have no exit intent to detect.
			return;
		}

		// This promo can now plausibly open, so fetch its design while the reader is
		// still reading rather than at the moment it has to appear. Pages where the
		// cookie already suppressed it never get here, and never pay for the CSS.
		ensureCss( winner );

		var root = document.documentElement;
		var timer = null;
		var fired = false;

		function onLeave( event ) {
			if ( fired || null !== timer ) {
				return;
			}

			if ( event.clientY > 0 ) {
				return;
			}

			if ( new Date().getTime() - pageLoadedAt < EXIT_MIN_DWELL ) {
				return;
			}

			timer = window.setTimeout( function () {
				fired = true;
				root.removeEventListener( 'mouseleave', onLeave );

				// Already in flight from arming, so this is normally instant.
				ensureCss( winner, function () {
					showExit( winner );
				} );
			}, EXIT_DEBOUNCE );
		}

		root.addEventListener( 'mouseleave', onLeave );
	}

	/**
	 * Where a modal should put focus on open: the dialog itself, which the close
	 * button used to take. Focusing a control would arm Enter on it the instant the
	 * promo appears — on dismiss, or on a signup nobody has read yet. From the
	 * dialog, one Tab reaches the call to action, and the close button is last in
	 * the card so Tab never offers it first.
	 */
	function focusTarget( promo ) {
		return promo.querySelector( '.whim-promo__card' ) ||
			promo.querySelector( '.whim-promo__close' );
	}

	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	/** Keeps Tab inside a modal promo, so it never reaches page content behind it. */
	function trapTab( promo, event ) {
		var items = promo.querySelectorAll( FOCUSABLE );

		if ( ! items.length ) {
			event.preventDefault();
			return;
		}

		var first = items[ 0 ];
		var last = items[ items.length - 1 ];
		var active = document.activeElement;

		// Membership in `items`, not mere containment: the card itself sits inside
		// `promo` but carries tabindex="-1" and so is never one of the stops, which
		// `promo.contains()` alone would miss on the very first Shift+Tab after open.
		var inside = Array.prototype.indexOf.call( items, active ) > -1;

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

		if ( 'modal' === attr( promo, 'presentation' ) ) {
			document.body.classList.add( 'whim-has-modal' );

			var target = focusTarget( promo );

			if ( target ) {
				target.focus();
			}
		}

		document.addEventListener( 'keydown', onKeydown );
	}

	function onKeydown( event ) {
		var open = document.querySelector( '.whim-promo--exit-intent.is-revealed' );

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
		document.body.classList.remove( 'whim-has-modal' );

		promo.classList.remove( 'is-revealed' );
		promo.classList.add( 'is-dismissing' );

		window.setTimeout( function () {
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
		var target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		var promo = target.closest( '.whim-promo' );

		if ( ! promo ) {
			return;
		}

		if ( target.closest( '[data-whim-close]' ) ) {
			dismiss( promo );
			return;
		}

		var action = target.closest( 'a, button, input[type="submit"]' );

		if ( ! action ) {
			return;
		}

		markInteracted( promo );
		pushEvent( promo, 'click', action.getAttribute( 'href' ) || action.id || action.name || '' );
		sparkle( action );
	}

	function onSubmit( event ) {
		var form = event.target;

		if ( ! form || ! form.closest ) {
			return;
		}

		var promo = form.closest( '.whim-promo' );

		if ( ! promo ) {
			return;
		}

		markInteracted( promo );
		pushEvent( promo, 'submit', form.id || form.getAttribute( 'action' ) || '' );
	}

	/* ------------------------------------------------------------------ init */

	function init() {
		var slots = document.querySelectorAll( '.whim-promo-slot' );

		for ( var i = 0; i < slots.length; i++ ) {
			setupInlineSlot( slots[ i ] );
		}

		var exits = document.querySelectorAll( '.whim-promo-exit' );

		for ( var j = 0; j < exits.length; j++ ) {
			setupExitIntent( exits[ j ] );
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
