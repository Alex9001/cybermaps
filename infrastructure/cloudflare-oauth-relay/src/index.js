const TRANSACTION_TTL_MS = 300000;
const MAX_BODY_BYTES = 2048;
const AUTHORIZATION_ENDPOINT = 'https://dash.cloudflare.com/oauth2/auth';
const EXPECTED_SCOPES = [ 'cache-settings.write', 'zone-transform-rules.write', 'zone.read' ];
const POLL_DELAYS_SECONDS = [ 2, 3, 5, 8, 10 ];
const DEFAULT_DAILY_TRANSACTION_LIMIT = 1500;
const DEFAULT_MAX_TRANSACTION_POLLS = 48;

export const securityHeaders = ( contentType, nonce = '' ) => {
	const script = nonce ? "script-src 'nonce-" + nonce + "'" : "script-src 'none'";
	return {
		'Cache-Control': 'no-store, max-age=0',
		'Content-Type': contentType,
		'Content-Security-Policy': "default-src 'none'; " + script + "; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
		'Permissions-Policy': 'accelerometer=(), camera=(), geolocation=(), microphone=()',
		'Referrer-Policy': 'no-referrer',
		'X-Content-Type-Options': 'nosniff',
		'X-Frame-Options': 'DENY',
	};
};

const json = ( value, status = 200, additionalHeaders = {} ) => new Response( JSON.stringify( value ), {
	status,
	headers: { ...securityHeaders( 'application/json; charset=utf-8' ), ...additionalHeaders },
} );

const randomToken = ( bytes = 32 ) => {
	const value = new Uint8Array( bytes );
	crypto.getRandomValues( value );
	return btoa( String.fromCharCode( ...value ) ).replaceAll( '+', '-' ).replaceAll( '/', '_' ).replaceAll( '=', '' );
};

const digest = async ( value ) => {
	const bytes = new TextEncoder().encode( value );
	const hash = new Uint8Array( await crypto.subtle.digest( 'SHA-256', bytes ) );
	return Array.from( hash, ( byte ) => byte.toString( 16 ).padStart( 2, '0' ) ).join( '' );
};

const equal = ( left, right ) => {
	if ( typeof left !== 'string' || typeof right !== 'string' || left.length !== right.length ) return false;
	let difference = 0;
	for ( let index = 0; index < left.length; index++ ) difference |= left.charCodeAt( index ) ^ right.charCodeAt( index );
	return difference === 0;
};

export const validateCreateBody = ( body ) => Boolean(
	body
	&& body.protocol_version === 1
	&& body.code_challenge_method === 'S256'
	&& typeof body.code_challenge === 'string'
	&& /^[A-Za-z0-9_-]{43,128}$/.test( body.code_challenge )
);

export const transactionIdFromState = ( state ) => {
	if ( typeof state !== 'string' || ! /^[a-f0-9-]{36}\.[A-Za-z0-9_-]{43,128}$/i.test( state ) ) return '';
	return state.slice( 0, 36 );
};

export const pollDelaySeconds = ( pollCount ) => POLL_DELAYS_SECONDS[
	Math.min( Math.max( 0, Number( pollCount ) || 0 ), POLL_DELAYS_SECONDS.length - 1 )
];

export const positiveInteger = ( value, fallback, maximum ) => {
	const parsed = Number.parseInt( String( value || '' ), 10 );
	return Number.isSafeInteger( parsed ) && parsed > 0 && parsed <= maximum ? parsed : fallback;
};

export const secondsUntilUtcReset = ( now = Date.now() ) => {
	const date = new Date( now );
	const reset = Date.UTC( date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate() + 1 );
	return Math.max( 1, Math.ceil( ( reset - now ) / 1000 ) );
};

export const buildAuthorizationUrl = ( config, state, challenge ) => {
	const url = new URL( AUTHORIZATION_ENDPOINT );
	url.searchParams.set( 'client_id', config.clientId );
	url.searchParams.set( 'redirect_uri', config.redirectUri );
	url.searchParams.set( 'response_type', 'code' );
	url.searchParams.set( 'scope', config.scopes );
	url.searchParams.set( 'state', state );
	url.searchParams.set( 'code_challenge', challenge );
	url.searchParams.set( 'code_challenge_method', 'S256' );
	return url.toString();
};

const configuration = ( env ) => {
	const clientId = String( env.CLOUDFLARE_OAUTH_CLIENT_ID || '' ).trim();
	const scopes = String( env.CLOUDFLARE_OAUTH_SCOPES || '' ).trim();
	const redirectUri = String( env.CLOUDFLARE_OAUTH_REDIRECT_URI || '' ).trim();
	const configuredScopes = scopes.split( /\s+/ ).filter( Boolean ).sort();
	const rateLimitSecret = String( env.RATE_LIMIT_SECRET || '' );
	if (
		! /^[A-Za-z0-9._-]{8,256}$/.test( clientId )
		|| JSON.stringify( configuredScopes ) !== JSON.stringify( EXPECTED_SCOPES )
		|| redirectUri !== 'https://connect.cybermaps.dev/cloudflare/callback'
		|| rateLimitSecret.length < 32
	) return null;
	return {
		clientId,
		scopes,
		redirectUri,
		rateLimitSecret,
		dailyTransactionLimit: positiveInteger( env.DAILY_TRANSACTION_LIMIT, DEFAULT_DAILY_TRANSACTION_LIMIT, 10000 ),
		maxTransactionPolls: positiveInteger( env.MAX_TRANSACTION_POLLS, DEFAULT_MAX_TRANSACTION_POLLS, 100 ),
	};
};

const readJson = async ( request ) => {
	if ( ! ( request.headers.get( 'content-type' ) || '' ).toLowerCase().startsWith( 'application/json' ) ) throw new Error( 'invalid_content_type' );
	const length = Number( request.headers.get( 'content-length' ) || 0 );
	if ( length > MAX_BODY_BYTES ) throw new Error( 'request_too_large' );
	const text = await request.text();
	if ( text.length > MAX_BODY_BYTES ) throw new Error( 'request_too_large' );
	return JSON.parse( text || '{}' );
};

const transactionStub = ( env, transactionId ) => env.OAUTH_TRANSACTIONS.get( env.OAUTH_TRANSACTIONS.idFromName( transactionId ) );

const limitedResponse = ( retryAfter, reason ) => json(
	{ error: 'rate_limited', reason, retry_after: retryAfter },
	429,
	{ 'Retry-After': String( retryAfter ) }
);

const applyRateLimit = async ( binding, key, retryAfter, reason ) => {
	if ( ! binding ) return null;
	const result = await binding.limit( { key } );
	if ( result.success ) return null;
	console.warn( JSON.stringify( { event: 'rate_limited', reason } ) );
	return limitedResponse( retryAfter, reason );
};

const reserveDailyBudget = async ( env, limit ) => {
	const now = Date.now();
	const day = new Date( now ).toISOString().slice( 0, 10 );
	return transactionStub( env, '__budget__:' + day ).fetch( 'https://transaction.internal/reserve', {
		method: 'POST',
		body: JSON.stringify( {
			limit,
			resetAt: now + secondsUntilUtcReset( now ) * 1000,
		} ),
	} );
};

const createTransaction = async ( request, env ) => {
	const config = configuration( env );
	if ( ! config ) return json( { error: 'service_not_configured' }, 503 );
	const ipAddress = request.headers.get( 'cf-connecting-ip' ) || 'unknown';
	const ipKey = await digest( config.rateLimitSecret + '\0' + ipAddress );
	const ipLimit = await applyRateLimit( env.CREATE_IP_RATE_LIMITER, ipKey, 60, 'transaction_ip' );
	if ( ipLimit ) return ipLimit;
	const burstLimit = await applyRateLimit( env.CREATE_BURST_RATE_LIMITER, 'transaction-create', 60, 'transaction_burst' );
	if ( burstLimit ) return burstLimit;
	let body;
	try {
		body = await readJson( request );
	} catch ( error ) {
		return json( { error: error.message === 'request_too_large' ? error.message : 'invalid_request' }, 400 );
	}
	if ( ! validateCreateBody( body ) ) return json( { error: 'invalid_transaction_request' }, 400 );
	const budget = await reserveDailyBudget( env, config.dailyTransactionLimit );
	if ( ! budget.ok ) {
		const retryAfter = Number( budget.headers.get( 'Retry-After' ) || secondsUntilUtcReset() );
		return limitedResponse( retryAfter, 'daily_transaction_budget' );
	}
	const transactionId = crypto.randomUUID();
	const state = transactionId + '.' + randomToken();
	const consumeSecret = randomToken();
	const expiresAt = Date.now() + TRANSACTION_TTL_MS;
	const response = await transactionStub( env, transactionId ).fetch( 'https://transaction.internal/create', {
		method: 'POST',
		body: JSON.stringify( {
			state,
			secretHash: await digest( consumeSecret ),
			expiresAt,
			maxPolls: config.maxTransactionPolls,
		} ),
	} );
	if ( ! response.ok ) return json( { error: 'transaction_creation_failed' }, 502 );
	return json( {
		transaction_id: transactionId,
		consume_secret: consumeSecret,
		state,
		client_id: config.clientId,
		redirect_uri: config.redirectUri,
		authorization_url: buildAuthorizationUrl( config, state, body.code_challenge ),
		expires_at: new Date( expiresAt ).toISOString(),
	}, 201 );
};

const consumeTransaction = async ( request, env, transactionId ) => {
	const authorization = request.headers.get( 'authorization' ) || '';
	if ( ! authorization.startsWith( 'Bearer ' ) ) return json( { error: 'missing_transaction_secret' }, 401 );
	const pollLimit = await applyRateLimit( env.POLL_RATE_LIMITER, transactionId, 5, 'transaction_poll' );
	if ( pollLimit ) {
		return json( { status: 'pending', retry_after: 5, rate_limited: true }, 200, { 'Retry-After': '5' } );
	}
	const response = await transactionStub( env, transactionId ).fetch( 'https://transaction.internal/consume', {
		method: 'POST',
		headers: { Authorization: authorization },
	} );
	const headers = securityHeaders( 'application/json; charset=utf-8' );
	const retryAfter = response.headers.get( 'Retry-After' );
	if ( retryAfter ) headers['Retry-After'] = retryAfter;
	return new Response( response.body, { status: response.status, headers } );
};

const completionPage = ( completed ) => {
	const nonce = randomToken( 18 );
	const title = completed ? 'Cloudflare authorization received' : 'Cloudflare authorization could not be matched';
	const detail = completed ? 'Return to the Cybermaps Advanced page. This window can now close.' : 'Return to Cybermaps and start a new authorization.';
	const html = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>'
		+ title + '</title><style>body{font:18px/1.5 ui-sans-serif,sans-serif;max-width:42rem;margin:12vh auto;padding:2rem;color:#17202a}main{border:1px solid #d8dee4;border-radius:12px;padding:2rem}h1{font-size:1.5rem}</style><main><h1>'
		+ title + '</h1><p>' + detail + '</p></main><script nonce="' + nonce + '">setTimeout(function(){window.close()},1500)</script></html>';
	return new Response( html, { status: completed ? 200 : 400, headers: securityHeaders( 'text/html; charset=utf-8', nonce ) } );
};

const callback = async ( request, env ) => {
	const url = new URL( request.url );
	const state = url.searchParams.get( 'state' ) || '';
	const transactionId = transactionIdFromState( state );
	if ( ! transactionId ) return completionPage( false );
	const response = await transactionStub( env, transactionId ).fetch( 'https://transaction.internal/callback', {
		method: 'POST',
		body: JSON.stringify( {
			state,
			code: ( url.searchParams.get( 'code' ) || '' ).slice( 0, 4096 ),
			error: ( url.searchParams.get( 'error' ) || '' ).slice( 0, 128 ),
		} ),
	} );
	return completionPage( response.ok );
};

export class OAuthTransaction {
	constructor( state ) {
		this.state = state;
	}

	async fetch( request ) {
		const path = new URL( request.url ).pathname;
		if ( path === '/reserve' && request.method === 'POST' ) return this.reserve( request );
		if ( path === '/create' && request.method === 'POST' ) return this.create( request );
		if ( path === '/callback' && request.method === 'POST' ) return this.callback( request );
		if ( path === '/consume' && request.method === 'POST' ) return this.consume( request );
		return json( { error: 'not_found' }, 404 );
	}

	async create( request ) {
		if ( await this.state.storage.get( 'transaction' ) ) return json( { error: 'already_exists' }, 409 );
		const value = await request.json();
		await this.state.storage.put( 'transaction', { ...value, status: 'pending', pollCount: 0, nextPollAt: 0 } );
		await this.state.storage.setAlarm( value.expiresAt );
		return json( { status: 'created' }, 201 );
	}

	async reserve( request ) {
		const value = await request.json();
		const limit = positiveInteger( value.limit, DEFAULT_DAILY_TRANSACTION_LIMIT, 10000 );
		const resetAt = Number( value.resetAt || 0 );
		const budget = await this.state.storage.get( 'budget' ) || { count: 0, alerted: false };
		if ( budget.count >= limit ) {
			return limitedResponse( secondsUntilUtcReset(), 'daily_transaction_budget' );
		}
		budget.count++;
		if ( ! budget.alerted && budget.count >= Math.ceil( limit * 0.8 ) ) {
			budget.alerted = true;
			console.warn( JSON.stringify( { event: 'daily_budget_threshold', count: budget.count, limit } ) );
		}
		await this.state.storage.put( 'budget', budget );
		if ( Number.isFinite( resetAt ) && resetAt > Date.now() ) await this.state.storage.setAlarm( resetAt );
		return json( { status: 'reserved', count: budget.count, limit } );
	}

	async callback( request ) {
		const current = await this.state.storage.get( 'transaction' );
		if ( ! current || current.expiresAt <= Date.now() || current.status !== 'pending' ) return json( { error: 'expired_or_consumed' }, 410 );
		const result = await request.json();
		if ( ! equal( current.state, result.state ) ) return json( { error: 'state_mismatch' }, 400 );
		if ( result.error || ! result.code ) current.status = 'denied';
		else {
			current.status = 'authorized';
			current.code = result.code;
		}
		await this.state.storage.put( 'transaction', current );
		return json( { status: current.status } );
	}

	async consume( request ) {
		const current = await this.state.storage.get( 'transaction' );
		if ( ! current || current.expiresAt <= Date.now() ) return json( { status: 'expired' } );
		const supplied = ( request.headers.get( 'authorization' ) || '' ).replace( /^Bearer\s+/i, '' );
		if ( ! equal( current.secretHash, await digest( supplied ) ) ) return json( { error: 'invalid_transaction_secret' }, 403 );
		if ( current.status === 'pending' ) {
			if ( current.pollCount >= current.maxPolls ) {
				await this.state.storage.deleteAll();
				return json( { status: 'expired' } );
			}
			const remaining = Math.ceil( ( current.nextPollAt - Date.now() ) / 1000 );
			if ( remaining > 0 ) return json( { status: 'pending', retry_after: remaining }, 200, { 'Retry-After': String( remaining ) } );
			const retryAfter = pollDelaySeconds( current.pollCount );
			current.pollCount++;
			current.nextPollAt = Date.now() + retryAfter * 1000;
			await this.state.storage.put( 'transaction', current );
			return json( { status: 'pending', retry_after: retryAfter }, 200, { 'Retry-After': String( retryAfter ) } );
		}
		const result = current.status === 'authorized'
			? { status: 'authorized', state: current.state, code: current.code }
			: { status: 'denied', state: current.state };
		await this.state.storage.deleteAll();
		return json( result );
	}

	async alarm() {
		await this.state.storage.deleteAll();
	}
}

export default {
	async fetch( request, env ) {
		const url = new URL( request.url );
		if ( request.method === 'GET' && url.pathname === '/health' ) {
			return json( {
				status: configuration( env ) ? 'ok' : 'degraded',
				protocol_version: 1,
				controls: [ 'adaptive_polling', 'daily_budget', 'per_ip_rate_limit', 'per_transaction_poll_limit', 'relay_burst_limit' ],
			} );
		}
		const statefulRequest = request.method === 'POST'
			|| ( request.method === 'GET' && url.pathname === '/cloudflare/callback' );
		if ( statefulRequest ) {
			const config = configuration( env );
			if ( ! config ) return json( { error: 'service_not_configured' }, 503 );
			const ipAddress = request.headers.get( 'cf-connecting-ip' ) || 'unknown';
			const ipKey = await digest( config.rateLimitSecret + '\0' + ipAddress );
			const ipLimit = await applyRateLimit( env.RELAY_IP_RATE_LIMITER, ipKey, 10, 'relay_ip' );
			if ( ipLimit ) return ipLimit;
			const burstLimit = await applyRateLimit( env.RELAY_BURST_RATE_LIMITER, 'relay-stateful', 10, 'relay_burst' );
			if ( burstLimit ) return burstLimit;
		}
		if ( request.method === 'POST' && url.pathname === '/v1/cloudflare/transactions' ) return createTransaction( request, env );
		const consume = url.pathname.match( /^\/v1\/cloudflare\/transactions\/([a-f0-9-]{36})\/consume$/i );
		if ( request.method === 'POST' && consume ) return consumeTransaction( request, env, consume[1] );
		if ( request.method === 'GET' && url.pathname === '/cloudflare/callback' ) return callback( request, env );
		return json( { error: 'not_found' }, 404 );
	},
};
