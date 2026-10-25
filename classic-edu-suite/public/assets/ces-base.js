/**
 * Classic EDU Suite - shared front-end helper (handle: ces-base).
 *
 * window.CES is created by an inline script printed before this file, carrying
 * restUrl, nonce, isLoggedIn, userId, dashboardUrl, loginUrl and version. This
 * file adds the behaviour every module can rely on:
 *
 *   CES.rest( path, options )  Fetch wrapper. Prefixes restUrl, sends the
 *                              X-WP-Nonce header and same-origin cookies,
 *                              JSON-encodes options.data, resolves to the
 *                              parsed body, rejects with { code, message }.
 *   CES.get( path, query )     Shorthand for a GET.
 *   CES.post( path, data )     Shorthand for a POST.
 *   CES.ready( fn )            Run fn once the DOM is parsed.
 *   CES.copy( text )           Copy to clipboard, resolves to a boolean.
 *
 * It also wires one piece of shared UI: any element with a data-ces-copy
 * attribute copies that attribute's value on click, briefly swapping its label
 * for data-ces-copy-done when one is given.
 *
 * No jQuery, no build step, no external requests.
 */
( function () {
	'use strict';

	var CES = window.CES = window.CES || {};

	/**
	 * Run a callback once the DOM is ready.
	 *
	 * @param {Function} fn Callback.
	 */
	CES.ready = function ( fn ) {
		if ( 'function' !== typeof fn ) {
			return;
		}

		if ( 'loading' !== document.readyState ) {
			window.setTimeout( fn, 0 );
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	};

	/**
	 * Call a plugin REST route.
	 *
	 * @param {string} path    Route below the ces/v1 namespace, e.g. 'wallet/balance'.
	 * @param {Object} options method, data, query, headers, keepalive.
	 * @return {Promise} Resolves with the parsed body.
	 */
	CES.rest = function ( path, options ) {
		options = options || {};

		if ( ! CES.restUrl || typeof window.fetch !== 'function' ) {
			return Promise.reject( {
				code: 'ces_unavailable',
				message: 'REST is not available in this browser.'
			} );
		}

		var method = ( options.method || ( options.data ? 'POST' : 'GET' ) ).toUpperCase();
		var url = CES.restUrl + String( path || '' ).replace( /^\/+/, '' );

		if ( options.query ) {
			var pairs = [];

			for ( var key in options.query ) {
				if ( Object.prototype.hasOwnProperty.call( options.query, key ) ) {
					pairs.push( encodeURIComponent( key ) + '=' + encodeURIComponent( options.query[ key ] ) );
				}
			}

			if ( pairs.length ) {
				url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + pairs.join( '&' );
			}
		}

		var headers = {
			'X-WP-Nonce': CES.nonce || ''
		};

		if ( options.headers ) {
			for ( var name in options.headers ) {
				if ( Object.prototype.hasOwnProperty.call( options.headers, name ) ) {
					headers[ name ] = options.headers[ name ];
				}
			}
		}

		var init = {
			method: method,
			credentials: 'same-origin',
			headers: headers
		};

		if ( options.keepalive ) {
			init.keepalive = true;
		}

		if ( options.data && 'GET' !== method && 'HEAD' !== method ) {
			headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( options.data );
		}

		return window.fetch( url, init ).then( function ( response ) {
			return response.text().then( function ( text ) {
				var body = null;

				if ( text ) {
					try {
						body = JSON.parse( text );
					} catch ( e ) {
						body = null;
					}
				}

				if ( ! response.ok ) {
					throw {
						code: ( body && body.code ) || 'ces_http_' + response.status,
						message: ( body && body.message ) || 'Request failed.',
						status: response.status,
						data: body
					};
				}

				return body;
			} );
		} );
	};

	/**
	 * GET shorthand.
	 *
	 * @param {string} path  Route.
	 * @param {Object} query Query parameters.
	 * @return {Promise} Resolves with the parsed body.
	 */
	CES.get = function ( path, query ) {
		return CES.rest( path, { method: 'GET', query: query } );
	};

	/**
	 * POST shorthand.
	 *
	 * @param {string} path Route.
	 * @param {Object} data JSON payload.
	 * @return {Promise} Resolves with the parsed body.
	 */
	CES.post = function ( path, data ) {
		return CES.rest( path, { method: 'POST', data: data || {} } );
	};

	/**
	 * Copy text to the clipboard, with a fallback for older browsers.
	 *
	 * @param {string} text Text to copy.
	 * @return {Promise} Resolves true on success.
	 */
	CES.copy = function ( text ) {
		text = String( text || '' );

		if ( ! text ) {
			return Promise.resolve( false );
		}

		if ( window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			return window.navigator.clipboard.writeText( text ).then(
				function () {
					return true;
				},
				function () {
					return false;
				}
			);
		}

		var ok = false;
		var field = document.createElement( 'textarea' );

		field.value = text;
		field.setAttribute( 'readonly', 'readonly' );
		field.style.position = 'fixed';
		field.style.top = '-1000px';
		field.style.opacity = '0';

		document.body.appendChild( field );
		field.select();

		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}

		document.body.removeChild( field );

		return Promise.resolve( !! ok );
	};

	/**
	 * Delegated handler for every [data-ces-copy] button on the page.
	 *
	 * @param {MouseEvent} event Click event.
	 */
	function onCopyClick( event ) {
		var target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		var button = target.closest( '[data-ces-copy]' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		var value = button.getAttribute( 'data-ces-copy' );
		var done = button.getAttribute( 'data-ces-copy-done' );

		CES.copy( value ).then( function ( ok ) {
			if ( ! ok || ! done || button.hasAttribute( 'data-ces-copy-busy' ) ) {
				return;
			}

			var original = button.textContent;

			button.setAttribute( 'data-ces-copy-busy', '1' );
			button.textContent = done;

			window.setTimeout( function () {
				button.textContent = original;
				button.removeAttribute( 'data-ces-copy-busy' );
			}, 2000 );
		} );
	}

	CES.ready( function () {
		document.addEventListener( 'click', onCopyClick );
	} );
} )();
