/**
 * The Studio's forms.
 *
 * Every panel saves on its own, over REST, without reloading the page — a
 * tutor filling in a lesson plan has typed a lot by the time they press save,
 * and a full page round trip is exactly where that gets lost. The quality
 * review redraws from each response, so the score moves as they work.
 *
 * Shares `window.CESChat`'s fetch wrapper rather than carrying a second copy
 * of the nonce handling.
 *
 * @package ClassicEduSuite\Classroom
 */
( function () {
	'use strict';

	var CFG = window.CESClass || {};
	var I18N = CFG.i18n || {};

	if ( ! window.CESChat ) {
		return;
	}

	var api = window.CESChat.api;
	var esc = window.CESChat.esc;
	var status = window.CESChat.status;

	/**
	 * The status element inside a form or panel.
	 *
	 * @param {HTMLElement} root Form or panel.
	 * @return {HTMLElement|null} The status node.
	 */
	function note( root ) {
		return root ? root.querySelector( '[data-ces-status]' ) : null;
	}

	/**
	 * Read a form into a plain object.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @return {Object} Field name => value.
	 */
	function values( form ) {
		var out = {};

		Array.prototype.forEach.call( form.elements, function ( field ) {
			if ( ! field.name ) {
				return;
			}

			if ( 'checkbox' === field.type ) {
				out[ field.name ] = field.checked;

				return;
			}

			out[ field.name ] = field.value;
		} );

		return out;
	}

	/**
	 * The lesson id the page is editing.
	 *
	 * @return {number} Lesson id, 0 when none.
	 */
	function lessonId() {
		var root = document.querySelector( '[data-ces-lesson]' );

		return root ? parseInt( root.getAttribute( 'data-ces-lesson' ), 10 ) || 0 : 0;
	}

	/**
	 * Redraw the quality review from a response.
	 *
	 * @param {Object} review The review payload.
	 * @return {void}
	 */
	function paintReview( review ) {
		var panel = document.querySelector( '[data-ces-review]' );

		if ( ! panel || ! review || ! review.checks ) {
			return;
		}

		var score = panel.querySelector( '.ces-studio__score' );

		if ( score ) {
			score.className = 'ces-studio__score ces-studio__score--' +
				( review.score >= 90 ? 'ok' : ( review.score >= 60 ? 'wait' : 'bad' ) );
			score.innerHTML = '<strong>' + esc( review.score ) + '</strong><span>' +
				esc( 'out of 100' ) + '</span>';
		}

		var verdict = panel.querySelector( '.ces-studio__verdict' );

		if ( verdict ) {
			verdict.textContent = review.verdict || '';
		}

		var list = panel.querySelector( '.ces-studio__checks' );

		if ( ! list ) {
			return;
		}

		var html = '';

		review.checks.forEach( function ( check ) {
			html += '<li class="' + ( check.pass ? 'is-done' : 'is-todo' ) + '">';
			html += '<span class="ces-studio__check-mark" aria-hidden="true">' + ( check.pass ? '✔' : '○' ) + '</span>';
			html += '<span class="ces-studio__check-label">' + esc( check.label ) + '</span>';

			if ( ! check.pass ) {
				html += '<span class="ces-studio__check-advice">' + esc( check.advice ) + '</span>';
			}

			html += '</li>';
		} );

		list.innerHTML = html;
	}

	/**
	 * Save one lesson panel.
	 *
	 * @param {string}      action Route segment: plan, chapters, captions…
	 * @param {Object}      body   Request body.
	 * @param {HTMLElement} root   Form or panel, for the status message.
	 * @return {Promise} Resolves when saved.
	 */
	function saveLesson( action, body, root ) {
		var id = lessonId();

		if ( ! id ) {
			return Promise.resolve();
		}

		status( note( root ), I18N.sending || '' );

		return api( '/class/lessons/' + id + '/' + action, {
			method: 'POST',
			body: body
		} )
			.then( function ( data ) {
				status( note( root ), I18N.saved || 'Saved' );
				paintReview( data.review );

				return data;
			} )
			.catch( function ( error ) {
				status( note( root ), error.message || I18N.saveFailed, true );
				throw error;
			} );
	}

	/* -----------------------------------------------------------------
	 * Classes.
	 * -------------------------------------------------------------- */

	/**
	 * Create a class.
	 *
	 * @return {void}
	 */
	function bindNewClass() {
		var form = document.querySelector( '[data-ces-new-class]' );

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			status( note( form ), I18N.sending || '' );

			api( '/class/classes', {
				method: 'POST',
				body: values( form )
			} )
				.then( function ( data ) {
					window.location.href = CFG.studioUrl + ( CFG.studioUrl.indexOf( '?' ) > -1 ? '&' : '?' ) + 'class=' + data.id;
				} )
				.catch( function ( error ) {
					status( note( form ), error.message || I18N.saveFailed, true );
				} );
		} );
	}

	/**
	 * Save a class's settings.
	 *
	 * @return {void}
	 */
	function bindClassSettings() {
		var form = document.querySelector( '[data-ces-class-settings]' );

		if ( ! form ) {
			return;
		}

		var id = parseInt( form.getAttribute( 'data-ces-class' ), 10 );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var raw = values( form );

			status( note( form ), I18N.sending || '' );

			api( '/class/classes/' + id, {
				method: 'POST',
				body: {
					approval: raw.approval ? 'approve' : 'open',
					visibility: raw.visibility ? 'public' : 'private',
					chat: !! raw.chat,
					welcome: raw.welcome || '',
					archived: !! raw.archived
				}
			} )
				.then( function () {
					status( note( form ), I18N.saved || 'Saved' );
				} )
				.catch( function ( error ) {
					status( note( form ), error.message || I18N.saveFailed, true );
				} );
		} );

		var recode = form.querySelector( '[data-ces-new-code]' );

		if ( recode ) {
			recode.addEventListener( 'click', function () {
				if ( ! window.confirm( I18N.confirmCode || '' ) ) {
					return;
				}

				api( '/class/classes/' + id, {
					method: 'POST',
					body: { new_code: true }
				} )
					.then( function () {
						window.location.reload();
					} )
					.catch( function ( error ) {
						status( note( form ), error.message || I18N.saveFailed, true );
					} );
			} );
		}
	}

	/**
	 * Approve, refuse or remove a member.
	 *
	 * @return {void}
	 */
	function bindMembers() {
		document.addEventListener( 'click', function ( event ) {
			var node = event.target;

			if ( node && 3 === node.nodeType ) {
				node = node.parentNode;
			}

			var button = node && node.closest ? node.closest( '[data-ces-member-action]' ) : null;

			if ( ! button ) {
				return;
			}

			event.preventDefault();

			var row = button.closest( '[data-ces-member]' );
			var holder = button.closest( '[data-ces-class]' );

			if ( ! row || ! holder ) {
				return;
			}

			var classId = parseInt( holder.getAttribute( 'data-ces-class' ), 10 );
			var userId = parseInt( row.getAttribute( 'data-ces-member' ), 10 );
			var what = button.getAttribute( 'data-ces-member-action' );

			button.disabled = true;

			api( '/class/classes/' + classId + '/members/' + userId, {
				method: 'POST',
				body: { action: what }
			} )
				.then( function () {
					row.parentNode.removeChild( row );
				} )
				.catch( function () {
					button.disabled = false;
				} );
		} );
	}

	/* -----------------------------------------------------------------
	 * Lessons and sessions.
	 * -------------------------------------------------------------- */

	/**
	 * Create a lesson.
	 *
	 * @return {void}
	 */
	function bindNewLesson() {
		var form = document.querySelector( '[data-ces-new-lesson]' );

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			status( note( form ), I18N.sending || '' );

			var payload = values( form );

			payload.class_id = parseInt( form.getAttribute( 'data-ces-class' ), 10 );

			api( '/class/lessons', {
				method: 'POST',
				body: payload
			} )
				.then( function ( data ) {
					window.location.href = data.editUrl;
				} )
				.catch( function ( error ) {
					status( note( form ), error.message || I18N.saveFailed, true );
				} );
		} );
	}

	/**
	 * Schedule a session.
	 *
	 * @return {void}
	 */
	function bindNewSession() {
		var form = document.querySelector( '[data-ces-new-session]' );

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			status( note( form ), I18N.sending || '' );

			var payload = values( form );

			payload.class_id = parseInt( form.getAttribute( 'data-ces-class' ), 10 );
			payload.lesson_id = parseInt( payload.lesson_id, 10 ) || 0;

			api( '/session', {
				method: 'POST',
				body: payload
			} )
				.then( function ( data ) {
					window.location.href = data.url;
				} )
				.catch( function ( error ) {
					status( note( form ), error.message || I18N.saveFailed, true );
				} );
		} );
	}

	/* -----------------------------------------------------------------
	 * The lesson panels.
	 * -------------------------------------------------------------- */

	/**
	 * The plan.
	 *
	 * @return {void}
	 */
	function bindPlan() {
		var form = document.querySelector( '[data-ces-plan]' );

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			saveLesson( 'plan', { plan: values( form ) }, form ).catch( function () {} );
		} );
	}

	/**
	 * Chapters and captions, which are the same shape.
	 *
	 * @return {void}
	 */
	function bindText() {
		[ 'chapters', 'captions' ].forEach( function ( what ) {
			var form = document.querySelector( '[data-ces-' + what + ']' );

			if ( ! form ) {
				return;
			}

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				var field = form.querySelector( 'textarea[name="text"]' );

				saveLesson( what, { text: field ? field.value : '' }, form )
					.then( function ( data ) {
						if ( 'captions' === what && data && data.result && data.result.converted ) {
							status(
								note( form ),
								'Captions timed automatically from your text — check a few and adjust if they drift.'
							);
						}
					} )
					.catch( function () {} );
			} );
		} );
	}

	/**
	 * The checkpoint editor.
	 *
	 * @return {void}
	 */
	function bindCheckpoints() {
		var panel = document.querySelector( '[data-ces-checkpoints]' );

		if ( ! panel ) {
			return;
		}

		var list = panel.querySelector( '[data-ces-checkpoint-list]' );
		var points = [];

		try {
			points = JSON.parse( panel.getAttribute( 'data-ces-points' ) || '[]' );
		} catch ( error ) {
			points = [];
		}

		/**
		 * Draw one checkpoint's fields.
		 *
		 * @param {Object} point The checkpoint.
		 * @param {number} index Its position.
		 * @return {string} Markup.
		 */
		function row( point, index ) {
			var options = ( point.options || [ '', '' ] ).join( '\n' );

			return '<div class="ces-studio__point" data-ces-point>' +
				'<p class="ces-field"><label>' + esc( 'At what time?' ) + '</label>' +
				'<input type="text" data-ces-point-t value="' + esc( point.stamp || '0:00' ) + '" placeholder="4:30" />' +
				'<span class="ces-field__hint">' + esc( 'Minutes and seconds, like 4:30.' ) + '</span></p>' +
				'<p class="ces-field"><label>' + esc( 'Question' ) + '</label>' +
				'<input type="text" data-ces-point-q value="' + esc( point.question || '' ) + '" maxlength="300" /></p>' +
				'<p class="ces-field"><label>' + esc( 'Answers, one per line' ) + '</label>' +
				'<textarea data-ces-point-o rows="4">' + esc( options ) + '</textarea></p>' +
				'<p class="ces-field"><label>' + esc( 'Which line is right?' ) + '</label>' +
				'<input type="number" data-ces-point-c min="1" max="5" value="' + esc( ( point.correct || 0 ) + 1 ) + '" /></p>' +
				'<p class="ces-field"><label>' + esc( 'Why (shown after they answer)' ) + '</label>' +
				'<textarea data-ces-point-e rows="2">' + esc( point.explain || '' ) + '</textarea></p>' +
				'<p><button type="button" class="ces-link-btn" data-ces-point-remove>' + esc( 'Remove this checkpoint' ) + '</button></p>' +
				'</div>';
		}

		/**
		 * Redraw the list.
		 *
		 * @return {void}
		 */
		function paint() {
			if ( ! list ) {
				return;
			}

			list.innerHTML = points.map( row ).join( '' );
		}

		/**
		 * Turn "4:30" into seconds.
		 *
		 * @param {string} text Timestamp.
		 * @return {number} Seconds.
		 */
		function seconds( text ) {
			var parts = String( text || '' ).split( ':' ).reverse();
			var total = 0;

			parts.forEach( function ( part, index ) {
				total += ( parseInt( part, 10 ) || 0 ) * Math.pow( 60, index );
			} );

			return total;
		}

		paint();

		panel.addEventListener( 'click', function ( event ) {
			var node = event.target;

			if ( node && node.hasAttribute && node.hasAttribute( 'data-ces-point-remove' ) ) {
				var holder = node.closest( '[data-ces-point]' );
				var index = Array.prototype.indexOf.call( list.children, holder );

				if ( index > -1 ) {
					points.splice( index, 1 );
					paint();
				}
			}
		} );

		var add = panel.querySelector( '[data-ces-add-checkpoint]' );

		if ( add ) {
			add.addEventListener( 'click', function () {
				points.push( {
					stamp: '0:00',
					question: '',
					options: [ '', '' ],
					correct: 0,
					explain: ''
				} );

				paint();
			} );
		}

		var save = panel.querySelector( '[data-ces-save-checkpoints]' );

		if ( save ) {
			save.addEventListener( 'click', function () {
				var payload = [];

				Array.prototype.forEach.call( list.children, function ( holder ) {
					var options = ( holder.querySelector( '[data-ces-point-o]' ).value || '' )
						.split( /\r\n|\r|\n/ )
						.map( function ( line ) {
							return line.trim();
						} )
						.filter( function ( line ) {
							return line.length > 0;
						} );

					payload.push( {
						t: seconds( holder.querySelector( '[data-ces-point-t]' ).value ),
						question: holder.querySelector( '[data-ces-point-q]' ).value,
						options: options,
						correct: Math.max( 0, ( parseInt( holder.querySelector( '[data-ces-point-c]' ).value, 10 ) || 1 ) - 1 ),
						explain: holder.querySelector( '[data-ces-point-e]' ).value
					} );
				} );

				saveLesson( 'checkpoints', { checkpoints: payload }, panel )
					.then( function ( data ) {
						if ( data && data.result ) {
							points = data.result;
							paint();
						}
					} )
					.catch( function () {} );
			} );
		}
	}

	/**
	 * Publish and unpublish.
	 *
	 * @return {void}
	 */
	function bindPublish() {
		var button = document.querySelector( '[data-ces-publish]' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var wanted = '1' === button.getAttribute( 'data-ces-publish' );
			var panel = button.closest( '.ces-studio__publish' );

			status( note( panel ), I18N.sending || '' );

			saveLesson( 'publish', { publish: wanted }, panel )
				.then( function () {
					window.location.reload();
				} )
				.catch( function () {} );
		} );
	}

	/* -----------------------------------------------------------------
	 * Boot.
	 * -------------------------------------------------------------- */

	/**
	 * Start.
	 *
	 * @return {void}
	 */
	function start() {
		bindNewClass();
		bindClassSettings();
		bindMembers();
		bindNewLesson();
		bindNewSession();
		bindPlan();
		bindText();
		bindCheckpoints();
		bindPublish();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
