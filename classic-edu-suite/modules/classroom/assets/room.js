/**
 * The live room: one poll, everything else follows from it.
 *
 * Each tick asks `/session/<id>/state` for the playback position, any chat
 * since the last message we drew, the hands, the poll and the head-count.
 * Chat messages are handed to `window.CESChat`, which owns the rendering, so
 * the room and the stand-alone chat look and behave identically.
 *
 * ### Keeping thirty players together
 *
 * The tutor's player is the clock. On play, pause or seek it posts
 * `{ position, playing }`. Everyone else compares their own `currentTime`
 * with where the server says the room should be and only corrects when the
 * gap is bigger than the drift tolerance — about a second and a half.
 * Correcting on every tick would make the video stutter for everyone; this
 * way it is imperceptible and a student whose connection drops simply
 * rejoins the room's position.
 *
 * Browsers refuse to start a video without a gesture, so if `play()` is
 * rejected the room shows a single button and the student taps once. After
 * that, every sync is silent.
 *
 * @package ClassicEduSuite\Classroom
 */
( function () {
	'use strict';

	var CFG = window.CESClass || {};
	var I18N = CFG.i18n || {};

	var root = document.querySelector( '[data-ces-room]' );

	if ( ! root || ! window.CESChat ) {
		return;
	}

	var api = window.CESChat.api;
	var esc = window.CESChat.esc;

	var config = {};

	try {
		config = JSON.parse( root.getAttribute( 'data-ces-room' ) || '{}' );
	} catch ( error ) {
		return;
	}

	var ui = {
		state: root.querySelector( '[data-ces-room-state]' ),
		present: root.querySelector( '[data-ces-room-present]' ),
		start: root.querySelector( '[data-ces-room-start]' ),
		end: root.querySelector( '[data-ces-room-end]' ),
		hand: root.querySelector( '[data-ces-room-hand]' ),
		handLabel: root.querySelector( '[data-ces-room-handlabel]' ),
		handList: root.querySelector( '[data-ces-room-handlist]' ),
		handCount: root.querySelector( '[data-ces-room-handcount]' ),
		clearHands: root.querySelector( '[data-ces-room-clearhands]' ),
		poll: root.querySelector( '[data-ces-room-poll]' ),
		pollForm: root.querySelector( '[data-ces-room-pollform]' ),
		pollClose: root.querySelector( '[data-ces-room-pollclose]' )
	};

	var video = null;
	var applying = false;
	var needsGesture = false;
	var lastSent = 0;
	var timer = null;
	var interval = Math.max( 2, parseInt( config.poll, 10 ) || 4 );

	/* -----------------------------------------------------------------
	 * The player.
	 * -------------------------------------------------------------- */

	/**
	 * Find the player's video element once it exists.
	 *
	 * The LMS player builds it, and on an HLS stream that happens after its
	 * own script has initialised, so this retries for a few seconds.
	 *
	 * @param {Function} done Called with the element.
	 * @return {void}
	 */
	function findVideo( done ) {
		var tries = 0;

		var look = function () {
			var found = document.querySelector( '.ces-player__video' );

			if ( found ) {
				done( found );

				return;
			}

			if ( ++tries < 40 ) {
				window.setTimeout( look, 250 );
			}
		};

		look();
	}

	/**
	 * Show a one-tap button when the browser refuses to autoplay.
	 *
	 * @return {void}
	 */
	function askForGesture() {
		if ( needsGesture || ! video ) {
			return;
		}

		needsGesture = true;

		var button = document.createElement( 'button' );

		button.type = 'button';
		button.className = 'ces-btn ces-btn--primary ces-room__gesture';
		button.textContent = 'Tap to join the lesson';

		button.addEventListener( 'click', function () {
			video.play()
				.then( function () {
					needsGesture = false;
					button.remove();
				} )
				.catch( function () {} );
		} );

		if ( video.parentNode ) {
			video.parentNode.appendChild( button );
		}
	}

	/**
	 * Follow the room's playback.
	 *
	 * @param {Object} playback `{ position, playing, drift }`.
	 * @return {void}
	 */
	function follow( playback ) {
		if ( ! video || ! config.sync || config.isHost ) {
			return;
		}

		var drift = parseFloat( playback.drift ) || 1.5;

		applying = true;

		if ( Math.abs( video.currentTime - playback.position ) > drift && isFinite( playback.position ) ) {
			try {
				video.currentTime = playback.position;
			} catch ( error ) {
				// Seeking before metadata is ready throws; the next tick
				// will land it.
			}
		}

		if ( playback.playing && video.paused ) {
			var attempt = video.play();

			if ( attempt && attempt.catch ) {
				attempt.catch( askForGesture );
			}
		}

		if ( ! playback.playing && ! video.paused ) {
			video.pause();
		}

		window.setTimeout( function () {
			applying = false;
		}, 250 );
	}

	/**
	 * Tell the room where the tutor has moved to.
	 *
	 * @return {void}
	 */
	function broadcast() {
		if ( ! config.isHost || ! video || applying ) {
			return;
		}

		// Seeking fires repeatedly while a tutor drags the bar.
		if ( Date.now() - lastSent < 700 ) {
			return;
		}

		lastSent = Date.now();

		api( '/session/' + config.sessionId + '/playback', {
			method: 'POST',
			body: {
				position: video.currentTime,
				playing: ! video.paused
			}
		} ).catch( function () {} );
	}

	/* -----------------------------------------------------------------
	 * Hands and polls.
	 * -------------------------------------------------------------- */

	/**
	 * Draw the hand queue for the tutor.
	 *
	 * @param {Array}  hands The queue.
	 * @param {number} count How many.
	 * @return {void}
	 */
	function paintHands( hands, count ) {
		if ( ui.handCount ) {
			ui.handCount.textContent = String( count || 0 );
		}

		if ( ! ui.handList ) {
			return;
		}

		if ( ! hands || ! hands.length ) {
			ui.handList.innerHTML = '<li class="ces-room__handempty">' + esc( 'No hands up.' ) + '</li>';

			return;
		}

		ui.handList.innerHTML = hands
			.map( function ( hand ) {
				var waited = Math.round( hand.waited / 60 );

				return '<li><strong>' + esc( hand.name ) + '</strong>' +
					( waited > 0 ? ' <span class="ces-muted">' + esc( waited + 'm' ) + '</span>' : '' ) +
					'</li>';
			} )
			.join( '' );
	}

	/**
	 * Draw the poll.
	 *
	 * @param {Object|null} poll The poll, or null.
	 * @return {void}
	 */
	function paintPoll( poll ) {
		if ( ! ui.poll ) {
			return;
		}

		if ( ! poll ) {
			ui.poll.hidden = true;
			ui.poll.innerHTML = '';

			return;
		}

		ui.poll.hidden = false;

		var html = '<h4 class="ces-room__poll-q">' + esc( poll.question ) + '</h4>';

		html += '<ul class="ces-room__poll-list">';

		poll.options.forEach( function ( option ) {
			var share = poll.total > 0 ? Math.round( ( option.votes / poll.total ) * 100 ) : 0;
			var mine = poll.voted === option.index;
			var right = poll.correct === option.index && ! poll.open;

			html += '<li class="' + ( mine ? 'is-mine' : '' ) + ( right ? ' is-right' : '' ) + '">';

			if ( poll.open && ! mine ) {
				html += '<button type="button" class="ces-room__vote" data-ces-room-vote="' + option.index + '">' +
					esc( option.label ) + '</button>';
			} else {
				html += '<span class="ces-room__poll-label">' + esc( option.label ) + '</span>';
			}

			// Counts are shown to the tutor while it is open, and to the room
			// once it closes — so nobody simply follows the crowd.
			if ( config.isHost || ! poll.open ) {
				html += '<span class="ces-room__poll-bar"><span style="width:' + share + '%"></span></span>';
				html += '<span class="ces-room__poll-count">' + esc( option.votes ) + '</span>';
			}

			html += '</li>';
		} );

		html += '</ul>';

		if ( ! poll.open ) {
			html += '<p class="ces-room__poll-note">' + esc( 'Poll closed.' ) + '</p>';
		} else if ( poll.voted > -1 ) {
			html += '<p class="ces-room__poll-note">' + esc( 'Your answer is in.' ) + '</p>';
		}

		ui.poll.innerHTML = html;
	}

	/* -----------------------------------------------------------------
	 * Polling.
	 * -------------------------------------------------------------- */

	/**
	 * Apply a state payload.
	 *
	 * @param {Object} data The room state.
	 * @return {void}
	 */
	function apply( data ) {
		if ( ! data ) {
			return;
		}

		if ( data.messages && data.messages.length ) {
			window.CESChat.feed( config.room, data.messages );
		}

		follow( data.playback || {} );
		paintHands( data.hands, data.handCount );
		paintPoll( data.poll );

		if ( ui.present ) {
			var count = parseInt( data.present, 10 ) || 0;

			ui.present.textContent = 0 === count
				? ( I18N.nobodyHere || '' )
				: ( 1 === count ? ( I18N.onePerson || '' ) : ( I18N.manyPeople || '%d people here' ).replace( '%d', count ) );
		}

		if ( ui.state && data.state ) {
			if ( 'live' === data.state ) {
				ui.state.textContent = I18N.live || 'Live now';
			} else if ( 'ended' === data.state ) {
				ui.state.textContent = I18N.ended || 'Finished';
			}
		}

		if ( ui.hand && ui.handLabel ) {
			ui.handLabel.textContent = data.myHand ? ( I18N.handDown || '' ) : ( I18N.handUp || '' );
			ui.hand.className = 'ces-btn ces-btn--block ' + ( data.myHand ? 'ces-btn--primary' : 'ces-btn--ghost' );
		}

		// The state changed under us — redraw the page rather than guess.
		if ( data.state && config.state && data.state !== config.state ) {
			config.state = data.state;

			if ( 'live' === data.state || 'ended' === data.state ) {
				window.setTimeout( function () {
					window.location.reload();
				}, 1200 );
			}
		}
	}

	/**
	 * One poll.
	 *
	 * @param {number} secs Seconds to credit to attendance.
	 * @return {Promise} Resolves when done.
	 */
	function tick( secs ) {
		var after = window.CESChat.cursor( config.room );

		return api( '/session/' + config.sessionId + '/state?after=' + after + '&secs=' + ( secs || 0 ) )
			.then( apply )
			.catch( function () {} );
	}

	/**
	 * Start the loop.
	 *
	 * @return {void}
	 */
	function loop() {
		tick( 0 );

		timer = window.setInterval( function () {
			if ( document.hidden ) {
				return;
			}

			tick( interval );
		}, interval * 1000 );
	}

	/* -----------------------------------------------------------------
	 * Wiring.
	 * -------------------------------------------------------------- */

	findVideo( function ( found ) {
		video = found;

		if ( config.isHost && config.sync ) {
			[ 'play', 'pause', 'seeked', 'ratechange' ].forEach( function ( event ) {
				video.addEventListener( event, broadcast );
			} );
		}
	} );

	if ( ui.start ) {
		ui.start.addEventListener( 'click', function () {
			ui.start.disabled = true;

			api( '/session/' + config.sessionId + '/start', { method: 'POST' } )
				.then( function () {
					window.location.reload();
				} )
				.catch( function () {
					ui.start.disabled = false;
				} );
		} );
	}

	if ( ui.end ) {
		ui.end.addEventListener( 'click', function () {
			if ( ! window.confirm( 'End the session for everyone?' ) ) {
				return;
			}

			api( '/session/' + config.sessionId + '/end', { method: 'POST' } )
				.then( function () {
					window.location.reload();
				} )
				.catch( function () {} );
		} );
	}

	if ( ui.hand ) {
		ui.hand.addEventListener( 'click', function () {
			var up = ui.handLabel && ui.handLabel.textContent === ( I18N.handUp || '' );

			api( '/session/' + config.sessionId + '/hand', {
				method: 'POST',
				body: { up: up }
			} )
				.then( apply )
				.catch( function () {} );
		} );
	}

	if ( ui.clearHands ) {
		ui.clearHands.addEventListener( 'click', function () {
			api( '/session/' + config.sessionId + '/clear-hands', { method: 'POST' } )
				.then( apply )
				.catch( function () {} );
		} );
	}

	if ( ui.pollForm ) {
		ui.pollForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var question = ui.pollForm.querySelector( '[name="question"]' );
			var options = ui.pollForm.querySelector( '[name="options"]' );
			var correct = ui.pollForm.querySelector( '[name="correct"]' );

			var lines = ( options.value || '' )
				.split( /\r\n|\r|\n/ )
				.map( function ( line ) {
					return line.trim();
				} )
				.filter( function ( line ) {
					return line.length > 0;
				} );

			api( '/session/' + config.sessionId + '/poll', {
				method: 'POST',
				body: {
					question: question.value,
					options: lines,
					correct: correct.value ? ( parseInt( correct.value, 10 ) - 1 ) : -1
				}
			} )
				.then( function ( data ) {
					apply( data );
					question.value = '';
					options.value = '';
					correct.value = '';
				} )
				.catch( function ( error ) {
					window.alert( error.message || '' );
				} );
		} );
	}

	if ( ui.pollClose ) {
		ui.pollClose.addEventListener( 'click', function () {
			api( '/session/' + config.sessionId + '/poll', {
				method: 'POST',
				body: { close: true }
			} )
				.then( apply )
				.catch( function () {} );
		} );
	}

	if ( ui.poll ) {
		ui.poll.addEventListener( 'click', function ( event ) {
			var node = event.target;

			if ( node && 3 === node.nodeType ) {
				node = node.parentNode;
			}

			var button = node && node.closest ? node.closest( '[data-ces-room-vote]' ) : null;

			if ( ! button ) {
				return;
			}

			api( '/session/' + config.sessionId + '/vote', {
				method: 'POST',
				body: { index: parseInt( button.getAttribute( 'data-ces-room-vote' ), 10 ) }
			} )
				.then( apply )
				.catch( function () {} );
		} );
	}

	// Let the chat's own mount run first, then take over its feeding.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', loop );
	} else {
		loop();
	}

	window.addEventListener( 'beforeunload', function () {
		if ( timer ) {
			window.clearInterval( timer );
		}
	} );
}() );
