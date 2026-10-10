/**
 * RR-13 rev 4: the captcha lifecycle of reviews.js and collect.js, run for
 * real in a node sandbox (stub DOM, provider widgets, fetch, fake timers).
 *
 *     node .agents/qa/rr-13-js.mjs      (also run by rr-13.php)
 *
 * Prints PASS:/FAIL: lines and a RESULT line; exits 1 on any failure.
 * QA script, never shipped.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const root  = join( dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const READY = ( /const READY_JS = '([^']*)';/.exec( readFileSync( join( root, 'includes/Forms/AntiSpam.php' ), 'utf8' ) ) || [] )[ 1 ] || '';
const WAIT  = 'Please complete the verification, then submit again.';

let pass = 0;
let fail = 0;
function ok( cond, msg ) {
	if ( cond ) {
		pass++;
	} else {
		fail++;
	}
	console.log( ( cond ? 'PASS: ' : 'FAIL: ' ) + msg );
}

// ── Stubs ────────────────────────────────────────────────────────────────
function el( tag, opts = {} ) {
	const e = {
		tagName: tag,
		attrs: Object.assign( {}, opts.attrs || {} ),
		listeners: {},
		children: [],
		value: opts.value || '',
		textContent: '',
		className: '',
		disabled: false,
		style: {},
		parentNode: null,
		q: opts.q || {},
		qa: opts.qa || {},
		classList: {
			set: new Set(),
			add( c ) { this.set.add( c ); },
			remove( c ) { this.set.delete( c ); },
			toggle( c, on ) { ( on === undefined ? ! this.set.has( c ) : on ) ? this.set.add( c ) : this.set.delete( c ); },
			contains( c ) { return this.set.has( c ); },
		},
		getAttribute( n ) { return n in this.attrs ? this.attrs[ n ] : null; },
		setAttribute( n, v ) { this.attrs[ n ] = String( v ); },
		addEventListener( t, fn ) { ( this.listeners[ t ] = this.listeners[ t ] || [] ).push( fn ); },
		dispatch( t, extra = {} ) {
			const ev = Object.assign( { type: t, preventDefault() {} }, extra );
			( this.listeners[ t ] || [] ).forEach( ( fn ) => fn( ev ) );
		},
		querySelector( s ) { return this.q[ s ] || null; },
		querySelectorAll( s ) { return this.qa[ s ] || []; },
		contains() { return false; },
		appendChild( c ) { this.children.push( c ); },
		insertBefore() {},
		reset() { this.dispatch( 'reset' ); },
	};
	return e;
}

/**
 * A sandbox: window === the global, fake timers, fetch and FormData stubs.
 */
function sandbox( extra = {} ) {
	let now      = 0;
	const timers = [];
	const ctx    = {
		console,
		fetches: [],
		created: [],
		reply: { success: true, data: { message: 'Thanks' } },
		setTimeout( fn, ms ) {
			timers.push( { at: now + ( ms || 0 ), fn } );
			return timers.length;
		},
		clearTimeout( id ) {
			if ( timers[ id - 1 ] ) {
				timers[ id - 1 ].fn = null;
			}
		},
		advance( ms ) {
			now += ms;
			timers.filter( ( t ) => t.fn && t.at <= now ).forEach( ( t ) => {
				const fn = t.fn;
				t.fn     = null;
				fn();
			} );
		},
		listeners: {},
		addEventListener( t, fn ) { ( ctx.listeners[ t ] = ctx.listeners[ t ] || [] ).push( fn ); },
		fire( t ) { ( ctx.listeners[ t ] || [] ).forEach( ( fn ) => fn( { type: t } ) ); },
	};
	ctx.window   = ctx;
	ctx.FormData = class {
		constructor() { this.d = new Map(); }
		append( k, v ) { this.d.set( k, v ); }
		get( k ) { return this.d.has( k ) ? this.d.get( k ) : null; }
	};
	ctx.fetch    = ( url, init ) => {
		ctx.fetches.push( { url, body: init && init.body, token: ctx.tokenInput ? ctx.tokenInput.value : undefined } );
		return Promise.resolve( { json: () => Promise.resolve( ctx.reply ) } );
	};
	Object.assign( ctx, extra );
	vm.createContext( ctx );
	return ctx;
}

async function flush( n = 8 ) {
	for ( let i = 0; i < n; i++ ) {
		await new Promise( ( r ) => setImmediate( r ) );
	}
}

function turnstileStub( renderReturns = 'w1' ) {
	const t = {
		renders: [],
		resets: [],
		response: '',
		render( node, opts ) { t.renders.push( { node, opts } ); return 'function' === typeof renderReturns ? renderReturns( t.renders.length ) : renderReturns; },
		reset( id ) { t.resets.push( id ); },
		getResponse() { return t.response; },
	};
	return t;
}

// ── reviews.js ───────────────────────────────────────────────────────────
function productPage( file, { provider = 'turnstile', cfg = null, rating = true, tokenName = 'ndvr_captcha_token', ready = true, before = '', widgets = {} } = {} ) {
	const token  = el( 'input', { attrs: { name: tokenName } } );
	const widget = el( 'div', { attrs: { 'data-sitekey': 'k' } } );
	const btn    = el( 'button', { attrs: { type: 'submit' } } );
	const msg    = el( 'div' );
	const q      = {
		'.ndvr-form-message': msg,
		'input[name="ndvr_captcha_token"], input[name="ndvr_recaptcha_token"]': token,
		'input[name^="ndvr_criteria"]:checked': rating ? el( 'input', { value: '5' } ) : null,
	};
	if ( 'turnstile' === provider || 'hcaptcha' === provider ) {
		q[ '.ndvr-captcha' ] = widget;
	}
	const form = el( 'form', { q, qa: { '[type="submit"]': [ btn ] } } );
	const ctx  = sandbox( Object.assign( { tokenInput: token }, widgets ) );
	ctx.document = {
		getElementById: ( id ) => ( 'ndvr-review-form' === id ? form : null ),
		createElement: ( tag ) => { const c = el( tag ); ctx.created.push( c ); return c; },
		head: el( 'head' ),
		querySelectorAll: () => [],
	};
	ctx.ndvrReviews = cfg || {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		action: 'ndvr_submit_review',
		captcha: { provider, siteKey: 'k' },
		i18n: { captcha: WAIT, rating: 'Rate it', error: 'Err', submitting: '…', thanks: 'Thanks' },
	};
	if ( ready ) {
		ctx.ndvrCaptchaLoaded = true;
	}
	if ( before ) {
		vm.runInContext( before, ctx );
	}
	vm.runInContext( readFileSync( join( root, 'assets/js', file ), 'utf8' ), ctx );
	return { ctx, form, msg, token, btn };
}

async function reviewsCases( file ) {
	// Turnstile: an early submit waits for the callback, then sends once.
	{
		const t                  = turnstileStub();
		const { ctx, form, msg } = productPage( file, { widgets: { turnstile: t } } );
		form.dispatch( 'submit' );
		await flush();
		const before = ctx.fetches.length;
		t.renders[ 0 ].opts.callback( 'tok1' );
		await flush();
		ok( 1 === t.renders.length && 0 === before && 1 === ctx.fetches.length && 'tok1' === ctx.fetches[ 0 ].token && 1 === t.resets.length && 'w1' === t.resets[ 0 ] && 'Thanks' === msg.textContent, `${ file } M1: Turnstile submit before the challenge ends sends nothing, then exactly one request with the token; reset once after the reply` );
	}
	// Turnstile: no token within 15 s → wait message, nothing sent, no reset.
	{
		const t                       = turnstileStub();
		const { ctx, form, msg, btn } = productPage( file, { widgets: { turnstile: t } } );
		form.dispatch( 'submit' );
		await flush();
		const busy = btn.disabled;
		ctx.advance( 14999 );
		await flush();
		const early = msg.textContent;
		ctx.advance( 1 );
		await flush();
		ok( busy && WAIT !== early && 0 === ctx.fetches.length && WAIT === msg.textContent && 0 === t.resets.length && false === btn.disabled, `${ file } M1: no token after 15 s → the wait message, no request, no reset, the button is enabled again` );
	}
	// Double submit with a token ready → one request.
	{
		const t             = turnstileStub();
		const { ctx, form } = productPage( file, { widgets: { turnstile: t } } );
		t.renders[ 0 ].opts.callback( 'tok2' );
		form.dispatch( 'submit' );
		form.dispatch( 'submit' );
		await flush();
		ok( 1 === ctx.fetches.length, `${ file } m1: two quick submits send one request` );
	}
	// Rating check first: no star → no captcha work.
	{
		let executed             = 0;
		const h                  = { render: () => 'h1', execute: () => { executed++; return Promise.resolve( { response: 'x' } ); }, reset() {} };
		const { ctx, form, msg } = productPage( file, { provider: 'hcaptcha', rating: false, widgets: { hcaptcha: h } } );
		form.dispatch( 'submit' );
		await flush();
		ok( 0 === executed && 0 === ctx.fetches.length && 'Rate it' === msg.textContent, `${ file } m2: without a star rating hCaptcha is never executed and nothing is sent` );
	}
	// hCaptcha rejection → no request.
	{
		const h                  = { render: () => 'h1', execute: () => Promise.reject( new Error( 'challenge-closed' ) ), reset() {} };
		const { ctx, form, msg } = productPage( file, { provider: 'hcaptcha', widgets: { hcaptcha: h } } );
		form.dispatch( 'submit' );
		await flush();
		ok( 0 === ctx.fetches.length && WAIT === msg.textContent, `${ file } m2: a rejected hCaptcha execute() sends nothing and shows the wait message` );
	}
	// hCaptcha success → token sent.
	{
		const h             = { render: () => 'h1', execute: () => Promise.resolve( { response: 'htok' } ), reset() {} };
		const { ctx, form } = productPage( file, { provider: 'hcaptcha', widgets: { hcaptcha: h } } );
		form.dispatch( 'submit' );
		await flush();
		ok( 1 === ctx.fetches.length && 'htok' === ctx.fetches[ 0 ].token, `${ file }: hCaptcha execute() → one request with its token` );
	}
	// A failed render (undefined) is never reset.
	{
		const t             = turnstileStub( undefined );
		const { ctx, form } = productPage( file, { widgets: { turnstile: t } } );
		form.dispatch( 'submit' );
		await flush();
		ok( 0 === ctx.fetches.length && ! t.resets.some( ( id ) => undefined === id ), `${ file } m9: a render returning undefined isn't a widget: nothing sent, reset never gets undefined` );
	}
	// Onload queue: no render before the API is ready, one after; load adds none.
	{
		const t       = turnstileStub();
		const { ctx } = productPage( file, { ready: false, before: READY, widgets: { turnstile: t } } );
		const pre     = t.renders.length;
		ctx.ndvrCaptchaReady();
		const mid = t.renders.length;
		ctx.fire( 'load' );
		ctx.advance( 0 );
		ok( '' !== READY && 0 === pre && 1 === mid && 1 === t.renders.length, `${ file } M3: the widget renders from ndvrCaptchaReady (0 before, 1 after) and the load fallback adds none` );
	}
	// Load fallback when the callback never runs.
	{
		const t       = turnstileStub();
		const { ctx } = productPage( file, { ready: false, widgets: { turnstile: t } } );
		ctx.fire( 'load' );
		ctx.advance( 0 );
		ok( 1 === t.renders.length, `${ file } M3: without the callback, the load fallback renders once` );
	}
	// Cached page: only the old siteKey and the old input.
	{
		const calls = [];
		const g     = { ready: ( cb ) => cb(), execute: ( key, opts ) => { calls.push( [ key, opts.action ] ); return Promise.resolve( 'rtok' ); } };
		const { ctx, form } = productPage( file, {
			provider: 'none',
			tokenName: 'ndvr_recaptcha_token',
			cfg: { ajaxUrl: '/a', action: 'ndvr_submit_review', siteKey: 'old-key', i18n: {} },
			widgets: { grecaptcha: g },
		} );
		form.dispatch( 'submit' );
		await flush();
		ok( 1 === ctx.fetches.length && 'rtok' === ctx.fetches[ 0 ].token && 'old-key' === ( calls[ 0 ] || [] )[ 0 ] && 'review' === ( calls[ 0 ] || [] )[ 1 ], `${ file } M2: a page cached before RR-13 (siteKey only) gets a reCAPTCHA token into ndvr_recaptcha_token` );
	}
	// reCAPTCHA ready() that never fires → timeout, nothing sent.
	{
		const g                  = { ready() {}, execute: () => Promise.resolve( 'never' ) };
		const { ctx, form, msg } = productPage( file, { provider: 'recaptcha', widgets: { grecaptcha: g } } );
		form.dispatch( 'submit' );
		await flush();
		ctx.advance( 15000 );
		await flush();
		ok( 0 === ctx.fetches.length && WAIT === msg.textContent, `${ file } m9: a reCAPTCHA ready() that never fires times out after 15 s without a request` );
	}
	// No provider → sent straight away.
	{
		const { ctx, form } = productPage( file, { provider: 'none' } );
		form.dispatch( 'submit' );
		await flush();
		ok( 1 === ctx.fetches.length, `${ file }: with no captcha the review is sent at once` );
	}
}

// ── collect.js ───────────────────────────────────────────────────────────
function collectPage( file, { attrs = {}, forms = 1, widgets = {}, ready = true } = {} ) {
	const list = [];
	for ( let i = 0; i < forms; i++ ) {
		const q = {
			'.ndvr-form-message': el( 'div' ),
			'.ndvr-collect-submit': el( 'button' ),
		};
		if ( 'turnstile' === attrs[ 'data-captcha-provider' ] || 'hcaptcha' === attrs[ 'data-captcha-provider' ] ) {
			q[ '.ndvr-captcha' ] = el( 'div', { attrs: { 'data-sitekey': 'k' } } );
		}
		list.push( el( 'form', { q } ) );
	}
	const rootEl = el( 'div', {
		attrs: Object.assign( { 'data-ajax-url': '/a', 'data-action': 'ndvr_testimonial_submit' }, attrs ),
		qa: { '.ndvr-collect-form': list },
	} );
	const ctx = sandbox( widgets );
	ctx.document = {
		querySelectorAll: ( s ) => ( '.ndvr-collect' === s ? [ rootEl ] : [] ),
		createElement: ( tag ) => { const c = el( tag ); ctx.created.push( c ); return c; },
		head: el( 'head' ),
		getElementById: () => null,
	};
	if ( ready ) {
		ctx.ndvrCaptchaLoaded = true;
	}
	vm.runInContext( readFileSync( join( root, 'assets/js', file ), 'utf8' ), ctx );
	return { ctx, forms: list };
}

async function collectCases( file ) {
	// Legacy cached markup: reCAPTCHA is loaded once, then used.
	{
		const { ctx, forms } = collectPage( file, { attrs: { 'data-recaptcha-key': 'old' }, forms: 2 } );
		forms[ 0 ].dispatch( 'submit' );
		forms[ 1 ].dispatch( 'submit' );
		await flush();
		const scripts = ctx.created.filter( ( c ) => 'script' === c.tagName );
		ctx.grecaptcha = { ready: ( cb ) => cb(), execute: () => Promise.resolve( 'ctok' ) };
		( scripts[ 0 ] && scripts[ 0 ].onload ) && scripts[ 0 ].onload();
		await flush();
		ok( 1 === scripts.length && /render=old$/.test( scripts[ 0 ].src || '' ) && 2 === ctx.fetches.length && 'ctok' === ctx.fetches[ 0 ].body.get( 'ndvr_captcha_token' ), `${ file } M2: a root cached before RR-13 loads reCAPTCHA once for both forms, then sends each with a token` );
	}
	// Current markup: never adds a script.
	{
		const g              = { ready: ( cb ) => cb(), execute: () => Promise.resolve( 'cur' ) };
		const { ctx, forms } = collectPage( file, { attrs: { 'data-captcha-provider': 'recaptcha', 'data-captcha-key': 'k', 'data-recaptcha-key': 'k', 'data-captcha-message': 'Wait!' }, widgets: { grecaptcha: g } } );
		forms[ 0 ].dispatch( 'submit' );
		await flush();
		ok( 0 === ctx.created.filter( ( c ) => 'script' === c.tagName ).length && 1 === ctx.fetches.length && 'cur' === ctx.fetches[ 0 ].body.get( 'ndvr_captcha_token' ), `${ file } AC2: current markup adds no <script> and sends the reCAPTCHA token` );
	}
	// Turnstile, two forms: each waits for its own callback.
	{
		const t              = turnstileStub( ( n ) => 'w' + n );
		const { ctx, forms } = collectPage( file, { attrs: { 'data-captcha-provider': 'turnstile', 'data-captcha-key': 'k', 'data-captcha-message': 'Wait!' }, forms: 2, widgets: { turnstile: t } } );
		forms[ 0 ].dispatch( 'submit' );
		await flush();
		t.renders[ 1 ].opts.callback( 'second' );
		await flush();
		const afterOther = ctx.fetches.length;
		t.renders[ 0 ].opts.callback( 'first' );
		await flush();
		ok( 2 === t.renders.length && 0 === afterOther && 1 === ctx.fetches.length && 'first' === ctx.fetches[ 0 ].body.get( 'ndvr_captcha_token' ) && 'w1' === t.resets[ 0 ], `${ file } M1: with two forms, a submit waits for its own widget's token (the other form's doesn't release it)` );
	}
	// Turnstile timeout → the root's message, no request.
	{
		const t              = turnstileStub();
		const { ctx, forms } = collectPage( file, { attrs: { 'data-captcha-provider': 'turnstile', 'data-captcha-key': 'k', 'data-captcha-message': 'Wait!' }, widgets: { turnstile: t } } );
		forms[ 0 ].dispatch( 'submit' );
		await flush();
		ctx.advance( 15000 );
		await flush();
		const msg = forms[ 0 ].q[ '.ndvr-form-message' ];
		const btn = forms[ 0 ].q[ '.ndvr-collect-submit' ];
		ok( 0 === ctx.fetches.length && 'Wait!' === msg.textContent && false === btn.disabled && 0 === t.resets.length, `${ file } M1: no token after 15 s → data-captcha-message, no request, no reset, button enabled` );
	}
	// Double submit → one request.
	{
		const t              = turnstileStub();
		const { ctx, forms } = collectPage( file, { attrs: { 'data-captcha-provider': 'turnstile', 'data-captcha-key': 'k' }, widgets: { turnstile: t } } );
		t.renders[ 0 ].opts.callback( 'tok' );
		forms[ 0 ].dispatch( 'submit' );
		forms[ 0 ].dispatch( 'submit' );
		await flush();
		ok( 1 === ctx.fetches.length, `${ file } m1: two quick submits send one request` );
	}
	// Landing page (no provider): sent at once, no token.
	{
		const { ctx, forms } = collectPage( file );
		forms[ 0 ].dispatch( 'submit' );
		await flush();
		ok( 1 === ctx.fetches.length && null === ctx.fetches[ 0 ].body.get( 'ndvr_captcha_token' ), `${ file }: the landing page (no provider) sends at once without a token` );
	}
	// Onload queue.
	{
		const t       = turnstileStub();
		const { ctx } = collectPage( file, { attrs: { 'data-captcha-provider': 'turnstile', 'data-captcha-key': 'k' }, widgets: { turnstile: t }, ready: false } );
		const before  = t.renders.length;
		vm.runInContext( READY, ctx );
		ctx.ndvrCaptchaReady();
		ctx.fire( 'load' );
		ctx.advance( 0 );
		ok( 0 === before && 1 === t.renders.length, `${ file } M3: forms render from the queue (0 before, 1 after) and the load fallback adds none` );
	}
}

for ( const f of [ 'reviews.js', 'reviews.min.js' ] ) {
	await reviewsCases( f );
}
for ( const f of [ 'collect.js', 'collect.min.js' ] ) {
	await collectCases( f );
}
console.log( `RESULT: ${ fail ? 'FAIL' : 'PASS' } (${ pass } passed, ${ fail } failed)` );
process.exit( fail ? 1 : 0 );
