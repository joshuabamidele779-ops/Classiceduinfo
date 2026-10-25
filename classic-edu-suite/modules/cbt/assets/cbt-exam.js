/**
 * Classic EDU Suite - CBT exam runner.
 *
 * Vanilla JS, no dependencies, no build step.
 *
 * Three things in here matter more than the rest:
 *
 * 1. The clock is the server's. We never count down from a local duration; we
 *    take `expiresAt` (a real UTC timestamp) plus `serverTime`, keep the
 *    difference between the two clocks as an offset, and re-sync that offset
 *    from every single response. Changing the device clock does nothing.
 * 2. Answers are queued, persisted to localStorage, and retried with backoff.
 *    Nigerian mobile data drops constantly; an answer must survive that, a
 *    reload, and a dead battery.
 * 3. No correct answer is ever in this file's data until the paper has been
 *    submitted and the server chooses to send one.
 */
( function () {
	'use strict';

	var CFG = window.cesCbt || {};
	var S   = CFG.strings || {};

	/* -----------------------------------------------------------------
	 * Tiny helpers.
	 * -------------------------------------------------------------- */

	/**
	 * Create an element.
	 *
	 * @param {string} tag      Tag name.
	 * @param {Object} [attrs]  Attributes; `class`, `text`, `html` are special.
	 * @param {Array}  [kids]   Child nodes.
	 * @return {HTMLElement} The element.
	 */
	function el( tag, attrs, kids ) {
		var node = document.createElement( tag );
		var key;

		if ( attrs ) {
			for ( key in attrs ) {
				if ( ! Object.prototype.hasOwnProperty.call( attrs, key ) ) {
					continue;
				}

				if ( 'class' === key ) {
					node.className = attrs[ key ];
				} else if ( 'text' === key ) {
					node.textContent = attrs[ key ];
				} else if ( 'html' === key ) {
					node.innerHTML = attrs[ key ];
				} else if ( null !== attrs[ key ] && false !== attrs[ key ] ) {
					node.setAttribute( key, attrs[ key ] );
				}
			}
		}

		( kids || [] ).forEach( function ( kid ) {
			if ( kid ) {
				node.appendChild( kid );
			}
		} );

		return node;
	}

	/**
	 * Replace an element's children.
	 *
	 * @param {HTMLElement} node Target.
	 * @param {Array}       kids New children.
	 * @return {HTMLElement} The target.
	 */
	function fill( node, kids ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}

		( kids || [] ).forEach( function ( kid ) {
			if ( kid ) {
				node.appendChild( kid );
			}
		} );

		return node;
	}

	/**
	 * Interpolate the first %d in a string.
	 *
	 * @param {string} template Template.
	 * @param {number} value    Replacement.
	 * @return {string} Result.
	 */
	function sprintfD( template, value ) {
		return String( template || '' ).replace( '%d', value );
	}

	/**
	 * Format seconds as h:mm:ss or mm:ss.
	 *
	 * @param {number} total Seconds.
	 * @return {string} Formatted.
	 */
	function clock( total ) {
		total = Math.max( 0, Math.floor( total ) );

		var h = Math.floor( total / 3600 );
		var m = Math.floor( ( total % 3600 ) / 60 );
		var s = total % 60;
		var pad = function ( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		};

		return h > 0 ? h + ':' + pad( m ) + ':' + pad( s ) : pad( m ) + ':' + pad( s );
	}

	/**
	 * Typeset maths, if the site has loaded a renderer.
	 *
	 * @param {HTMLElement} scope Element to typeset.
	 * @return {void}
	 */
	function typeset( scope ) {
		try {
			if ( window.MathJax && 'function' === typeof window.MathJax.typesetPromise ) {
				window.MathJax.typesetPromise( [ scope ] );
			} else if ( window.MathJax && window.MathJax.Hub && window.MathJax.Hub.Queue ) {
				window.MathJax.Hub.Queue( [ 'Typeset', window.MathJax.Hub, scope ] );
			} else if ( 'function' === typeof window.renderMathInElement ) {
				window.renderMathInElement( scope, {
					delimiters: [
						{ left: '\\(', right: '\\)', display: false },
						{ left: '$$', right: '$$', display: true }
					]
				} );
			}
		} catch ( e ) {
			// A missing or unhappy maths renderer must never break the exam.
		}
	}

	/**
	 * One REST call.
	 *
	 * XHR rather than fetch: it gives us a real timeout, which is what makes
	 * the retry queue work on a flaky connection.
	 *
	 * @param {string} method  HTTP method.
	 * @param {string} path    Path below the /cbt root.
	 * @param {Object} [body]  JSON body.
	 * @param {number} [wait]  Timeout in ms.
	 * @return {Promise} Resolves with parsed JSON.
	 */
	function request( method, path, body, wait ) {
		return new Promise( function ( resolve, reject ) {
			var xhr = new XMLHttpRequest();

			xhr.open( method, ( CFG.root || '' ) + path, true );
			xhr.setRequestHeader( 'X-WP-Nonce', CFG.nonce || '' );
			xhr.setRequestHeader( 'Accept', 'application/json' );

			if ( body ) {
				xhr.setRequestHeader( 'Content-Type', 'application/json' );
			}

			xhr.timeout = wait || 20000;

			xhr.onload = function () {
				var data = null;

				try {
					data = JSON.parse( xhr.responseText );
				} catch ( e ) {
					data = null;
				}

				if ( xhr.status >= 200 && xhr.status < 300 ) {
					resolve( data || {} );
					return;
				}

				reject( {
					network: false,
					status: xhr.status,
					code: data && data.code ? data.code : '',
					message: data && data.message ? data.message : S.genericError
				} );
			};

			xhr.onerror = function () {
				reject( { network: true, status: 0, code: 'network', message: S.offline } );
			};

			xhr.ontimeout = function () {
				reject( { network: true, status: 0, code: 'timeout', message: S.offline } );
			};

			xhr.send( body ? JSON.stringify( body ) : null );
		} );
	}

	/* -----------------------------------------------------------------
	 * The runner.
	 * -------------------------------------------------------------- */

	/**
	 * Build a runner bound to one shortcode instance.
	 *
	 * @param {HTMLElement} root Mount element.
	 * @return {void}
	 */
	function Runner( root ) {
		var boot = {};

		try {
			boot = JSON.parse( root.getAttribute( 'data-ces-cbt' ) || '{}' );
		} catch ( e ) {
			boot = {};
		}

		var paneIntro  = root.querySelector( '[data-ces-role="intro"]' );
		var paneRunner = root.querySelector( '[data-ces-role="runner"]' );
		var paneResult = root.querySelector( '[data-ces-role="result"]' );
		var introError = root.querySelector( '[data-ces-role="intro-error"]' );

		var st = {
			attemptId: 0,
			questions: [],
			index: 0,
			answers: {},
			flags: {},
			deadline: 0,
			offset: 0,
			lockdown: !! boot.lockdown,
			finished: false,
			starting: false
		};

		// Queue of pending writes. Each item: { kind, qid, value, tries }.
		var queue    = [];
		var flushing = false;
		var retryAt  = 0;
		var timerId  = null;
		var tickId   = null;
		var blurSent = 0;

		var ui = {};

		/* -------------------------------------------------------------
		 * Offline-tolerant queue.
		 * ---------------------------------------------------------- */

		/**
		 * localStorage key for this attempt.
		 *
		 * @return {string} Key.
		 */
		function storeKey() {
			return 'ces_cbt_' + ( CFG.userId || 0 ) + '_' + st.attemptId;
		}

		/**
		 * Persist answers and the pending queue.
		 *
		 * @return {void}
		 */
		function persist() {
			if ( ! st.attemptId ) {
				return;
			}

			try {
				window.localStorage.setItem(
					storeKey(),
					JSON.stringify( { answers: st.answers, flags: st.flags, queue: queue } )
				);
			} catch ( e ) {
				// Private mode, or a full quota. The server copy is what counts.
			}
		}

		/**
		 * Drop the local copy once the paper is in.
		 *
		 * @return {void}
		 */
		function forget() {
			try {
				window.localStorage.removeItem( storeKey() );
			} catch ( e ) {
				// Nothing to do.
			}
		}

		/**
		 * Re-queue anything that never reached the server last session.
		 *
		 * @return {void}
		 */
		function recover() {
			var saved;

			try {
				saved = JSON.parse( window.localStorage.getItem( storeKey() ) || 'null' );
			} catch ( e ) {
				saved = null;
			}

			if ( ! saved || ! saved.queue || ! saved.queue.length ) {
				return;
			}

			saved.queue.forEach( function ( item ) {
				if ( ! item || ! item.qid ) {
					return;
				}

				// Trust the local value: it is newer than the server's.
				if ( 'answer' === item.kind ) {
					st.answers[ item.qid ] = ( item.value || '' ) ? String( item.value ).split( ',' ) : [];
				} else if ( 'flag' === item.kind ) {
					if ( item.value ) {
						st.flags[ item.qid ] = true;
					} else {
						delete st.flags[ item.qid ];
					}
				}

				enqueue( item.kind, item.qid, item.value );
			} );
		}

		/**
		 * Add a write to the queue, replacing any pending write of the same
		 * kind for the same question.
		 *
		 * @param {string} kind  `answer` or `flag`.
		 * @param {number} qid   Question ID.
		 * @param {*}      value Payload.
		 * @return {void}
		 */
		function enqueue( kind, qid, value ) {
			var i;

			for ( i = queue.length - 1; i >= 0; i-- ) {
				// Never touch the item currently in flight at index 0.
				if ( queue[ i ].kind === kind && queue[ i ].qid === qid && ! ( flushing && 0 === i ) ) {
					queue.splice( i, 1 );
				}
			}

			queue.push( { kind: kind, qid: qid, value: value, tries: 0 } );

			persist();
			paintStatus();
			flush();
		}

		/**
		 * Send the head of the queue, then the next, until it is empty.
		 *
		 * @return {void}
		 */
		function flush() {
			if ( flushing || ! queue.length || st.finished || ! st.attemptId ) {
				return;
			}

			if ( retryAt && Date.now() < retryAt ) {
				return;
			}

			var item = queue[ 0 ];

			flushing = true;
			paintStatus();

			var path;
			var body;

			if ( 'flag' === item.kind ) {
				path = '/attempts/' + st.attemptId + '/flag';
				body = { question_id: item.qid, flagged: !! item.value };
			} else {
				path = '/attempts/' + st.attemptId + '/answer';
				body = { question_id: item.qid, answer: String( item.value || '' ) };
			}

			request( 'POST', path, body, 15000 ).then( function ( data ) {
				sync( data );

				queue.shift();
				flushing = false;
				retryAt  = 0;

				persist();
				paintStatus();

				if ( queue.length ) {
					flush();
				}
			} ).catch( function ( err ) {
				flushing = false;

				if ( 'ces_cbt_expired' === err.code || 410 === err.status ) {
					// The clock ran out server-side; the paper is already in.
					queue.length = 0;
					persist();
					finishByTimeout();
					return;
				}

				if ( ! err.network && err.status >= 400 && err.status < 500 && 429 !== err.status ) {
					// A permanent rejection: dropping it stops an endless loop.
					queue.shift();
					persist();
					paintStatus();
					flush();
					return;
				}

				item.tries = ( item.tries || 0 ) + 1;

				var backoff = Math.min( 30000, 1000 * Math.pow( 2, Math.min( 5, item.tries ) ) );

				retryAt = Date.now() + backoff;

				persist();
				paintStatus();

				window.setTimeout( function () {
					retryAt = 0;
					flush();
				}, backoff + 50 );
			} );
		}

		/* -------------------------------------------------------------
		 * Server clock.
		 * ---------------------------------------------------------- */

		/**
		 * Re-sync the clock offset from any response that carries one.
		 *
		 * @param {Object} data Response body.
		 * @return {void}
		 */
		function sync( data ) {
			if ( ! data ) {
				return;
			}

			if ( data.serverTime ) {
				st.offset = ( Number( data.serverTime ) * 1000 ) - Date.now();
			}

			if ( data.expiresAt ) {
				st.deadline = Number( data.expiresAt );
			} else if ( 'undefined' !== typeof data.remaining && ! st.deadline ) {
				st.deadline = Math.floor( ( Date.now() + st.offset ) / 1000 ) + Number( data.remaining );
			}
		}

		/**
		 * Seconds left, from the server's clock.
		 *
		 * @return {number} Seconds.
		 */
		function remaining() {
			if ( ! st.deadline ) {
				return 0;
			}

			return Math.max( 0, st.deadline - Math.floor( ( Date.now() + st.offset ) / 1000 ) );
		}

		/* -------------------------------------------------------------
		 * Answer state.
		 * ---------------------------------------------------------- */

		/**
		 * Keys chosen for a question.
		 *
		 * @param {number} qid Question ID.
		 * @return {Array} Keys.
		 */
		function given( qid ) {
			var value = st.answers[ qid ];

			return value && value.length ? value : [];
		}

		/**
		 * Whether a question has an answer.
		 *
		 * @param {number} qid Question ID.
		 * @return {boolean} True when answered.
		 */
		function answered( qid ) {
			return given( qid ).length > 0;
		}

		/**
		 * How many questions are unanswered.
		 *
		 * @return {number} Count.
		 */
		function unansweredCount() {
			var n = 0;

			st.questions.forEach( function ( q ) {
				if ( ! answered( q.id ) ) {
					n++;
				}
			} );

			return n;
		}

		/**
		 * How many questions are flagged.
		 *
		 * @return {number} Count.
		 */
		function flaggedCount() {
			return Object.keys( st.flags ).length;
		}

		/**
		 * Record an answer and queue the save.
		 *
		 * @param {number} qid  Question ID.
		 * @param {Array}  keys Option keys.
		 * @return {void}
		 */
		function setAnswer( qid, keys ) {
			st.answers[ qid ] = keys.slice();

			paintPalette();
			paintProgress();
			enqueue( 'answer', qid, keys.join( ',' ) );
		}

		/**
		 * Toggle a flag and queue the save.
		 *
		 * @param {number} qid Question ID.
		 * @return {void}
		 */
		function toggleFlag( qid ) {
			var on = ! st.flags[ qid ];

			if ( on ) {
				st.flags[ qid ] = true;
			} else {
				delete st.flags[ qid ];
			}

			paintPalette();
			paintQuestion();
			enqueue( 'flag', qid, on );
		}

		/* -------------------------------------------------------------
		 * Chrome.
		 * ---------------------------------------------------------- */

		/**
		 * Build the runner shell once.
		 *
		 * @return {void}
		 */
		function buildShell() {
			ui.timer = el( 'span', { class: 'ces-cbt__timer-value', text: '--:--' } );

			ui.status = el( 'span', { class: 'ces-cbt__save', 'aria-live': 'polite', text: S.saved || 'Saved' } );

			ui.progress = el( 'span', { class: 'ces-cbt__progress' } );

			ui.paletteGrid = el( 'div', { class: 'ces-cbt__palette-grid' } );

			ui.paletteToggle = el( 'button', {
				type: 'button',
				class: 'ces-cbt__btn ces-cbt__btn--ghost ces-cbt__palette-toggle',
				'aria-expanded': 'false',
				text: S.palette || 'Question palette'
			} );

			ui.palette = el( 'div', { class: 'ces-cbt__palette' }, [
				el( 'div', { class: 'ces-cbt__legend' }, [
					el( 'span', { class: 'ces-cbt__legend-item is-answered', text: S.answeredLabel || 'Answered' } ),
					el( 'span', { class: 'ces-cbt__legend-item is-unanswered', text: S.unansweredLbl || 'Not answered' } ),
					el( 'span', { class: 'ces-cbt__legend-item is-flagged', text: S.flaggedLabel || 'Flagged' } )
				] ),
				ui.paletteGrid
			] );

			ui.question = el( 'div', { class: 'ces-cbt__question' } );

			ui.prev = el( 'button', {
				type: 'button',
				class: 'ces-cbt__btn ces-cbt__btn--ghost',
				text: S.previous || 'Previous'
			} );

			ui.next = el( 'button', {
				type: 'button',
				class: 'ces-cbt__btn ces-cbt__btn--primary',
				text: S.next || 'Next'
			} );

			ui.submit = el( 'button', {
				type: 'button',
				class: 'ces-cbt__btn ces-cbt__btn--submit',
				text: S.submit || 'Submit paper'
			} );

			ui.prev.addEventListener( 'click', function () {
				go( st.index - 1 );
			} );

			ui.next.addEventListener( 'click', function () {
				go( st.index + 1 );
			} );

			ui.submit.addEventListener( 'click', function () {
				confirmSubmit();
			} );

			ui.paletteToggle.addEventListener( 'click', function () {
				var open = ui.palette.classList.toggle( 'is-open' );

				ui.paletteToggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );

			ui.warning = el( 'div', { class: 'ces-cbt__warning', hidden: 'hidden', role: 'alert' } );

			fill( paneRunner, [
				el( 'header', { class: 'ces-cbt__bar' }, [
					el( 'div', { class: 'ces-cbt__bar-left' }, [
						el( 'span', { class: 'ces-cbt__bar-title', text: boot.title || '' } ),
						ui.progress
					] ),
					el( 'div', { class: 'ces-cbt__bar-right' }, [
						el( 'span', { class: 'ces-cbt__timer', 'aria-live': 'off' }, [
							el( 'span', { class: 'ces-cbt__timer-label', text: S.timeLeft || 'Time left' } ),
							ui.timer
						] ),
						ui.status
					] )
				] ),
				ui.warning,
				ui.paletteToggle,
				ui.palette,
				ui.question,
				el( 'nav', { class: 'ces-cbt__nav' }, [ ui.prev, ui.next, ui.submit ] )
			] );
		}

		/**
		 * Paint the autosave indicator.
		 *
		 * @return {void}
		 */
		function paintStatus() {
			if ( ! ui.status ) {
				return;
			}

			var pending = queue.length;

			ui.status.classList.remove( 'is-saved', 'is-saving', 'is-queued' );

			if ( ! pending ) {
				ui.status.classList.add( 'is-saved' );
				ui.status.textContent = S.saved || 'Saved';
				return;
			}

			if ( flushing && 1 === pending ) {
				ui.status.classList.add( 'is-saving' );
				ui.status.textContent = S.saving || 'Saving…';
				return;
			}

			ui.status.classList.add( 'is-queued' );
			ui.status.textContent = pending + ' ' + ( S.queued || 'waiting to save' );
		}

		/**
		 * Paint the "Question n of m" counter.
		 *
		 * @return {void}
		 */
		function paintProgress() {
			if ( ! ui.progress ) {
				return;
			}

			var done = st.questions.length - unansweredCount();

			ui.progress.textContent = ( S.question || 'Question' ) + ' ' + ( st.index + 1 ) + ' ' +
				( S.of || 'of' ) + ' ' + st.questions.length + ' · ' + done + '/' + st.questions.length;
		}

		/**
		 * Paint the palette grid.
		 *
		 * @return {void}
		 */
		function paintPalette() {
			if ( ! ui.paletteGrid ) {
				return;
			}

			var kids = st.questions.map( function ( q, i ) {
				var classes = [ 'ces-cbt__pal' ];

				if ( answered( q.id ) ) {
					classes.push( 'is-answered' );
				} else {
					classes.push( 'is-unanswered' );
				}

				if ( st.flags[ q.id ] ) {
					classes.push( 'is-flagged' );
				}

				if ( i === st.index ) {
					classes.push( 'is-current' );
				}

				var button = el( 'button', {
					type: 'button',
					class: classes.join( ' ' ),
					text: String( i + 1 ),
					'aria-current': i === st.index ? 'true' : null,
					'aria-label': ( S.question || 'Question' ) + ' ' + ( i + 1 )
				} );

				button.addEventListener( 'click', function () {
					go( i );
					ui.palette.classList.remove( 'is-open' );
					ui.paletteToggle.setAttribute( 'aria-expanded', 'false' );
				} );

				return button;
			} );

			fill( ui.paletteGrid, kids );
		}

		/**
		 * Paint the current question.
		 *
		 * @return {void}
		 */
		function paintQuestion() {
			var q = st.questions[ st.index ];

			if ( ! q ) {
				return;
			}

			var multi    = 'multi' === q.type;
			var selected = given( q.id );

			var head = el( 'div', { class: 'ces-cbt__q-head' }, [
				el( 'span', {
					class: 'ces-cbt__q-number',
					text: ( S.question || 'Question' ) + ' ' + ( st.index + 1 ) + ' ' + ( S.of || 'of' ) + ' ' + st.questions.length
				} ),
				q.subject ? el( 'span', { class: 'ces-cbt__q-topic', text: q.topic ? q.subject + ' · ' + q.topic : q.subject } ) : null
			] );

			var body = el( 'div', { class: 'ces-cbt__q-body ces-math', html: q.body || '' } );

			var image = q.image
				? el( 'img', { class: 'ces-cbt__q-image', src: q.image, alt: '', loading: 'lazy' } )
				: null;

			var hint = multi ? el( 'p', { class: 'ces-cbt__q-hint', text: S.multiHint || '' } ) : null;

			var list = el( 'div', { class: 'ces-cbt__options', role: multi ? 'group' : 'radiogroup' } );

			( q.options || [] ).forEach( function ( option ) {
				var on = selected.indexOf( option.key ) !== -1;

				var input = el( 'input', {
					type: multi ? 'checkbox' : 'radio',
					name: 'ces-q-' + q.id,
					value: option.key,
					class: 'ces-cbt__option-input',
					id: 'ces-q-' + q.id + '-' + option.key
				} );

				input.checked = on;

				input.addEventListener( 'change', function () {
					var next;

					if ( multi ) {
						next = given( q.id ).slice();

						var at = next.indexOf( option.key );

						if ( input.checked && -1 === at ) {
							next.push( option.key );
						} else if ( ! input.checked && at !== -1 ) {
							next.splice( at, 1 );
						}

						next.sort();
					} else {
						next = [ option.key ];
					}

					setAnswer( q.id, next );
					paintOptionStates( list, q );
				} );

				var label = el( 'label', {
					class: 'ces-cbt__option' + ( on ? ' is-selected' : '' ),
					for: input.id,
					'data-key': option.key
				}, [
					input,
					el( 'span', { class: 'ces-cbt__option-key', text: option.key } ),
					el( 'span', { class: 'ces-cbt__option-text ces-math', html: option.text || '' } ),
					option.image ? el( 'img', { class: 'ces-cbt__option-image', src: option.image, alt: '', loading: 'lazy' } ) : null
				] );

				list.appendChild( label );
			} );

			var flagBtn = el( 'button', {
				type: 'button',
				class: 'ces-cbt__btn ces-cbt__btn--ghost ces-cbt__flag' + ( st.flags[ q.id ] ? ' is-on' : '' ),
				'aria-pressed': st.flags[ q.id ] ? 'true' : 'false',
				text: st.flags[ q.id ] ? ( S.unflag || 'Remove flag' ) : ( S.flag || 'Flag for review' )
			} );

			flagBtn.addEventListener( 'click', function () {
				toggleFlag( q.id );
			} );

			var clearBtn = el( 'button', {
				type: 'button',
				class: 'ces-cbt__btn ces-cbt__btn--ghost',
				text: S.clear || 'Clear answer'
			} );

			clearBtn.addEventListener( 'click', function () {
				setAnswer( q.id, [] );
				paintQuestion();
			} );

			fill( ui.question, [
				head,
				body,
				image,
				hint,
				list,
				el( 'div', { class: 'ces-cbt__q-tools' }, [ flagBtn, clearBtn ] )
			] );

			ui.prev.disabled = 0 === st.index;
			ui.next.disabled = st.index >= st.questions.length - 1;

			paintProgress();
			typeset( ui.question );
		}

		/**
		 * Keep the option labels' selected class in step with the inputs.
		 *
		 * @param {HTMLElement} list Options container.
		 * @param {Object}      q    Question.
		 * @return {void}
		 */
		function paintOptionStates( list, q ) {
			var selected = given( q.id );
			var labels   = list.querySelectorAll( '.ces-cbt__option' );
			var i;

			for ( i = 0; i < labels.length; i++ ) {
				var key = labels[ i ].getAttribute( 'data-key' );
				var on  = selected.indexOf( key ) !== -1;

				labels[ i ].classList.toggle( 'is-selected', on );

				var input = labels[ i ].querySelector( 'input' );

				if ( input ) {
					input.checked = on;
				}
			}
		}

		/**
		 * Move to a question by index.
		 *
		 * @param {number} index Target index.
		 * @return {void}
		 */
		function go( index ) {
			if ( index < 0 || index >= st.questions.length ) {
				return;
			}

			st.index = index;

			paintPalette();
			paintQuestion();

			if ( ui.question.scrollIntoView ) {
				ui.question.scrollIntoView( { block: 'start', behavior: 'smooth' } );
			}
		}

		/**
		 * Show a transient warning strip.
		 *
		 * @param {string} message Message.
		 * @return {void}
		 */
		function warn( message ) {
			if ( ! ui.warning ) {
				return;
			}

			ui.warning.textContent = message;
			ui.warning.hidden = false;

			window.setTimeout( function () {
				ui.warning.hidden = true;
			}, 6000 );
		}

		/* -------------------------------------------------------------
		 * Timer.
		 * ---------------------------------------------------------- */

		/**
		 * Start the countdown.
		 *
		 * @return {void}
		 */
		function startTimer() {
			stopTimer();

			tickId = window.setInterval( function () {
				var left = remaining();

				if ( ui.timer ) {
					ui.timer.textContent = clock( left );
					ui.timer.parentNode.classList.toggle( 'is-low', left <= 60 );
					ui.timer.parentNode.classList.toggle( 'is-warn', left <= 300 && left > 60 );
				}

				if ( left <= 0 && ! st.finished ) {
					stopTimer();
					warn( S.timeUp || 'Time is up.' );
					doSubmit( true );
				}
			}, 500 );

			// A long background spell on mobile pauses timers; re-sync on return.
			timerId = window.setInterval( function () {
				if ( ! st.finished && st.attemptId ) {
					resync();
				}
			}, 60000 );
		}

		/**
		 * Stop the countdown.
		 *
		 * @return {void}
		 */
		function stopTimer() {
			if ( tickId ) {
				window.clearInterval( tickId );
				tickId = null;
			}

			if ( timerId ) {
				window.clearInterval( timerId );
				timerId = null;
			}
		}

		/**
		 * Pull the authoritative state back from the server.
		 *
		 * @return {void}
		 */
		function resync() {
			request( 'GET', '/attempts/' + st.attemptId, null, 15000 ).then( function ( data ) {
				if ( data && data.finished ) {
					finishByTimeout();
					return;
				}

				sync( data );

				// Server-side answers win for anything we are not still holding.
				if ( data && data.answers ) {
					Object.keys( data.answers ).forEach( function ( qid ) {
						var pending = queue.some( function ( item ) {
							return 'answer' === item.kind && String( item.qid ) === String( qid );
						} );

						if ( ! pending ) {
							st.answers[ qid ] = data.answers[ qid ];
						}
					} );
				}

				paintPalette();
				paintProgress();
			} ).catch( function () {
				// Offline: the local clock offset still holds.
			} );
		}

		/* -------------------------------------------------------------
		 * Submission.
		 * ---------------------------------------------------------- */

		/**
		 * Show the submit confirmation sheet.
		 *
		 * @return {void}
		 */
		function confirmSubmit() {
			var left    = unansweredCount();
			var flagged = flaggedCount();

			var lines = [];

			if ( left > 0 ) {
				lines.push( sprintfD( S.confirmSome || 'You have %d unanswered question(s).', left ) );
			} else {
				lines.push( S.confirmAll || 'All questions answered.' );
			}

			if ( flagged > 0 ) {
				lines.push( sprintfD( S.confirmFlagged || '%d question(s) are still flagged.', flagged ) );
			}

			var cancel = el( 'button', {
				type: 'button',
				class: 'ces-cbt__btn ces-cbt__btn--ghost',
				text: S.cancel || 'Keep working'
			} );

			var confirm = el( 'button', {
				type: 'button',
				class: 'ces-cbt__btn ces-cbt__btn--submit',
				text: S.yesSubmit || 'Yes, submit'
			} );

			var sheet = el( 'div', { class: 'ces-cbt__modal', role: 'dialog', 'aria-modal': 'true' }, [
				el( 'div', { class: 'ces-cbt__modal-box' }, [
					el( 'h3', { class: 'ces-cbt__modal-title', text: S.confirmTitle || 'Submit your paper?' } ),
					el( 'div', { class: 'ces-cbt__modal-body' }, lines.map( function ( line ) {
						return el( 'p', { text: line } );
					} ) ),
					el( 'div', { class: 'ces-cbt__modal-actions' }, [ cancel, confirm ] )
				] )
			] );

			cancel.addEventListener( 'click', function () {
				if ( sheet.parentNode ) {
					sheet.parentNode.removeChild( sheet );
				}
			} );

			confirm.addEventListener( 'click', function () {
				confirm.disabled = true;
				confirm.textContent = S.submitting || 'Submitting…';

				if ( sheet.parentNode ) {
					sheet.parentNode.removeChild( sheet );
				}

				doSubmit( false );
			} );

			root.appendChild( sheet );
			confirm.focus();
		}

		/**
		 * Submit the paper.
		 *
		 * Pending answers are flushed first where possible, but the server
		 * refuses late saves anyway, so a submit is never blocked by the queue.
		 *
		 * @param {boolean} automatic Whether the clock forced this.
		 * @return {void}
		 */
		function doSubmit( automatic ) {
			if ( st.finished ) {
				return;
			}

			st.finished = true;
			stopTimer();

			ui.submit.disabled = true;
			ui.submit.textContent = S.submitting || 'Submitting…';

			var pending = queue.filter( function ( item ) {
				return 'answer' === item.kind;
			} );

			var lastGasp = Promise.all( pending.map( function ( item ) {
				return request(
					'POST',
					'/attempts/' + st.attemptId + '/answer',
					{ question_id: item.qid, answer: String( item.value || '' ) },
					8000
				).catch( function () {
					return null;
				} );
			} ) );

			lastGasp.then( function () {
				return request( 'POST', '/attempts/' + st.attemptId + '/submit', {}, 30000 );
			} ).then( function ( data ) {
				queue.length = 0;
				forget();
				renderResult( data, automatic );
			} ).catch( function ( err ) {
				// Submitting is idempotent server-side, so retrying is safe.
				st.finished = false;
				ui.submit.disabled = false;
				ui.submit.textContent = S.submit || 'Submit paper';
				warn( err && err.message ? err.message : S.genericError );
				startTimer();
			} );
		}

		/**
		 * Close the paper locally when the server says the clock ran out.
		 *
		 * @return {void}
		 */
		function finishByTimeout() {
			if ( st.finished ) {
				return;
			}

			st.finished = true;
			stopTimer();
			forget();

			request( 'GET', '/attempts/' + st.attemptId + '/result', null, 20000 ).then( function ( data ) {
				renderResult( data, true );
			} ).catch( function () {
				fill( paneResult, [
					el( 'p', { class: 'ces-cbt__hint', text: S.timeUp || 'Time is up.' } ),
					el( 'a', {
						class: 'ces-cbt__btn ces-cbt__btn--primary',
						href: boot.resultsUrl || '#',
						text: S.viewResults || 'View all my results'
					} )
				] );

				paneRunner.hidden = true;
				paneResult.hidden = false;
			} );
		}

		/* -------------------------------------------------------------
		 * Result screen.
		 * ---------------------------------------------------------- */

		/**
		 * Render the result screen from a submit or result response.
		 *
		 * @param {Object}  data      Result payload.
		 * @param {boolean} automatic Whether the clock forced the submit.
		 * @return {void}
		 */
		function renderResult( data, automatic ) {
			data = data || {};

			var score  = Number( data.score || 0 );
			var passed = !! data.passed;

			var kids = [];

			kids.push( el( 'div', { class: 'ces-cbt__scorecard ' + ( passed ? 'is-pass' : 'is-fail' ) }, [
				el( 'h2', { class: 'ces-cbt__title', text: data.examTitle || boot.title || '' } ),
				el( 'p', { class: 'ces-cbt__score' }, [
					document.createTextNode( score.toFixed( 1 ) ),
					el( 'span', { text: '%' } )
				] ),
				el( 'p', { class: 'ces-cbt__verdict', text: passed ? ( S.passed || 'Passed' ) : ( S.failed || 'Not passed' ) } ),
				el( 'ul', { class: 'ces-cbt__stats' }, [
					el( 'li', {}, [
						el( 'strong', { text: ( data.correctCount || 0 ) + ' / ' + ( data.totalQuestions || 0 ) } ),
						el( 'span', { text: S.correctAnswers || 'Correct answers' } )
					] ),
					el( 'li', {}, [
						el( 'strong', { text: clock( data.timeSpent || 0 ) } ),
						el( 'span', { text: S.timeTaken || 'Time taken' } )
					] )
				] ),
				automatic ? el( 'p', { class: 'ces-cbt__hint', text: S.timeUp || '' } ) : null
			] ) );

			if ( data.questions && data.questions.length ) {
				kids.push( el( 'h3', { class: 'ces-cbt__section-title', text: S.reviewHeading || 'Question review' } ) );

				if ( ! data.revealAnswers ) {
					kids.push( el( 'p', { class: 'ces-cbt__hint', text: S.answersHidden || '' } ) );
				}

				var list = el( 'ol', { class: 'ces-cbt__review' } );

				data.questions.forEach( function ( q ) {
					var options = el( 'ul', { class: 'ces-cbt__review-options' } );

					( q.options || [] ).forEach( function ( option ) {
						var classes = [ 'ces-cbt__review-option' ];

						if ( ( q.given || [] ).indexOf( option.key ) !== -1 ) {
							classes.push( 'is-given' );
						}

						if ( q.correct && q.correct.indexOf( option.key ) !== -1 ) {
							classes.push( 'is-right' );
						}

						options.appendChild( el( 'li', { class: classes.join( ' ' ) }, [
							el( 'span', { class: 'ces-cbt__option-key', text: option.key } ),
							el( 'span', { class: 'ces-cbt__option-text ces-math', html: option.text || '' } ),
							option.image ? el( 'img', { src: option.image, alt: '', loading: 'lazy' } ) : null
						] ) );
					} );

					var badge;

					if ( ! q.given || ! q.given.length ) {
						badge = el( 'span', { class: 'ces-cbt__badge ces-cbt__badge--muted', text: S.noAnswer || 'Not answered' } );
					} else if ( q.isCorrect ) {
						badge = el( 'span', { class: 'ces-cbt__badge ces-cbt__badge--pass', text: S.passed || 'Correct' } );
					} else {
						badge = el( 'span', { class: 'ces-cbt__badge ces-cbt__badge--fail', text: S.failed || 'Wrong' } );
					}

					list.appendChild( el( 'li', {
						class: 'ces-cbt__review-item ' + ( q.isCorrect ? 'is-correct' : 'is-wrong' )
					}, [
						el( 'div', { class: 'ces-cbt__review-body ces-math', html: q.body || '' } ),
						q.image ? el( 'img', { class: 'ces-cbt__review-image', src: q.image, alt: '', loading: 'lazy' } ) : null,
						options,
						el( 'p', { class: 'ces-cbt__review-meta' }, [ badge ] ),
						q.explanation
							? el( 'div', { class: 'ces-cbt__explanation' }, [
								el( 'h4', { text: S.explanation || 'Explanation' } ),
								el( 'div', { class: 'ces-math', html: q.explanation } )
							] )
							: null
					] ) );
				} );

				kids.push( list );
			}

			kids.push( el( 'p', { class: 'ces-cbt__actions' }, [
				el( 'a', {
					class: 'ces-cbt__btn ces-cbt__btn--primary',
					href: boot.resultsUrl || '#',
					text: S.viewResults || 'View all my results'
				} )
			] ) );

			fill( paneResult, kids );

			paneRunner.hidden = true;
			paneResult.hidden = false;

			typeset( paneResult );

			if ( paneResult.scrollIntoView ) {
				paneResult.scrollIntoView( { block: 'start', behavior: 'smooth' } );
			}
		}

		/* -------------------------------------------------------------
		 * Lockdown deterrent.
		 * ---------------------------------------------------------- */

		/**
		 * Watch for the student leaving the tab.
		 *
		 * A deterrent and an audit trail, not proctoring: it is trivially
		 * defeated by disabling JavaScript.
		 *
		 * @return {void}
		 */
		function watchLockdown() {
			if ( ! st.lockdown ) {
				return;
			}

			var report = function ( type ) {
				if ( st.finished || ! st.attemptId ) {
					return;
				}

				// At most one report every three seconds, and 40 in total.
				var now = Date.now();

				if ( blurSent > 40 || ( report.last && now - report.last < 3000 ) ) {
					return;
				}

				report.last = now;
				blurSent++;

				warn( S.lockdownWarn || '' );

				request( 'POST', '/attempts/' + st.attemptId + '/event', { type: type }, 10000 ).catch( function () {
					// Losing a deterrent log line is not worth a retry.
				} );
			};

			window.addEventListener( 'blur', function () {
				report( 'blur' );
			} );

			document.addEventListener( 'visibilitychange', function () {
				if ( document.hidden ) {
					report( 'visibility' );
				}
			} );
		}

		/* -------------------------------------------------------------
		 * Lifecycle.
		 * ---------------------------------------------------------- */

		/**
		 * Enter the runner with a start or resume payload.
		 *
		 * @param {Object} payload Attempt payload.
		 * @return {void}
		 */
		function enter( payload ) {
			st.attemptId = Number( payload.attemptId || 0 );
			st.questions = payload.questions || [];
			st.answers   = {};
			st.flags     = {};
			st.index     = 0;
			st.finished  = false;
			st.lockdown  = !! payload.lockdown;

			if ( ! st.attemptId || ! st.questions.length ) {
				showIntroError( S.genericError );
				return;
			}

			Object.keys( payload.answers || {} ).forEach( function ( qid ) {
				st.answers[ qid ] = payload.answers[ qid ];
			} );

			( payload.flags || [] ).forEach( function ( qid ) {
				st.flags[ qid ] = true;
			} );

			sync( payload );
			recover();

			buildShell();
			paintPalette();
			paintQuestion();
			paintStatus();
			startTimer();
			watchLockdown();

			paneIntro.hidden  = true;
			paneResult.hidden = true;
			paneRunner.hidden = false;

			// Jump to the first unanswered question on a resume.
			var first = st.questions.findIndex
				? st.questions.findIndex( function ( q ) {
					return ! answered( q.id );
				} )
				: -1;

			if ( first > 0 ) {
				go( first );
			}

			flush();

			window.addEventListener( 'online', function () {
				retryAt = 0;
				flush();
			} );

			window.addEventListener( 'beforeunload', function ( event ) {
				if ( st.finished ) {
					return undefined;
				}

				event.preventDefault();
				event.returnValue = '';

				return '';
			} );
		}

		/**
		 * Show an error on the intro pane.
		 *
		 * @param {string} message Message.
		 * @return {void}
		 */
		function showIntroError( message ) {
			if ( ! introError ) {
				window.alert( message ); // eslint-disable-line no-alert
				return;
			}

			introError.textContent = message;
			introError.hidden = false;
		}

		/**
		 * Start or resume the attempt.
		 *
		 * @return {void}
		 */
		function start() {
			if ( st.starting ) {
				return;
			}

			st.starting = true;

			var button = root.querySelector( '[data-ces-action="start"]' );

			if ( button ) {
				button.disabled = true;
				button.textContent = S.starting || 'Starting…';
			}

			request( 'POST', '/attempts', { exam_id: Number( boot.examId || 0 ) }, 30000 ).then( function ( data ) {
				st.starting = false;
				enter( data );
			} ).catch( function ( err ) {
				st.starting = false;

				if ( button ) {
					button.disabled = false;
					button.textContent = boot.resumeAttemptId ? ( S.resumeExam || 'Resume exam' ) : ( S.startExam || 'Start exam' );
				}

				showIntroError( err && err.message ? err.message : S.genericError );
			} );
		}

		var startButton = root.querySelector( '[data-ces-action="start"]' );

		if ( startButton ) {
			startButton.addEventListener( 'click', start );
		}
	}

	/**
	 * Boot every exam mount on the page.
	 *
	 * @return {void}
	 */
	function boot() {
		var mounts = document.querySelectorAll( '.ces-cbt--exam[data-ces-cbt]' );
		var i;

		for ( i = 0; i < mounts.length; i++ ) {
			Runner( mounts[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
