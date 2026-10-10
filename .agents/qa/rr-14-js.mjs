/**
 * RR-14: the report handlers of display.js (source and .min), run for real
 * in a node sandbox: a stub DOM, fetch and FormData.
 *
 *     node .agents/qa/rr-14-js.mjs      (also run by rr-14.php)
 *
 * Prints PASS:/FAIL: lines and a RESULT line; exits 1 on any failure.
 * QA script, never shipped.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
let pass   = 0;
let fail   = 0;
function ok( cond, msg ) {
	cond ? pass++ : fail++;
	console.log( ( cond ? 'PASS: ' : 'FAIL: ' ) + msg );
}

function el( tag, { cls = [], attrs = {}, parent = null } = {} ) {
	const e = {
		tagName: tag.toUpperCase(),
		classes: new Set( cls ),
		attrs: Object.assign( {}, attrs ),
		parentNode: parent,
		hidden: false,
		disabled: false,
		textContent: '',
		focused: 0,
		resets: 0,
		children: [],
		classList: null,
		get id() { return this.attrs.id || ''; },
		getAttribute( n ) { return n in this.attrs ? this.attrs[ n ] : null; },
		setAttribute( n, v ) { this.attrs[ n ] = String( v ); },
		matches( sel ) {
			return sel.split( ',' ).map( ( s ) => s.trim() ).some( ( s ) => {
				if ( s.startsWith( '.' ) ) {
					return this.classes.has( s.slice( 1 ) );
				}
				const a = /^\[([\w-]+)\]$/.exec( s );
				if ( a ) {
					return a[ 1 ] in this.attrs;
				}
				return s.toUpperCase() === this.tagName;
			} );
		},
		closest( sel ) {
			let n = this;
			while ( n ) {
				if ( n.matches && n.matches( sel ) ) {
					return n;
				}
				n = n.parentNode;
			}
			return null;
		},
		querySelector( sel ) {
			const stack = [ ...this.children ];
			while ( stack.length ) {
				const c = stack.shift();
				if ( sel.split( ',' ).some( ( s ) => c.matches( s.trim() ) ) ) {
					return c;
				}
				stack.push( ...c.children );
			}
			return null;
		},
		querySelectorAll() { return []; },
		appendChild( c ) { c.parentNode = this; this.children.push( c ); return c; },
		focus() { this.focused++; },
		reset() { this.resets++; },
	};
	e.classList = {
		add: ( c ) => e.classes.add( c ),
		remove: ( c ) => e.classes.delete( c ),
		contains: ( c ) => e.classes.has( c ),
		toggle: ( c, on ) => ( ( on === undefined ? ! e.classes.has( c ) : on ) ? e.classes.add( c ) : e.classes.delete( c ) ),
	};
	if ( parent ) {
		parent.appendChild( e );
	}
	return e;
}

/**
 * A card footer: button, form (select, textarea, send, cancel) and status.
 */
function page( file, replies ) {
	const card   = el( 'div', { cls: [ 'ndvr-review' ] } );
	const foot   = el( 'div', { cls: [ 'ndvr-review-foot' ], parent: card } );
	const base   = 'ndvr-report-r1-42';
	const btn    = el( 'button', { cls: [ 'ndvr-report' ], attrs: { 'aria-expanded': 'false', 'aria-controls': base }, parent: foot } );
	const form   = el( 'form', { cls: [ 'ndvr-report-form' ], attrs: { id: base, 'data-comment-id': '42' }, parent: foot } );
	form.hidden  = true;
	const select = el( 'select', { attrs: { id: base + '-reason' }, parent: form } );
	el( 'textarea', { attrs: { id: base + '-note' }, parent: form } );
	const send   = el( 'button', { cls: [ 'ndvr-report-send' ], attrs: { type: 'submit' }, parent: form } );
	const cancel = el( 'button', { cls: [ 'ndvr-report-cancel' ], attrs: { type: 'button' }, parent: form } );
	const status = el( 'span', { cls: [ 'ndvr-report-status' ], attrs: { id: base + '-status', role: 'status' }, parent: foot } );
	const byId   = { [ base ]: form, [ base + '-status' ]: status };

	const listeners = {};
	const ctx       = {
		fetches: [],
		console,
		setTimeout: ( fn ) => fn(),
		ndvrDisplay: {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			action: 'ndvr_list_reviews',
			nonce: 'n',
			voteAction: 'ndvr_vote',
			reportAction: 'ndvr_report_review',
			reportNonce: 'rn',
			i18n: { reportSent: 'Sent!', reportDuplicate: 'Dup!', reportLimited: 'Limit!', reportError: 'Err!' },
		},
	};
	ctx.window   = ctx;
	ctx.FormData = class {
		constructor( f ) { this.d = new Map(); this.form = f || null; }
		append( k, v ) { this.d.set( k, v ); }
		get( k ) { return this.d.has( k ) ? this.d.get( k ) : null; }
	};
	ctx.fetch    = ( url, init ) => {
		ctx.fetches.push( { url, body: init.body } );
		const r = replies.shift() || { code: 200, body: { success: true } };
		if ( r.network ) {
			return Promise.reject( new Error( 'offline' ) );
		}
		return Promise.resolve( { status: r.code, json: () => Promise.resolve( r.body ) } );
	};
	ctx.document = {
		addEventListener: ( t, fn ) => ( listeners[ t ] = listeners[ t ] || [] ).push( fn ),
		getElementById: ( id ) => byId[ id ] || null,
		querySelector: ( sel ) => {
			const m = /^\[aria-controls="([^"]+)"\]$/.exec( sel );
			return m && m[ 1 ] === base ? btn : null;
		},
		querySelectorAll: () => [],
		createElement: ( t ) => el( t ),
		body: el( 'body' ),
	};
	vm.createContext( ctx );
	vm.runInContext( readFileSync( join( root, 'assets/js', file ), 'utf8' ), ctx );
	const fire = ( type, target, extra = {} ) => {
		const ev = Object.assign( { type, target, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } }, extra );
		( listeners[ type ] || [] ).forEach( ( fn ) => fn( ev ) );
		return ev;
	};
	return { ctx, btn, form, select, send, cancel, status, fire };
}

const flush = async () => {
	for ( let i = 0; i < 6; i++ ) {
		await new Promise( ( r ) => setImmediate( r ) );
	}
};

for ( const file of [ 'display.js', 'display.min.js' ] ) {
	{
		const p = page( file, [] );
		p.fire( 'click', p.btn );
		const opened = ! p.form.hidden && 'true' === p.btn.getAttribute( 'aria-expanded' ) && 1 === p.select.focused;
		p.fire( 'click', p.btn );
		ok( opened && p.form.hidden && 'false' === p.btn.getAttribute( 'aria-expanded' ) && 1 === p.btn.focused, `${ file }: the button opens its form (aria-expanded, focus on the reason) and closes it again (focus back)` );
	}
	{
		const p = page( file, [] );
		p.fire( 'click', p.btn );
		const ev = p.fire( 'keydown', p.select, { key: 'Escape' } );
		const esc = p.form.hidden && 'false' === p.btn.getAttribute( 'aria-expanded' ) && 1 === p.btn.focused && ev.defaultPrevented;
		p.fire( 'click', p.btn );
		p.fire( 'click', p.cancel );
		ok( esc && p.form.hidden && 2 === p.btn.focused, `${ file }: Escape and Cancel close the form and return focus to the button` );
	}
	{
		const p  = page( file, [ { code: 200, body: { success: true, data: { message: 'x' } } } ] );
		p.fire( 'click', p.btn );
		const ev = p.fire( 'submit', p.form );
		p.fire( 'submit', p.form );
		const busy = p.send.disabled;
		await flush();
		const f = p.ctx.fetches[ 0 ] || { body: new p.ctx.FormData() };
		ok( ev.defaultPrevented && 1 === p.ctx.fetches.length && busy && 'ndvr_report_review' === f.body.get( 'action' ) && 'rn' === f.body.get( 'nonce' ) && '42' === f.body.get( 'comment_id' ) && f.body.form === p.form, `${ file }: submit posts once (busy guard) with action, nonce, comment id and the form fields` );
		ok( 'Sent!' === p.status.textContent && p.form.hidden && 1 === p.form.resets && false === p.send.disabled && p.btn.focused >= 1, `${ file }: success → "${ p.status.textContent }", the form resets and closes, focus returns to the button` );
	}
	{
		const p = page( file, [ { code: 409, body: { success: false, data: { message: 'dup' } } } ] );
		p.fire( 'click', p.btn );
		p.fire( 'submit', p.form );
		await flush();
		ok( 'Dup!' === p.status.textContent && p.form.hidden, `${ file }: 409 → the duplicate message and the form closes` );
	}
	{
		const p = page( file, [ { code: 429, body: { success: false, data: { message: 'lim' } } } ] );
		p.fire( 'click', p.btn );
		p.fire( 'submit', p.form );
		await flush();
		ok( 'Limit!' === p.status.textContent && ! p.form.hidden && false === p.send.disabled, `${ file }: 429 → the limit message; the form stays open and can be sent again` );
	}
	{
		const p = page( file, [ { code: 404, body: { success: false, data: { message: 'Review not found.' } } }, { network: true } ] );
		p.fire( 'click', p.btn );
		p.fire( 'submit', p.form );
		await flush();
		const server = p.status.textContent;
		p.fire( 'submit', p.form );
		await flush();
		ok( 'Review not found.' === server && 'Err!' === p.status.textContent && ! p.form.hidden && 2 === p.ctx.fetches.length, `${ file }: other errors show the server's message; a network error shows the generic one; the form stays open` );
	}
}

console.log( `RESULT: ${ fail ? 'FAIL' : 'PASS' } (${ pass } passed, ${ fail } failed)` );
process.exit( fail ? 1 : 0 );
