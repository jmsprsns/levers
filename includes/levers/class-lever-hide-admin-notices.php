<?php
/**
 * Lever: hide admin notices.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds a small "Hide notice" button to the bottom-right of every admin
 * notice. Clicking it (as an admin) hides that notice site-wide for
 * everyone, from now on.
 *
 * Implementation, in two halves:
 *
 *   PHP   - stores a list of "notice fingerprints" (short string hashes
 *           computed in JS) in the levers_hidden_notices option, plus
 *           two AJAX endpoints: hide one fingerprint, reset all.
 *   JS    - on every admin page, walks the .notice / .updated / .error
 *           elements in the DOM. For each, it hashes the trimmed text
 *           content. If that hash is in the stored list, the notice is
 *           hidden; otherwise (and only for users who can manage
 *           options), a "Hide notice" button is appended.
 *
 * Fingerprinting is done in JS so both directions agree on the value
 * without PHP needing to parse notice HTML it never sees - WordPress's
 * admin_notices action is "echo your HTML" not "return a payload",
 * so there's no server-side enumeration of notices.
 */
class Levers_Lever_Hide_Admin_Notices extends Levers_Lever {

	/** Option holding the list of hidden-notice fingerprints. */
	const OPTION = 'levers_hidden_notices';

	/** Nonce action shared by both AJAX endpoints. */
	const NONCE = 'levers_hide_notice';

	/** Cap on stored fingerprints, so the option can't balloon. */
	const MAX_STORED = 500;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'hide-admin-notices';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Hide admin notices', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Adds a 'Hide notice' link to every admin notice. Click once and it stays hidden site-wide - perfect for nagging promo banners.", 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'wordpress-cleanup';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'eye-off';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'wp_ajax_levers_hide_notice', array( $this, 'ajax_hide_notice' ) );
		add_action( 'wp_ajax_levers_reset_hidden_notices', array( $this, 'ajax_reset' ) );
		add_action( 'admin_footer', array( $this, 'print_hider_assets' ) );
	}

	/* ---------------------------------------------------------------------
	 * Stored fingerprints
	 * ------------------------------------------------------------------- */

	/**
	 * Currently-hidden fingerprints, as plain strings.
	 *
	 * @return string[]
	 */
	private function get_hidden() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_values( array_filter( $stored, 'is_string' ) );
	}

	/**
	 * Whether a string looks like a fingerprint we'd accept (8-64 hex chars).
	 *
	 * @param string $hash Candidate.
	 * @return bool
	 */
	private function valid_hash( $hash ) {
		return is_string( $hash ) && (bool) preg_match( '/^[a-f0-9]{8,64}$/', $hash );
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * AJAX: append one fingerprint to the hidden list.
	 *
	 * @return void
	 */
	public function ajax_hide_notice() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'levers' ) ) );
		}

		$hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';

		if ( ! $this->valid_hash( $hash ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid notice fingerprint.', 'levers' ) ) );
		}

		$hidden = $this->get_hidden();

		if ( ! in_array( $hash, $hidden, true ) ) {
			$hidden[] = $hash;

			// Cap to a reasonable size so the option can't balloon
			// indefinitely; oldest entries fall off.
			if ( count( $hidden ) > self::MAX_STORED ) {
				$hidden = array_slice( $hidden, -self::MAX_STORED );
			}

			update_option( self::OPTION, $hidden, false );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: forget every hidden fingerprint.
	 *
	 * @return void
	 */
	public function ajax_reset() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		delete_option( self::OPTION );
		wp_send_json_success();
	}

	/* ---------------------------------------------------------------------
	 * Settings UI
	 * ------------------------------------------------------------------- */

	/**
	 * Inline "hidden notices" count + reset link in the lever's heading row.
	 *
	 * @param bool $enabled Whether the lever is currently enabled.
	 * @return void
	 */
	public function render_extra( $enabled = false ) {
		if ( ! $enabled ) {
			return;
		}

		$count = count( $this->get_hidden() );

		if ( $count <= 0 ) {
			return;
		}
		?>
		<span class="levers-extra-sep" aria-hidden="true">&bull;</span>
		<span class="levers-hidden-notices-count">
			<?php
			printf(
				/* translators: %d: number of notices currently hidden. */
				esc_html( _n( '%d notice hidden', '%d notices hidden', $count, 'levers' ) ),
				(int) $count
			);
			?>
		</span>
		<a href="#" class="levers-favicon-link levers-favicon-link--remove" data-levers-hidden-notices-reset><?php esc_html_e( 'Reset', 'levers' ); ?></a>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Inline assets
	 * ------------------------------------------------------------------- */

	/**
	 * Print the JS and tiny CSS that does the actual hiding.
	 *
	 * @return void
	 */
	public function print_hider_assets() {
		// Bail entirely for guests (nothing to hide for them anyway).
		if ( ! is_user_logged_in() ) {
			return;
		}

		$cfg = array(
			'ajaxurl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE ),
			'hidden'   => $this->get_hidden(),
			'canHide'  => current_user_can( 'manage_options' ),
			'label'    => __( 'Hide notice', 'levers' ),
			'iconHtml' => Levers_Icons::get( 'eye-off', 13 ),
		);
		?>
		<style id="levers-notice-hider-css">
		.notice.levers-notice-attached,
		.updated.levers-notice-attached,
		.error.levers-notice-attached {
			position: relative;
			padding-bottom: 26px;
		}

		.levers-notice-hide-btn {
			position: absolute;
			bottom: 6px;
			right: 8px;
			display: inline-flex;
			align-items: center;
			gap: 4px;
			padding: 2px 6px;
			background: transparent;
			border: 0;
			border-radius: 3px;
			font-size: 11px;
			line-height: 1;
			color: #646970;
			cursor: pointer;
			opacity: 0.65;
			transition: opacity 0.12s ease, color 0.12s ease, background 0.12s ease;
		}

		.levers-notice-hide-btn:hover,
		.levers-notice-hide-btn:focus {
			opacity: 1;
			color: #b32d2e;
			background: rgba(0, 0, 0, 0.04);
			outline: 0;
		}

		.levers-notice-hide-btn svg {
			width: 13px;
			height: 13px;
		}
		</style>
		<script>
		/* Levers - admin notice hider */
		( function () {
			var cfg    = <?php echo wp_json_encode( $cfg ); ?>;
			var hidden = Array.isArray( cfg.hidden ) ? cfg.hidden.slice() : [];

			/**
			 * Tiny deterministic 32-bit FNV-1a string hash. Same input -> same
			 * output across page loads, no crypto dependency, no PHP-side hash
			 * mirror needed (PHP just stores whatever string JS sends).
			 */
			function fingerprint( s ) {
				var h = 2166136261 >>> 0;
				for ( var i = 0; i < s.length; i++ ) {
					h ^= s.charCodeAt( i );
					h = ( h + ( ( h << 1 ) + ( h << 4 ) + ( h << 7 ) + ( h << 8 ) + ( h << 24 ) ) ) >>> 0;
				}
				return ( '00000000' + h.toString( 16 ) ).slice( -8 );
			}

			function noticeText( el ) {
				var clone = el.cloneNode( true );
				var strip = clone.querySelectorAll( '.notice-dismiss, .levers-notice-hide-btn' );
				for ( var i = 0; i < strip.length; i++ ) {
					strip[ i ].parentNode.removeChild( strip[ i ] );
				}
				return ( clone.textContent || '' ).replace( /\s+/g, ' ' ).trim();
			}

			function attachButton( el, fp ) {
				if ( ! cfg.canHide ) { return; }

				el.classList.add( 'levers-notice-attached' );

				var btn = document.createElement( 'button' );
				btn.type      = 'button';
				btn.className = 'levers-notice-hide-btn';
				btn.title     = cfg.label;
				btn.innerHTML = cfg.iconHtml + '<span>' + cfg.label + '</span>';

				btn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					btn.disabled = true;

					var body = new URLSearchParams();
					body.append( 'action', 'levers_hide_notice' );
					body.append( 'nonce', cfg.nonce );
					body.append( 'hash', fp );

					fetch( cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							if ( res && res.success ) {
								hidden.push( fp );
								el.style.display = 'none';
							} else {
								btn.disabled = false;
							}
						} )
						.catch( function () { btn.disabled = false; } );
				} );

				el.appendChild( btn );
			}

			function process() {
				var nodes = document.querySelectorAll( '.notice, .updated, .error' );
				for ( var i = 0; i < nodes.length; i++ ) {
					var el = nodes[ i ];

					if ( el.dataset.leversNoticeHandled === '1' ) { continue; }
					el.dataset.leversNoticeHandled = '1';

					// Skip our own footer-rendered styles/scripts in case
					// they ever sit inside something selector-matching.
					if ( el.id && el.id.indexOf( 'levers-' ) === 0 ) { continue; }

					var fp = fingerprint( noticeText( el ) );

					if ( hidden.indexOf( fp ) !== -1 ) {
						el.style.display = 'none';
						continue;
					}

					attachButton( el, fp );
				}
			}

			function start() {
				process();

				// Some plugins (and Gutenberg) inject notices after load -
				// keep watching.
				if ( window.MutationObserver ) {
					new MutationObserver( process ).observe( document.body, {
						childList: true,
						subtree:   true
					} );
				}
			}

			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', start );
			} else {
				start();
			}

			// Reset link on the Levers settings page.
			document.addEventListener( 'click', function ( e ) {
				var target = e.target.closest ? e.target.closest( '[data-levers-hidden-notices-reset]' ) : null;
				if ( ! target ) { return; }
				e.preventDefault();

				if ( ! window.confirm( <?php echo wp_json_encode( __( 'Show every previously-hidden notice again?', 'levers' ) ); ?> ) ) {
					return;
				}

				var body = new URLSearchParams();
				body.append( 'action', 'levers_reset_hidden_notices' );
				body.append( 'nonce', cfg.nonce );

				fetch( cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res && res.success ) {
							window.location.reload();
						}
					} );
			} );
		}() );
		</script>
		<?php
	}
}
