/**
 * Rosette Reviews — front-end review form submission + UI enhancements.
 * Vanilla JS, no jQuery. Intercepts the WooCommerce review form and submits
 * it over AJAX so there is no full page reload.
 */
( function () {
	'use strict';

	var cfg = window.ndvrReviews || {};
	var form = document.getElementById( 'ndvr-review-form' );

	if ( ! form ) {
		return;
	}

	var messageEl = form.querySelector( '.ndvr-form-message' );

	// ── Recommend pills ── JS fallback for browsers without CSS :has() ──────
	var recommendLabels = form.querySelectorAll( '.ndvr-field-recommend label' );
	function syncRecommendPills() {
		recommendLabels.forEach( function ( label ) {
			var radio = label.querySelector( 'input[type="radio"]' );
			if ( radio ) {
				label.classList.toggle( 'is-checked', radio.checked );
			}
		} );
	}
	recommendLabels.forEach( function ( label ) {
		label.addEventListener( 'click', function () {
			// Let the browser process the click first, then sync
			setTimeout( syncRecommendPills, 0 );
		} );
	} );
	syncRecommendPills(); // mark the pre-checked "Neutral" on load

	// Focus-ring fallback for browsers without :has() — the radio is invisible,
	// so keyboard focus has to be shown on its pill.
	recommendLabels.forEach( function ( label ) {
		var radio = label.querySelector( 'input[type="radio"]' );
		if ( ! radio ) {
			return;
		}
		radio.addEventListener( 'focus', function () {
			var visible = true;
			try {
				visible = radio.matches( ':focus-visible' );
			} catch ( e ) {}
			label.classList.toggle( 'has-focus', visible );
		} );
		radio.addEventListener( 'blur', function () {
			label.classList.remove( 'has-focus' );
		} );
		radio.addEventListener( 'change', syncRecommendPills );
	} );

	// ── Upload zone enhancements ─────────────────────────────────────────────
	var uploadWrappers = form.querySelectorAll( '.ndvr-upload-wrapper' );
	uploadWrappers.forEach( function ( wrapper ) {
		var fileInput = wrapper.querySelector( 'input[type="file"]' );
		var countEl   = wrapper.querySelector( '.ndvr-upload-count' );

		if ( ! fileInput ) return;

		function updateCount() {
			if ( ! countEl ) return;
			var n = fileInput.files ? fileInput.files.length : 0;
			if ( n === 0 ) {
				countEl.textContent = '';
			} else {
				countEl.textContent = n === 1
					? '1 photo selected'
					: n + ' photos selected';
			}
		}

		fileInput.addEventListener( 'change', updateCount );

		// Drag-over visual feedback
		wrapper.addEventListener( 'dragenter', function ( e ) {
			e.preventDefault();
			wrapper.classList.add( 'is-dragging' );
		} );
		wrapper.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
		} );
		wrapper.addEventListener( 'dragleave', function ( e ) {
			if ( ! wrapper.contains( e.relatedTarget ) ) {
				wrapper.classList.remove( 'is-dragging' );
			}
		} );
		wrapper.addEventListener( 'drop', function () {
			wrapper.classList.remove( 'is-dragging' );
			setTimeout( updateCount, 0 );
		} );
	} );

	// ── Form submission ──────────────────────────────────────────────────────
	if ( ! cfg.ajaxUrl ) {
		return;
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
	lengthCounter( form.querySelector( 'textarea[data-ndvr-min-length]' ) );

	function setMessage( text, type ) {
		if ( ! messageEl ) {
			return;
		}
		messageEl.textContent = text;
		messageEl.className = 'ndvr-form-message' + ( type ? ' is-' + type : '' );
	}

	// Captcha (RR-13): reCAPTCHA v3, Cloudflare Turnstile or hCaptcha. The
	// provider script is enqueued before this one; widgets render explicitly.
	// Tokens are single-use, so the widget resets after every response.
	var captcha    = ( cfg.captcha && cfg.captcha.provider ) ? cfg.captcha : { provider: 'none', siteKey: '' };
	var tokenInput = form.querySelector( 'input[name="ndvr_captcha_token"]' );
	var widgetEl   = form.querySelector( '.ndvr-captcha' );
	var widgetId   = null;

	function setToken( token ) {
		if ( tokenInput ) {
			tokenInput.value = token || '';
		}
	}

	function renderWidget() {
		if ( ! widgetEl || null !== widgetId ) {
			return;
		}
		var key = widgetEl.getAttribute( 'data-sitekey' ) || '';
		try {
			if ( 'turnstile' === captcha.provider && window.turnstile ) {
				widgetId = window.turnstile.render( widgetEl, {
					sitekey: key,
					'response-field': false,
					callback: setToken,
					'expired-callback': function () { setToken( '' ); },
					'error-callback': function () { setToken( '' ); }
				} );
			} else if ( 'hcaptcha' === captcha.provider && window.hcaptcha ) {
				widgetId = window.hcaptcha.render( widgetEl, {
					sitekey: key,
					size: 'invisible',
					callback: setToken,
					'expired-callback': function () { setToken( '' ); }
				} );
			}
		} catch ( e ) {
			widgetId = null;
		}
	}

	function resetCaptcha() {
		setToken( '' );
		try {
			if ( null !== widgetId && 'turnstile' === captcha.provider && window.turnstile ) {
				window.turnstile.reset( widgetId );
			} else if ( null !== widgetId && 'hcaptcha' === captcha.provider && window.hcaptcha ) {
				window.hcaptcha.reset( widgetId );
			}
		} catch ( e ) {}
	}

	renderWidget();
	if ( widgetEl && null === widgetId ) {
		window.addEventListener( 'load', renderWidget );
	}

	// Get a fresh token, then submit. A provider that fails or is blocked
	// submits without one, and the server reports the captcha message.
	function withCaptcha( callback ) {
		try {
			if ( 'recaptcha' === captcha.provider && window.grecaptcha && captcha.siteKey ) {
				window.grecaptcha.ready( function () {
					window.grecaptcha.execute( captcha.siteKey, { action: 'review' } ).then( function ( token ) {
						setToken( token );
						callback();
					}, callback );
				} );
				return;
			}
			if ( 'hcaptcha' === captcha.provider && window.hcaptcha ) {
				renderWidget();
				if ( null !== widgetId ) {
					window.hcaptcha.execute( widgetId, { async: true } ).then( function ( res ) {
						setToken( res && res.response );
						callback();
					}, callback );
					return;
				}
			}
			if ( 'turnstile' === captcha.provider && window.turnstile ) {
				renderWidget();
				if ( null !== widgetId && tokenInput && ! tokenInput.value ) {
					setToken( window.turnstile.getResponse( widgetId ) );
				}
			}
		} catch ( e ) {}
		callback();
	}

	function submit() {
		// Require at least one star rating before submitting — a rating-less
		// review would be excluded from the product average.
		var ratingChecked = form.querySelector( 'input[name^="ndvr_criteria"]:checked' );
		if ( ! ratingChecked || parseFloat( ratingChecked.value ) <= 0 ) {
			setMessage( ( cfg.i18n && cfg.i18n.rating ) || 'Please give a star rating before submitting.', 'error' );
			return;
		}

		var data = new FormData( form );
		data.append( 'action', cfg.action );

		// Ensure the product id is present (WooCommerce uses comment_post_ID).
		if ( ! data.get( 'product_id' ) && data.get( 'comment_post_ID' ) ) {
			data.append( 'product_id', data.get( 'comment_post_ID' ) );
		}

		form.classList.add( 'is-submitting' );
		setMessage( ( cfg.i18n && cfg.i18n.submitting ) || 'Submitting…', '' );

		// `action` also travels in the URL: an upload larger than post_max_size
		// makes PHP drop the body, and admin-ajax.php still needs the action to
		// return the size message.
		var url = cfg.ajaxUrl + ( cfg.ajaxUrl.indexOf( '?' ) < 0 ? '?' : '&' ) + 'action=' + encodeURIComponent( cfg.action );

		fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				form.classList.remove( 'is-submitting' );
				resetCaptcha();
				if ( res && res.success ) {
					setMessage( ( res.data && res.data.message ) || ( cfg.i18n && cfg.i18n.thanks ), 'success' );
					form.reset();
					// Reset upload count displays
					uploadWrappers.forEach( function ( wrapper ) {
						var c = wrapper.querySelector( '.ndvr-upload-count' );
						if ( c ) c.textContent = '';
					} );
					// Reset recommend pills
					syncRecommendPills();
				} else {
					setMessage( ( res && res.data && res.data.message ) || ( cfg.i18n && cfg.i18n.error ), 'error' );
				}
			} )
			.catch( function () {
				form.classList.remove( 'is-submitting' );
				resetCaptcha();
				setMessage( ( cfg.i18n && cfg.i18n.error ) || 'Something went wrong.', 'error' );
			} );
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		withCaptcha( submit );
	} );
}() );
