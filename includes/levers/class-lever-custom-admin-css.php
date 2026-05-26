<?php
/**
 * Lever: custom admin CSS.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single-textarea editor for injecting arbitrary CSS into the WordPress
 * admin via admin_head (front-end and wp-login.php are untouched).
 *
 * The lever toggle is the master on/off: when off, nothing is echoed
 * regardless of what's saved, so you can flip it off temporarily without
 * losing the code. The "Edit CSS" link opens the modal whether the
 * toggle is on or off, so users can paste CSS first and flip the lever
 * on afterwards.
 *
 * Saving is gated by the `unfiltered_html` capability (admins-only by
 * default) since CSS can @import remote stylesheets and reference
 * arbitrary URLs. AJAX endpoint also nonce-checks.
 */
class Levers_Lever_Custom_Admin_Css extends Levers_Lever {

	/** Option storing the CSS blob. */
	const OPTION = 'levers_custom_admin_css';

	/** Nonce action for the editor AJAX endpoint. */
	const NONCE = 'levers_admin_css_edit';

	/**
	 * Always-on wiring (regardless of lever state) - the editor needs
	 * to be reachable so users can prepare CSS before flipping the
	 * lever on.
	 */
	public function __construct() {
		if ( is_admin() ) {
			add_action( 'wp_ajax_levers_save_admin_css', array( $this, 'ajax_save' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'custom-admin-css';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Custom admin CSS', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Inject your own CSS into the WordPress admin - restyle the dashboard, menus or any wp-admin screen.", 'levers' );
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
		return 'paintbrush-vertical';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_action( 'admin_head', array( $this, 'output_css' ), 999 );
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------- */

	/**
	 * Saved CSS string.
	 *
	 * @return string
	 */
	private function get_css() {
		$stored = get_option( self::OPTION, '' );

		return is_string( $stored ) ? $stored : '';
	}

	/* ---------------------------------------------------------------------
	 * Output
	 * ------------------------------------------------------------------- */

	/**
	 * Echo the CSS inside a <style> tag.
	 *
	 * @return void
	 */
	public function output_css() {
		$css = $this->get_css();

		if ( '' === trim( $css ) ) {
			return;
		}

		// Defensive: strip any </style> sequences so a stray closing tag
		// can't escape the <style> block. Capability-gated on save, but
		// belt-and-suspenders.
		$css = str_ireplace( '</style>', '', $css );

		echo "\n<style id=\"levers-custom-admin-css\">\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw CSS by design; gated on save by unfiltered_html, </style> stripped above.
		echo $css;
		echo "\n</style>\n";
	}

	/* ---------------------------------------------------------------------
	 * Settings UI - link + modal
	 * ------------------------------------------------------------------- */

	/**
	 * "Edit CSS" link in the lever's heading row + the modal markup.
	 *
	 * Renders regardless of the lever's enabled state so users can paste
	 * CSS first and flip the toggle on afterwards.
	 *
	 * @param bool $enabled Whether the lever is currently enabled.
	 * @return void
	 */
	public function render_extra( $enabled = false ) {
		unset( $enabled ); // Intentionally ignored.

		$css      = $this->get_css();
		$can_save = current_user_can( 'unfiltered_html' );
		?>
		<span class="levers-extra-sep" aria-hidden="true">&bull;</span>
		<a href="#" class="levers-favicon-link" data-levers-admin-css-edit><?php esc_html_e( 'Edit CSS', 'levers' ); ?></a>

		<div id="levers-admin-css-modal" class="levers-modal" hidden>
			<div class="levers-modal__overlay" data-levers-close></div>
			<div class="levers-modal__panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Custom admin CSS', 'levers' ); ?>">
				<div class="levers-modal__head">
					<h2><?php esc_html_e( 'Custom admin CSS', 'levers' ); ?></h2>
					<button type="button" class="levers-modal__close" data-levers-close aria-label="<?php esc_attr_e( 'Close', 'levers' ); ?>">&times;</button>
				</div>
				<div class="levers-modal__body">
					<p class="levers-scripts__intro">
						<?php esc_html_e( 'CSS is added to a <style> tag in the <head> of every wp-admin page.', 'levers' ); ?>
					</p>

					<?php if ( ! $can_save ) : ?>
						<p class="levers-scripts__warning">
							<?php esc_html_e( 'Your account does not have the unfiltered_html capability, so saving is disabled. Ask an administrator.', 'levers' ); ?>
						</p>
					<?php endif; ?>

					<label class="levers-scripts__field">
						<span class="levers-scripts__label"><?php esc_html_e( 'Your CSS', 'levers' ); ?></span>
						<textarea name="css" rows="14" spellcheck="false"><?php echo esc_textarea( $css ); ?></textarea>
					</label>

					<div class="levers-scripts__actions">
						<button type="button" class="button button-primary" data-levers-admin-css-save<?php disabled( ! $can_save ); ?>>
							<?php esc_html_e( 'Save CSS', 'levers' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>

		<script>
		/* Levers - custom admin CSS editor */
		( function () {
			var modal = document.getElementById( 'levers-admin-css-modal' );
			if ( ! modal ) { return; }

			var cfg = <?php echo wp_json_encode( array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'saved'   => __( 'CSS saved.', 'levers' ),
				'failed'  => __( 'Could not save the CSS.', 'levers' ),
			) ); ?>;

			function open( e ) { if ( e ) { e.preventDefault(); } modal.hidden = false; }
			function close() { modal.hidden = true; }

			document.querySelectorAll( '[data-levers-admin-css-edit]' ).forEach( function ( link ) {
				link.addEventListener( 'click', open );
			} );

			modal.addEventListener( 'click', function ( e ) {
				if ( e.target.hasAttribute && e.target.hasAttribute( 'data-levers-close' ) ) { close(); }
			} );

			document.addEventListener( 'keyup', function ( e ) {
				if ( 'Escape' === e.key && ! modal.hidden ) { close(); }
			} );

			var saveBtn = modal.querySelector( '[data-levers-admin-css-save]' );
			if ( saveBtn ) {
				saveBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					saveBtn.disabled = true;

					var body = new URLSearchParams();
					body.append( 'action', 'levers_save_admin_css' );
					body.append( 'nonce', cfg.nonce );

					modal.querySelectorAll( 'textarea' ).forEach( function ( ta ) {
						body.append( ta.name, ta.value );
					} );

					fetch( cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							saveBtn.disabled = false;
							if ( res && res.success ) {
								if ( window.toastr ) { window.toastr.success( cfg.saved ); }
								close();
							} else if ( window.toastr ) {
								window.toastr.error( ( res && res.data && res.data.message ) || cfg.failed );
							} else {
								window.alert( cfg.failed );
							}
						} )
						.catch( function () { saveBtn.disabled = false; } );
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
	 * AJAX: store the CSS blob verbatim.
	 *
	 * @return void
	 */
	public function ajax_save() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			wp_send_json_error( array( 'message' => __( 'You need the unfiltered_html capability to save CSS.', 'levers' ) ) );
		}

		$css = isset( $_POST['css'] ) ? (string) wp_unslash( $_POST['css'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw CSS stored by design, capability-gated above.

		update_option( self::OPTION, $css, false );

		wp_send_json_success();
	}
}
