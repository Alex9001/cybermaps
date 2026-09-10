( () => {
	'use strict';

	const config = window.cybermapsEdgeOptimization || {};
	const root = document.getElementById( 'cybermaps-edge-optimization' );
	if ( ! root ) return;

	const token = document.getElementById( 'cybermaps-cloudflare-token' );
	const feedback = document.getElementById( 'cybermaps-edge-feedback' );
	const verificationResults = document.getElementById( 'cybermaps-edge-verification-results' );
	const buttons = Array.from( root.querySelectorAll( '[data-cybermaps-edge-action]' ) );
	const oauthLinks = Array.from( root.querySelectorAll( '[data-cybermaps-oauth-start]' ) );
	const cloudflareControls = Array.from( root.querySelectorAll( '[data-cloudflare-required="1"]' ) );
	const cloudflareNotice = document.getElementById( 'cybermaps-cloudflare-detection' );
	const cloudflareMessage = document.getElementById( 'cybermaps-cloudflare-detection-message' );
	const cloudflareConfirm = document.getElementById( 'cybermaps-cloudflare-confirm' );
	const cloudflareConfirmRow = document.getElementById( 'cybermaps-cloudflare-confirm-row' );
	const publicResources = Array.isArray( config.publicResources ) ? config.publicResources : [];
	let cloudflareConfirmed = config.cloudflareDetected === true;
	let busyState = false;
	let oauthPolling = false;
	let oauthStartedAt = 0;
	let oauthPollAttempt = 0;
	const pollSchedule = Array.isArray( config.pollSchedule ) && config.pollSchedule.length
		? config.pollSchedule.map( Number ).filter( ( delay ) => Number.isFinite( delay ) && delay >= 1000 )
		: [ 2000, 3000, 5000, 8000, 10000 ];

	const announce = ( message, error = false ) => {
		feedback.textContent = message;
		feedback.classList.toggle( 'is-error', error );
	};

	const updateControlState = () => {
		buttons.forEach( ( button ) => { button.disabled = busyState || ( button.dataset.cloudflareRequired === '1' && ! cloudflareConfirmed ); } );
		oauthLinks.forEach( ( link ) => {
			const disabled = busyState || ( link.dataset.cloudflareRequired === '1' && ! cloudflareConfirmed );
			link.setAttribute( 'aria-disabled', disabled ? 'true' : 'false' );
		} );
	};

	const setBusy = ( busy ) => {
		busyState = busy;
		updateControlState();
		root.setAttribute( 'aria-busy', busyState ? 'true' : 'false' );
	};

	const confirmCloudflare = ( manual = false ) => {
		cloudflareConfirmed = true;
		cloudflareControls.forEach( ( control ) => {
			if ( control.tagName !== 'A' ) return;
			const url = new URL( control.href, window.location.href );
			url.searchParams.set( 'cloudflare_confirmed', '1' );
			url.searchParams.set( 'cloudflare_host', config.cloudflareHost || '' );
			control.href = url.toString();
		} );
		if ( cloudflareNotice ) {
			cloudflareNotice.dataset.detected = '1';
			cloudflareNotice.classList.remove( 'notice-warning' );
			cloudflareNotice.classList.add( 'notice-success' );
		}
		if ( cloudflareMessage ) cloudflareMessage.textContent = manual
			? config.strings?.cloudflareConfirmed || 'Manual Cloudflare confirmation accepted for this page.'
			: config.strings?.cloudflareDetected || 'Cloudflare proxy traffic was detected. Cloudflare rule tools are available.';
		if ( ! manual && cloudflareConfirmRow ) cloudflareConfirmRow.hidden = true;
		updateControlState();
	};

	const resetCloudflareConfirmation = () => {
		cloudflareConfirmed = false;
		cloudflareControls.forEach( ( control ) => {
			if ( control.tagName !== 'A' ) return;
			const url = new URL( control.href, window.location.href );
			url.searchParams.delete( 'cloudflare_confirmed' );
			url.searchParams.delete( 'cloudflare_host' );
			control.href = url.toString();
		} );
		if ( cloudflareNotice ) {
			cloudflareNotice.dataset.detected = '0';
			cloudflareNotice.classList.remove( 'notice-success' );
			cloudflareNotice.classList.add( 'notice-warning' );
		}
		if ( cloudflareMessage ) cloudflareMessage.textContent = config.strings?.cloudflareMissing || 'Cloudflare proxy traffic was not detected.';
		if ( cloudflareConfirmRow ) cloudflareConfirmRow.hidden = false;
		updateControlState();
	};

	const detectCloudflareInBrowser = async () => {
		if ( cloudflareConfirmed || ! config.cloudflareDetectionUrl ) return;
		if ( cloudflareMessage ) cloudflareMessage.textContent = config.strings?.cloudflareChecking || 'Checking the public hostname for Cloudflare proxy traffic…';
		try {
			const url = new URL( config.cloudflareDetectionUrl, window.location.href );
			url.searchParams.set( 'cybermaps_cloudflare_probe', String( Date.now() ) );
			const response = await fetch( url.toString(), { method: 'HEAD', cache: 'no-store', credentials: 'omit' } );
			const ray = response.headers.get( 'cf-ray' ) || '';
			const server = response.headers.get( 'server' ) || '';
			const finalHost = new URL( response.url ).hostname.toLowerCase();
			if ( finalHost === String( config.cloudflareHost || '' ).toLowerCase() && ( ray || server.toLowerCase().includes( 'cloudflare' ) ) ) {
				confirmCloudflare();
			} else if ( cloudflareMessage ) {
				cloudflareMessage.textContent = config.strings?.cloudflareMissing || 'Cloudflare proxy traffic was not detected.';
			}
		} catch ( error ) {
			if ( cloudflareMessage ) cloudflareMessage.textContent = config.strings?.cloudflareProbeFailed || 'The public hostname could not be checked from this browser.';
		}
	};

	const summarizeVerification = ( verification ) => {
		const total = Number( verification?.total || 0 );
		const passed = Number( verification?.passed || 0 );
		return passed + ' of ' + total + ' public discovery resources passed.';
	};

	const bodyIsValid = ( body, resource ) => {
		if ( ! body.trim() ) return false;
		if ( resource.profile === 'markdown' ) return true;
		try {
			const decoded = JSON.parse( body );
			return ! resource.requires_linkset || Array.isArray( decoded?.linkset );
		} catch ( error ) {
			return false;
		}
	};

	const verifyResourceInBrowser = async ( resource ) => {
		const url = new URL( resource.url, window.location.href );
		url.searchParams.set( 'cybermaps_verify', window.crypto?.randomUUID?.() || String( Date.now() ) );
		try {
			const response = await fetch( url.toString(), {
				method: 'GET',
				cache: 'no-store',
				credentials: 'omit',
			} );
			const body = await response.text();
			const type = ( response.headers.get( 'content-type' ) || '' ).toLowerCase();
			const cache = ( response.headers.get( 'cache-control' ) || '' ).toLowerCase();
			const link = ( response.headers.get( 'link' ) || '' ).toLowerCase();
			const marker = ( response.headers.get( 'x-cybermaps-cloudflare-rule' ) || '' ).toLowerCase();
			const linkOk = ! resource.requires_linkset || url.origin !== window.location.origin || link.includes( 'rel="api-catalog"' );
			const bodyOk = response.status === 200 && bodyIsValid( body, resource );
			const headersOk = type.startsWith( resource.expected_type ) && cache.includes( resource.expected_cache ) && linkOk && marker === 'v1';
			return { path: resource.path, ok: bodyOk && headersOk, status: response.status, body: bodyOk, headers: headersOk };
		} catch ( error ) {
			return { path: resource.path, ok: false, status: 0, body: false, headers: false, message: config.strings?.network || 'The browser could not reach this resource.' };
		}
	};

	const verifyPublicInBrowser = async () => {
		const results = await Promise.all( publicResources.map( verifyResourceInBrowser ) );
		const passed = results.filter( ( result ) => result.ok ).length;
		return { total: results.length, passed, failed: results.length - passed, source: 'browser', results };
	};

	const pause = ( delay ) => new Promise( ( resolve ) => window.setTimeout( resolve, delay ) );

	const renderVerificationResults = ( verification ) => {
		if ( ! verificationResults ) return;
		verificationResults.replaceChildren();
		const failed = Array.isArray( verification?.results ) ? verification.results.filter( ( row ) => ! row.ok ) : [];
		if ( ! failed.length ) return;
		const list = document.createElement( 'ul' );
		failed.forEach( ( row ) => {
			const item = document.createElement( 'li' );
			const reason = row.message || ( row.status ? 'HTTP ' + row.status + '; body ' + ( row.body ? 'passed' : 'failed' ) + '; headers ' + ( row.headers ? 'passed' : 'failed' ) + '.' : 'Request failed.' );
			item.textContent = row.path + ': ' + reason;
			list.appendChild( item );
		} );
		verificationResults.appendChild( list );
	};

	const verifyPublic = async () => {
		if ( ! publicResources.length ) {
			const verification = ( await request( 'cybermaps_edge_verify' ) ).verification;
			renderVerificationResults( verification );
			return verification;
		}
		let verification = await verifyPublicInBrowser();
		for ( let attempt = 1; verification.failed > 0 && attempt < 3; attempt++ ) {
			await pause( attempt * 1500 );
			verification = await verifyPublicInBrowser();
		}
		renderVerificationResults( verification );
		return verification;
	};

	const request = async ( action, requiresToken = false ) => {
		const body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce || '' );
		if ( cloudflareConfirmed ) {
			body.append( 'cloudflare_confirmed', '1' );
			body.append( 'cloudflare_host', config.cloudflareHost || '' );
		}
		if ( requiresToken ) body.append( 'token', token?.value || '' );
		const response = await fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } );
		const payload = await response.json();
		if ( ! response.ok || ! payload.success ) throw new Error( payload?.data?.message || config.strings?.failed || 'Request failed.' );
		return payload.data;
	};

	const finishOAuth = ( message, error = false ) => {
		oauthPolling = false;
		oauthPollAttempt = 0;
		setBusy( false );
		announce( message, error );
	};

	const scheduleOAuthPoll = ( retryAfter = 0 ) => {
		const scheduled = pollSchedule[ Math.min( oauthPollAttempt, pollSchedule.length - 1 ) ] || 10000;
		const serverDelay = Math.max( 0, Number( retryAfter || 0 ) * 1000 );
		oauthPollAttempt++;
		window.setTimeout( pollOAuth, Math.max( scheduled, serverDelay ) );
	};

	const pollOAuth = async ( resume = false ) => {
		if ( oauthPolling && resume ) return;
		try {
			const data = await request( config.oauthPollAction || 'cybermaps_edge_oauth_poll' );
			const status = data.status || 'failed';
			if ( status === 'idle' ) {
				if ( oauthPolling && Date.now() - oauthStartedAt < 10000 ) scheduleOAuthPoll();
				else if ( oauthPolling ) finishOAuth( config.strings?.failed || 'Authorization did not start.', true );
				return;
			}
			if ( status === 'pending' || status === 'processing' ) {
				oauthPolling = true;
				setBusy( true );
				announce( data.message || config.strings?.pending || 'Waiting for Cloudflare authorization…' );
				scheduleOAuthPoll( data.retry_after );
				return;
			}
			if ( status === 'complete' || status === 'partial' ) {
				announce( config.strings?.verifying || 'Cloudflare rules were updated. Verifying public resources…' );
				const verification = await verifyPublic();
				const verificationFailed = verification.failed > 0;
				const message = ( data.message || config.strings?.complete || 'Operation complete.' ) + ' ' + summarizeVerification( verification );
				finishOAuth( message, status === 'partial' || verificationFailed );
				return;
			}
			finishOAuth( data.message || config.strings?.failed || 'Authorization failed.', true );
		} catch ( error ) {
			if ( resume && ! oauthPolling ) return;
			finishOAuth( error.message || config.strings?.failed || 'Request failed.', true );
		}
	};

	oauthLinks.forEach( ( link ) => {
		link.addEventListener( 'click', ( event ) => {
			if ( oauthPolling || ( link.dataset.cloudflareRequired === '1' && ! cloudflareConfirmed ) ) {
				event.preventDefault();
				if ( ! cloudflareConfirmed ) announce( config.strings?.cloudflareMissing || 'Cloudflare proxy traffic was not detected.', true );
				return;
			}
			oauthPolling = true;
			oauthStartedAt = Date.now();
			oauthPollAttempt = 0;
			setBusy( true );
			announce( config.strings?.waiting || 'Cloudflare opened in a new tab.' );
			scheduleOAuthPoll();
		} );
	} );

	buttons.forEach( ( button ) => {
		button.addEventListener( 'click', async () => {
			const action = button.dataset.cybermapsEdgeAction;
			const requiresToken = button.dataset.requiresToken === '1';
			setBusy( true );
			announce( config.strings?.working || 'Working…' );
			try {
				const data = action === 'cybermaps_edge_verify' && publicResources.length
					? { verification: await verifyPublic() }
					: await request( action, requiresToken );
				const message = data.verification
					? summarizeVerification( data.verification )
					: data.message || config.strings?.complete || 'Operation complete.';
				announce( message );
			} catch ( error ) {
				announce( error.message || config.strings?.failed || 'Request failed.', true );
			} finally {
				if ( token ) token.value = '';
				setBusy( false );
			}
		} );
	} );

	if ( cloudflareConfirm ) cloudflareConfirm.addEventListener( 'change', () => { cloudflareConfirm.checked ? confirmCloudflare( true ) : resetCloudflareConfirmation(); } );
	updateControlState();
	detectCloudflareInBrowser();

	root.querySelectorAll( '[data-cybermaps-copy-target]' ).forEach( ( button ) => {
		button.addEventListener( 'click', async () => {
			const source = document.getElementById( button.dataset.cybermapsCopyTarget || '' );
			if ( ! source ) return;
			try {
				await navigator.clipboard.writeText( source.value );
				announce( config.strings?.copied || 'Snippet copied.' );
			} catch ( error ) {
				source.select();
				announce( document.execCommand( 'copy' ) ? config.strings?.copied || 'Snippet copied.' : config.strings?.copyFailed || 'Copy failed.', true );
			}
		} );
	} );

	pollOAuth( true );
} )();
