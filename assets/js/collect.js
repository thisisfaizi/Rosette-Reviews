/**
 * Rosette Reviews — collection forms (tokenized landing page + [ndvr-testimonial]).
 * Submits each product review independently. Vanilla JS, no jQuery.
 *
 * Captcha (RR-13): when the form container carries data-captcha-provider
 * (recaptcha|turnstile|hcaptcha) and data-captcha-key, the provider script was
 * enqueued before this one. Turnstile and hCaptcha widgets render explicitly
 * into each form's .ndvr-captcha; the token is sent as ndvr_captcha_token and
 * the widget resets after every response (tokens are single-use). The
 * tokenized landing page never sets a provider: the review link authenticates
 * the customer.
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

	// Render a form's Turnstile / hCaptcha widget once (on load if the
	// provider script isn't ready yet).
	function renderCaptcha( root, form ) {
		var el       = form.querySelector( '.ndvr-captcha' );
		var provider = captchaProvider( root );
		if ( ! el || form.ndvrWidget !== undefined ) {
			return;
		}
		try {
			if ( 'turnstile' === provider && window.turnstile ) {
				form.ndvrWidget = window.turnstile.render( el, {
					sitekey: el.getAttribute( 'data-sitekey' ) || captchaKey( root ),
					'response-field': false,
					callback: function ( token ) { form.ndvrToken = token; },
					'expired-callback': function () { form.ndvrToken = ''; },
					'error-callback': function () { form.ndvrToken = ''; }
				} );
			} else if ( 'hcaptcha' === provider && window.hcaptcha ) {
				form.ndvrWidget = window.hcaptcha.render( el, {
					sitekey: el.getAttribute( 'data-sitekey' ) || captchaKey( root ),
					size: 'invisible'
				} );
			}
		} catch ( e ) {}
	}

	function resetCaptcha( root, form ) {
		var provider = captchaProvider( root );
		form.ndvrToken = '';
		if ( form.ndvrWidget === undefined ) {
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

	// A fresh token ('' when there is no captcha, or it failed: the server
	// then reports the captcha message).
	function captchaToken( root, form ) {
		var provider = captchaProvider( root );
		var key      = captchaKey( root );
		try {
			if ( 'recaptcha' === provider && window.grecaptcha && key ) {
				return new Promise( function ( resolve ) {
					window.grecaptcha.ready( function () {
						window.grecaptcha.execute( key, { action: 'review' } ).then( resolve, function () { resolve( '' ); } );
					} );
				} );
			}
			renderCaptcha( root, form );
			if ( 'hcaptcha' === provider && window.hcaptcha && form.ndvrWidget !== undefined ) {
				return window.hcaptcha.execute( form.ndvrWidget, { async: true } ).then( function ( res ) {
					return ( res && res.response ) || '';
				}, function () { return ''; } );
			}
			if ( 'turnstile' === provider && window.turnstile && form.ndvrWidget !== undefined ) {
				return Promise.resolve( form.ndvrToken || window.turnstile.getResponse( form.ndvrWidget ) || '' );
			}
		} catch ( e ) {}
		return Promise.resolve( '' );
	}

	function submitForm( root, form ) {
		var ajaxUrl = root.getAttribute( 'data-ajax-url' );
		var action = root.getAttribute( 'data-action' );
		var msg = form.querySelector( '.ndvr-form-message' );
		var btn = form.querySelector( '.ndvr-collect-submit' );

		if ( btn ) {
			btn.disabled = true;
		}
		if ( msg ) {
			msg.textContent = '';
			msg.className = 'ndvr-form-message';
		}

		function fail( text ) {
			if ( msg ) {
				msg.textContent = text;
				msg.className = 'ndvr-form-message is-error';
			}
			if ( btn ) {
				btn.disabled = false;
			}
		}

		return captchaToken( root, form ).then( function ( token ) {
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
			renderCaptcha( root, form );
			if ( form.querySelector( '.ndvr-captcha' ) && form.ndvrWidget === undefined ) {
				window.addEventListener( 'load', function () { renderCaptcha( root, form ); } );
			}
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				submitForm( root, form );
			} );
		} );
	} );
}() );
