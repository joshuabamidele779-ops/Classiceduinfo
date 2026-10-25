/**
 * Classic EDU Suite - CBT question editor.
 *
 * Vanilla JS plus `wp.media` for the image picker. No build step, no jQuery.
 *
 * The one rule that shapes this whole file: option letters are positional.
 * The server reads `ces_q[options][N]` and `ces_q[correct]` as indexes and
 * assigns A, B, C from the top down, so after any add, remove or type change
 * every row has to be renumbered together — the letter shown, the field names
 * and the value of the "correct" input. `reindex()` is the only function that
 * writes those, which is why adding a row cannot silently detach a tick from
 * its answer.
 */
( function () {
	'use strict';

	var CFG = window.cesCbtAdmin || {};
	var S = CFG.strings || {};

	/**
	 * Largest number of options a question may carry.
	 *
	 * @type {number}
	 */
	var MAX = Math.max( 2, parseInt( CFG.maxOptions, 10 ) || 10 );

	/**
	 * Question type slugs, matching the PHP constants.
	 */
	var TYPE_SINGLE = 'single';
	var TYPE_MULTI = 'multi';
	var TYPE_TRUEFALSE = 'truefalse';

	/* -----------------------------------------------------------------
	 * Helpers.
	 * -------------------------------------------------------------- */

	/**
	 * Translated string with a fallback.
	 *
	 * @param {string} key      Key in the strings map.
	 * @param {string} fallback Used when the key is missing.
	 * @return {string} The string.
	 */
	function t( key, fallback ) {
		return S[ key ] || fallback || '';
	}

	/**
	 * The letter shown for a position.
	 *
	 * @param {number} index Zero-based position.
	 * @return {string} A single letter.
	 */
	function letterFor( index ) {
		if ( index < 0 || index > 25 ) {
			return 'Z';
		}

		return String.fromCharCode( 65 + index );
	}

	/**
	 * Show or hide an element using the `hidden` attribute.
	 *
	 * @param {HTMLElement} node Target.
	 * @param {boolean}     on   Whether it should be visible.
	 * @return {void}
	 */
	function toggle( node, on ) {
		if ( ! node ) {
			return;
		}

		if ( on ) {
			node.removeAttribute( 'hidden' );
		} else {
			node.setAttribute( 'hidden', 'hidden' );
		}
	}

	/* -----------------------------------------------------------------
	 * Image fields.
	 * -------------------------------------------------------------- */

	/**
	 * Wire one image field: pick through the media library, or clear.
	 *
	 * @param {HTMLElement} field The `[data-ces-image-field]` element.
	 * @return {void}
	 */
	function imageField( field ) {
		if ( field.getAttribute( 'data-ces-image-ready' ) ) {
			return;
		}

		field.setAttribute( 'data-ces-image-ready', '1' );

		var input = field.querySelector( '[data-ces-image-input]' );
		var preview = field.querySelector( '[data-ces-image-preview]' );
		var pick = field.querySelector( '[data-ces-image-pick]' );
		var clear = field.querySelector( '[data-ces-image-clear]' );
		var frame = null;

		if ( ! input ) {
			return;
		}

		if ( pick ) {
			pick.addEventListener( 'click', function () {
				if ( ! window.wp || ! window.wp.media ) {
					return;
				}

				if ( frame ) {
					frame.open();

					return;
				}

				frame = window.wp.media( {
					title: t( 'chooseImage' ),
					button: { text: t( 'useImage' ) },
					library: { type: 'image' },
					multiple: false
				} );

				frame.on( 'select', function () {
					var selected = frame.state().get( 'selection' ).first();

					if ( ! selected ) {
						return;
					}

					var attachment = selected.toJSON();
					var sizes = attachment.sizes || {};
					var chosen = sizes.thumbnail || sizes.medium || sizes.full || null;

					input.value = attachment.id;

					if ( preview ) {
						while ( preview.firstChild ) {
							preview.removeChild( preview.firstChild );
						}

						var img = document.createElement( 'img' );

						img.src = chosen ? chosen.url : attachment.url;
						img.alt = attachment.alt || '';
						preview.appendChild( img );
					}
				} );

				frame.open();
			} );
		}

		if ( clear ) {
			clear.addEventListener( 'click', function () {
				input.value = '0';

				if ( preview ) {
					while ( preview.firstChild ) {
						preview.removeChild( preview.firstChild );
					}
				}
			} );
		}
	}

	/**
	 * Wire every image field inside a root.
	 *
	 * @param {HTMLElement|Document} root Search root.
	 * @return {void}
	 */
	function wireImageFields( root ) {
		Array.prototype.forEach.call(
			root.querySelectorAll( '[data-ces-image-field]' ),
			imageField
		);
	}

	/* -----------------------------------------------------------------
	 * The options repeater.
	 * -------------------------------------------------------------- */

	/**
	 * Wire the options block of the question editor.
	 *
	 * @param {HTMLElement} box The `[data-ces-options]` element.
	 * @return {void}
	 */
	function Options( box ) {
		var list = box.querySelector( '[data-ces-option-list]' );
		var actions = box.querySelector( '[data-ces-option-actions]' );
		var tf = box.querySelector( '[data-ces-tf]' );
		var add = box.querySelector( '[data-ces-option-add]' );
		var typeSelect = document.querySelector( '[data-ces-q-type]' );

		if ( ! list ) {
			return;
		}

		/**
		 * Every option row, in display order.
		 *
		 * @return {HTMLElement[]} The rows.
		 */
		function rows() {
			return Array.prototype.slice.call( list.querySelectorAll( '[data-ces-option]' ) );
		}

		/**
		 * The type currently selected.
		 *
		 * @return {string} A type slug.
		 */
		function currentType() {
			return typeSelect ? typeSelect.value : TYPE_SINGLE;
		}

		/**
		 * Renumber every row: letter, field names and correct-input value.
		 *
		 * This is the only place those are written. Everything else changes
		 * the DOM and then calls this.
		 *
		 * @return {void}
		 */
		function reindex() {
			var type = currentType();
			var multi = TYPE_MULTI === type;
			var all = rows();

			all.forEach( function ( row, index ) {
				var letter = row.querySelector( '[data-ces-option-letter]' );
				var correct = row.querySelector( '[data-ces-option-correct]' );
				var text = row.querySelector( '.ces-option__text' );
				var image = row.querySelector( '[data-ces-image-input]' );
				var remove = row.querySelector( '[data-ces-option-remove]' );

				if ( letter ) {
					letter.textContent = letterFor( index );
				}

				if ( correct ) {
					/*
					 * Switching single/multi changes the input type, and a
					 * checked radio must not survive as a checked checkbox in
					 * a second row — so the checked state is read first and
					 * only kept for the single-answer case when it is the one
					 * that was checked.
					 */
					var wasChecked = correct.checked;

					correct.type = multi ? 'checkbox' : 'radio';
					correct.name = multi ? 'ces_q[correct][]' : 'ces_q[correct]';
					correct.value = String( index );
					correct.checked = wasChecked;
				}

				if ( text ) {
					text.name = 'ces_q[options][' + index + '][text]';
				}

				if ( image ) {
					image.name = 'ces_q[options][' + index + '][image]';
				}

				// Below two options there is nothing meaningful to remove.
				if ( remove ) {
					remove.disabled = all.length <= 2;
				}
			} );

			if ( add ) {
				add.disabled = all.length >= MAX;
			}
		}

		/**
		 * Build a fresh, empty option row by cloning the first one.
		 *
		 * Cloning rather than building from a string keeps the markup in PHP
		 * as the single source of truth: a field added there appears here
		 * without this file changing.
		 *
		 * @return {HTMLElement|null} The new row.
		 */
		function blankRow() {
			var template = list.querySelector( '[data-ces-option]' );

			if ( ! template ) {
				return null;
			}

			var row = template.cloneNode( true );

			var text = row.querySelector( '.ces-option__text' );
			var correct = row.querySelector( '[data-ces-option-correct]' );
			var image = row.querySelector( '[data-ces-image-input]' );
			var preview = row.querySelector( '[data-ces-image-preview]' );

			if ( text ) {
				text.value = '';
			}

			if ( correct ) {
				correct.checked = false;
			}

			if ( image ) {
				image.value = '0';
			}

			if ( preview ) {
				while ( preview.firstChild ) {
					preview.removeChild( preview.firstChild );
				}
			}

			// The clone carries the original's "already wired" marker.
			var field = row.querySelector( '[data-ces-image-field]' );

			if ( field ) {
				field.removeAttribute( 'data-ces-image-ready' );
			}

			return row;
		}

		/**
		 * Apply a question type to the visible controls.
		 *
		 * @param {string} type The type slug.
		 * @return {void}
		 */
		function applyType( type ) {
			var isTf = TYPE_TRUEFALSE === type;

			toggle( list, ! isTf );
			toggle( actions, ! isTf );
			toggle( tf, isTf );

			reindex();
		}

		/* ---------------- Events ---------------- */

		if ( add ) {
			add.addEventListener( 'click', function () {
				if ( rows().length >= MAX ) {
					window.alert( t( 'maxReached' ) );

					return;
				}

				var row = blankRow();

				if ( ! row ) {
					return;
				}

				list.appendChild( row );
				wireImageFields( row );
				reindex();

				var text = row.querySelector( '.ces-option__text' );

				if ( text ) {
					text.focus();
				}
			} );
		}

		list.addEventListener( 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( '[data-ces-option-remove]' ) : null;

			if ( ! button || button.disabled ) {
				return;
			}

			var row = button.closest( '[data-ces-option]' );

			if ( ! row || rows().length <= 2 ) {
				return;
			}

			row.parentNode.removeChild( row );
			reindex();
		} );

		if ( typeSelect ) {
			// Remembered so a cancelled confirm can be rolled back.
			var previous = typeSelect.value;

			typeSelect.addEventListener( 'change', function () {
				var next = typeSelect.value;

				if ( TYPE_TRUEFALSE === next && previous !== next ) {
					if ( ! window.confirm( t( 'confirmType' ) ) ) {
						typeSelect.value = previous;

						return;
					}
				}

				previous = next;
				applyType( next );
			} );
		}

		reindex();
	}

	/* -----------------------------------------------------------------
	 * Boot.
	 * -------------------------------------------------------------- */

	/**
	 * Wire everything on the question editor.
	 *
	 * @return {void}
	 */
	function boot() {
		wireImageFields( document );

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-ces-options]' ),
			function ( box ) {
				if ( box.getAttribute( 'data-ces-ready' ) ) {
					return;
				}

				box.setAttribute( 'data-ces-ready', '1' );
				Options( box );
			}
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
