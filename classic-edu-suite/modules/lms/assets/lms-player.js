/**
 * Classic EDU Suite - protected video player.
 *
 * Vanilla JS plus the bundled hls.js. No build step, no CDN.
 *
 * Four things in here matter more than the rest:
 *
 * 1. No media URL is ever in the served HTML. Every source is fetched from the
 *    REST endpoint at play time, so a "view source" gets nothing useful.
 * 2. The token refresh timer is also the concurrency heartbeat. The server
 *    reissues the playlist token *and* touches the stream slot on the same
 *    call, so a player that stops refreshing quietly loses its slot. That is
 *    why the timer keeps running while paused, and why a 409 is treated as
 *    "someone else is watching" rather than a generic failure.
 * 3. Watch time is reported, never asserted. We send the seconds we actually
 *    saw elapse between heartbeats; the server clamps that against its own
 *    wall clock before a single coin is credited. Dragging the scrubber to the
 *    end earns nothing.
 * 4. Most of these students are on metered Android data. We start low on a
 *    slow connection, never preload more than metadata, and let them pin a
 *    quality by hand.
 */
( function () {
	'use strict';

	var CFG = window.cesLmsConfig || {};
	var I18N = CFG.i18n || {};

	/**
	 * Seconds between progress heartbeats.
	 *
	 * @type {number}
	 */
	var HEARTBEAT = Math.max( 5, parseInt( CFG.heartbeat, 10 ) || 15 );

	/**
	 * Largest watch credit one heartbeat may claim. Mirrors the server's own
	 * cap; sending more is pointless because it gets clamped away.
	 *
	 * @type {number}
	 */
	var MAX_DELTA = 60;

	/* -----------------------------------------------------------------
	 * Tiny helpers.
	 * -------------------------------------------------------------- */

	/**
	 * Look up a translated string with a fallback.
	 *
	 * @param {string} key      Key in the i18n map.
	 * @param {string} fallback Used when the key is missing.
	 * @return {string} The string.
	 */
	function t( key, fallback ) {
		return I18N[ key ] || fallback || '';
	}

	/**
	 * Find one element inside a root by its data-role.
	 *
	 * @param {HTMLElement} root Container.
	 * @param {string}      name Role name.
	 * @return {HTMLElement|null} The element.
	 */
	function role( root, name ) {
		return root.querySelector( '[data-role="' + name + '"]' );
	}

	/**
	 * Parse a JSON attribute without throwing.
	 *
	 * @param {string} raw Raw attribute value.
	 * @return {Object|null} Parsed object.
	 */
	function parseJson( raw ) {
		try {
			return JSON.parse( raw );
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * POST to an LMS REST route.
	 *
	 * Resolves with the parsed body on success. Rejects with an Error carrying
	 * `status` and `code` so callers can tell an expired token (403) from a
	 * concurrency rejection (409) from a dead network.
	 *
	 * @param {string} path Path after `/lms`, e.g. `/lessons/12/token`.
	 * @param {Object} [body] JSON body.
	 * @param {Object} [opts] Extra fetch options (`keepalive`).
	 * @return {Promise<Object>} The response body.
	 */
	function post( path, body, opts ) {
		var options = {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CFG.nonce || ''
			},
			body: JSON.stringify( body || {} )
		};

		if ( opts && opts.keepalive ) {
			options.keepalive = true;
		}

		return window.fetch( ( CFG.rest || '' ) + path, options ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( response.ok ) {
					return data;
				}

				var error = new Error( data && data.message ? data.message : 'request_failed' );

				error.status = response.status;
				error.code = data && data.code ? data.code : '';

				throw error;
			} );
		} );
	}

	/**
	 * Whether the device is on a connection we should go easy on.
	 *
	 * @return {boolean} True when data saving is wanted.
	 */
	function isMeteredConnection() {
		var net = navigator.connection || navigator.mozConnection || navigator.webkitConnection;

		if ( ! net ) {
			return false;
		}

		if ( net.saveData ) {
			return true;
		}

		return 'slow-2g' === net.effectiveType || '2g' === net.effectiveType || '3g' === net.effectiveType;
	}

	/* -----------------------------------------------------------------
	 * One player instance.
	 * -------------------------------------------------------------- */

	/**
	 * Wire up a single player shell.
	 *
	 * @param {HTMLElement} root   The `.ces-player` element.
	 * @param {Object}      config Decoded `data-ces-player` payload.
	 * @return {void}
	 */
	function Player( root, config ) {
		var video = root.querySelector( '.ces-player__video' );

		if ( ! video ) {
			return;
		}

		var overlay = role( root, 'overlay' );
		var overlayText = role( root, 'overlay-text' );
		var statusNode = role( root, 'status' );
		var qualityBtn = role( root, 'quality' );
		var levelList = role( root, 'levels' );

		var hls = null;
		var sid = config.sid || '';
		var duration = parseInt( config.duration, 10 ) || 0;
		var resume = parseInt( config.resume, 10 ) || 0;
		var refreshIn = Math.max( 15, parseInt( config.refreshIn, 10 ) || 60 );

		var refreshTimer = null;
		var beatTimer = null;

		// Watch-time accounting. `credit` is the real seconds seen since the
		// last heartbeat, measured from timeupdate gaps rather than from the
		// playhead, so a seek contributes nothing.
		var credit = 0;
		var lastTime = 0;
		var seeking = false;
		var released = false;
		var fatal = false;

		/**
		 * Show a transient line in the control bar.
		 *
		 * @param {string} message Text.
		 * @return {void}
		 */
		function setStatus( message ) {
			if ( statusNode ) {
				statusNode.textContent = message || '';
			}
		}

		/**
		 * Cover the video with a message. Used for terminal states only.
		 *
		 * @param {string} message Text.
		 * @return {void}
		 */
		function showOverlay( message ) {
			if ( ! overlay ) {
				setStatus( message );

				return;
			}

			if ( overlayText ) {
				overlayText.textContent = message;
			}

			overlay.hidden = false;
		}

		/**
		 * Hide the overlay.
		 *
		 * @return {void}
		 */
		function hideOverlay() {
			if ( overlay ) {
				overlay.hidden = true;
			}
		}

		/**
		 * Stop everything and show a final message.
		 *
		 * @param {string} message Text.
		 * @return {void}
		 */
		function die( message ) {
			fatal = true;

			stopTimers();

			try {
				video.pause();
			} catch ( e ) {
				// Nothing useful to do if the element is already gone.
			}

			showOverlay( message );
		}

		/* ---------------- Source attachment ---------------- */

		/**
		 * Attach the current source to the video element.
		 *
		 * Uses hls.js when the server says the stream is HLS and the browser
		 * cannot play it natively; otherwise hands the URL straight to the
		 * element, which is what Safari and the progressive fallback want.
		 *
		 * @return {void}
		 */
		function attach() {
			if ( ! config.src ) {
				die( t( 'error' ) );

				return;
			}

			var wantsHls = 'hls' === config.kind;
			var canUseHlsJs = wantsHls && config.useHls && window.Hls && window.Hls.isSupported();

			if ( canUseHlsJs ) {
				attachHlsJs();

				return;
			}

			video.src = config.src;
		}

		/**
		 * Attach via hls.js and wire its events.
		 *
		 * @return {void}
		 */
		function attachHlsJs() {
			hls = new window.Hls( {
				// Keep the buffer modest: this is a phone on metered data, not
				// a desktop with bandwidth to burn.
				maxBufferLength: 30,
				maxMaxBufferLength: 60,
				capLevelToPlayerSize: true,
				startLevel: isMeteredConnection() ? 0 : -1,
				xhrSetup: function ( xhr ) {
					xhr.withCredentials = false;
				}
			} );

			hls.on( window.Hls.Events.MANIFEST_PARSED, function () {
				buildQualityMenu();

				if ( isMeteredConnection() ) {
					setStatus( t( 'dataSaver' ) );
				}
			} );

			hls.on( window.Hls.Events.LEVEL_SWITCHED, function () {
				markActiveLevel();
			} );

			hls.on( window.Hls.Events.ERROR, function ( event, data ) {
				if ( ! data || ! data.fatal ) {
					return;
				}

				// A 403 on a segment or playlist means the token aged out.
				// Refreshing it is nearly always the right repair.
				var http = data.response && data.response.code ? data.response.code : 0;

				if ( 403 === http || 401 === http ) {
					refreshToken( true );

					return;
				}

				if ( window.Hls.ErrorTypes.NETWORK_ERROR === data.type ) {
					hls.startLoad();

					return;
				}

				if ( window.Hls.ErrorTypes.MEDIA_ERROR === data.type ) {
					hls.recoverMediaError();

					return;
				}

				die( t( 'error' ) );
			} );

			hls.loadSource( config.src );
			hls.attachMedia( video );
		}

		/* ---------------- Quality menu ---------------- */

		/**
		 * Build the quality list from the parsed manifest.
		 *
		 * @return {void}
		 */
		function buildQualityMenu() {
			if ( ! hls || ! qualityBtn || ! levelList || ! hls.levels || hls.levels.length < 2 ) {
				return;
			}

			while ( levelList.firstChild ) {
				levelList.removeChild( levelList.firstChild );
			}

			var entries = [ { index: -1, label: t( 'auto', 'Auto' ) } ];

			hls.levels.forEach( function ( level, index ) {
				entries.push( {
					index: index,
					label: level.height ? level.height + 'p' : Math.round( ( level.bitrate || 0 ) / 1000 ) + 'k'
				} );
			} );

			entries.forEach( function ( entry ) {
				var item = document.createElement( 'li' );
				var button = document.createElement( 'button' );

				button.type = 'button';
				button.className = 'ces-player__level';
				button.textContent = entry.label;
				button.setAttribute( 'data-level', String( entry.index ) );

				button.addEventListener( 'click', function () {
					hls.currentLevel = entry.index;

					if ( -1 === entry.index ) {
						hls.loadLevel = -1;
					}

					levelList.hidden = true;
					qualityBtn.textContent = entry.label;
					markActiveLevel();
				} );

				item.appendChild( button );
				levelList.appendChild( item );
			} );

			qualityBtn.hidden = false;

			qualityBtn.addEventListener( 'click', function () {
				levelList.hidden = ! levelList.hidden;
				qualityBtn.setAttribute( 'aria-expanded', levelList.hidden ? 'false' : 'true' );
			} );

			markActiveLevel();
		}

		/**
		 * Flag the level currently in use.
		 *
		 * @return {void}
		 */
		function markActiveLevel() {
			if ( ! hls || ! levelList ) {
				return;
			}

			var active = -1 === hls.currentLevel ? -1 : hls.currentLevel;

			Array.prototype.forEach.call(
				levelList.querySelectorAll( '[data-level]' ),
				function ( button ) {
					var mine = parseInt( button.getAttribute( 'data-level' ), 10 );

					if ( mine === active ) {
						button.setAttribute( 'aria-current', 'true' );
					} else {
						button.removeAttribute( 'aria-current' );
					}
				}
			);
		}

		/* ---------------- Token refresh ---------------- */

		/**
		 * Fetch a fresh token, holding the concurrency slot open.
		 *
		 * @param {boolean} [reattach] Re-point the player at the new URL.
		 * @return {void}
		 */
		function refreshToken( reattach ) {
			if ( fatal ) {
				return;
			}

			post( '/lessons/' + config.lessonId + '/token', { sid: sid } ).then( function ( data ) {
				sid = data.sid || sid;
				config.src = data.src || config.src;
				config.kind = data.kind || config.kind;
				config.useHls = !! data.useHls;

				if ( data.refreshIn ) {
					refreshIn = Math.max( 15, parseInt( data.refreshIn, 10 ) || refreshIn );
				}

				if ( ! reattach ) {
					return;
				}

				// Put the playhead back where it was: swapping the source
				// resets it, and losing a student's place mid-lesson on a
				// token hiccup is exactly the kind of thing they notice.
				var at = video.currentTime;
				var playing = ! video.paused;

				if ( hls ) {
					hls.loadSource( config.src );
					hls.startLoad();
				} else {
					video.src = config.src;
					video.load();
				}

				var restore = function () {
					video.removeEventListener( 'loadedmetadata', restore );

					if ( at > 0 ) {
						seekTo( at );
					}

					if ( playing ) {
						var resumed = video.play();

						if ( resumed && resumed.catch ) {
							resumed.catch( function () {
								// Autoplay refused; the student can press play.
							} );
						}
					}
				};

				video.addEventListener( 'loadedmetadata', restore );
				hideOverlay();
			} ).catch( function ( error ) {
				if ( 409 === error.status || 'ces_concurrent_limit' === error.code ) {
					die( t( 'concurrent' ) );

					return;
				}

				if ( 401 === error.status || 403 === error.status ) {
					die( t( 'expired' ) );

					return;
				}

				// A transient network failure: the current token is usually
				// still good, so say nothing and try again on the next tick.
				setStatus( '' );
			} );
		}

		/* ---------------- Progress ---------------- */

		/**
		 * Send one heartbeat, if there is anything worth sending.
		 *
		 * @return {void}
		 */
		function beat() {
			if ( fatal || ! CFG.loggedIn ) {
				return;
			}

			var delta = Math.min( MAX_DELTA, Math.round( credit ) );
			var position = Math.max( 0, Math.round( video.currentTime || 0 ) );

			if ( delta <= 0 ) {
				return;
			}

			credit = 0;

			post( '/lessons/' + config.lessonId + '/progress', {
				position: position,
				delta: delta
			} ).then( function ( data ) {
				if ( data && data.completed ) {
					root.classList.add( 'is-complete' );
				}
			} ).catch( function ( error ) {
				if ( 409 === error.status || 'ces_concurrent_limit' === error.code ) {
					die( t( 'concurrent' ) );

					return;
				}

				if ( 401 === error.status || 403 === error.status ) {
					die( t( 'expired' ) );
				}

				// Anything else: drop this beat. Watch time is not money in
				// the bank until the server says so, and the next beat will
				// carry on from here.
			} );
		}

		/**
		 * Give up the concurrency slot on the way out.
		 *
		 * @return {void}
		 */
		function release() {
			if ( released || ! sid || ! CFG.loggedIn ) {
				return;
			}

			released = true;

			// `keepalive` so the request survives the page going away.
			// sendBeacon cannot carry the REST nonce header, so this is the
			// one that actually arrives.
			post( '/lessons/' + config.lessonId + '/release', { sid: sid }, { keepalive: true } ).catch( function () {
				// Best effort: the server expires idle slots on its own.
			} );
		}

		/* ---------------- Timers ---------------- */

		/**
		 * Start the refresh and heartbeat timers.
		 *
		 * @return {void}
		 */
		function startTimers() {
			if ( ! refreshTimer ) {
				refreshTimer = window.setInterval( function () {
					refreshToken( false );
				}, refreshIn * 1000 );
			}

			if ( ! beatTimer ) {
				beatTimer = window.setInterval( beat, HEARTBEAT * 1000 );
			}
		}

		/**
		 * Stop both timers.
		 *
		 * @return {void}
		 */
		function stopTimers() {
			if ( refreshTimer ) {
				window.clearInterval( refreshTimer );
				refreshTimer = null;
			}

			if ( beatTimer ) {
				window.clearInterval( beatTimer );
				beatTimer = null;
			}
		}

		/* ---------------- Seeking and resume ---------------- */

		/**
		 * Move the playhead, tolerating a not-yet-seekable element.
		 *
		 * @param {number} seconds Target position.
		 * @return {void}
		 */
		function seekTo( seconds ) {
			try {
				video.currentTime = seconds;
				lastTime = seconds;
			} catch ( e ) {
				// Not seekable yet; the loadedmetadata handler retries.
			}
		}

		/* ---------------- Events ---------------- */

		video.addEventListener( 'loadedmetadata', function () {
			hideOverlay();

			if ( ! duration && video.duration && isFinite( video.duration ) ) {
				duration = Math.round( video.duration );
			}

			// Only resume when there is a meaningful way back and the student
			// is not already within a few seconds of where they stopped.
			if ( resume > 5 && ( ! duration || resume < duration - 10 ) ) {
				seekTo( resume );
				setStatus( t( 'resumed' ) );
				resume = 0;
			}

			lastTime = video.currentTime || 0;
		} );

		video.addEventListener( 'waiting', function () {
			setStatus( t( 'loading' ) );
		} );

		video.addEventListener( 'playing', function () {
			setStatus( '' );
			hideOverlay();
			startTimers();
		} );

		video.addEventListener( 'play', function () {
			lastTime = video.currentTime || 0;
			startTimers();
		} );

		video.addEventListener( 'seeking', function () {
			seeking = true;
		} );

		video.addEventListener( 'seeked', function () {
			seeking = false;
			lastTime = video.currentTime || 0;
		} );

		video.addEventListener( 'timeupdate', function () {
			var now = video.currentTime || 0;
			var step = now - lastTime;

			lastTime = now;

			if ( seeking || video.paused ) {
				return;
			}

			/*
			 * Only forward motion at roughly real speed counts. A jump
			 * forwards is a seek, a jump backwards is a rewind, and neither is
			 * watch time. The server clamps this again anyway.
			 */
			if ( step > 0 && step < 2 ) {
				credit += step;
			}
		} );

		video.addEventListener( 'pause', function () {
			// Flush what was watched before the student walked away, but keep
			// the refresh timer alive so the stream slot is not handed to
			// another tab during a bathroom break.
			beat();
		} );

		video.addEventListener( 'ended', function () {
			beat();
		} );

		video.addEventListener( 'error', function () {
			if ( ! hls ) {
				die( t( 'error' ) );
			}
		} );

		// Make casual saving that bit harder. Not a security control; the
		// signed, short-lived URLs are. This only removes the obvious path.
		video.addEventListener( 'contextmenu', function ( event ) {
			event.preventDefault();
		} );

		window.addEventListener( 'pagehide', function () {
			beat();
			release();
		} );

		window.addEventListener( 'beforeunload', function () {
			release();
		} );

		document.addEventListener( 'visibilitychange', function () {
			if ( 'hidden' === document.visibilityState ) {
				beat();
			}
		} );

		attach();
	}

	/* -----------------------------------------------------------------
	 * Coin unlock buttons.
	 * -------------------------------------------------------------- */

	/**
	 * Wire one unlock gate.
	 *
	 * @param {HTMLElement} box The `[data-ces-unlock]` container.
	 * @return {void}
	 */
	function Unlock( box ) {
		var lessonId = parseInt( box.getAttribute( 'data-ces-unlock' ), 10 );
		var button = role( box, 'unlock' );
		var errorNode = role( box, 'error' );

		if ( ! lessonId || ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			if ( button.disabled ) {
				return;
			}

			var original = button.textContent;

			button.disabled = true;
			button.textContent = t( 'unlocking' );

			if ( errorNode ) {
				errorNode.hidden = true;
				errorNode.textContent = '';
			}

			post( '/lessons/' + lessonId + '/unlock', {} ).then( function () {
				// The gate and the player are rendered server-side from the
				// access state, so a reload is both the simplest and the most
				// honest way to show the unlocked lesson.
				window.location.reload();
			} ).catch( function ( error ) {
				button.disabled = false;
				button.textContent = original;

				if ( errorNode ) {
					errorNode.textContent = error.message || t( 'unlockFailed' );
					errorNode.hidden = false;
				}
			} );
		} );
	}

	/* -----------------------------------------------------------------
	 * Boot.
	 * -------------------------------------------------------------- */

	/**
	 * Find and initialise everything on the page.
	 *
	 * @return {void}
	 */
	function boot() {
		if ( ! window.fetch ) {
			return;
		}

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-ces-player]' ),
			function ( node ) {
				if ( node.getAttribute( 'data-ces-ready' ) ) {
					return;
				}

				var config = parseJson( node.getAttribute( 'data-ces-player' ) );

				if ( ! config ) {
					return;
				}

				node.setAttribute( 'data-ces-ready', '1' );

				// The URL has done its job once it is in memory; leaving it in
				// the DOM just invites a copy and paste.
				node.removeAttribute( 'data-ces-player' );

				Player( node, config );
			}
		);

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-ces-unlock]' ),
			function ( node ) {
				if ( node.getAttribute( 'data-ces-ready' ) ) {
					return;
				}

				node.setAttribute( 'data-ces-ready', '1' );

				Unlock( node );
			}
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
