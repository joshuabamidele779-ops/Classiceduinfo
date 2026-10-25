/**
 * Classic EDU Suite - coin wallet front end.
 *
 * Vanilla JS, no jQuery, no build step. Progressive enhancement only: every
 * screen is fully rendered server-side and still works with this file blocked.
 *
 * Other modules can ask the wallet to refresh its displayed balance with:
 *   document.dispatchEvent( new CustomEvent( 'ces:coins-changed' ) );
 *
 * @package ClassicEduSuite
 */
( function () {
	'use strict';

	var config = window.cesWallet || {};
	var strings = config.i18n || {};

	/**
	 * Fetch JSON from a wallet REST route.
	 *
	 * @param {string} route Route relative to the wallet namespace.
	 * @param {Object} query Query parameters.
	 * @return {Promise<Object>} Parsed response body.
	 */
	function api( route, query ) {
		if ( ! config.root ) {
			return Promise.reject( new Error( 'missing-root' ) );
		}

		var url = new URL( config.root + route, window.location.origin );

		Object.keys( query || {} ).forEach( function ( key ) {
			url.searchParams.set( key, query[ key ] );
		} );

		return fetch( url.toString(), {
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce || '',
				Accept: 'application/json'
			}
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'http-' + response.status );
			}
			return response.json();
		} );
	}

	// -----------------------------------------------------------------
	// Copy the referral link.
	// -----------------------------------------------------------------

	/**
	 * Copy text to the clipboard, falling back to selecting the input.
	 *
	 * @param {string}      text   Text to copy.
	 * @param {HTMLElement} button Button that was pressed.
	 * @return {void}
	 */
	function copyText( text, button ) {
		var original = button.getAttribute( 'data-ces-original' ) || button.textContent;

		button.setAttribute( 'data-ces-original', original );

		function done( ok ) {
			button.textContent = ok ? ( strings.copied || 'Copied!' ) : ( strings.copyFailed || 'Copy failed' );
			button.classList.toggle( 'is-copied', ok );

			window.setTimeout( function () {
				button.textContent = original;
				button.classList.remove( 'is-copied' );
			}, 2200 );
		}

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then(
				function () {
					done( true );
				},
				function () {
					selectFallback( button, done );
				}
			);
			return;
		}

		selectFallback( button, done );
	}

	/**
	 * Older-browser fallback: select the sibling input so the student can use
	 * the native copy menu.
	 *
	 * @param {HTMLElement} button Button that was pressed.
	 * @param {Function}    done   Completion callback.
	 * @return {void}
	 */
	function selectFallback( button, done ) {
		var wrapper = button.closest( '.ces-invite__link-row' );
		var input = wrapper ? wrapper.querySelector( '[data-ces-link]' ) : null;

		if ( ! input ) {
			done( false );
			return;
		}

		input.focus();
		input.setSelectionRange( 0, input.value.length );

		var ok = false;

		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}

		done( ok );
	}

	/**
	 * Wire every copy button on the page.
	 *
	 * @return {void}
	 */
	function initCopy() {
		document.addEventListener( 'click', function ( event ) {
			if ( ! event.target || 'function' !== typeof event.target.closest ) {
				return;
			}

			var button = event.target.closest( '[data-ces-copy]' );

			if ( ! button ) {
				return;
			}

			event.preventDefault();
			copyText( button.getAttribute( 'data-ces-copy' ) || '', button );
		} );
	}

	// -----------------------------------------------------------------
	// Paginated transaction history.
	// -----------------------------------------------------------------

	/**
	 * Build one ledger list item. All text goes in as textContent, so nothing
	 * from the server is ever parsed as markup.
	 *
	 * @param {Object} item Ledger item from the REST response.
	 * @return {HTMLElement} List item.
	 */
	function ledgerRow( item ) {
		var li = document.createElement( 'li' );
		li.className = 'ces-ledger__item';

		var meta = document.createElement( 'span' );
		meta.className = 'ces-ledger__meta';

		var label = document.createElement( 'span' );
		label.className = 'ces-ledger__label';
		label.textContent = item.label || '';
		meta.appendChild( label );

		if ( item.note ) {
			var note = document.createElement( 'span' );
			note.className = 'ces-ledger__note';
			note.textContent = item.note;
			meta.appendChild( note );
		}

		var date = document.createElement( 'span' );
		date.className = 'ces-ledger__date';
		date.textContent = item.created_human || '';
		meta.appendChild( date );

		li.appendChild( meta );

		var delta = parseInt( item.delta, 10 ) || 0;
		var amount = document.createElement( 'span' );
		amount.className = 'ces-ledger__amount ' + ( delta >= 0 ? 'ces-ledger__amount--in' : 'ces-ledger__amount--out' );
		amount.textContent = ( delta >= 0 ? '+' : '−' ) + Math.abs( delta );
		li.appendChild( amount );

		return li;
	}

	/**
	 * Wire the "load more" button on the My Coins tab.
	 *
	 * @return {void}
	 */
	function initLoadMore() {
		var button = document.querySelector( '[data-ces-load-more]' );
		var list = document.querySelector( '[data-ces-ledger]' );

		if ( ! button || ! list ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var page = parseInt( button.getAttribute( 'data-ces-load-more' ), 10 ) || 2;
			var perPage = parseInt( button.getAttribute( 'data-ces-per-page' ), 10 ) || 20;

			button.disabled = true;
			button.textContent = strings.loading || 'Loading…';

			api( 'history', { page: page, per_page: perPage } ).then( function ( data ) {
				var items = ( data && data.items ) || [];

				items.forEach( function ( item ) {
					list.appendChild( ledgerRow( item ) );
				} );

				var totalPages = parseInt( data && data.total_pages, 10 ) || 0;

				if ( page >= totalPages || ! items.length ) {
					button.textContent = strings.noMore || 'That is everything.';
					button.classList.add( 'is-done' );
					return;
				}

				button.setAttribute( 'data-ces-load-more', String( page + 1 ) );
				button.textContent = strings.loadMore || 'Load more';
				button.disabled = false;
			} ).catch( function () {
				button.textContent = strings.error || 'Could not load that.';
				button.disabled = false;
			} );
		} );
	}

	// -----------------------------------------------------------------
	// Balance refresh.
	// -----------------------------------------------------------------

	/**
	 * Re-read the balance and update every balance display on the page.
	 *
	 * @return {void}
	 */
	function refreshBalance() {
		var targets = document.querySelectorAll( '[data-ces-balance]' );

		if ( ! targets.length || ! config.loggedIn ) {
			return;
		}

		api( 'balance', {} ).then( function ( data ) {
			if ( ! data || typeof data.formatted === 'undefined' ) {
				return;
			}

			Array.prototype.forEach.call( targets, function ( node ) {
				node.textContent = data.formatted;
			} );
		} ).catch( function () {
			// Silent: the server-rendered value stays on screen.
		} );
	}

	/**
	 * Boot.
	 *
	 * @return {void}
	 */
	function init() {
		initCopy();
		initLoadMore();

		document.addEventListener( 'ces:coins-changed', refreshBalance );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
