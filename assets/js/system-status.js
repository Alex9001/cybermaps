( () => {
	'use strict';

	const config = window.cybermapsSystemStatus || {};
	const feedback = document.getElementById( 'cybermaps-status-feedback' );
	const results = document.getElementById( 'cybermaps-public-check-results' );
	const debugEnabled = document.getElementById( 'cybermaps-debug-enabled' );
	const debugDuration = document.getElementById( 'cybermaps-debug-duration' );
	const debugState = document.getElementById( 'cybermaps-debug-state' );
	const debugFeedback = document.getElementById( 'cybermaps-debug-feedback' );
	const debugCount = document.getElementById( 'cybermaps-debug-count' );
	const debugLog = document.getElementById( 'cybermaps-debug-log' );
	const debugDetails = document.querySelector( '.cybermaps-debug-events' );

	const announce = ( message ) => { if ( feedback ) feedback.textContent = message; };
	const announceDebug = ( message ) => { if ( debugFeedback ) debugFeedback.textContent = message; };

	const post = async ( action, nonce, values = {} ) => {
		const body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', nonce || '' );
		Object.entries( values ).forEach( ( [ key, value ] ) => body.append( key, value ) );
		const response = await fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } );
		const payload = await response.json();
		if ( ! response.ok || ! payload.success ) throw new Error( payload?.data?.message || config.strings?.failed );
		return payload.data;
	};

	const renderVerification = ( verification ) => {
		if ( ! results ) return;
		results.hidden = false;
		results.replaceChildren();
		const card = document.createElement( 'div' );
		card.className = 'cm-card-sm';
		const heading = document.createElement( 'h2' );
		heading.textContent = `Public delivery: ${ verification.passed } of ${ verification.total } passed`;
		card.appendChild( heading );
		const list = document.createElement( 'ul' );
		( verification.results || [] ).forEach( ( row ) => {
			const item = document.createElement( 'li' );
			item.textContent = `${ row.ok ? 'Pass' : 'Needs attention' }: ${ row.path } (${ row.status || 'no response' }) - ${ row.message }`;
			list.appendChild( item );
		} );
		card.appendChild( list );
		results.appendChild( card );
	};

	const describeDebugState = ( state ) => {
		if ( debugEnabled ) debugEnabled.checked = Boolean( state.enabled );
		if ( debugCount ) debugCount.textContent = String( state.entry_count || 0 );
		if ( ! debugState ) return;
		const retained = `${ state.entry_count || 0 } ${ config.strings?.eventsRetained || 'events retained' }`;
		debugState.textContent = state.enabled && state.expires_at
			? `${ config.strings?.debugActiveUntil || 'Active until' } ${ new Date( state.expires_at ).toLocaleString() }. ${ retained }.`
			: `${ config.strings?.debugOff || 'Off' }. ${ retained }.`;
	};

	const renderDebugEvents = ( entries ) => {
		if ( ! debugLog ) return;
		debugLog.replaceChildren();
		if ( ! entries.length ) {
			const empty = document.createElement( 'p' );
			empty.className = 'description';
			empty.textContent = config.strings?.noEvents || 'No diagnostic events have been collected.';
			debugLog.appendChild( empty );
			return;
		}
		const list = document.createElement( 'ol' );
		list.className = 'cybermaps-debug-log-list';
		entries.slice().reverse().forEach( ( entry ) => {
			const item = document.createElement( 'li' );
			const heading = document.createElement( 'strong' );
			heading.textContent = `${ entry.time } | ${ entry.level } | ${ entry.event }`;
			item.appendChild( heading );
			if ( entry.context && Object.keys( entry.context ).length ) {
				const context = document.createElement( 'pre' );
				context.textContent = JSON.stringify( entry.context, null, 2 );
				item.appendChild( context );
			}
			list.appendChild( item );
		} );
		debugLog.appendChild( list );
	};

	const fetchBundle = async () => {
		const data = await post( 'cybermaps_debug_bundle', config.debugNonce );
		describeDebugState( data.bundle.debugging.state );
		renderDebugEvents( data.bundle.debugging.entries || [] );
		return data.bundle;
	};

	const copyText = async ( text ) => {
		try {
			await navigator.clipboard.writeText( text );
			return true;
		} catch ( error ) {
			const area = document.createElement( 'textarea' );
			area.value = text;
			document.body.appendChild( area );
			area.select();
			const copied = document.execCommand( 'copy' );
			area.remove();
			return copied;
		}
	};

	const copyBundle = async () => {
		const bundle = await fetchBundle();
		const copied = await copyText( JSON.stringify( bundle, null, 2 ) );
		announceDebug( copied ? config.strings?.copied : config.strings?.copyFailed );
		announce( copied ? config.strings?.copied : config.strings?.copyFailed );
	};

	const handleDebugError = ( error ) => {
		announceDebug( error instanceof Error ? error.message : config.strings?.failed );
	};

	document.getElementById( 'cybermaps-status-refresh' )?.addEventListener( 'click', () => window.location.reload() );
	document.getElementById( 'cybermaps-status-verify' )?.addEventListener( 'click', async ( event ) => {
		event.currentTarget.disabled = true;
		announce( config.strings?.checking || 'Checking...' );
		try {
			const data = await post( 'cybermaps_edge_verify', config.nonce );
			renderVerification( data.verification );
			announce( `${ data.verification.passed } of ${ data.verification.total } resources passed.` );
			post(
				'cybermaps_debug_verification',
				config.debugNonce,
				{ passed: String( data.verification.passed ), total: String( data.verification.total ) }
			).catch( () => {} );
		} catch ( error ) {
			announce( error.message || config.strings?.failed );
		} finally {
			event.currentTarget.disabled = false;
		}
	} );

	document.getElementById( 'cybermaps-status-copy' )?.addEventListener( 'click', () => {
		copyBundle().catch( handleDebugError );
	} );

	describeDebugState( config.debugState || { enabled: false, entry_count: 0 } );
	debugEnabled?.addEventListener( 'change', async () => {
		debugEnabled.disabled = true;
		try {
			const data = await post(
				'cybermaps_debug_toggle',
				config.debugNonce,
				{ enabled: debugEnabled.checked ? '1' : '0', duration: debugDuration?.value || '86400' }
			);
			describeDebugState( data.state );
			announceDebug( debugEnabled.checked ? config.strings?.debugEnabled : config.strings?.debugDisabled );
		} catch ( error ) {
			debugEnabled.checked = ! debugEnabled.checked;
			handleDebugError( error );
		} finally {
			debugEnabled.disabled = false;
		}
	} );

	document.getElementById( 'cybermaps-debug-clear' )?.addEventListener( 'click', async () => {
		if ( ! window.confirm( config.strings?.confirmClear ) ) return;
		try {
			const data = await post( 'cybermaps_debug_clear', config.debugNonce );
			describeDebugState( data.state );
			renderDebugEvents( [] );
			announceDebug( config.strings?.cleared );
		} catch ( error ) {
			handleDebugError( error );
		}
	} );

	document.getElementById( 'cybermaps-debug-copy' )?.addEventListener( 'click', () => {
		copyBundle().catch( handleDebugError );
	} );

	document.getElementById( 'cybermaps-debug-download' )?.addEventListener( 'click', async () => {
		try {
			const bundle = await fetchBundle();
			const blob = new Blob( [ JSON.stringify( bundle, null, 2 ) ], { type: 'application/json' } );
			const url = URL.createObjectURL( blob );
			const link = document.createElement( 'a' );
			link.href = url;
			link.download = `cybermaps-support-${ new Date().toISOString().replace( /[:.]/g, '-' ) }.json`;
			link.click();
			URL.revokeObjectURL( url );
		} catch ( error ) {
			handleDebugError( error );
		}
	} );

	debugDetails?.addEventListener( 'toggle', () => {
		if ( debugDetails.open ) fetchBundle().catch( handleDebugError );
	} );
} )();
