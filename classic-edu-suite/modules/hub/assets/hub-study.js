/**
 * Study mode runner.
 *
 * Talks to ces/v1/hub/study/*. The page source never contains a question or
 * an answer: everything arrives after the student asks for it, and the
 * correct answer only after they have committed to one.
 *
 * No framework, no build step. Depends on window.CES from ces-base.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'ces-study' );

	if ( ! root || ! window.CES || ! window.CES_STUDY ) {
		return;
	}

	var cfg = window.CES_STUDY;
	var i18n = cfg.i18n || {};

	var el = {
		setup: root.querySelector( '[data-ces-study-setup]' ),
		start: root.querySelector( '[data-ces-study-start]' ),
		error: root.querySelector( '[data-ces-study-error]' ),
		runner: root.querySelector( '[data-ces-study-runner]' ),
		progress: root.querySelector( '[data-ces-study-progress]' ),
		score: root.querySelector( '[data-ces-study-score]' ),
		question: root.querySelector( '[data-ces-study-question]' ),
		options: root.querySelector( '[data-ces-study-options]' ),
		feedback: root.querySelector( '[data-ces-study-feedback]' ),
		check: root.querySelector( '[data-ces-study-check]' ),
		next: root.querySelector( '[data-ces-study-next]' ),
		end: root.querySelector( '[data-ces-study-end]' ),
		summary: root.querySelector( '[data-ces-study-summary]' )
	};

	var state = {
		session: '',
		index: 0,
		total: 0,
		score: 0,
		current: null,
		chosen: [],
		locked: false,
		busy: false
	};

	/* ------------------------------------------------------------------ */

	function request( path, method, body ) {
		var options = {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.CES.nonce
			}
		};

		if ( body ) {
			options.body = JSON.stringify( body );
		}

		return fetch( window.CES.restUrl + path, options ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					var message = ( data && data.message ) ? data.message : i18n.offline;
					throw new Error( message );
				}

				return data;
			} );
		} );
	}

	function sprintf( template, values ) {
		var out = String( template || '' );

		values.forEach( function ( value, index ) {
			out = out.split( '%' + ( index + 1 ) + '$s' ).join( value );
		} );

		return out;
	}

	function show( node, visible ) {
		if ( ! node ) {
			return;
		}

		if ( visible ) {
			node.removeAttribute( 'hidden' );
		} else {
			node.setAttribute( 'hidden', 'hidden' );
		}
	}

	function fail( message ) {
		if ( ! el.error ) {
			return;
		}

		el.error.textContent = message;
		show( el.error, true );
	}

	function termId( selectId ) {
		var select = document.getElementById( selectId );

		if ( ! select || ! select.value ) {
			return 0;
		}

		var option = select.options[ select.selectedIndex ];
		var raw = option ? option.getAttribute( 'data-term-id' ) : '';

		return parseInt( raw, 10 ) || 0;
	}

	function countValue() {
		var select = document.getElementById( 'study_count' );
		var value = select ? parseInt( select.value, 10 ) : 0;

		return value > 0 ? value : ( cfg.preset.count || 20 );
	}

	/* ------------------------------------------------------------------ */

	function startSession() {
		if ( state.busy ) {
			return;
		}

		state.busy = true;
		show( el.error, false );

		if ( el.start ) {
			el.start.disabled = true;
			el.start.textContent = i18n.loading;
		}

		var payload = {
			subject: termId( 'study_subject' ) || cfg.preset.subject || 0,
			exam_type: termId( 'study_exam_type' ) || cfg.preset.exam_type || 0,
			year: termId( 'study_year' ) || cfg.preset.year || 0,
			count: countValue()
		};

		request( 'hub/study', 'POST', payload ).then( function ( data ) {
			state.session = data.session;
			state.total = data.total;
			state.score = 0;
			state.index = 0;

			show( el.setup, false );
			show( el.runner, true );

			return loadQuestion( 0 );
		} ).catch( function ( error ) {
			fail( error.message );
		} ).then( function () {
			state.busy = false;

			if ( el.start ) {
				el.start.disabled = false;
				el.start.textContent = i18n.again || 'Start';
			}
		} );
	}

	function loadQuestion( index ) {
		state.busy = true;
		state.chosen = [];
		state.locked = false;

		el.question.innerHTML = '<p class="ces-study__loading">' + escapeHtml( i18n.loading ) + '</p>';
		el.options.innerHTML = '';
		show( el.feedback, false );
		show( el.next, false );
		show( el.check, true );

		return request( 'hub/study/' + state.session + '/question?index=' + index, 'GET' ).then( function ( data ) {
			state.current = data;
			state.index = data.index;
			state.total = data.total;

			renderQuestion( data );

			if ( data.answered ) {
				state.chosen = data.given || [];
				lockWith( {
					isCorrect: data.isCorrect,
					correct: data.correct,
					explanation: data.explanation,
					given: data.given
				} );
			}
		} ).catch( function ( error ) {
			el.question.innerHTML = '<p class="ces-study__error-line">' + escapeHtml( error.message ) + '</p>';
		} ).then( function () {
			state.busy = false;
		} );
	}

	function renderQuestion( data ) {
		var meta = [ data.subject, data.examType, data.year, data.topic ].filter( function ( value ) {
			return !! value;
		} ).join( ' · ' );

		var html = '';

		if ( meta ) {
			html += '<p class="ces-study__meta">' + escapeHtml( meta ) + '</p>';
		}

		// The body arrives already sanitised by wp_kses on the server.
		html += '<div class="ces-study__body">' + data.body + '</div>';

		if ( data.image ) {
			html += '<p class="ces-study__image"><img src="' + escapeAttr( data.image ) + '" alt="" loading="lazy" /></p>';
		}

		el.question.innerHTML = html;

		el.progress.textContent = sprintf( i18n.questionOf, [ data.index + 1, data.total ] );
		updateScore();

		el.options.innerHTML = '';

		( data.options || [] ).forEach( function ( option, position ) {
			var button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'ces-opt';
			button.setAttribute( 'data-key', option.key );
			button.setAttribute( 'aria-pressed', 'false' );

			var letter = document.createElement( 'span' );
			letter.className = 'ces-opt__letter';
			letter.textContent = String.fromCharCode( 65 + position );

			var text = document.createElement( 'span' );
			text.className = 'ces-opt__text';
			text.innerHTML = option.text;

			button.appendChild( letter );
			button.appendChild( text );

			if ( option.image ) {
				var image = document.createElement( 'img' );
				image.src = option.image;
				image.alt = '';
				image.className = 'ces-opt__image';
				button.appendChild( image );
			}

			button.addEventListener( 'click', function () {
				choose( option.key, data.type );
			} );

			el.options.appendChild( button );
		} );
	}

	function choose( key, type ) {
		if ( state.locked ) {
			return;
		}

		if ( 'multi' === type ) {
			var at = state.chosen.indexOf( key );

			if ( at > -1 ) {
				state.chosen.splice( at, 1 );
			} else {
				state.chosen.push( key );
			}
		} else {
			state.chosen = [ key ];
		}

		Array.prototype.forEach.call( el.options.querySelectorAll( '.ces-opt' ), function ( button ) {
			var on = state.chosen.indexOf( button.getAttribute( 'data-key' ) ) > -1;

			button.classList.toggle( 'ces-opt--on', on );
			button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
	}

	function checkAnswer() {
		if ( state.locked || state.busy ) {
			return;
		}

		if ( ! state.chosen.length ) {
			fail( i18n.pickOne );
			el.feedback.textContent = i18n.pickOne;
			el.feedback.className = 'ces-study__feedback ces-study__feedback--hint';
			show( el.feedback, true );

			return;
		}

		state.busy = true;
		el.check.disabled = true;

		request( 'hub/study/' + state.session + '/answer', 'POST', {
			question: state.current.id,
			answer: state.chosen
		} ).then( function ( data ) {
			state.score = data.score;
			lockWith( data );
			updateScore();
		} ).catch( function ( error ) {
			el.feedback.textContent = error.message;
			el.feedback.className = 'ces-study__feedback ces-study__feedback--hint';
			show( el.feedback, true );
		} ).then( function () {
			state.busy = false;
			el.check.disabled = false;
		} );
	}

	function lockWith( data ) {
		state.locked = true;

		var correct = data.correct || [];

		Array.prototype.forEach.call( el.options.querySelectorAll( '.ces-opt' ), function ( button ) {
			var key = button.getAttribute( 'data-key' );

			button.disabled = true;

			if ( correct.indexOf( key ) > -1 ) {
				button.classList.add( 'ces-opt--right' );
			} else if ( state.chosen.indexOf( key ) > -1 ) {
				button.classList.add( 'ces-opt--wrong' );
			}
		} );

		var html = '<p class="ces-study__verdict">' + escapeHtml( data.isCorrect ? i18n.correct : i18n.wrong ) + '</p>';

		if ( ! data.isCorrect && correct.length ) {
			html += '<p class="ces-study__answer">' + escapeHtml( i18n.answerIs ) + ' <strong>' + escapeHtml( correct.join( ', ' ) ) + '</strong></p>';
		}

		if ( data.explanation ) {
			html += '<div class="ces-study__why"><strong>' + escapeHtml( i18n.explanation ) + ':</strong> ' + data.explanation + '</div>';
		}

		el.feedback.innerHTML = html;
		el.feedback.className = 'ces-study__feedback ' + ( data.isCorrect ? 'ces-study__feedback--right' : 'ces-study__feedback--wrong' );
		show( el.feedback, true );

		show( el.check, false );
		show( el.next, true );

		el.next.textContent = ( state.index + 1 >= state.total ) ? i18n.finish : i18n.next;
		el.next.focus();
	}

	function updateScore() {
		if ( el.score ) {
			el.score.textContent = state.score + ' / ' + state.total;
		}
	}

	function nextQuestion() {
		if ( state.index + 1 >= state.total ) {
			finishSession();

			return;
		}

		loadQuestion( state.index + 1 );
	}

	function finishSession() {
		if ( state.busy || ! state.session ) {
			return;
		}

		state.busy = true;

		request( 'hub/study/' + state.session + '/finish', 'POST', {} ).then( function ( data ) {
			show( el.runner, false );

			var percent = data.percent || 0;
			var html = '<h3>' + escapeHtml( i18n.summary ) + '</h3>';

			html += '<p class="ces-study__result">' + escapeHtml( sprintf( i18n.scoreLine, [ data.correct, data.answered, percent ] ) ) + '</p>';
			html += '<p><button type="button" class="ces-btn ces-btn--primary" data-ces-study-restart>' + escapeHtml( i18n.again ) + '</button></p>';

			el.summary.innerHTML = html;
			show( el.summary, true );

			var again = el.summary.querySelector( '[data-ces-study-restart]' );

			if ( again ) {
				again.addEventListener( 'click', function () {
					show( el.summary, false );
					show( el.setup, true );
					state.session = '';
				} );
			}
		} ).catch( function ( error ) {
			fail( error.message );
		} ).then( function () {
			state.busy = false;
		} );
	}

	/* ------------------------------------------------------------------ */

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );

		div.textContent = String( value === undefined || value === null ? '' : value );

		return div.innerHTML;
	}

	function escapeAttr( value ) {
		return escapeHtml( value ).replace( /"/g, '&quot;' );
	}

	if ( el.start ) {
		el.start.addEventListener( 'click', startSession );
	}

	if ( el.check ) {
		el.check.addEventListener( 'click', checkAnswer );
	}

	if ( el.next ) {
		el.next.addEventListener( 'click', nextQuestion );
	}

	if ( el.end ) {
		el.end.addEventListener( 'click', finishSession );
	}

	// Keyboard: 1-9 picks an option, Enter checks or advances.
	document.addEventListener( 'keydown', function ( event ) {
		if ( ! state.session || el.runner.hasAttribute( 'hidden' ) ) {
			return;
		}

		if ( event.target && /^(INPUT|TEXTAREA|SELECT)$/.test( event.target.tagName ) ) {
			return;
		}

		if ( 'Enter' === event.key ) {
			if ( state.locked ) {
				nextQuestion();
			} else {
				checkAnswer();
			}

			return;
		}

		var digit = parseInt( event.key, 10 );

		if ( digit >= 1 && digit <= 9 && ! state.locked ) {
			var buttons = el.options.querySelectorAll( '.ces-opt' );

			if ( buttons[ digit - 1 ] ) {
				buttons[ digit - 1 ].click();
			}
		}
	} );

	if ( cfg.preset && cfg.preset.auto ) {
		startSession();
	}
}() );
