/* Levers - instant per-toggle save with Toastr feedback. */
( function () {
	'use strict';

	var cfg = window.leversAdmin || {};

	if ( ! cfg.ajaxurl || ! cfg.nonce ) {
		return;
	}

	// Reload the page shortly after the last successful toggle so render_extra()
	// outputs (ban-log link, favicon picker, modal triggers, etc.) reflect the
	// new state. Rapid successive toggles keep canceling the pending reload so
	// the user can flip several switches in a row before the page jumps.
	var RELOAD_DELAY_MS = 1000;
	var reloadTimer     = null;

	function cancelPendingReload() {
		if ( null !== reloadTimer ) {
			window.clearTimeout( reloadTimer );
			reloadTimer = null;
		}
	}

	function schedulePendingReload() {
		cancelPendingReload();
		reloadTimer = window.setTimeout( function () {
			window.location.reload();
		}, RELOAD_DELAY_MS );
	}

	function format( template, value ) {
		return String( template ).replace( '%s', value );
	}

	function toast( type, message ) {
		if ( ! window.toastr ) {
			return;
		}

		window.toastr.options = {
			closeButton:   true,
			progressBar:   true,
			positionClass: 'toast-top-right',
			timeOut:       4000
		};

		window.toastr[ type ]( message );
	}

	function bind( checkbox ) {
		checkbox.addEventListener( 'change', function () {
			var leverId  = checkbox.getAttribute( 'data-lever-id' );
			var title    = checkbox.getAttribute( 'data-lever-title' ) || leverId;
			var nowOn    = checkbox.checked;
			var wasOn    = ! nowOn; // The change just happened; previous was the opposite.

			// A new toggle is starting -- cancel any pending reload from a
			// prior toggle so we don't interrupt this request mid-flight.
			cancelPendingReload();

			checkbox.disabled = true;

			var body = new URLSearchParams();
			body.append( 'action',   'levers_toggle' );
			body.append( 'nonce',    cfg.nonce );
			body.append( 'lever_id', leverId );
			body.append( 'enabled',  nowOn ? '1' : '0' );

			fetch( cfg.ajaxurl, {
				method:      'POST',
				credentials: 'same-origin',
				body:        body
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					checkbox.disabled = false;

					if ( res && res.success ) {
						var responseTitle = ( res.data && res.data.title ) ? res.data.title : title;
						var template      = nowOn ? cfg.strings.enabled : cfg.strings.disabled;
						toast( 'success', format( template, responseTitle ) );
						schedulePendingReload();
					} else {
						checkbox.checked = wasOn;
						var msg = ( res && res.data && res.data.message ) || format( cfg.strings.failed, title );
						toast( 'error', msg );
					}
				} )
				.catch( function () {
					checkbox.disabled = false;
					checkbox.checked  = wasOn;
					toast( 'error', format( cfg.strings.failed, title ) );
				} );
		} );
	}

	function init() {
		var checkboxes = document.querySelectorAll(
			'.levers-item input[type="checkbox"][data-lever-id]:not(:disabled)'
		);

		checkboxes.forEach( bind );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
