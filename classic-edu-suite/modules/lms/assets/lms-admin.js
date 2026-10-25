/**
 * Classic EDU Suite - LMS admin: chunked video uploader and course builder.
 *
 * Vanilla JS, no build step, no jQuery.
 *
 * The uploader exists because shared hosting caps a single POST well below the
 * size of a lesson recording and the site owner cannot raise it. The file is
 * sliced in the browser and each slice is appended server-side, so the host's
 * limit stops mattering.
 *
 * Two details carry most of the value:
 *
 * 1. Every chunk is retried with backoff, and the server is the authority on
 *    how many bytes it actually holds. A dropped connection resumes from the
 *    server's offset rather than restarting a 900 MB upload, which is the
 *    difference between this being usable and not on a connection that drops.
 * 2. The byte offset is sent with every chunk and the server refuses anything
 *    that does not line up, so a retry that actually arrived twice is a no-op
 *    instead of a corrupted video.
 */
( function () {
	'use strict';

	var CFG = window.cesLmsAdmin || {};
	var I18N = CFG.i18n || {};

	/**
	 * Attempts per chunk before giving up.
	 *
	 * @type {number}
	 */
	var MAX_TRIES = 5;

	/* -----------------------------------------------------------------
	 * Helpers.
	 * -------------------------------------------------------------- */

	/**
	 * Translated string with a fallback.
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
	 * Wait.
	 *
	 * @param {number} ms Milliseconds.
	 * @return {Promise} Resolves after the delay.
	 */
	function sleep( ms ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, ms );
		} );
	}

	/**
	 * POST a FormData payload to admin-ajax.
	 *
	 * Resolves with the `data` member of a WordPress JSON response. Rejects
	 * with an Error carrying `status` so a caller can tell "retry this" from
	 * "this will never work".
	 *
	 * @param {FormData} body Payload, already carrying `action`.
	 * @return {Promise<Object>} Response data.
	 */
	function post( body ) {
		body.append( 'nonce', CFG.nonce || '' );

		return window.fetch( CFG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return null;
			} ).then( function ( payload ) {
				var data = payload && payload.data ? payload.data : {};

				if ( response.ok && payload && payload.success ) {
					return data;
				}

				var error = new Error( data.message || t( 'failed' ) );

				error.status = response.status;

				throw error;
			} );
		} );
	}

	/**
	 * Build a FormData with an action already set.
	 *
	 * @param {string} action admin-ajax action name.
	 * @return {FormData} The payload.
	 */
	function payload( action ) {
		var body = new FormData();

		body.append( 'action', action );

		return body;
	}

	/* -----------------------------------------------------------------
	 * Uploader.
	 * -------------------------------------------------------------- */

	/**
	 * Wire one upload panel.
	 *
	 * @param {HTMLElement} root   Panel root.
	 * @param {Object}      config Decoded `data-ces-upload` payload.
	 * @return {void}
	 */
	function Uploader( root, config ) {
		var input = role( root, 'input' );
		var drop = role( root, 'drop' );
		var progress = role( root, 'progress' );
		var fill = role( root, 'fill' );
		var pct = role( root, 'pct' );
		var msg = role( root, 'msg' );
		var notice = role( root, 'notice' );
		var cancel = role( root, 'cancel' );
		var lessonSelect = role( root, 'lesson' );
		var titleInput = role( root, 'title' );
		var statusPill = role( root, 'status-pill' );

		var uid = '';
		var aborted = false;
		var busy = false;

		/**
		 * Show a message under the panel.
		 *
		 * @param {string}  text    Message.
		 * @param {boolean} isError Whether to style it as a failure.
		 * @return {void}
		 */
		function say( text, isError ) {
			if ( ! notice ) {
				return;
			}

			notice.textContent = text || '';
			notice.hidden = ! text;
			notice.className = 'ces-up__notice' + ( isError ? ' ces-up__notice--error' : '' );
		}

		/**
		 * Move the progress bar.
		 *
		 * @param {number} sent  Bytes sent.
		 * @param {number} total Bytes in total.
		 * @return {void}
		 */
		function setProgress( sent, total ) {
			var percent = total > 0 ? Math.min( 100, Math.round( ( sent / total ) * 100 ) ) : 0;

			if ( fill ) {
				fill.style.width = percent + '%';
			}

			if ( pct ) {
				pct.textContent = percent + '%';
			}
		}

		/**
		 * Show or hide the progress block.
		 *
		 * @param {boolean} on Whether an upload is running.
		 * @return {void}
		 */
		function setBusy( on ) {
			busy = on;

			if ( progress ) {
				progress.hidden = ! on;
			}

			if ( drop ) {
				drop.hidden = on;
			}
		}

		/**
		 * Which lesson this upload is for, reading the page controls when the
		 * panel is the standalone screen rather than a metabox.
		 *
		 * @return {Object} `lessonId` and `newTitle`.
		 */
		function target() {
			var lessonId = parseInt( config.lessonId, 10 ) || 0;
			var newTitle = '';

			if ( lessonSelect ) {
				lessonId = parseInt( lessonSelect.value, 10 ) || 0;
			}

			if ( ! lessonId && titleInput ) {
				newTitle = titleInput.value || '';
			}

			return { lessonId: lessonId, newTitle: newTitle };
		}

		/**
		 * Validate a chosen file before any request is made.
		 *
		 * @param {File} file The file.
		 * @return {string} An error message, or '' when it is acceptable.
		 */
		function validate( file ) {
			var max = parseInt( CFG.maxBytes, 10 ) || 0;
			var name = ( file.name || '' ).toLowerCase();
			var ok = [ '.mp4', '.m4v', '.mov', '.webm', '.mkv' ];
			var matched = false;
			var i;

			for ( i = 0; i < ok.length; i++ ) {
				if ( name.length > ok[ i ].length && name.indexOf( ok[ i ], name.length - ok[ i ].length ) !== -1 ) {
					matched = true;
					break;
				}
			}

			if ( ! matched ) {
				return t( 'badType' );
			}

			if ( max > 0 && file.size > max ) {
				return t( 'tooBig' );
			}

			if ( ! file.size ) {
				return t( 'failed' );
			}

			return '';
		}

		/**
		 * Run one upload from start to finish.
		 *
		 * @param {File} file The chosen file.
		 * @return {void}
		 */
		function start( file ) {
			if ( busy ) {
				return;
			}

			var problem = validate( file );

			if ( problem ) {
				say( problem, true );

				return;
			}

			var where = target();

			if ( ! where.lessonId && '' === where.newTitle.replace( /\s+/g, '' ) ) {
				say( t( 'needLesson', 'Choose a lesson, or type a title for a new one.' ), true );

				return;
			}

			aborted = false;
			say( '' );
			setBusy( true );
			setProgress( 0, file.size );

			if ( msg ) {
				msg.textContent = t( 'uploading' );
			}

			var begin = payload( 'ces_lms_upload_begin' );

			begin.append( 'lesson_id', String( where.lessonId ) );
			begin.append( 'new_title', where.newTitle );
			begin.append( 'name', file.name );
			begin.append( 'size', String( file.size ) );

			post( begin ).then( function ( data ) {
				uid = data.uid;

				var chunkSize = parseInt( data.chunkSize, 10 ) || parseInt( CFG.chunkSize, 10 ) || 1048576;

				config.lessonId = parseInt( data.lessonId, 10 ) || config.lessonId;

				return sendChunks( file, chunkSize, 0 );
			} ).then( function () {
				if ( aborted ) {
					return null;
				}

				if ( msg ) {
					msg.textContent = t( 'processing' );
				}

				var finish = payload( 'ces_lms_upload_finish' );

				finish.append( 'uid', uid );

				return post( finish );
			} ).then( function ( data ) {
				if ( aborted || ! data ) {
					return;
				}

				setBusy( false );
				setProgress( file.size, file.size );
				say( t( 'done' ) );

				if ( statusPill && data.label ) {
					statusPill.textContent = data.label;
					statusPill.className = 'ces-pill ces-pill--' + ( data.status || 'queued' );
				}

				// The panel is rendered server-side from the lesson's meta, so
				// a reload is the honest way to show the new state. On the
				// standalone screen there may be no lesson open to reload into,
				// so send the admin to the lesson they just created.
				if ( data.editLink && ! statusPill ) {
					window.location.href = data.editLink;

					return;
				}

				window.setTimeout( function () {
					window.location.reload();
				}, 900 );
			} ).catch( function ( error ) {
				setBusy( false );
				say( error.message || t( 'failed' ), true );
				abort();
			} );
		}

		/**
		 * Send every chunk from a byte offset onwards.
		 *
		 * Recursive rather than looped so each step can await its own retries
		 * without a nested promise chain.
		 *
		 * @param {File}   file      The file.
		 * @param {number} chunkSize Bytes per chunk.
		 * @param {number} offset    Byte offset to send from.
		 * @return {Promise} Resolves when the whole file is sent.
		 */
		function sendChunks( file, chunkSize, offset ) {
			if ( aborted || offset >= file.size ) {
				return Promise.resolve();
			}

			var slice = file.slice( offset, Math.min( offset + chunkSize, file.size ) );

			return sendOne( slice, offset, 1 ).then( function ( written ) {
				if ( aborted ) {
					return null;
				}

				setProgress( written, file.size );

				if ( msg ) {
					msg.textContent = t( 'uploading' );
				}

				// Trust the server's byte count, not our own arithmetic.
				return sendChunks( file, chunkSize, written );
			} );
		}

		/**
		 * Send one chunk, retrying with backoff.
		 *
		 * @param {Blob}   slice  The slice.
		 * @param {number} offset Its byte offset.
		 * @param {number} tries  Attempt number, from 1.
		 * @return {Promise<number>} Bytes the server holds afterwards.
		 */
		function sendOne( slice, offset, tries ) {
			var body = payload( 'ces_lms_upload_chunk' );

			body.append( 'uid', uid );
			body.append( 'offset', String( offset ) );
			body.append( 'chunk', slice, 'chunk.bin' );

			return post( body ).then( function ( data ) {
				return parseInt( data.written, 10 ) || 0;
			} ).catch( function ( error ) {
				// 410 means the session is gone and 403 means the nonce or the
				// capability is gone. Neither improves by trying again.
				if ( 410 === error.status || 403 === error.status ) {
					throw error;
				}

				if ( aborted || tries >= MAX_TRIES ) {
					throw error;
				}

				if ( msg ) {
					msg.textContent = t( 'retrying' );
				}

				return sleep( Math.pow( 2, tries - 1 ) * 1000 ).then( function () {
					return sendOne( slice, offset, tries + 1 );
				} );
			} );
		}

		/**
		 * Abandon the upload in progress.
		 *
		 * @return {void}
		 */
		function abort() {
			if ( ! uid ) {
				return;
			}

			var body = payload( 'ces_lms_upload_abort' );

			body.append( 'uid', uid );

			post( body ).catch( function () {
				// The server sweeps stale uploads on its own.
			} );

			uid = '';
		}

		/* ---------------- Events ---------------- */

		if ( input ) {
			input.addEventListener( 'change', function () {
				if ( input.files && input.files.length ) {
					start( input.files[ 0 ] );
				}
			} );
		}

		if ( drop ) {
			[ 'dragenter', 'dragover' ].forEach( function ( name ) {
				drop.addEventListener( name, function ( event ) {
					event.preventDefault();
					drop.classList.add( 'is-over' );
				} );
			} );

			[ 'dragleave', 'drop' ].forEach( function ( name ) {
				drop.addEventListener( name, function ( event ) {
					event.preventDefault();
					drop.classList.remove( 'is-over' );
				} );
			} );

			drop.addEventListener( 'drop', function ( event ) {
				var files = event.dataTransfer && event.dataTransfer.files;

				if ( files && files.length ) {
					start( files[ 0 ] );
				}
			} );
		}

		if ( cancel ) {
			cancel.addEventListener( 'click', function () {
				aborted = true;
				setBusy( false );
				abort();
				say( t( 'cancelled', 'Upload cancelled.' ) );
			} );
		}

		// Leaving mid-upload abandons the session rather than leaving a part
		// file on the server until the sweeper finds it.
		window.addEventListener( 'beforeunload', function ( event ) {
			if ( ! busy ) {
				return;
			}

			abort();
			event.preventDefault();
			event.returnValue = '';
		} );

		wireLessonActions( root, config, say, statusPill );
	}

	/**
	 * Delete and re-package buttons for an attached video.
	 *
	 * @param {HTMLElement} root       Panel root.
	 * @param {Object}      config     Panel config.
	 * @param {Function}    say        Message helper.
	 * @param {HTMLElement} statusPill The status pill, when present.
	 * @return {void}
	 */
	function wireLessonActions( root, config, say, statusPill ) {
		var remove = role( root, 'delete' );
		var requeue = role( root, 'requeue' );

		if ( remove ) {
			remove.addEventListener( 'click', function () {
				if ( ! window.confirm( t( 'confirmDelete', 'Delete this video? The lesson stays, but the video and every packaged quality are removed.' ) ) ) {
					return;
				}

				remove.disabled = true;

				var body = payload( 'ces_lms_video_delete' );

				body.append( 'lesson_id', String( config.lessonId ) );

				post( body ).then( function () {
					window.location.reload();
				} ).catch( function ( error ) {
					remove.disabled = false;
					say( error.message || t( 'failed' ), true );
				} );
			} );
		}

		if ( requeue ) {
			requeue.addEventListener( 'click', function () {
				requeue.disabled = true;

				var body = payload( 'ces_lms_video_requeue' );

				body.append( 'lesson_id', String( config.lessonId ) );

				post( body ).then( function ( data ) {
					requeue.disabled = false;

					if ( statusPill && data.label ) {
						statusPill.textContent = data.label;
						statusPill.className = 'ces-pill ces-pill--' + ( data.status || 'queued' );
					}

					say( t( 'queuedAgain', 'Queued again for packaging.' ) );
				} ).catch( function ( error ) {
					requeue.disabled = false;
					say( error.message || t( 'failed' ), true );
				} );
			} );
		}
	}

	/* -----------------------------------------------------------------
	 * Course builder.
	 * -------------------------------------------------------------- */

	/**
	 * Wire one course builder panel.
	 *
	 * @param {HTMLElement} root   Panel root.
	 * @param {Object}      config Decoded `data-ces-builder` payload.
	 * @return {void}
	 */
	function Builder( root, config ) {
		var rows = role( root, 'rows' );
		var msg = role( root, 'msg' );

		/**
		 * Show a short status line.
		 *
		 * @param {string}  text    Message.
		 * @param {boolean} isError Whether it is a failure.
		 * @return {void}
		 */
		function say( text, isError ) {
			if ( ! msg ) {
				return;
			}

			msg.textContent = text || '';
			msg.className = 'ces-builder__msg' + ( isError ? ' ces-builder__msg--error' : '' );
		}

		/**
		 * The lesson ids currently shown, in display order.
		 *
		 * @return {number[]} Lesson ids.
		 */
		function currentOrder() {
			if ( ! rows ) {
				return [];
			}

			return Array.prototype.map.call(
				rows.querySelectorAll( '[data-lesson]' ),
				function ( row ) {
					return parseInt( row.getAttribute( 'data-lesson' ), 10 ) || 0;
				}
			).filter( function ( id ) {
				return id > 0;
			} );
		}

		/**
		 * Persist the order shown on screen.
		 *
		 * @return {void}
		 */
		function saveOrder() {
			var order = currentOrder();

			if ( ! order.length ) {
				return;
			}

			say( t( 'saveOrder' ) );

			var body = payload( 'ces_lms_save_order' );

			body.append( 'course_id', String( config.courseId ) );

			order.forEach( function ( id ) {
				body.append( 'order[]', String( id ) );
			} );

			post( body ).then( function () {
				say( t( 'orderSaved' ) );
				refreshArrows();
			} ).catch( function ( error ) {
				say( error.message || t( 'failed' ), true );
			} );
		}

		/**
		 * Disable the arrows that cannot move anywhere.
		 *
		 * @return {void}
		 */
		function refreshArrows() {
			if ( ! rows ) {
				return;
			}

			var list = rows.querySelectorAll( '[data-lesson]' );

			Array.prototype.forEach.call( list, function ( row, index ) {
				var up = role( row, 'up' );
				var down = role( row, 'down' );

				if ( up ) {
					up.disabled = 0 === index;
				}

				if ( down ) {
					down.disabled = index === list.length - 1;
				}
			} );
		}

		/**
		 * Replace the rows markup and re-wire it.
		 *
		 * @param {string} html New markup.
		 * @return {void}
		 */
		function replaceRows( html ) {
			if ( ! rows || 'string' !== typeof html ) {
				return;
			}

			rows.innerHTML = html;
			refreshArrows();
		}

		if ( rows ) {
			rows.addEventListener( 'click', function ( event ) {
				var button = event.target.closest ? event.target.closest( 'button' ) : null;

				if ( ! button ) {
					return;
				}

				var row = button.closest( '[data-lesson]' );

				if ( ! row ) {
					return;
				}

				var action = button.getAttribute( 'data-role' );

				if ( 'up' === action && row.previousElementSibling ) {
					row.parentNode.insertBefore( row, row.previousElementSibling );
					saveOrder();

					return;
				}

				if ( 'down' === action && row.nextElementSibling ) {
					row.parentNode.insertBefore( row.nextElementSibling, row );
					saveOrder();

					return;
				}

				if ( 'detach' === action ) {
					if ( ! window.confirm( t( 'confirmDetach', 'Remove this lesson from the course? The lesson itself is kept.' ) ) ) {
						return;
					}

					button.disabled = true;

					var body = payload( 'ces_lms_detach_lesson' );

					body.append( 'course_id', String( config.courseId ) );
					body.append( 'lesson_id', row.getAttribute( 'data-lesson' ) );

					post( body ).then( function ( data ) {
						replaceRows( data.html );
						say( t( 'detached', 'Lesson removed from this course.' ) );
					} ).catch( function ( error ) {
						button.disabled = false;
						say( error.message || t( 'failed' ), true );
					} );
				}
			} );
		}

		var attachBtn = role( root, 'attach' );
		var attachSelect = role( root, 'attach-select' );

		if ( attachBtn && attachSelect ) {
			attachBtn.addEventListener( 'click', function () {
				var lessonId = parseInt( attachSelect.value, 10 ) || 0;

				if ( ! lessonId ) {
					return;
				}

				attachBtn.disabled = true;

				var body = payload( 'ces_lms_attach_lesson' );

				body.append( 'course_id', String( config.courseId ) );
				body.append( 'lesson_id', String( lessonId ) );

				post( body ).then( function ( data ) {
					attachBtn.disabled = false;
					replaceRows( data.html );

					// Drop the option so it cannot be attached twice.
					var option = attachSelect.querySelector( 'option[value="' + lessonId + '"]' );

					if ( option ) {
						option.parentNode.removeChild( option );
					}

					attachSelect.value = '0';
					say( t( 'attached', 'Lesson added to this course.' ) );
				} ).catch( function ( error ) {
					attachBtn.disabled = false;
					say( error.message || t( 'failed' ), true );
				} );
			} );
		}

		refreshArrows();
	}

	/* -----------------------------------------------------------------
	 * Boot.
	 * -------------------------------------------------------------- */

	/**
	 * Find and initialise every panel on the page.
	 *
	 * @return {void}
	 */
	function boot() {
		if ( ! window.fetch || ! window.FormData || ! window.Promise ) {
			return;
		}

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-ces-upload]' ),
			function ( node ) {
				if ( node.getAttribute( 'data-ces-ready' ) ) {
					return;
				}

				var config = parseJson( node.getAttribute( 'data-ces-upload' ) );

				if ( ! config ) {
					return;
				}

				node.setAttribute( 'data-ces-ready', '1' );
				Uploader( node, config );
			}
		);

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-ces-builder]' ),
			function ( node ) {
				if ( node.getAttribute( 'data-ces-ready' ) ) {
					return;
				}

				var config = parseJson( node.getAttribute( 'data-ces-builder' ) );

				if ( ! config || ! config.courseId ) {
					return;
				}

				node.setAttribute( 'data-ces-ready', '1' );
				Builder( node, config );
			}
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
