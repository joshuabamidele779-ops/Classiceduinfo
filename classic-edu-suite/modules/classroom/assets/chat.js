/**
 * Chat, plus the small front-end actions every classroom page needs.
 *
 * Polling, not sockets: a WebSocket needs a process that stays alive, and
 * this plugin runs on LiteSpeed shared hosting where it would not. So each
 * open room asks "anything after message N?" every few seconds, which is one
 * indexed row lookup server-side and a few hundred bytes on the wire.
 *
 * Two things keep that cheap. The poll backs off to a slow tick when the tab
 * is hidden or the room has been silent for a while, and speeds up again the
 * moment anyone says something. And the live room does not use this poll at
 * all — it hands its messages to `window.CESChat` from its own single
 * endpoint, so a student in a session makes one request per tick, not two.
 *
 * Every automated message is drawn with a BOT badge beside the name. That is
 * done here, in one place, from the message's own `kind` — so a bot cannot be
 * mistaken for a classmate however the personas are configured.
 *
 * @package ClassicEduSuite\Classroom
 */
( function () {
	'use strict';

	var CFG = window.CESClass || {};
	var I18N = CFG.i18n || {};

	/* -----------------------------------------------------------------
	 * Plumbing.
	 * -------------------------------------------------------------- */

	/**
	 * Call the REST API.
	 *
	 * @param {string} path   Route after the namespace.
	 * @param {Object} [opts] Options: method, body.
	 * @return {Promise<Object>} Parsed JSON.
	 */
	function api( path, opts ) {
		opts = opts || {};

		var init = {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': CFG.nonce || '',
				Accept: 'application/json'
			}
		};

		if ( opts.body ) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify( opts.body );
		}

		return window.fetch( ( CFG.rest || '' ) + path, init ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					var error = new Error( ( data && data.message ) || 'Request failed' );
					error.data = data;
					throw error;
				}

				return data;
			} );
		} );
	}

	/**
	 * The nearest matching ancestor of an event's target.
	 *
	 * `event.target.closest` is not safe to call blind: a click can land on a
	 * text node or an SVG element without the method.
	 *
	 * @param {Event}  event    The event.
	 * @param {string} selector CSS selector.
	 * @return {HTMLElement|null} The match, or null.
	 */
	function hit( event, selector ) {
		var node = event.target;

		if ( node && 3 === node.nodeType ) {
			node = node.parentNode;
		}

		if ( ! node || ! node.closest ) {
			return null;
		}

		return node.closest( selector );
	}

	/**
	 * Escape text for insertion as HTML.
	 *
	 * @param {string} text Raw text.
	 * @return {string} Escaped text.
	 */
	function esc( text ) {
		var node = document.createElement( 'span' );

		node.textContent = String( text == null ? '' : text );

		return node.innerHTML;
	}

	/**
	 * Show a transient status message.
	 *
	 * @param {HTMLElement} node Status element.
	 * @param {string}      text Message.
	 * @param {boolean}     bad  Whether it is an error.
	 * @return {void}
	 */
	function status( node, text, bad ) {
		if ( ! node ) {
			return;
		}

		node.textContent = text || '';
		node.className = node.className.replace( / is-bad| is-good/g, '' ) + ( text ? ( bad ? ' is-bad' : ' is-good' ) : '' );

		if ( text && ! bad ) {
			window.setTimeout( function () {
				if ( node.textContent === text ) {
					node.textContent = '';
				}
			}, 4000 );
		}
	}

	/* -----------------------------------------------------------------
	 * One room.
	 * -------------------------------------------------------------- */

	var rooms = {};

	/**
	 * A chat room bound to its markup.
	 *
	 * @param {HTMLElement} root The `[data-ces-chat]` element.
	 * @constructor
	 */
	function Room( root ) {
		this.root = root;
		this.name = root.getAttribute( 'data-ces-chat' );
		this.embedded = '1' === root.getAttribute( 'data-ces-chat-embedded' );
		this.list = root.querySelector( '[data-ces-chat-list]' );
		this.form = root.querySelector( '[data-ces-chat-form]' );
		this.input = root.querySelector( '[data-ces-chat-input]' );
		this.status = root.querySelector( '[data-ces-chat-status]' );
		this.lastId = 0;
		this.quiet = 0;
		this.timer = null;
		this.sending = false;

		this.bind();
	}

	/**
	 * Wire the form up.
	 *
	 * @return {void}
	 */
	Room.prototype.bind = function () {
		var self = this;

		if ( this.form ) {
			this.form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				self.send();
			} );
		}

		if ( this.list ) {
			this.list.addEventListener( 'click', function ( event ) {
				var button = hit( event, '[data-ces-chat-do]' );

				if ( ! button ) {
					return;
				}

				event.preventDefault();
				self.act( button );
			} );
		}
	};

	/**
	 * Whether the viewer is scrolled to the bottom of the list.
	 *
	 * Stops a new message yanking the view away from somebody reading back.
	 *
	 * @return {boolean} True when pinned to the bottom.
	 */
	Room.prototype.atBottom = function () {
		if ( ! this.list ) {
			return false;
		}

		return ( this.list.scrollHeight - this.list.scrollTop - this.list.clientHeight ) < 60;
	};

	/**
	 * Draw one message.
	 *
	 * @param {Object} message Prepared message from the server.
	 * @return {HTMLElement} The list item.
	 */
	Room.prototype.draw = function ( message ) {
		var item = document.createElement( 'li' );

		item.className = 'ces-chat__msg ces-chat__msg--' + esc( message.kind );
		item.setAttribute( 'data-id', String( message.id ) );

		if ( message.own ) {
			item.className += ' is-own';
		}

		if ( 'pending' === message.status ) {
			item.className += ' is-held';
		}

		if ( 'hidden' === message.status ) {
			item.className += ' is-hidden';
		}

		if ( message.isSystem ) {
			item.innerHTML = '<span class="ces-chat__system">' + esc( message.body ) + '</span>';

			return item;
		}

		var html = '<span class="ces-chat__meta">';

		html += '<span class="ces-chat__name">' + esc( message.name ) + '</span>';

		if ( message.isBot ) {
			html += ' <span class="ces-chat__badge" title="' +
				esc( 'Automated helper, not a student' ) + '">' + esc( I18N.botLabel || 'BOT' ) + '</span>';
		} else if ( 'consultant' === message.kind ) {
			html += ' <span class="ces-chat__badge ces-chat__badge--tutor">' + esc( 'TUTOR' ) + '</span>';
		} else if ( 'staff' === message.kind ) {
			html += ' <span class="ces-chat__badge ces-chat__badge--staff">' + esc( 'STAFF' ) + '</span>';
		}

		html += ' <span class="ces-chat__time">' + esc( message.time ) + '</span>';
		html += '</span>';

		html += '<span class="ces-chat__body">' + esc( message.body ) + '</span>';

		if ( 'pending' === message.status ) {
			html += '<span class="ces-chat__note">' + esc( I18N.held || '' ) + '</span>';
		} else if ( 'hidden' === message.status ) {
			html += '<span class="ces-chat__note">' + esc( I18N.hidden || '' ) + '</span>';
		}

		html += '<span class="ces-chat__actions">';

		if ( ! message.own && ! message.isBot ) {
			html += '<button type="button" class="ces-chat__action" data-ces-chat-do="report" title="' +
				esc( I18N.report || '' ) + '" aria-label="' + esc( I18N.report || '' ) + '">⚑</button>';
		}

		if ( CFG.canMod ) {
			if ( 'visible' === message.status ) {
				html += '<button type="button" class="ces-chat__action" data-ces-chat-do="hide">' + esc( I18N.hide || 'Hide' ) + '</button>';
			} else {
				html += '<button type="button" class="ces-chat__action" data-ces-chat-do="show">' + esc( I18N.restore || 'Restore' ) + '</button>';
			}
		}

		html += '</span>';

		item.innerHTML = html;

		return item;
	};

	/**
	 * Add messages to the list.
	 *
	 * @param {Array} messages Prepared messages, oldest first.
	 * @return {void}
	 */
	Room.prototype.append = function ( messages ) {
		if ( ! this.list || ! messages || ! messages.length ) {
			return;
		}

		var pinned = this.atBottom();
		var self = this;
		var added = 0;

		messages.forEach( function ( message ) {
			if ( message.id <= self.lastId ) {
				// Already have it, unless its status changed under us.
				var existing = self.list.querySelector( '[data-id="' + message.id + '"]' );

				if ( existing ) {
					existing.replaceWith( self.draw( message ) );
				}

				return;
			}

			self.list.appendChild( self.draw( message ) );
			self.lastId = message.id;
			added++;
		} );

		var empty = this.list.querySelector( '.ces-chat__empty' );

		if ( empty && this.list.children.length > 1 ) {
			empty.remove();
		}

		// Keep the DOM from growing without limit in a busy room.
		while ( this.list.children.length > 300 ) {
			this.list.removeChild( this.list.firstChild );
		}

		if ( added && pinned ) {
			this.list.scrollTop = this.list.scrollHeight;
		}

		if ( added ) {
			this.quiet = 0;
		}
	};

	/**
	 * Say there is nothing here yet.
	 *
	 * @return {void}
	 */
	Room.prototype.markEmpty = function () {
		if ( ! this.list || this.list.children.length ) {
			return;
		}

		var item = document.createElement( 'li' );

		item.className = 'ces-chat__empty';
		item.textContent = I18N.empty || '';

		this.list.appendChild( item );
	};

	/**
	 * Fetch and draw.
	 *
	 * @return {Promise} Resolves when done.
	 */
	Room.prototype.load = function () {
		var self = this;

		return api( '/chat/messages?room=' + encodeURIComponent( this.name ) + '&after=' + this.lastId )
			.then( function ( data ) {
				self.append( data.messages );
				self.markEmpty();

				return data;
			} )
			.catch( function () {
				// A failed poll is not worth telling a student about; the
				// next one will almost certainly work.
			} );
	};

	/**
	 * Send what is in the box.
	 *
	 * @return {void}
	 */
	Room.prototype.send = function () {
		if ( this.sending || ! this.input ) {
			return;
		}

		var body = String( this.input.value || '' ).trim();

		if ( ! body ) {
			return;
		}

		var self = this;

		this.sending = true;
		status( this.status, I18N.sending || '' );

		api( '/chat/messages', {
			method: 'POST',
			body: {
				room: this.name,
				body: body
			}
		} )
			.then( function ( data ) {
				self.input.value = '';
				status( self.status, '' );
				self.append( [ data.message ] );

				if ( self.list ) {
					self.list.scrollTop = self.list.scrollHeight;
				}
			} )
			.catch( function ( error ) {
				status( self.status, error.message || I18N.saveFailed, true );
			} )
			.then( function () {
				self.sending = false;
			} );
	};

	/**
	 * Report or moderate one message.
	 *
	 * @param {HTMLElement} button The pressed button.
	 * @return {void}
	 */
	Room.prototype.act = function ( button ) {
		var item = button.closest( '[data-id]' );

		if ( ! item ) {
			return;
		}

		var id = parseInt( item.getAttribute( 'data-id' ), 10 );
		var what = button.getAttribute( 'data-ces-chat-do' );
		var self = this;

		if ( 'report' === what ) {
			button.disabled = true;

			api( '/chat/messages/' + id + '/report', { method: 'POST' } )
				.then( function ( data ) {
					status( self.status, I18N.reported || '' );

					if ( data && data.hidden ) {
						item.className += ' is-hidden';
					}
				} )
				.catch( function ( error ) {
					status( self.status, error.message || '', true );
					button.disabled = false;
				} );

			return;
		}

		if ( ! CFG.canMod ) {
			return;
		}

		api( '/chat/messages/' + id + '/moderate', {
			method: 'POST',
			body: { status: 'hide' === what ? 'hidden' : 'visible' }
		} )
			.then( function () {
				// Re-read from this message back, so the row redraws with its
				// new status.
				self.lastId = Math.max( 0, id - 1 );
				self.load();
			} )
			.catch( function ( error ) {
				status( self.status, error.message || '', true );
			} );
	};

	/**
	 * Start polling, backing off when nothing is happening.
	 *
	 * @return {void}
	 */
	Room.prototype.start = function () {
		var self = this;
		var base = Math.max( 3, parseInt( CFG.chatPoll, 10 ) || 6 ) * 1000;

		this.load();

		var tick = function () {
			var wait = base;

			if ( document.hidden ) {
				wait = base * 5;
			} else if ( self.quiet > 10 ) {
				wait = base * 3;
			} else if ( self.quiet > 4 ) {
				wait = base * 2;
			}

			self.timer = window.setTimeout( function () {
				if ( ! document.hidden ) {
					self.quiet++;
					self.load();
				}

				tick();
			}, wait );
		};

		tick();

		// Coming back to the tab should feel instant.
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				self.quiet = 0;
				self.load();
			}
		} );
	};

	/* -----------------------------------------------------------------
	 * The other small actions on these pages.
	 * -------------------------------------------------------------- */

	/**
	 * Copy buttons.
	 *
	 * @return {void}
	 */
	function bindCopy() {
		document.addEventListener( 'click', function ( event ) {
			var button = hit( event, '[data-ces-copy]' );

			if ( ! button ) {
				return;
			}

			event.preventDefault();

			var text = button.getAttribute( 'data-ces-copy' );
			var original = button.textContent;

			var done = function () {
				button.textContent = I18N.copied || 'Copied';

				window.setTimeout( function () {
					button.textContent = original;
				}, 2000 );
			};

			if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
				window.navigator.clipboard.writeText( text ).then( done ).catch( function () {
					button.textContent = I18N.copyFailed || '';
				} );

				return;
			}

			// Older browsers: put it in a field and select it, which at least
			// makes a manual copy one keystroke.
			var field = document.querySelector( '[data-ces-share]' );

			if ( field ) {
				field.focus();
				field.select();
			}

			button.textContent = I18N.copyFailed || '';
		} );
	}

	/**
	 * Join and leave buttons.
	 *
	 * @return {void}
	 */
	function bindJoin() {
		var panel = document.querySelector( '[data-ces-join]' );

		if ( panel ) {
			var button = panel.querySelector( '[data-ces-join-button]' );
			var note = panel.querySelector( '[data-ces-join-status]' );

			if ( button ) {
				button.addEventListener( 'click', function () {
					button.disabled = true;
					status( note, I18N.joining || '' );

					api( '/class/join', {
						method: 'POST',
						body: { code: panel.getAttribute( 'data-ces-join' ) }
					} )
						.then( function ( data ) {
							status( note, 'active' === data.status ? ( I18N.joined || '' ) : ( I18N.requested || '' ) );

							if ( 'active' === data.status ) {
								window.location.reload();
							} else {
								button.remove();
							}
						} )
						.catch( function ( error ) {
							status( note, error.message || '', true );
							button.disabled = false;
						} );
				} );
			}
		}

		var form = document.querySelector( '[data-ces-join-form]' );

		if ( form ) {
			var field = form.querySelector( 'input[name="code"]' );
			var box = form.closest( '[data-ces-joinbox]' ) || form.parentNode;
			var boxNote = box ? box.querySelector( '[data-ces-join-status]' ) : null;

			if ( field ) {
				field.addEventListener( 'input', function () {
					field.value = field.value.toUpperCase().replace( /[^A-Z0-9]/g, '' );
				} );
			}

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				status( boxNote, I18N.joining || '' );

				api( '/class/join', {
					method: 'POST',
					body: { code: field ? field.value : '' }
				} )
					.then( function ( data ) {
						status( boxNote, 'active' === data.status ? ( I18N.joined || '' ) : ( I18N.requested || '' ) );
						window.setTimeout( function () {
							window.location.href = data.url;
						}, 600 );
					} )
					.catch( function ( error ) {
						status( boxNote, error.message || '', true );
					} );
			} );
		}

		document.addEventListener( 'click', function ( event ) {
			var leave = hit( event, '[data-ces-leave]' );

			if ( ! leave ) {
				return;
			}

			event.preventDefault();

			if ( ! window.confirm( I18N.confirmLeave || 'Leave this class?' ) ) {
				return;
			}

			api( '/class/classes/' + parseInt( leave.getAttribute( 'data-ces-leave' ), 10 ) + '/leave', { method: 'POST' } )
				.then( function () {
					window.location.reload();
				} )
				.catch( function () {
					window.location.reload();
				} );
		} );
	}

	/* -----------------------------------------------------------------
	 * Boot.
	 * -------------------------------------------------------------- */

	/**
	 * Mount every room on the page.
	 *
	 * @return {void}
	 */
	function mountAll() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-ces-chat]' ),
			function ( node ) {
				if ( node.getAttribute( 'data-ces-mounted' ) ) {
					return;
				}

				node.setAttribute( 'data-ces-mounted', '1' );

				var room = new Room( node );

				rooms[ room.name ] = room;

				// An embedded room is fed by whatever owns the page — the
				// live room's own poll — so it does not poll for itself.
				if ( ! room.embedded ) {
					room.start();
				} else {
					room.load();
				}
			}
		);
	}

	/**
	 * The public handle other scripts use.
	 */
	window.CESChat = {
		api: api,
		esc: esc,
		status: status,
		rooms: rooms,

		/**
		 * Push messages into an already-mounted room.
		 *
		 * @param {string} name     Room string.
		 * @param {Array}  messages Prepared messages.
		 * @return {void}
		 */
		feed: function ( name, messages ) {
			if ( rooms[ name ] ) {
				rooms[ name ].append( messages );
				rooms[ name ].markEmpty();
			}
		},

		/**
		 * The highest message id a room has drawn.
		 *
		 * @param {string} name Room string.
		 * @return {number} Message id.
		 */
		cursor: function ( name ) {
			return rooms[ name ] ? rooms[ name ].lastId : 0;
		}
	};

	/**
	 * Start.
	 *
	 * @return {void}
	 */
	function start() {
		if ( ! window.fetch || ! window.Promise ) {
			return;
		}

		mountAll();
		bindCopy();
		bindJoin();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
