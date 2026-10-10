/**
 * Rosette Reviews — front-end display interactions (filter/sort/paginate, voting,
 * photo lightbox, "Write a review" disclosure). Vanilla JS, no jQuery.
 *
 * Every list is an independent instance (`.ndvr-reviews-instance`, or the
 * legacy `#ndvr-reviews`) whose state lives on the element, so several lists
 * can share a page. Listeners are delegated from the document, so lists added
 * later (Elementor editor preview, AJAX-inserted markup) work without re-init.
 */
( function () {
	'use strict';

	var cfg = window.ndvrDisplay || {};
	if ( ! cfg.ajaxUrl ) {
		return;
	}

	var INSTANCE = '.ndvr-reviews-instance, #ndvr-reviews';

	function closest( el, selector ) {
		return el && el.closest ? el.closest( selector ) : null;
	}

	function getState( wrap ) {
		if ( ! wrap.ndvrState ) {
			wrap.ndvrState = {
				star: 0,
				verified: false,
				with_media: false,
				orderby: wrap.getAttribute( 'data-orderby' ) || 'recent',
				tag: '',
				answers: {},
				// RR-16: starts from the box (a no-JS search may have filled it).
				q: searchInput( wrap ) ? searchInput( wrap ).defaultValue.trim() : '',
				page: 1,
				perPage: parseInt( wrap.getAttribute( 'data-per-page' ), 10 ) || 0
			};
		}
		return wrap.ndvrState;
	}

	function searchInput( wrap ) {
		return wrap.querySelector( '.ndvr-search input[type="search"]' );
	}

	function listOf( wrap ) {
		return wrap.querySelector( '.ndvr-review-list-wrap' ) || wrap.querySelector( '#ndvr-review-list' );
	}

	function fetchList( wrap, scroll ) {
		var listWrap = listOf( wrap );
		if ( ! listWrap ) {
			return;
		}
		var state = getState( wrap );
		listWrap.classList.add( 'is-loading' );
		listWrap.setAttribute( 'aria-busy', 'true' );

		var body = new FormData();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'product_id', wrap.getAttribute( 'data-product' ) || '' );
		body.append( 'star', state.star );
		body.append( 'verified', state.verified ? '1' : '' );
		body.append( 'with_media', state.with_media ? '1' : '' );
		body.append( 'orderby', state.orderby );
		body.append( 'tag', state.tag );
		body.append( 'q', state.q || '' );
		body.append( 'page', state.page );
		body.append( 'per_page', state.perPage );
		Object.keys( state.answers || {} ).forEach( function ( field ) {
			if ( state.answers[ field ] ) {
				body.append( 'answers[' + field + ']', state.answers[ field ] );
			}
		} );

		// Add-ons may add their own fields to the request.
		if ( typeof window.CustomEvent === 'function' ) {
			wrap.dispatchEvent( new window.CustomEvent( 'ndvr:list-request', { bubbles: true, detail: { body: body, state: state } } ) );
		}

		// Only the newest request may update the list: a slow earlier reply is
		// dropped (fast searches can come back out of order).
		var seq = ( wrap.ndvrSeq || 0 ) + 1;
		wrap.ndvrSeq = seq;

		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( seq !== wrap.ndvrSeq ) {
					return;
				}
				listWrap.classList.remove( 'is-loading' );
				if ( res && res.success && res.data && typeof res.data.html === 'string' ) {
					listWrap.innerHTML = res.data.html;
					searchStatus( wrap, typeof res.data.status === 'string' ? res.data.status : '' );
					if ( scroll ) {
						listWrap.scrollIntoView( { behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' } );
					}
				} else if ( state.q ) {
					searchStatus( wrap, ( res && res.data && res.data.message ) || ( cfg.i18n && cfg.i18n.listError ) || '', true );
				}
				listWrap.setAttribute( 'aria-busy', 'false' );
			} )
			.catch( function () {
				if ( seq !== wrap.ndvrSeq ) {
					return;
				}
				listWrap.classList.remove( 'is-loading' );
				listWrap.setAttribute( 'aria-busy', 'false' );
				if ( state.q ) {
					searchStatus( wrap, ( cfg.i18n && cfg.i18n.listError ) || '', true );
				}
			} );
	}

	// The search line (role=status). It speaks only when its text changes, or
	// when forced (a new search, an error): paging through a search stays
	// quiet. A forced repeat is cleared first and written on the next tick.
	function searchStatus( wrap, text, force ) {
		var status = wrap.querySelector( '.ndvr-search-status' );
		var clear  = wrap.querySelector( '.ndvr-search-clear' );
		var state  = getState( wrap );
		if ( clear ) {
			clear.hidden = ! state.q;
		}
		if ( ! status ) {
			return;
		}
		if ( ! force && ! state.announce && status.textContent === ( text || '' ) ) {
			return;
		}
		state.announce = false;
		status.textContent = '';
		setTimeout( function () {
			status.textContent = text || '';
		}, 50 );
	}

	function reducedMotion() {
		return window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	}

	// Reflect the active star filter on both the filter-bar buttons and the
	// clickable summary rows, so the two controls never disagree.
	function syncStar( wrap, value ) {
		Array.prototype.forEach.call( wrap.querySelectorAll( '[data-filter="star"]' ), function ( b ) {
			var on = String( parseInt( b.getAttribute( 'data-value' ), 10 ) || 0 ) === String( value );
			b.classList.toggle( 'is-current', on );
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var target = e.target;

		var write = closest( target, '[data-ndvr-write-review]' );
		if ( write ) {
			openForm( write, e );
			return;
		}

		var report = closest( target, '.ndvr-report' );
		if ( report ) {
			toggleReport( report );
			return;
		}

		var cancelReport = closest( target, '.ndvr-report-cancel' );
		if ( cancelReport ) {
			closeReport( closest( cancelReport, '.ndvr-report-form' ) );
			return;
		}

		var helpful = closest( target, '.ndvr-helpful' );
		if ( helpful ) {
			if ( ! helpful.disabled && ! helpful.classList.contains( 'is-voted' ) ) {
				vote( helpful );
			}
			return;
		}

		var photo = closest( target, '.ndvr-review-photo' );
		if ( photo ) {
			e.preventDefault();
			openLightbox( photo );
			return;
		}

		var wrap = closest( target, INSTANCE );
		if ( ! wrap ) {
			return;
		}
		var state = getState( wrap );

		var page = closest( target, '.ndvr-page' );
		if ( page ) {
			state.page = parseInt( page.getAttribute( 'data-page' ), 10 ) || 1;
			fetchList( wrap, true );
			return;
		}

		var star = closest( target, '[data-filter="star"]' );
		if ( star ) {
			var value = parseInt( star.getAttribute( 'data-value' ), 10 ) || 0;
			// Clicking the active summary row again clears the filter.
			if ( star.classList.contains( 'ndvr-bar-row' ) && state.star === value ) {
				value = 0;
			}
			state.star = value;
			state.page = 1;
			syncStar( wrap, value );
			fetchList( wrap, star.classList.contains( 'ndvr-bar-row' ) );
			return;
		}

		// Answer chips (RR-11; markup from add-ons): data-ndvr-answer-field +
		// data-value. Clicking the active chip again clears that question.
		var chip = closest( target, '[data-ndvr-answer-field]' );
		if ( chip ) {
			var field  = chip.getAttribute( 'data-ndvr-answer-field' );
			var answer = chip.getAttribute( 'data-value' ) || '';
			if ( state.answers[ field ] === answer ) {
				answer = '';
			}
			state.answers[ field ] = answer;
			Array.prototype.forEach.call( wrap.querySelectorAll( '[data-ndvr-answer-field]' ), function ( c ) {
				if ( c.getAttribute( 'data-ndvr-answer-field' ) === field ) {
					var on = '' !== answer && c.getAttribute( 'data-value' ) === answer;
					c.classList.toggle( 'is-current', on );
					c.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
				}
			} );
			state.page = 1;
			fetchList( wrap, false );
			return;
		}

		var topic = closest( target, '.ndvr-topic' );
		if ( topic ) {
			Array.prototype.forEach.call( wrap.querySelectorAll( '.ndvr-topic' ), function ( b ) {
				b.classList.remove( 'is-current' );
				b.setAttribute( 'aria-pressed', 'false' );
			} );
			topic.classList.add( 'is-current' );
			topic.setAttribute( 'aria-pressed', 'true' );
			state.tag = topic.getAttribute( 'data-value' ) || '';
			state.page = 1;
			fetchList( wrap, false );
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		var el = e.target;
		var wrap = closest( el, INSTANCE );
		var filter = el.getAttribute && el.getAttribute( 'data-filter' );
		if ( ! wrap || ! filter ) {
			return;
		}
		var state = getState( wrap );
		if ( filter === 'verified' ) {
			state.verified = el.checked;
		} else if ( filter === 'with_media' ) {
			state.with_media = el.checked;
		} else if ( filter === 'orderby' ) {
			state.orderby = el.value;
		} else {
			return;
		}
		state.page = 1;
		fetchList( wrap, false );
	} );

	// ── "Write a review" disclosure ─────────────────────────────────
	// On the reviews tab the form starts collapsed behind the summary's CTA
	// when reviews already exist. It stays open when there are none, when the
	// URL points at the form, or after a non-JS submit came back with an error.
	// Without JS nothing is hidden (the collapse class is only added here).
	function formTarget( link ) {
		var id = ( link.getAttribute( 'href' ) || '' ).replace( /^#/, '' );
		return id ? document.getElementById( id ) : null;
	}

	function setExpanded( form, open ) {
		form.classList.toggle( 'is-collapsed', ! open );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-ndvr-write-review]' ), function ( l ) {
			if ( formTarget( l ) === form ) {
				l.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			}
		} );
	}

	function openForm( link, e ) {
		var form = formTarget( link );
		if ( ! form ) {
			return;
		}
		e.preventDefault();
		var wasCollapsed = form.classList.contains( 'is-collapsed' );
		setExpanded( form, true );
		form.scrollIntoView( { behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' } );
		if ( wasCollapsed || document.activeElement === link ) {
			var first = form.querySelector( 'input:not([type="hidden"]):not([tabindex="-1"]), textarea, select' );
			( first || form ).focus( { preventScroll: true } );
		}
	}

	function initForms() {
		Array.prototype.forEach.call( document.querySelectorAll( INSTANCE ), function ( wrap ) {
			var form = wrap.querySelector( '#ndvr-review-form-wrap' );
			var link = wrap.querySelector( '[data-ndvr-write-review]' );
			if ( ! form || ! link || form.ndvrInit ) {
				return;
			}
			form.ndvrInit = true;
			link.setAttribute( 'aria-controls', form.id );

			var count = parseInt( wrap.getAttribute( 'data-count' ), 10 ) || 0;
			var hash = window.location.hash;
			var params = window.location.search;
			// ?ndvr_review=1 is the shareable/QR review link (Collection\ReviewLinkFocus
			// scrolls to the form for it, so it must be open).
			var targeted = hash === '#ndvr-review-form-wrap' || hash === '#respond' || hash === '#review_form';
			var wantsForm = targeted ||
				/[?&]ndvr_review=1(&|$)/.test( params ) ||
				!! form.querySelector( '.ndvr-form-message.is-error, .comment-form-error' );

			setExpanded( form, count === 0 || wantsForm );

			// WooCommerce only opens the Reviews tab for #reviews / #tab-reviews /
			// #comment-*; for a direct link to the form, open the tab ourselves —
			// after window load, because Woo's own tab init (on ready) would
			// otherwise switch back to the first tab.
			if ( targeted ) {
				var openTab = function () {
					if ( form.offsetParent !== null ) {
						return;
					}
					var tab = document.querySelector( '.reviews_tab a, #tab-title-reviews a' );
					if ( tab ) {
						tab.click();
						form.scrollIntoView( { block: 'start' } );
					}
				};
				if ( document.readyState === 'complete' ) {
					setTimeout( openTab, 0 );
				} else {
					window.addEventListener( 'load', function () { setTimeout( openTab, 0 ); } );
				}
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initForms );
	} else {
		initForms();
	}

	// A link to one review (#ndvr-review-{id}, as in the store-reply email):
	// WooCommerce opens the Reviews tab only for #reviews / #comment-*, so
	// open it ourselves after its own tab init, then bring the review into
	// view. A review on a later page isn't in the markup: the tab still opens.
	function openReviewFromHash() {
		var hash = window.location.hash;
		if ( ! /^#ndvr-review-\d+$/.test( hash ) ) {
			return;
		}
		var target = document.getElementById( hash.slice( 1 ) );
		if ( ! target || target.offsetParent === null ) {
			var tab = document.querySelector( '.reviews_tab a, #tab-title-reviews a' );
			if ( tab ) {
				tab.click();
			}
		}
		target = target || document.querySelector( INSTANCE );
		if ( target && target.offsetParent !== null && target.scrollIntoView ) {
			target.scrollIntoView( { block: 'start' } );
		}
	}
	if ( document.readyState === 'complete' ) {
		setTimeout( openReviewFromHash, 0 );
	} else {
		window.addEventListener( 'load', function () { setTimeout( openReviewFromHash, 0 ); } );
	}

	// ── Photo lightbox ──────────────────────────────────────────────
	// Keyboard-operable modal: Escape/overlay/close-button dismiss, Left/Right
	// arrows step through the same review's photos, focus moves into the
	// dialog on open and returns to the trigger link on close.
	var lightbox = null;
	var lbGroup = [];
	var lbIndex = 0;
	var lbReturnFocus = null;

	function escAttr( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' );
	}

	function buildLightbox() {
		if ( lightbox ) {
			return lightbox;
		}
		lightbox = document.createElement( 'div' );
		lightbox.className = 'ndvr-lightbox';
		lightbox.hidden = true;
		var i18n = cfg.i18n || {};
		lightbox.innerHTML =
			'<div class="ndvr-lightbox-overlay" data-ndvr-close></div>' +
			'<div class="ndvr-lightbox-dialog" role="dialog" aria-modal="true" aria-label="' + escAttr( i18n.photo || 'Customer photo' ) + '">' +
			'<button type="button" class="ndvr-lightbox-close" data-ndvr-close aria-label="' + escAttr( i18n.close || 'Close' ) + '">&times;</button>' +
			'<button type="button" class="ndvr-lightbox-prev" aria-label="' + escAttr( i18n.prev || 'Previous photo' ) + '">&lsaquo;</button>' +
			'<img class="ndvr-lightbox-img" src="" alt="" />' +
			'<button type="button" class="ndvr-lightbox-next" aria-label="' + escAttr( i18n.next || 'Next photo' ) + '">&rsaquo;</button>' +
			'</div>';
		document.body.appendChild( lightbox );

		lightbox.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-ndvr-close]' ) ) {
				closeLightbox();
			} else if ( e.target.closest( '.ndvr-lightbox-prev' ) ) {
				stepLightbox( -1 );
			} else if ( e.target.closest( '.ndvr-lightbox-next' ) ) {
				stepLightbox( 1 );
			}
		} );

		lightbox.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				closeLightbox();
			} else if ( e.key === 'ArrowLeft' ) {
				stepLightbox( -1 );
			} else if ( e.key === 'ArrowRight' ) {
				stepLightbox( 1 );
			} else if ( e.key === 'Tab' ) {
				// Simple focus trap: only the dialog's own visible buttons.
				var focusable = Array.prototype.filter.call( lightbox.querySelectorAll( 'button' ), function ( b ) { return ! b.hidden; } );
				var first = focusable[ 0 ];
				var last = focusable[ focusable.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}
		} );

		return lightbox;
	}

	function showLightboxImage() {
		var link = lbGroup[ lbIndex ];
		var img = lightbox.querySelector( '.ndvr-lightbox-img' );
		img.src = link.getAttribute( 'href' );
		img.alt = link.querySelector( 'img' ) ? link.querySelector( 'img' ).alt : '';
		var multi = lbGroup.length > 1;
		lightbox.querySelector( '.ndvr-lightbox-prev' ).hidden = ! multi;
		lightbox.querySelector( '.ndvr-lightbox-next' ).hidden = ! multi;
	}

	function stepLightbox( delta ) {
		lbIndex = ( lbIndex + delta + lbGroup.length ) % lbGroup.length;
		showLightboxImage();
	}

	function openLightbox( trigger ) {
		var media = trigger.closest( '.ndvr-review-media' );
		lbGroup = media ? Array.prototype.slice.call( media.querySelectorAll( '.ndvr-review-photo' ) ) : [ trigger ];
		lbIndex = lbGroup.indexOf( trigger );
		if ( lbIndex < 0 ) {
			lbIndex = 0;
		}
		lbReturnFocus = trigger;

		buildLightbox();
		showLightboxImage();
		lightbox.hidden = false;
		lightbox.querySelector( '.ndvr-lightbox-close' ).focus();
		document.addEventListener( 'keydown', trapEscapeAtDocument, true );
	}

	function closeLightbox() {
		if ( ! lightbox || lightbox.hidden ) {
			return;
		}
		lightbox.hidden = true;
		document.removeEventListener( 'keydown', trapEscapeAtDocument, true );
		if ( lbReturnFocus ) {
			lbReturnFocus.focus();
		}
	}

	// Belt-and-braces: Escape closes even if focus somehow left the dialog.
	function trapEscapeAtDocument( e ) {
		if ( e.key === 'Escape' ) {
			closeLightbox();
		}
	}

	// Report a review (RR-14). The button, its form and its status line are
	// found within the card's footer, never page-wide: a list refreshed by the
	// list AJAX can repeat ids from elsewhere on the page. Everything is
	// delegated, so replaced cards keep working.
	function reportParts( el ) {
		var scope = closest( el, '.ndvr-review-foot' ) || ( el ? el.parentNode : null );
		return {
			scope: scope,
			btn: scope ? scope.querySelector( '.ndvr-report' ) : null,
			form: scope ? scope.querySelector( '.ndvr-report-form' ) : null,
			status: scope ? scope.querySelector( '.ndvr-report-status' ) : null
		};
	}

	// The status line stays in the accessibility tree; clearing it first and
	// writing on the next tick makes a repeated message announce again.
	function announce( status, text ) {
		if ( ! status ) {
			return;
		}
		status.textContent = '';
		setTimeout( function () {
			status.textContent = text || '';
		}, 50 );
	}

	function toggleReport( btn ) {
		var parts = reportParts( btn );
		if ( ! parts.form ) {
			return;
		}
		if ( ! parts.form.hidden ) {
			closeReport( parts.form );
			return;
		}
		parts.form.hidden = false;
		btn.setAttribute( 'aria-expanded', 'true' );
		if ( parts.scope && parts.scope.classList ) {
			parts.scope.classList.add( 'is-reporting' );
		}
		if ( parts.status ) {
			parts.status.textContent = '';
		}
		var first = parts.form.querySelector( 'select, textarea' );
		if ( first ) {
			first.focus();
		}
	}

	function closeReport( form ) {
		if ( ! form ) {
			return;
		}
		var parts   = reportParts( form );
		form.hidden = true;
		if ( parts.scope && parts.scope.classList ) {
			parts.scope.classList.remove( 'is-reporting' );
		}
		if ( parts.btn ) {
			parts.btn.setAttribute( 'aria-expanded', 'false' );
			parts.btn.focus();
		}
	}

	function sendReport( form ) {
		if ( form.ndvrBusy ) {
			return;
		}
		var i18n = cfg.i18n || {};
		var send = form.querySelector( '.ndvr-report-send' );
		var body = new FormData( form );
		body.append( 'action', cfg.reportAction );
		body.append( 'nonce', cfg.reportNonce );
		body.append( 'comment_id', form.getAttribute( 'data-comment-id' ) );

		form.ndvrBusy = true;
		if ( send ) {
			send.disabled = true;
		}

		function done( text, close ) {
			form.ndvrBusy = false;
			if ( send ) {
				send.disabled = false;
			}
			// The list AJAX may have replaced this card meanwhile.
			if ( false === form.isConnected ) {
				return;
			}
			var status = reportParts( form ).status;
			if ( close ) {
				closeReport( form );
			}
			// After the form closes, so it is read with focus back on the button.
			announce( status, text );
		}

		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json().then( function ( res ) {
					return { code: r.status, res: res || {} };
				} );
			} )
			.then( function ( out ) {
				if ( out.res.success ) {
					form.reset();
					done( i18n.reportSent || ( out.res.data && out.res.data.message ), true );
				} else if ( 409 === out.code ) {
					done( i18n.reportDuplicate || ( out.res.data && out.res.data.message ), true );
				} else if ( 429 === out.code ) {
					done( i18n.reportLimited || ( out.res.data && out.res.data.message ), false );
				} else {
					done( ( out.res.data && out.res.data.message ) || i18n.reportError, false );
				}
			} )
			.catch( function () {
				done( i18n.reportError, false );
			} );
	}

	// Review search (RR-16): the GET form works without JS; with JS it
	// filters the list in place, keeping the other filters and the sort.
	document.addEventListener( 'submit', function ( e ) {
		var search = closest( e.target, '.ndvr-search' );
		var wrap   = search ? closest( search, INSTANCE ) : null;
		if ( ! wrap ) {
			return;
		}
		e.preventDefault();
		var input = searchInput( wrap );
		var state = getState( wrap );
		state.q        = input ? input.value.trim() : '';
		state.page     = 1;
		state.announce = true;
		fetchList( wrap, false );
	} );

	// The browser's own clear (✕ or Escape in a search field) fires `search`.
	document.addEventListener( 'search', function ( e ) {
		var input = e.target;
		var wrap  = input && input.closest ? closest( input, INSTANCE ) : null;
		if ( ! wrap || ! closest( input, '.ndvr-search' ) || '' !== input.value || ! getState( wrap ).q ) {
			return;
		}
		var state      = getState( wrap );
		state.q        = '';
		state.page     = 1;
		state.announce = true;
		fetchList( wrap, false );
	}, true );

	document.addEventListener( 'click', function ( e ) {
		var clear = closest( e.target, '.ndvr-search-clear' );
		var wrap  = clear ? closest( clear, INSTANCE ) : null;
		if ( ! wrap ) {
			return;
		}
		e.preventDefault(); // It is a link to the page without the search (no-JS).
		var input = searchInput( wrap );
		if ( input ) {
			input.value = '';
			input.focus();
		}
		var state = getState( wrap );
		state.q        = '';
		state.page     = 1;
		state.announce = true;
		fetchList( wrap, false );
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = closest( e.target, '.ndvr-report-form' );
		if ( form ) {
			e.preventDefault();
			sendReport( form );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key ) {
			return;
		}
		var form = closest( e.target, '.ndvr-report-form' );
		if ( form ) {
			e.preventDefault();
			closeReport( form );
		}
	} );

	function vote( btn ) {
		var body = new FormData();
		body.append( 'action', cfg.voteAction );
		body.append( 'nonce', btn.getAttribute( 'data-nonce' ) );
		body.append( 'comment_id', btn.getAttribute( 'data-comment-id' ) );

		btn.disabled = true;

		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success && res.data && typeof res.data.count !== 'undefined' ) {
					var c = btn.querySelector( '.ndvr-helpful-count' );
					if ( c ) {
						c.textContent = '(' + res.data.count + ')';
					}
					btn.classList.add( 'is-voted' );
					btn.setAttribute( 'aria-pressed', 'true' );
				} else {
					btn.disabled = false;
				}
			} )
			.catch( function () { btn.disabled = false; } );
	}
}() );
