( function () {
	'use strict';

	function setActiveButton( button, selector ) {
		document.querySelectorAll( selector ).forEach( function ( item ) {
			const active = item === button;
			item.classList.toggle( 'is-active', active );
			item.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );
	}

	function applyActivityFilters() {
		const feed = document.getElementById( 'cm-analytics-feed' );
		if ( ! feed ) {
			return;
		}

		const kindButton = document.querySelector( '[data-cm-filter-kind].is-active' );
		const identityButton = document.querySelector( '[data-cm-filter-identity].is-active' );
		const kind = kindButton ? kindButton.dataset.cmFilterKind : 'all';
		const identity = identityButton ? identityButton.dataset.cmFilterIdentity : 'all';
		let visible = 0;

		feed.querySelectorAll( '.cm-feed-item' ).forEach( function ( item ) {
			const kindMatches = 'all' === kind || item.dataset.cmRequestKind === kind;
			const identityMatches = 'all' === identity || item.dataset.cmIdentityGroup === identity;
			const show = kindMatches && identityMatches;
			item.hidden = ! show;
			if ( show ) {
				visible++;
			}
		} );

		const empty = document.getElementById( 'cm-analytics-feed-empty' );
		if ( empty ) {
			empty.hidden = visible > 0;
		}
	}

	document.addEventListener( 'click', function ( event ) {
		const kindButton = event.target.closest( '[data-cm-filter-kind]' );
		if ( kindButton ) {
			setActiveButton( kindButton, '[data-cm-filter-kind]' );
			applyActivityFilters();
			return;
		}

		const identityButton = event.target.closest( '[data-cm-filter-identity]' );
		if ( identityButton ) {
			setActiveButton( identityButton, '[data-cm-filter-identity]' );
			applyActivityFilters();
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		const form = event.target.closest( '[data-cm-confirm-form]' );
		if ( ! form ) {
			return;
		}

		const message = form.dataset.cmConfirmForm;
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
}() );
