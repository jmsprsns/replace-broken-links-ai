/**
 * Content Powered > Replace Broken Links page behaviour. Only loaded on that page.
 *
 * - "Scan now" runs the scan in short AJAX batches with a progress bar, then
 *   reloads the page. Without JavaScript the button still works: the form
 *   starts the scan and WP-Cron runs it.
 * - Connections: show the chosen provider's fields, "Other model..." boxes,
 *   and the Test connection buttons.
 * - Advanced: insert placeholders, reset a prompt, and the test lookup.
 * - Broken Links: "Find a replacement now", "Use this" and "Use my link".
 *
 * replace_broken_links_ai_admin.ajaxUrl, .nonce, .defaults and .i18n are
 * passed in from PHP (see Replace_Broken_Links_AI_Admin::enqueue_assets).
 */

/* global replace_broken_links_ai_admin */
( function () {
	'use strict';

	var data = window.replace_broken_links_ai_admin;

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' || ! window.fetch ) {
			return;
		}

		initScan();
		initProviders();
		initModels();
		initTests();
		initPrompts();
		initTry();
		initFind();
		initChoose();
		initResume();
	} );

	// AJAX -----------------------------------------------------------------------

	function post( action, fields ) {
		var body = new window.FormData();

		body.append( 'action', 'replace_broken_links_ai_' + action );
		body.append( 'nonce', data.nonce );
		Object.keys( fields || {} ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return window.fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.json();
		} );
	}

	function message( json ) {
		return json && json.data && json.data.message ? json.data.message : data.i18n.failed;
	}

	function show( element, text, ok ) {
		if ( ! element ) {
			return;
		}

		element.textContent = text;
		element.classList.toggle( 'is-ok', ok === true );
		element.classList.toggle( 'is-error', ok === false );
	}

	// Scan -----------------------------------------------------------------------

	function initScan() {
		var box = document.querySelector( '[data-rblai-scan]' );

		if ( ! box ) {
			return;
		}

		var button = box.querySelector( '[data-rblai-scan-button]' );
		var status = box.querySelector( '[data-rblai-scan-status]' );
		var progress = box.querySelector( '[data-rblai-progress]' );
		var bar = progress ? progress.querySelector( 'span' ) : null;
		var failures = 0;

		function setRunning( running ) {
			box.classList.toggle( 'is-running', running );
			button.disabled = running;
			if ( progress ) {
				progress.hidden = ! running;
			}
		}

		function step( start ) {
			post( 'scan', { step: start ? 'start' : 'run' } )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error( 'scan' );
					}

					var moved = status.textContent !== json.data.status;

					failures = 0;
					status.textContent = json.data.status;
					if ( bar ) {
						bar.style.width = json.data.percent + '%';
					}

					if ( json.data.running ) {
						// No progress usually means a background batch has the
						// lock; give it a moment instead of asking again at once.
						window.setTimeout( function () {
							step( false );
						}, moved ? 0 : 1500 );
					} else {
						window.location.reload();
					}
				} )
				.catch( function () {
					// A slow batch or a blip: try a few more times before giving up.
					failures++;
					if ( failures < 4 ) {
						window.setTimeout( function () {
							step( false );
						}, 3000 );
						return;
					}

					status.textContent = data.i18n.scanError;
					setRunning( false );
				} );
		}

		button.closest( 'form' ).addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( bar ) {
				bar.style.width = '0%';
			}
			setRunning( true );
			step( true );
		} );

		if ( '1' === box.getAttribute( 'data-running' ) ) {
			setRunning( true );
			step( false );
		}
	}

	// Connections ------------------------------------------------------------------

	// Show only the chosen provider's key and model fields. Without
	// JavaScript both stay visible, which works just as well.
	function initProviders() {
		var radios = document.querySelectorAll( 'input[name$="[provider]"]' );
		var panels = document.querySelectorAll( '[data-rblai-provider]' );

		if ( ! panels.length || ! radios.length ) {
			return;
		}

		function update( provider ) {
			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-rblai-provider' ) !== provider;
			} );
		}

		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				update( radio.value );
			} );
		} );

		var checked = document.querySelector( 'input[name$="[provider]"]:checked' );
		update( checked ? checked.value : 'openai' );
	}

	// "Other model..." shows a box for any model ID.
	function initModels() {
		document.querySelectorAll( 'select[data-rblai-model]' ).forEach( function ( select ) {
			var custom = select.parentNode.querySelector( '[data-rblai-custom]' );

			function update() {
				if ( custom ) {
					custom.hidden = select.value !== '__custom';
					if ( ! custom.hidden ) {
						custom.focus();
					}
				}
			}

			select.addEventListener( 'change', update );
		} );
	}

	// Add models an account has to the pickers after a successful test.
	function addModels( provider, models ) {
		if ( ! Array.isArray( models ) ) {
			return;
		}

		document.querySelectorAll( 'select[data-rblai-model="' + provider + '"]' ).forEach( function ( select ) {
			var custom = select.querySelector( 'option[value="__custom"]' );
			var have = {};

			Array.prototype.forEach.call( select.options, function ( option ) {
				have[ option.value ] = true;
			} );

			models.forEach( function ( id ) {
				if ( ! have[ id ] ) {
					var option = document.createElement( 'option' );
					option.value = id;
					option.textContent = id;
					select.insertBefore( option, custom );
				}
			} );
		} );
	}

	function initTests() {
		document.querySelectorAll( '[data-rblai-test]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var type = button.getAttribute( 'data-rblai-test' );
				var provider = button.getAttribute( 'data-provider' ) || '';
				var name = 'ai' === type ? provider : 'search';
				var result = document.querySelector( '[data-rblai-test-result="' + name + '"]' );
				var keyField = document.querySelector( '[data-rblai-key="' + ( 'ai' === type ? provider : 'serper' ) + '"]' );
				var model = document.querySelector( 'select[name$="[' + provider + '_model]"]' );
				var fields = { key: keyField ? keyField.value : '' };

				if ( 'ai' === type ) {
					fields.provider = provider;
					fields.model = model && model.value !== '__custom' ? model.value : ( model ? model.parentNode.querySelector( '[data-rblai-custom]' ).value : '' );
				}

				button.disabled = true;
				show( result, data.i18n.testing, null );

				post( 'ai' === type ? 'test_ai' : 'test_search', fields )
					.then( function ( json ) {
						show( result, message( json ), !! ( json && json.success ) );
						if ( json && json.data && json.data.models ) {
							addModels( provider, json.data.models );
						}
					} )
					.catch( function () {
						show( result, data.i18n.failed, false );
					} )
					.then( function () {
						button.disabled = false;
					} );
			} );
		} );
	}

	// Advanced ---------------------------------------------------------------------

	function initPrompts() {
		document.querySelectorAll( '[data-rblai-token]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var area = document.getElementById( button.getAttribute( 'data-target' ) );
				var token = button.getAttribute( 'data-rblai-token' );

				if ( ! area ) {
					return;
				}

				var start = area.selectionStart || 0;
				var end = area.selectionEnd || 0;

				area.value = area.value.slice( 0, start ) + token + area.value.slice( end );
				area.focus();
				area.selectionStart = area.selectionEnd = start + token.length;
			} );
		} );

		document.querySelectorAll( '[data-rblai-reset]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var step = button.getAttribute( 'data-rblai-reset' );
				var area = document.querySelector( '[data-rblai-prompt="' + step + '"]' );

				if ( area && data.defaults[ step ] && window.confirm( data.i18n.reset ) ) {
					area.value = data.defaults[ step ];
				}
			} );
		} );
	}

	function initTry() {
		var box = document.querySelector( '[data-rblai-try]' );

		if ( ! box ) {
			return;
		}

		var button = box.querySelector( '[data-rblai-try-run]' );
		var result = box.querySelector( '[data-rblai-try-result]' );

		button.addEventListener( 'click', function () {
			var url = box.querySelector( '[data-rblai-try-url]' ).value.trim();

			if ( ! /^https?:\/\//i.test( url ) ) {
				result.classList.add( 'is-error' );
				result.textContent = data.i18n.needUrl;
				return;
			}

			button.disabled = true;
			result.classList.remove( 'is-error' );
			result.textContent = data.i18n.running;

			post( 'try', {
				url: url,
				anchor: box.querySelector( '[data-rblai-try-anchor]' ).value,
				context: box.querySelector( '[data-rblai-try-context]' ).value
			} )
				.then( function ( json ) {
					if ( json && json.success ) {
						// Built and escaped on the server.
						result.innerHTML = json.data.html;
					} else {
						result.classList.add( 'is-error' );
						result.textContent = message( json );
					}
				} )
				.catch( function () {
					result.classList.add( 'is-error' );
					result.textContent = data.i18n.failed;
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	}

	// Broken Links -----------------------------------------------------------------

	function notice( text, ok ) {
		var wrap = document.querySelector( '.rblai-wrap' );
		var end = wrap ? wrap.querySelector( '.wp-header-end' ) : null;
		var box = document.createElement( 'div' );
		var p = document.createElement( 'p' );

		box.className = 'notice is-dismissible notice-' + ( ok ? 'success' : 'error' );
		p.textContent = text;
		box.appendChild( p );

		if ( end ) {
			end.parentNode.insertBefore( box, end.nextSibling );
			window.scrollTo( { top: 0, behavior: 'smooth' } );
		}
	}

	function initFind() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-rblai-find]' );

			if ( ! button || button.classList.contains( 'is-busy' ) ) {
				return;
			}

			event.preventDefault();
			button.classList.add( 'is-busy' );

			var label = button.querySelector( 'span' );
			var original = label ? label.textContent : '';

			if ( label ) {
				label.textContent = data.i18n.finding;
			}

			post( 'find', { id: button.getAttribute( 'data-rblai-find' ) } )
				.then( function ( json ) {
					if ( json && json.success ) {
						window.location.reload();
						return;
					}

					notice( message( json ), false );
					button.classList.remove( 'is-busy' );
					if ( label ) {
						label.textContent = original;
					}
				} )
				.catch( function () {
					notice( data.i18n.failed, false );
					button.classList.remove( 'is-busy' );
					if ( label ) {
						label.textContent = original;
					}
				} );
		} );
	}

	function initChoose() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-rblai-choose]' );

			if ( ! button ) {
				return;
			}

			var id = button.getAttribute( 'data-rblai-choose' );
			var number = button.getAttribute( 'data-number' );
			var result = document.querySelector( '[data-rblai-choice-result="' + id + '"]' );
			var own = document.querySelector( '[data-rblai-own="' + id + '"]' );
			var url = '0' === number && own ? own.value.trim() : '';

			event.preventDefault();

			if ( '0' === number && ! /^https?:\/\/\S+$/i.test( url ) ) {
				show( result, data.i18n.needUrl, false );
				if ( own ) {
					own.focus();
				}
				return;
			}

			button.disabled = true;
			show( result, data.i18n.saving, null );

			post( 'choose', { id: id, number: number, url: url } )
				.then( function ( json ) {
					show( result, message( json ), !! ( json && json.success ) );
					if ( json && json.success ) {
						window.setTimeout( function () {
							window.location.reload();
						}, 700 );
					} else {
						button.disabled = false;
					}
				} )
				.catch( function () {
					show( result, data.i18n.failed, false );
					button.disabled = false;
				} );
		} );
	}

	function initResume() {
		var button = document.querySelector( '[data-rblai-resume]' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			post( 'resume', {} ).then( function () {
				window.location.reload();
			} );
		} );
	}
}() );
