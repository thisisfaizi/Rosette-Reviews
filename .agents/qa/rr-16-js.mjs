/**
 * RR-16: the list and search code of display.js (source and .min), run in a
 * node sandbox with a stub DOM, fetch and FormData (code review gap 1: M1 shipped
 * with every PHP suite green).
 *
 *     node .agents/qa/rr-16-js.mjs      (also run by rr-16.php)
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

// A compound selector: tag, #id, .classes, [attr] and [attr="value"].
function compound( el, sel ) {
	const re = /^([a-z]+)?((?:#[\w-]+|\.[\w-]+|\[[\w-]+(?:="[^"]*")?\])*)$/i;
	const m  = re.exec( sel.trim() );
	if ( ! m ) {
		return false;
	}
	if ( m[ 1 ] && m[ 1 ].toUpperCase() !== el.tagName ) {
		return false;
	}
	const parts = m[ 2 ].match( /#[\w-]+|\.[\w-]+|\[[\w-]+(?:="[^"]*")?\]/g ) || [];
	return parts.every( ( p ) => {
		if ( '#' === p[ 0 ] ) {
			return el.attrs.id === p.slice( 1 );
		}
		if ( '.' === p[ 0 ] ) {
			return el.classes.has( p.slice( 1 ) );
		}
		const a = /^\[([\w-]+)(?:="([^"]*)")?\]$/.exec( p );
		return a[ 2 ] === undefined ? a[ 1 ] in el.attrs : el.attrs[ a[ 1 ] ] === a[ 2 ];
	} );
}

// One selector, with descendant combinators ("a b").
function matchSel( el, sel ) {
	const steps = sel.trim().split( /\s+/ );
	if ( ! compound( el, steps.pop() ) ) {
		return false;
	}
	let n = el.parentNode;
	while ( steps.length && n ) {
		if ( compound( n, steps[ steps.length - 1 ] ) ) {
			steps.pop();
		}
		n = n.parentNode;
	}
	return 0 === steps.length;
}

function el( tag, { cls = [], attrs = {}, parent = null } = {} ) {
	const e = {
		tagName: tag.toUpperCase(),
		classes: new Set( cls ),
		attrs: Object.assign( {}, attrs ),
		parentNode: parent,
		children: [],
		hidden: false,
		value: '',
		defaultValue: '',
		scrolled: 0,
		focused: 0,
		writes: [],
		_text: '',
		innerHTML: '',
		get textContent() { return this._text; },
		set textContent( v ) { this._text = String( v ); this.writes.push( this._text ); },
		getAttribute( n ) { return n in this.attrs ? this.attrs[ n ] : null; },
		setAttribute( n, v ) { this.attrs[ n ] = String( v ); },
		matches( sel ) { return sel.split( ',' ).some( ( s ) => matchSel( this, s ) ); },
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
		querySelectorAll( sel ) {
			const out   = [];
			const stack = [ ...this.children ];
			while ( stack.length ) {
				const c = stack.shift();
				if ( c.matches( sel ) ) {
					out.push( c );
				}
				stack.push( ...c.children );
			}
			return out;
		},
		querySelector( sel ) { return this.querySelectorAll( sel )[ 0 ] || null; },
		appendChild( c ) { c.parentNode = this; this.children.push( c ); return c; },
		focus() { this.focused++; },
		scrollIntoView() { this.scrolled++; },
		classList: null,
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

function page( file, { initial = '', typed = '' } = {} ) {
	const wrap   = el( 'div', { cls: [ 'ndvr-reviews-instance' ], attrs: { 'data-product': '5', 'data-per-page': '10', 'data-orderby': 'recent' } } );
	const form   = el( 'form', { cls: [ 'ndvr-search' ], parent: wrap } );
	const input  = el( 'input', { attrs: { type: 'search', name: 'ndvr_q' }, parent: form } );
	input.defaultValue = initial;
	input.value        = typed || initial;
	const clear  = el( 'a', { cls: [ 'ndvr-search-clear' ], attrs: { href: '/p/#tab-reviews' }, parent: form } );
	clear.hidden = ! initial;
	const status = el( 'p', { cls: [ 'ndvr-search-status' ], attrs: { role: 'status' }, parent: form } );
	const list   = el( 'div', { cls: [ 'ndvr-review-list-wrap' ], parent: wrap } );
	const pager  = el( 'button', { cls: [ 'ndvr-page' ], attrs: { 'data-page': '2' }, parent: list } );

	const listeners = {};
	const queue     = [];
	const ctx       = {
		fetches: [],
		console,
		setTimeout: ( fn ) => fn(),
		ndvrDisplay: { ajaxUrl: '/a', action: 'ndvr_list_reviews', nonce: 'n', i18n: { listError: 'Could not load.' } },
	};
	ctx.window   = ctx;
	ctx.FormData = class {
		constructor() { this.d = new Map(); }
		append( k, v ) { this.d.set( k, String( v ) ); }
		get( k ) { return this.d.has( k ) ? this.d.get( k ) : null; }
	};
	// Replies are released by the test, in any order.
	ctx.fetch = ( url, init ) => {
		ctx.fetches.push( init.body );
		return new Promise( ( resolve, reject ) => queue.push( { resolve, reject } ) );
	};
	ctx.document = {
		addEventListener: ( t, fn ) => ( listeners[ t ] = listeners[ t ] || [] ).push( fn ),
		querySelectorAll: () => [],
		querySelector: () => null,
		getElementById: () => null,
		createElement: ( t ) => el( t ),
		body: el( 'body' ),
	};
	vm.createContext( ctx );
	vm.runInContext( readFileSync( join( root, 'assets/js', file ), 'utf8' ), ctx );
	const fire = ( type, target ) => {
		const ev = { type, target, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
		( listeners[ type ] || [] ).forEach( ( fn ) => fn( ev ) );
		return ev;
	};
	const reply = ( i, data ) => {
		const r = queue[ i ];
		if ( data === 'network' ) {
			r.reject( new Error( 'offline' ) );
		} else {
			r.resolve( { json: () => Promise.resolve( data ) } );
		}
	};
	return { ctx, wrap, form, input, clear, status, list, pager, fire, reply };
}

const flush = async () => {
	for ( let i = 0; i < 6; i++ ) {
		await new Promise( ( r ) => setImmediate( r ) );
	}
};
const okData = ( html, status ) => ( { success: true, data: { html, total: 4, q: 'battery', status } } );

for ( const file of [ 'display.js', 'display.min.js' ] ) {
	// M1: paging scrolls to the list again.
	{
		const p = page( file );
		p.fire( 'click', p.pager );
		p.reply( 0, { success: true, data: { html: '<li>page 2</li>' } } );
		await flush();
		ok( 1 === p.list.scrolled && '<li>page 2</li>' === p.list.innerHTML && '2' === p.ctx.fetches[ 0 ].get( 'page' ), `${ file } M1: a page click replaces the list and scrolls to it` );
	}
	// Submit, then paging through the search stays quiet (m7).
	{
		const p = page( file );
		p.input.value = 'battery';
		const ev = p.fire( 'submit', p.form );
		p.reply( 0, okData( '<li>a</li>', '4 reviews mention “battery”' ) );
		await flush();
		const announced = p.status.writes.slice();
		p.fire( 'click', p.pager );
		p.reply( 1, okData( '<li>b</li>', '4 reviews mention “battery”' ) );
		await flush();
		ok( ev.defaultPrevented && 'battery' === p.ctx.fetches[ 0 ].get( 'q' ) && '1' === p.ctx.fetches[ 0 ].get( 'page' ) && '4 reviews mention “battery”' === p.status.textContent && false === p.clear.hidden, `${ file }: a search sends q on page 1, announces the result and shows Clear` );
		ok( announced.length === p.status.writes.length && 'battery' === p.ctx.fetches[ 1 ].get( 'q' ), `${ file } m7: paging through a search keeps q and doesn't re-announce the same line` );
	}
	// m4: replies out of order.
	{
		const p = page( file );
		p.input.value = 'first';
		p.fire( 'submit', p.form );
		p.input.value = 'second';
		p.fire( 'submit', p.form );
		p.reply( 1, okData( '<li>second</li>', 'second line' ) );
		await flush();
		p.reply( 0, okData( '<li>first</li>', 'first line' ) );
		await flush();
		ok( '<li>second</li>' === p.list.innerHTML && 'second line' === p.status.textContent, `${ file } m4: a slow earlier reply doesn't overwrite the newer result` );
	}
	// Clear (a link, for no-JS) and the native ✕ / Escape.
	{
		const p = page( file, { initial: 'battery' } );
		const ev = p.fire( 'click', p.clear );
		p.reply( 0, { success: true, data: { html: '<li>all</li>', total: 12, q: '', status: '' } } );
		await flush();
		ok( ev.defaultPrevented && '' === p.ctx.fetches[ 0 ].get( 'q' ) && '' === p.input.value && true === p.clear.hidden && '' === p.status.textContent, `${ file }: Clear empties the box, refetches without q and hides itself` );
		const q = page( file, { initial: 'battery' } );
		q.input.value = '';
		q.fire( 'search', q.input );
		ok( 1 === q.ctx.fetches.length && '' === q.ctx.fetches[ 0 ].get( 'q' ), `${ file } m3: the browser's own clear (search event) refetches without q` );
	}
	// m3: unsubmitted typing isn't searched by a filter click.
	{
		const p = page( file, { typed: 'half-typed' } );
		p.fire( 'click', p.pager );
		ok( '' === p.ctx.fetches[ 0 ].get( 'q' ), `${ file } m3: text typed but not submitted isn't sent with a page click` );
	}
	// m4: a network error during a search is announced.
	{
		const p = page( file );
		p.input.value = 'battery';
		p.fire( 'submit', p.form );
		p.reply( 0, 'network' );
		await flush();
		ok( 'Could not load.' === p.status.textContent, `${ file } m4: a network error during a search is announced` );
	}
}

console.log( `RESULT: ${ fail ? 'FAIL' : 'PASS' } (${ pass } passed, ${ fail } failed)` );
process.exit( fail ? 1 : 0 );
