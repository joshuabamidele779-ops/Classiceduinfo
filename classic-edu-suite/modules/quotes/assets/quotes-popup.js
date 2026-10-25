/**
 * Classic EDU Suite - daily quote popup.
 *
 * Loaded only on page views where the popup can actually fire. Everything is
 * namespaced `ces-` so it cannot collide with the Popup Builder plugin, and if
 * a Popup Builder dialog is already on screen this script stands down rather
 * than stacking a second box on top of it.
 *
 * Config comes from window.CESQuotes, REST details from window.CES.
 */
( function () {
	'use strict';

	var cfg = window.CESQuotes || {};
	var root = document.getElementById( 'ces-quote-popup' );

	if ( ! root || ! cfg.quoteId ) {
		return;
	}

	var endpoints = cfg.endpoints || {};
	var isOpen = false;
	var isDone = false;
	var lastFocus = null;
	var timer = null;

	/**
	 * POST to a plugin REST route. Never rejects: logging is best effort.
	 *
	 * @param {string}  path      Route below the namespace.
	 * @param {Object}  body      JSON payload.
	 * @param {boolean} keepalive Keep the request alive across navigation.
	 * @return {Promise} Always resolves.
	 */
	function send( path, body, keepalive ) {
		var ces = window.CES;

		if ( ! path || ! ces || ! ces.restUrl || typeof window.fetch !== 'function' ) {
			return Promise.resolve( null );
		}

		var options = {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ces.nonce || ''
			},
			body: JSON.stringify( body || {} )
		};

		if ( keepalive ) {
			options.keepalive = true;
		}

		try {
			return window.fetch( ces.restUrl + path, options ).catch( function () {
				return null;
			} );
		} catch ( e ) {
			return Promise.resolve( null );
		}
	}

	/**
	 * Remember that today's quote has been delivered, so the server can skip
	 * the whole feature on the next page view without touching the database.
	 */
	function remember() {
		if ( 'every_visit' === cfg.frequency || ! cfg.cookie || ! cfg.today ) {
			return;
		}

		try {
			var expires = new Date();
			expires.setTime( expires.getTime() + 36 * 60 * 60 * 1000 );

			var cookie = cfg.cookie + '=' + encodeURIComponent( cfg.today ) +
				'; expires=' + expires.toUTCString() + '; path=/; samesite=lax';

			if ( 'https:' === window.location.protocol ) {
				cookie += '; secure';
			}

			document.cookie = cookie;
		} catch ( e ) {
			/* Cookies blocked: the server-side log still covers us. */
		}
	}

	/**
	 * Visible, focusable children of the dialog.
	 *
	 * @return {Array} Elements.
	 */
	function focusables() {
		var nodes = root.querySelectorAll(
			'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
		);

		return Array.prototype.filter.call( nodes, function ( node ) {
			return !! ( node.offsetWidth || node.offsetHeight || node.getClientRects().length );
		} );
	}

	/**
	 * Escape closes; Tab is trapped inside the dialog.
	 *
	 * @param {KeyboardEvent} event Key event.
	 */
	function onKeydown( event ) {
		if ( 'Escape' === event.key || 'Esc' === event.key || 27 === event.keyCode ) {
			event.preventDefault();
			close( true );
			return;
		}

		if ( 'Tab' !== event.key && 9 !== event.keyCode ) {
			return;
		}

		var items = focusables();

		if ( ! items.length ) {
			return;
		}

		var first = items[ 0 ];
		var last = items[ items.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	/**
	 * Whether another popup plugin is currently showing something.
	 *
	 * @return {boolean} True when we should stand down.
	 */
	function otherPopupVisible() {
		var selectors = [
			'.sgpb-popup-dialog-main-div',
			'.sgpb-popup-overlay',
			'.pum-overlay.pum-active',
			'dialog[open]'
		];

		for ( var i = 0; i < selectors.length; i++ ) {
			var node = document.querySelector( selectors[ i ] );

			if ( ! node || node === root || root.contains( node ) ) {
				continue;
			}

			if ( node.offsetWidth || node.offsetHeight || node.getClientRects().length ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reveal the popup.
	 */
	function open() {
		if ( isOpen || isDone ) {
			return;
		}

		if ( otherPopupVisible() ) {
			// Another popup owns the screen. Do not stack, do not log.
			isDone = true;
			return;
		}

		isOpen = true;
		lastFocus = document.activeElement;

		root.hidden = false;
		root.setAttribute( 'aria-hidden', 'false' );

		if ( window.requestAnimationFrame ) {
			window.requestAnimationFrame( function () {
				root.classList.add( 'is-open' );
			} );
		} else {
			root.classList.add( 'is-open' );
		}

		document.addEventListener( 'keydown', onKeydown, true );

		var target = root.querySelector( '[data-ces-quote-initial]' ) || focusables()[ 0 ];

		if ( target ) {
			try {
				target.focus();
			} catch ( e ) {
				/* Nothing to focus. */
			}
		}

		remember();
		send( endpoints.impression, { quote_id: cfg.quoteId } );
	}

	/**
	 * Hide the popup.
	 *
	 * @param {boolean} dismissed True when the student actively closed it.
	 */
	function close( dismissed ) {
		if ( ! isOpen ) {
			return;
		}

		isOpen = false;
		isDone = true;

		root.classList.remove( 'is-open' );
		document.removeEventListener( 'keydown', onKeydown, true );

		window.setTimeout( function () {
			root.hidden = true;
			root.setAttribute( 'aria-hidden', 'true' );
		}, 240 );

		if ( dismissed ) {
			send( endpoints.dismiss, { quote_id: cfg.quoteId } );
		}

		if ( lastFocus && 'function' === typeof lastFocus.focus ) {
			try {
				lastFocus.focus();
			} catch ( e ) {
				/* The trigger element is gone. */
			}
		}
	}

	/**
	 * Record an invite-task click, then let the browser navigate.
	 */
	function recordCta() {
		send( endpoints.cta, { quote_id: cfg.quoteId }, true );
	}

	/**
	 * Copy the referral link to the clipboard.
	 *
	 * @param {HTMLElement} button The copy button.
	 */
	function copyLink( button ) {
		var value = button.getAttribute( 'data-ces-quote-copy' ) || '';

		if ( ! value ) {
			return;
		}

		var done = function () {
			var original = button.getAttribute( 'data-label-done' );

			if ( original ) {
				var previous = button.textContent;
				button.textContent = original;
				window.setTimeout( function () {
					button.textContent = previous;
				}, 2000 );
			}
		};

		if ( window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			window.navigator.clipboard.writeText( value ).then( done, function () {} );
		} else {
			var field = document.createElement( 'textarea' );
			field.value = value;
			field.setAttribute( 'readonly', 'readonly' );
			field.style.position = 'fixed';
			field.style.opacity = '0';
			document.body.appendChild( field );
			field.select();

			try {
				document.execCommand( 'copy' );
				done();
			} catch ( e ) {
				/* Clipboard unavailable. */
			}

			document.body.removeChild( field );
		}

		recordCta();
	}

	/**
	 * Bind the dialog's own controls.
	 */
	function bind() {
		root.addEventListener( 'click', function ( event ) {
			var target = event.target;

			if ( ! target || ! target.closest ) {
				return;
			}

			if ( target.closest( '[data-ces-quote-close]' ) ) {
				event.preventDefault();
				close( true );
				return;
			}

			var copy = target.closest( '[data-ces-quote-copy]' );

			if ( copy ) {
				event.preventDefault();
				copyLink( copy );
				return;
			}

			if ( target.closest( '[data-ces-quote-cta]' ) ) {
				recordCta();
			}
		} );
	}

	/**
	 * Start the delay timer, waiting for a visible tab first.
	 */
	function schedule() {
		if ( isDone || isOpen || null !== timer ) {
			return;
		}

		if ( document.hidden ) {
			document.addEventListener(
				'visibilitychange',
				function once() {
					document.removeEventListener( 'visibilitychange', once );
					timer = null;
					schedule();
				}
			);
			return;
		}

		var delay = parseInt( cfg.delay, 10 );

		if ( isNaN( delay ) || delay < 0 ) {
			delay = 8000;
		}

		timer = window.setTimeout( open, delay );
	}

	bind();

	if ( 'complete' === document.readyState ) {
		schedule();
	} else {
		window.addEventListener( 'load', schedule );
	}
} )();
