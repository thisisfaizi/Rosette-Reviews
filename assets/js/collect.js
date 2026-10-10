/**
 * Rosette Reviews — collection forms (tokenized landing page + [ndvr-testimonial]).
 * Submits each product review independently. Vanilla JS, no jQuery.
 *
 * Captcha (RR-13): when the form container carries data-captcha-provider
 * (recaptcha|turnstile|hcaptcha) and data-captcha-key, the provider script was
 * enqueued before this one. Turnstile and hCaptcha widgets render into each
 * form's .ndvr-captcha from the provider's onload callback (ndvrCaptchaReady,
 * AntiSpam::READY_JS). The request is sent only with a token, as
 * ndvr_captcha_token, and the widget resets after every response (tokens are
 * single-use). The tokenized landing page never sets a provider: the review
 * link authenticates the customer.
 */
( function () {
	'use strict';

	var roots = document.querySelectorAll( '.ndvr-collect' );
	if ( ! roots.length ) {
		return;
	}

	function captchaProvider( root ) {
		var provider = root.getAttribute( 'data-captcha-provider' ) || '';
		if ( ! provider && root.getAttribute( 'data-recaptcha-key' ) ) {
			provider = 'recaptcha';
		}
		return provider;
	}

	function captchaKey( root ) {
		return root.getAttribute( 'data-captcha-key' ) || root.getAttribute( 'data-recaptcha-key' ) || '';
	}

	var WAIT = 15000;

	// A testimonial page cached before RR-13 has only data-recaptcha-key and
	// no reCAPTCHA script tag (the old collect.js added it). Load it once, so
	// such a page keeps working until the cache refreshes.
	var legacyLoading = null;
	function legacyRecaptcha( root ) {
		if ( window.grecaptcha || root.getAttribute( 'data-captcha-provider' ) || ! root.getAttribute( 'data-recaptcha-key' ) ) {
			return Promise.resolve();
		}
		if ( ! legacyLoading ) {
			legacyLoading = new Promise( function ( resolve ) {
			var s     = document.createElement( 'script' );
			s.src     = 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent( root.getAttribute( 'data-recaptcha-key' ) );
			s.async   = true;
			s.onload  = resolve;
			s.onerror = resolve;
			document.head.appendChild( s );
			} );
		}
		return legacyLoading;
	}

	function setFormToken( form, token ) {
		form.ndvrToken = token || '';
		if ( form.ndvrToken && form.ndvrWaiters ) {
			var list         = form.ndvrWaiters;
			form.ndvrWaiters = [];
			list.forEach( function ( fn ) { fn( form.ndvrToken ); } );
		}
	}

	function rendered( form ) {
		return null !== form.ndvrWidget && undefined !== form.ndvrWidget;
	}

	// Render a form's Turnstile / hCaptcha widget once.
	function renderCaptcha( root, form ) {
		var el       = form.querySelector( '.ndvr-captcha' );
		var provider = captchaProvider( root );
		var id       = null;
		if ( ! el || rendered( form ) ) {
			return;
		}
		var options = {
			sitekey: el.getAttribute( 'data-sitekey' ) || captchaKey( root ),
			callback: function ( token ) { setFormToken( form, token ); },
			'expired-callback': function () { setFormToken( form, '' ); },
			'error-callback': function () { setFormToken( form, '' ); }
		};
		try {
			if ( 'turnstile' === provider && window.turnstile ) {
			options[ 'response-field' ] = false;
			id = window.turnstile.render( el, options );
			} else if ( 'hcaptcha' === provider && window.hcaptcha ) {
			options.size = 'invisible';
			id = window.hcaptcha.render( el, options );
			}
		} catch ( e ) {
			id = null;
		}
		// A failed render can return undefined: never treat that as a widget.
		form.ndvrWidget = ( 'string' === typeof id || 'number' === typeof id ) ? id : null;
	}

	function resetCaptcha( root, form ) {
		var provider = captchaProvider( root );
		setFormToken( form, '' );
		if ( ! rendered( form ) ) {
			return;
		}
		try {
			if ( 'turnstile' === provider && window.turnstile ) {
			window.turnstile.reset( form.ndvrWidget );
			} else if ( 'hcaptcha' === provider && window.hcaptcha ) {
			window.hcaptcha.reset( form.ndvrWidget );
			}
		} catch ( e ) {}
	}

	// Resolve after `ms` with '' unless `start` resolves first.
	function withTimeout( start, ms ) {
		return new Promise( function ( resolve ) {
			var settled = false;
			function done( value ) {
			if ( ! settled ) {
				settled = true;
				resolve( value || '' );
			}
			}
			setTimeout( function () { done( '' ); }, ms );
			try {
			start( done );
			} catch ( e ) {
			done( '' );
			}
		} );
	}

	// A token for this submission ('' when there is no captcha, or the
	// verification produced none: the request is then not sent).
	function captchaToken( root, form ) {
		var provider = captchaProvider( root );
		var key      = captchaKey( root );
		if ( 'recaptcha' === provider ) {
			return legacyRecaptcha( root ).then( function () {
			return withTimeout( function ( done ) {
				if ( ! window.grecaptcha || ! key ) {
					done( '' );
					return;
				}
				window.grecaptcha.ready( function () {
					window.grecaptcha.execute( key, { action: 'review' } ).then( done, function () { done( '' ); } );
				} );
			}, WAIT );
			} );
		}
		renderCaptcha( root, form );
		if ( 'hcaptcha' === provider && window.hcaptcha && rendered( form ) ) {
			// No timeout: the visitor may be solving a challenge.
			return window.hcaptcha.execute( form.ndvrWidget, { async: true } ).then( function ( res ) {
			return ( res && res.response ) || '';
			}, function () { return ''; } );
		}
		if ( 'turnstile' === provider && window.turnstile && rendered( form ) ) {
			if ( form.ndvrToken ) {
			return Promise.resolve( form.ndvrToken );
			}
			// The challenge may still be running: wait for its callback.
			return withTimeout( function ( done ) {
			var current = '';
			try {
				current = window.turnstile.getResponse( form.ndvrWidget ) || '';
			} catch ( e ) {}
			if ( current ) {
				done( current );
				return;
			}
			form.ndvrWaiters = form.ndvrWaiters || [];
			form.ndvrWaiters.push( done );
			}, WAIT );
		}
		return Promise.resolve( '' );
	}

	function submitForm( root, form ) {
		var ajaxUrl = root.getAttribute( 'data-ajax-url' );
		var action = root.getAttribute( 'data-action' );
		var msg = form.querySelector( '.ndvr-form-message' );
		var btn = form.querySelector( '.ndvr-collect-submit' );

		// One request at a time: a second click would reuse a single-use token.
		if ( form.ndvrBusy ) {
			return Promise.resolve( false );
		}
		form.ndvrBusy = true;
		if ( btn ) {
			btn.disabled = true;
		}
		if ( msg ) {
			msg.textContent = '';
			msg.className = 'ndvr-form-message';
		}

		function fail( text ) {
			form.ndvrBusy = false;
			if ( msg ) {
			msg.textContent = text;
			msg.className = 'ndvr-form-message is-error';
			}
			if ( btn ) {
			btn.disabled = false;
			}
		}

		return captchaToken( root, form ).then( function ( token ) {
			if ( captchaProvider( root ) && ! token ) {
			// Nothing is sent, so no attempt is used up and the widget keeps
			// its challenge.
			fail( root.getAttribute( 'data-captcha-message' ) || 'Please complete the verification, then submit again.' );
			return false;
			}

			var body = new FormData( form );
			body.append( 'action', action );
			if ( token ) {
			body.append( 'ndvr_captcha_token', token );
			}

			// `action` also travels in the URL: when the upload is larger than
			// post_max_size, PHP drops the body and admin-ajax.php would not know
			// which handler to run, so the size message could never be returned.
			var url = ajaxUrl + ( ajaxUrl.indexOf( '?' ) < 0 ? '?' : '&' ) + 'action=' + encodeURIComponent( action );

			return fetch( url, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				resetCaptcha( root, form );
				if ( res && res.success ) {
					form.ndvrBusy = false;
					if ( msg ) {
						msg.textContent = ( res.data && res.data.message ) || 'Thank you.';
						msg.className = 'ndvr-form-message is-success';
					}
					form.classList.add( 'is-done' );
					var fields = form.querySelector( '.ndvr-fields' );
					if ( fields ) {
						// Keep the status message visible: move it out before hiding the fields.
						if ( msg && fields.contains( msg ) ) {
							fields.parentNode.insertBefore( msg, fields );
						}
						fields.style.display = 'none';
					}
					return true;
				}
				fail( ( res && res.data && res.data.message ) || 'Something went wrong.' );
				return false;
			} )
			.catch( function () {
				resetCaptcha( root, form );
				fail( 'Network error. Please try again.' );
				return false;
			} );
		} );
	}

	// Minimum review length (RR-12): a visible counter (no live region), and a
	// quiet announcement only when the minimum is first reached or on blur
	// below it, debounced and never repeated.
	function lengthCounter( area ) {
		if ( ! area ) {
			return;
		}
		var min    = parseInt( area.getAttribute( 'data-ndvr-min-length' ) || '0', 10 );
		var hintId = area.getAttribute( 'aria-describedby' ) || '';
		var count  = hintId ? document.getElementById( hintId + '-count' ) : null;
		var status = hintId ? document.getElementById( hintId + '-status' ) : null;
		if ( ! min || ! count || ! status ) {
			return;
		}
		var countFormat = area.getAttribute( 'data-ndvr-count-format' ) || '%1$d / %2$d';
		var reachedText = area.getAttribute( 'data-ndvr-status-reached' ) || '';
		var belowFormat = area.getAttribute( 'data-ndvr-status-format' ) || '';
		var reached     = false;
		var last        = '';
		var timer       = null;
		// Count the way the server does (ReviewLength::count()): comments and
		// any "<…>" span are removed (kses), a lone "<" with no ">" after it
		// stays as text (core's wp_pre_kses_less_than), entities are decoded,
		// invisible padding (zero-width space, word joiner, soft hyphen) is
		// dropped, whitespace runs collapse to one space, code points count.
		var space   = /[\t\n\v\f\r \u0085\u00A0\u1680\u180E\u2000-\u200A\u2028\u2029\u202F\u205F\u3000\uFEFF]+/g;
		var hidden  = /[\u200B\u2060\u00AD]/g;
		var decoder = document.createElement( 'textarea' );
		function length() {
			var text = area.value.replace( /<!--[\s\S]*?-->/g, '' ).replace( /<[^>]*?(?:(?=<)|>|$)/g, function ( m ) {
				return '>' === m.slice( -1 ) ? '' : m;
			} );
			decoder.innerHTML = text;
			return Array.from( decoder.value.replace( hidden, '' ).replace( space, ' ' ).trim() ).length;
		}
		function fill( format, n ) {
			return format.replace( '%1$d', String( n ) ).replace( '%2$d', String( min ) );
		}
		function say( text ) {
			clearTimeout( timer );
			timer = setTimeout( function () {
				if ( text && text !== last ) {
					last               = text;
					status.textContent = text;
				}
			}, 1000 );
		}
		function update( announce ) {
			var n             = length();
			count.textContent = area.value ? fill( countFormat, n ) : '';
			if ( n < min ) {
				// Below again: the next crossing is announced (`last` still stops
				// the same words twice in a row).
				reached = false;
			} else if ( ! reached ) {
				reached = true;
				if ( announce ) {
					say( reachedText );
				}
			}
		}
		area.addEventListener( 'input', function ( e ) {
			// IME: count the converted text, not the romaji/pinyin being composed.
			if ( e.isComposing ) {
				return;
			}
			update( true );
		} );
		area.addEventListener( 'compositionend', function () {
			update( true );
		} );
		area.addEventListener( 'blur', function () {
			var n = length();
			if ( n < min ) {
				say( fill( belowFormat, n ) );
			}
		} );
		// A reset form (after a successful submit) starts over.
		if ( area.form ) {
			area.form.addEventListener( 'reset', function () {
				clearTimeout( timer );
				reached            = false;
				last               = '';
				count.textContent  = '';
				status.textContent = '';
			} );
		}
		// Text the browser restored (back/forward, form restore) or a pre-filled
		// value is counted without an announcement.
		update( false );
		window.addEventListener( 'pageshow', function () {
			update( false );
		} );
	}
	Array.prototype.forEach.call( roots, function ( root ) {
		Array.prototype.forEach.call( root.querySelectorAll( '.ndvr-collect-form' ), function ( form ) {
			lengthCounter( form.querySelector( 'textarea[data-ndvr-min-length]' ) );
			if ( form.querySelector( '.ndvr-captcha' ) ) {
				var render = function () { renderCaptcha( root, form ); };
				if ( window.ndvrCaptchaLoaded ) {
					render();
				} else {
					( window.ndvrCaptchaQueue = window.ndvrCaptchaQueue || [] ).push( render );
					// Without the callback (an optimiser that strips it), the API is
					// initialised by the time the page has loaded.
					window.addEventListener( 'load', function () { setTimeout( render, 0 ); } );
				}
			}
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				submitForm( root, form );
			} );
		} );
	} );
}() );
