( function () {
	'use strict';

	function init() {
		var l10n     = window.BCCGenerateSummaryView || {};
		var textMore = l10n.vis_mer    || 'Vis mer';
		var textLess = l10n.vis_mindre || 'Vis mindre';

		document.querySelectorAll( '.wp-block-bcc-generate-summary-with-ai-generate-summary' ).forEach( function ( block ) {
			var body     = block.querySelector( '.bcc-generate-summary-block__body' );
			var toggle   = block.querySelector( '.bcc-generate-summary-block__toggle' );
			var textSpan = toggle ? toggle.querySelector( 'span' ) : null;

			if ( ! body || ! toggle ) return;

			toggle.addEventListener( 'click', function () {
				var expanded = toggle.getAttribute( 'aria-expanded' ) === 'true';
				body.classList.toggle( 'is-collapsed', expanded );
				toggle.setAttribute( 'aria-expanded', String( ! expanded ) );
				if ( textSpan ) {
					textSpan.textContent = expanded ? textMore : textLess;
				}
			} );
		} );

		// Initialise Tippy on AI badge buttons.
		if ( typeof window.tippy === 'function' ) {
			document.querySelectorAll( '.bcc-generate-summary-block__ai-badge' ).forEach( function ( badge ) {
				window.tippy( badge, {
					content:   badge.getAttribute( 'data-ai-tooltip' ),
					theme:     'bcc-summary',
					placement: 'top',
					arrow:     false,
					delay:     [ 100, 100 ],
					maxWidth:  300,
					offset:    [ -114, 30 ],
				} );
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
