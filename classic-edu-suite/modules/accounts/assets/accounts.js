/**
 * Accounts module: two small conveniences, and nothing the page needs.
 *
 * Every screen in this module works with JavaScript switched off — the forms
 * post to admin-post.php and come back with their errors. This file only
 * removes friction:
 *
 *   1. The six-digit code box accepts whatever the parent read out, however
 *      it was typed: "123 456", "123-456", a pasted WhatsApp line.
 *   2. A submit button that has been pressed is disabled, because a student
 *      on a slow connection presses "send again" three times and the resend
 *      cap then locks them out of their own signup.
 *
 * Vanilla, no dependencies, no build step.
 */
( function () {
	'use strict';

	/**
	 * Keep only digits, and no more than six of them.
	 *
	 * @param {string} value Raw input.
	 * @return {string} Cleaned value.
	 */
	function cleanCode( value ) {
		return String( value || '' ).replace( /\D+/g, '' ).slice( 0, 6 );
	}

	/**
	 * Tidy the code input as it is typed or pasted.
	 *
	 * @param {HTMLInputElement} input The code box.
	 * @return {void}
	 */
	function wireCodeInput( input ) {
		input.addEventListener( 'input', function () {
			var cleaned = cleanCode( input.value );

			if ( cleaned !== input.value ) {
				input.value = cleaned;
			}
		} );

		// A parent reading from WhatsApp often sends the whole line. Take the
		// first run of six digits out of whatever lands in the box.
		input.addEventListener( 'paste', function ( event ) {
			var text = '';

			if ( event.clipboardData && event.clipboardData.getData ) {
				text = event.clipboardData.getData( 'text' );
			}

			var match = String( text ).match( /\d[\d\s-]{4,}\d/ );

			if ( ! match ) {
				return;
			}

			var code = cleanCode( match[ 0 ] );

			if ( 6 !== code.length ) {
				return;
			}

			event.preventDefault();
			input.value = code;
		} );
	}

	/**
	 * Stop a form being posted twice.
	 *
	 * The button is disabled rather than the form blocked, so the browser
	 * still submits the press that got there first.
	 *
	 * @param {HTMLFormElement} form A form in this module.
	 * @return {void}
	 */
	function wireSingleSubmit( form ) {
		form.addEventListener( 'submit', function () {
			var buttons = form.querySelectorAll( 'button[type="submit"], input[type="submit"]' );

			window.setTimeout( function () {
				Array.prototype.forEach.call( buttons, function ( button ) {
					button.disabled = true;
					button.setAttribute( 'aria-busy', 'true' );
				} );
			}, 0 );
		} );
	}

	/**
	 * Find this module's bits on the page and wire them up.
	 *
	 * @return {void}
	 */
	function start() {
		var code = document.querySelector( '.ces-code-input' );

		if ( code ) {
			wireCodeInput( code );
		}

		var forms = document.querySelectorAll( '.ces-onboard form, .ces-portal form' );

		Array.prototype.forEach.call( forms, wireSingleSubmit );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
