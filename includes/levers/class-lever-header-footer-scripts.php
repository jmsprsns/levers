<?php
/**
 * Lever: header & footer scripts.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Three-textarea editor for injecting raw code into the front-end:
 *
 *   - <head>   via wp_head           (analytics, search-console verification)
 *   - body open via wp_body_open     (GTM <noscript> goes here)
 *   - footer    via wp_footer        (chat widgets, late tracking pings)
 *
 * The lever toggle is the master on/off: when off, nothing is echoed
 * regardless of what's saved, so you can flip it off temporarily without
 * losing the code. The "Edit code" link is only shown while the lever is
 * on, matching the other extras-bearing levers on the settings screen.
 *
 * Output is intentionally NOT escaped - the whole point is to drop raw
 * <script> tags into the page. The one security line: saving requires
 * the `unfiltered_html` capability (admins-only by default), so a lower
 * role can't smuggle a script in. AJAX endpoint also nonce-checks.
 */
class Levers_Lever_Header_Footer_Scripts extends Levers_Lever {

	/** Option storing the three blobs. */
	const OPTION = 'levers_custom_scripts';

	/** Nonce action for the editor AJAX endpoint. */
	const NONCE = 'levers_scripts_edit';

	/**
	 * Always-on wiring (regardless of lever state) - the editor needs
	 * to be reachable so users can prepare code before flipping the
	 * lever on.
	 */
	public function __construct() {
		if ( is_admin() ) {
			add_action( 'wp_ajax_levers_save_scripts', array( $this, 'ajax_save' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'header-footer-scripts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Header & footer scripts', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Inject tracking, verification and custom code into your site's head, body or footer - no theme editing required.", 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'admin-tools';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'code-xml';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Output hooks - only registered when the lever is on, so flipping
		// it off stops everything immediately without touching what's saved.
		add_action( 'wp_head', array( $this, 'output_head' ), 999 );
		add_action( 'wp_body_open', array( $this, 'output_body_open' ), 1 );
		add_action( 'wp_footer', array( $this, 'output_footer' ), 999 );
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------- */

	/**
	 * Saved code, with defaults filled in.
	 *
	 * @return array{head:string,body_open:string,footer:string}
	 */
	private function get_scripts() {
		$stored = get_option( self::OPTION, array() );

		$defaults = array(
			'head'      => '',
			'body_open' => '',
			'footer'    => '',
		);

		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		return array(
			'head'      => isset( $stored['head'] ) ? (string) $stored['head'] : '',
			'body_open' => isset( $stored['body_open'] ) ? (string) $stored['body_open'] : '',
			'footer'    => isset( $stored['footer'] ) ? (string) $stored['footer'] : '',
		);
	}

	/* ---------------------------------------------------------------------
	 * Output
	 * ------------------------------------------------------------------- */

	/**
	 * Echo the head blob verbatim. Raw on purpose.
	 *
	 * @return void
	 */
	public function output_head() {
		$scripts = $this->get_scripts();

		if ( '' !== $scripts['head'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw script injection by design; gated on save by unfiltered_html.
			echo "\n" . $scripts['head'] . "\n";
		}
	}

	/**
	 * Echo the body-open blob verbatim (GTM <noscript> goes here).
	 *
	 * @return void
	 */
	public function output_body_open() {
		$scripts = $this->get_scripts();

		if ( '' !== $scripts['body_open'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw script injection by design; gated on save by unfiltered_html.
			echo "\n" . $scripts['body_open'] . "\n";
		}
	}

	/**
	 * Echo the footer blob verbatim.
	 *
	 * @return void
	 */
	public function output_footer() {
		$scripts = $this->get_scripts();

		if ( '' !== $scripts['footer'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw script injection by design; gated on save by unfiltered_html.
			echo "\n" . $scripts['footer'] . "\n";
		}
	}

	/* ---------------------------------------------------------------------
	 * Settings UI - link + modal
	 * ------------------------------------------------------------------- */

	/**
	 * "Edit code" link in the lever's heading row + the modal markup.
	 *
	 * Renders regardless of the lever's enabled state so users can paste
	 * code first and flip the toggle on afterwards.
	 *
	 * @param bool $enabled Whether the lever is currently enabled.
	 * @return void
	 */
	public function render_extra( $enabled = false ) {
		if ( ! $enabled ) {
			return;
		}

		$scripts = $this->get_scripts();
		$can_save = current_user_can( 'unfiltered_html' );
		?>
		<span class="levers-extra-sep" aria-hidden="true">&bull;</span>
		<a href="#" class="levers-favicon-link" data-levers-scripts-edit><?php esc_html_e( 'Edit code', 'levers' ); ?></a>

		<div id="levers-scripts-modal" class="levers-modal" hidden>
			<div class="levers-modal__overlay" data-levers-close></div>
			<div class="levers-modal__panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Header & footer scripts', 'levers' ); ?>">
				<div class="levers-modal__head">
					<h2><?php esc_html_e( 'Header & footer scripts', 'levers' ); ?></h2>
					<button type="button" class="levers-modal__close" data-levers-close aria-label="<?php esc_attr_e( 'Close', 'levers' ); ?>">&times;</button>
				</div>
				<div class="levers-modal__body">
					<p class="levers-scripts__intro">
						<?php esc_html_e( 'Code is output verbatim - include raw <script> tags, meta verification tags, etc.', 'levers' ); ?>
					</p>

					<?php if ( ! $can_save ) : ?>
						<p class="levers-scripts__warning">
							<?php esc_html_e( 'Your account does not have the unfiltered_html capability, so saving is disabled. Ask an administrator.', 'levers' ); ?>
						</p>
					<?php endif; ?>

					<label class="levers-scripts__field">
						<span class="levers-scripts__label"><?php esc_html_e( 'Header', 'levers' ); ?> <span class="levers-scripts__hint"><?php esc_html_e( 'output in <head> via wp_head', 'levers' ); ?></span></span>
						<textarea name="head" rows="6" spellcheck="false"><?php echo esc_textarea( $scripts['head'] ); ?></textarea>
					</label>

					<label class="levers-scripts__field">
						<span class="levers-scripts__label"><?php esc_html_e( 'Body open', 'levers' ); ?> <span class="levers-scripts__hint"><?php esc_html_e( 'right after <body> via wp_body_open (Google Tag Manager <noscript>)', 'levers' ); ?></span></span>
						<textarea name="body_open" rows="4" spellcheck="false"><?php echo esc_textarea( $scripts['body_open'] ); ?></textarea>
					</label>

					<label class="levers-scripts__field">
						<span class="levers-scripts__label"><?php esc_html_e( 'Footer', 'levers' ); ?> <span class="levers-scripts__hint"><?php esc_html_e( 'before </body> via wp_footer', 'levers' ); ?></span></span>
						<textarea name="footer" rows="6" spellcheck="false"><?php echo esc_textarea( $scripts['footer'] ); ?></textarea>
					</label>

					<div class="levers-scripts__actions">
						<button type="button" class="button button-primary" data-levers-scripts-save<?php disabled( ! $can_save ); ?>>
							<?php esc_html_e( 'Save code', 'levers' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>

		<script>
		/* Levers - header & footer scripts editor */
		( function () {
			var modal = document.getElementById( 'levers-scripts-modal' );
			if ( ! modal ) { return; }

			var cfg = <?php echo wp_json_encode( array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'saved'   => __( 'Code saved.', 'levers' ),
				'failed'  => __( 'Could not save the code.', 'levers' ),
			) ); ?>;

			function open( e ) { if ( e ) { e.preventDefault(); } modal.hidden = false; }
			function close() { modal.hidden = true; }

			document.querySelectorAll( '[data-levers-scripts-edit]' ).forEach( function ( link ) {
				link.addEventListener( 'click', open );
			} );

			modal.addEventListener( 'click', function ( e ) {
				if ( e.target.hasAttribute && e.target.hasAttribute( 'data-levers-close' ) ) { close(); }
			} );

			document.addEventListener( 'keyup', function ( e ) {
				if ( 'Escape' === e.key && ! modal.hidden ) { close(); }
			} );

			var saveBtn = modal.querySelector( '[data-levers-scripts-save]' );
			if ( saveBtn ) {
				saveBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					saveBtn.disabled = true;

					var body = new URLSearchParams();
					body.append( 'action', 'levers_save_scripts' );
					body.append( 'nonce', cfg.nonce );

					modal.querySelectorAll( 'textarea' ).forEach( function ( ta ) {
						body.append( ta.name, ta.value );
					} );

					function report( msg ) {
						if ( window.toastr ) { window.toastr.error( msg ); } else { window.alert( msg ); }
					}

					fetch( cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function ( r ) {
							// Read as text first: a security plugin / WAF or an
							// expired nonce can return a non-JSON 403, which
							// would otherwise throw and be swallowed silently.
							return r.text().then( function ( text ) {
								var res = null;
								try { res = JSON.parse( text ); } catch ( err ) {}
								return { ok: r.ok, status: r.status, res: res };
							} );
						} )
						.then( function ( out ) {
							saveBtn.disabled = false;

							if ( out.res && out.res.success ) {
								if ( window.toastr ) { window.toastr.success( cfg.saved ); }
								close();
								return;
							}

							if ( out.res && out.res.data && out.res.data.message ) {
								report( out.res.data.message );
								return;
							}

							// Non-JSON / unexpected response - surface the HTTP
							// status so a 403 (blocked <script> payload or stale
							// session) isn't mistaken for "nothing happened".
							report( cfg.failed + ' (HTTP ' + out.status + ')' );
						} )
						.catch( function () {
							saveBtn.disabled = false;
							report( cfg.failed );
						} );
				} );
			}
		}() );
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * AJAX: store the three blobs verbatim.
	 *
	 * @return void
	 */
	public function ajax_save() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			wp_send_json_error( array( 'message' => __( 'You need the unfiltered_html capability to save scripts.', 'levers' ) ) );
		}

		$head      = isset( $_POST['head'] )      ? (string) wp_unslash( $_POST['head'] )      : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw code stored by design, capability-gated above.
		$body_open = isset( $_POST['body_open'] ) ? (string) wp_unslash( $_POST['body_open'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw code stored by design, capability-gated above.
		$footer    = isset( $_POST['footer'] )    ? (string) wp_unslash( $_POST['footer'] )    : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw code stored by design, capability-gated above.

		update_option(
			self::OPTION,
			array(
				'head'      => $head,
				'body_open' => $body_open,
				'footer'    => $footer,
			),
			false
		);

		wp_send_json_success();
	}
}
