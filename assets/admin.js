/**
 * JPKCom Harden & Trim - settings page.
 *
 * Asks for confirmation before a preset is applied. The confirmation text is
 * rendered (translated and escaped) by PHP into the button's
 * data-jht-confirm attribute, so this file has no strings of its own.
 * Inert on any page without such a button.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		var button = target && target.closest ? target.closest( 'button[data-jht-confirm]' ) : null;

		if ( ! button ) {
			return;
		}

		if ( ! window.confirm( button.getAttribute( 'data-jht-confirm' ) ) ) {
			event.preventDefault();
		}
	} );
}() );
