/**
 * Resource editor: the media picker behind "Choose file".
 *
 * Uses the media modal WordPress already ships. No jQuery beyond what
 * wp.media itself pulls in.
 */
( function () {
	'use strict';

	var pick = document.getElementById( 'ces-res-file-pick' );
	var clear = document.getElementById( 'ces-res-file-clear' );
	var field = document.getElementById( 'ces_res_file' );
	var name = document.getElementById( 'ces-res-file-name' );

	if ( ! pick || ! field || ! name ) {
		return;
	}

	var strings = window.CES_HUB_ADMIN || {};
	var frame = null;

	pick.addEventListener( 'click', function ( event ) {
		event.preventDefault();

		if ( ! window.wp || ! window.wp.media ) {
			return;
		}

		if ( frame ) {
			frame.open();

			return;
		}

		frame = window.wp.media( {
			title: strings.chooseTitle || 'Choose a file',
			button: { text: strings.chooseButton || 'Use this file' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();

			field.value = attachment.id;
			name.textContent = attachment.filename || attachment.title || String( attachment.id );
		} );

		frame.open();
	} );

	if ( clear ) {
		clear.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			field.value = '';
			name.textContent = '—';
		} );
	}
}() );
