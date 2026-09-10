import assert from 'node:assert/strict';
import test from 'node:test';
import {
	buildAuthorizationUrl,
	pollDelaySeconds,
	positiveInteger,
	secondsUntilUtcReset,
	securityHeaders,
	transactionIdFromState,
	validateCreateBody,
} from '../src/index.js';

test( 'authorization URL is fixed to Cloudflare and carries PKCE', () => {
	const url = new URL( buildAuthorizationUrl( {
		clientId: 'public-client-id',
		redirectUri: 'https://connect.cybermaps.dev/cloudflare/callback',
		scopes: 'zone.read zone-transform-rules.write cache-settings.write',
	}, '11111111-1111-4111-8111-111111111111.' + 's'.repeat( 43 ), 'c'.repeat( 43 ) ) );
	assert.equal( url.origin, 'https://dash.cloudflare.com' );
	assert.equal( url.searchParams.get( 'code_challenge_method' ), 'S256' );
} );

test( 'polling backs off and remains bounded at ten seconds', () => {
	assert.deepEqual( [ 0, 1, 2, 3, 4, 12 ].map( pollDelaySeconds ), [ 2, 3, 5, 8, 10, 10 ] );
} );

test( 'deployment limits reject unbounded configuration', () => {
	assert.equal( positiveInteger( '1500', 100, 10000 ), 1500 );
	assert.equal( positiveInteger( '10001', 100, 10000 ), 100 );
	assert.equal( positiveInteger( '-1', 100, 10000 ), 100 );
} );

test( 'daily budget retry targets the next UTC boundary', () => {
	assert.equal( secondsUntilUtcReset( Date.UTC( 2026, 7, 31, 23, 59, 30 ) ), 30 );
} );

test( 'transaction validation requires protocol one and S256', () => {
	assert.equal( validateCreateBody( { protocol_version: 1, code_challenge_method: 'S256', code_challenge: 'a'.repeat( 43 ) } ), true );
	assert.equal( validateCreateBody( { protocol_version: 1, code_challenge_method: 'plain', code_challenge: 'a'.repeat( 43 ) } ), false );
} );

test( 'state parsing rejects unbounded callback input', () => {
	const id = '11111111-1111-4111-8111-111111111111';
	assert.equal( transactionIdFromState( id + '.' + 'a'.repeat( 43 ) ), id );
	assert.equal( transactionIdFromState( id + '.short' ), '' );
} );

test( 'security headers disable storage, framing, and CORS', () => {
	const headers = securityHeaders( 'application/json' );
	assert.equal( headers['Cache-Control'], 'no-store, max-age=0' );
	assert.equal( headers['X-Frame-Options'], 'DENY' );
	assert.equal( headers['Access-Control-Allow-Origin'], undefined );
} );
