<?php
/**
 * Lever: custom login screen logo.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the WordPress logo on wp-login.php with a user-uploaded image,
 * and points the logo's link at the site's home instead of wordpress.org.
 *
 * Behaviour:
 *   - No upload  : login screen looks like vanilla WordPress.
 *   - Upload set : `.login h1 a` is overridden via CSS to use the uploaded
 *                  image (sized to fit the login form, no cropping), the
 *                  logo's link points to the site home, and its visible
 *                  text becomes the site name.
 *
 * Same media-picker UX as the Favicon lever: "Change logo" link beside
 * the lever title opens the WordPress media frame; selecting an image
 * AJAX-saves the attachment id and reloads the screen.
 */
class Levers_Lever_Custom_Login_Logo extends Levers_Lever {

	/** Option holding the chosen attachment id (0 = none). */
	const OPTION = 'levers_login_logo_id';

	/** Nonce action for the picker's AJAX endpoints. */
	const NONCE = 'levers_login_logo';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'custom-login-logo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Custom login logo', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Replaces the WordPress logo on the login screen with your own. Click 'Change logo' to pick or upload via the media library.", 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'branding';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'log-in';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Login-screen overrides only when an image has actually been picked.
		if ( '' !== $this->custom_url() ) {
			add_action( 'login_enqueue_scripts', array( $this, 'print_logo_css' ) );
			add_filter( 'login_headerurl', array( $this, 'logo_link_url' ) );
			add_filter( 'login_headertext', array( $this, 'logo_link_text' ) );
		}

		// Settings-screen picker (always-on in admin while the lever is on).
		if ( is_admin() ) {
			add_action( 'wp_ajax_levers_set_login_logo', array( $this, 'ajax_set_logo' ) );
			add_action( 'wp_ajax_levers_remove_login_logo', array( $this, 'ajax_remove_logo' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
			add_action( 'admin_footer', array( $this, 'print_picker_script' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Login-screen output
	 * ------------------------------------------------------------------- */

	/**
	 * Override .login h1 a with the uploaded image.
	 *
	 * @return void
	 */
	public function print_logo_css() {
		$url = $this->custom_url();

		if ( '' === $url ) {
			return;
		}
		?>
		<style id="levers-custom-login-logo">
		.login h1 a {
			background-image: url('<?php echo esc_url( $url ); ?>') !important;
			background-size: contain !important;
			background-position: center center !important;
			background-repeat: no-repeat !important;
			width: 320px !important;
			height: 80px !important;
		}
		</style>
		<?php
	}

	/**
	 * Send the logo's link to the site home instead of wordpress.org.
	 *
	 * @return string
	 */
	public function logo_link_url() {
		return home_url( '/' );
	}

	/**
	 * Use the site name for the logo's visible text/title.
	 *
	 * @return string
	 */
	public function logo_link_text() {
		return get_bloginfo( 'name' );
	}

	/**
	 * Current logo URL (empty when nothing's been picked).
	 *
	 * @return string
	 */
	private function custom_url() {
		$id = (int) get_option( self::OPTION, 0 );

		if ( $id <= 0 ) {
			return '';
		}

		$url = wp_get_attachment_url( $id );

		return is_string( $url ) ? $url : '';
	}

	/* ---------------------------------------------------------------------
	 * Settings UI
	 * ------------------------------------------------------------------- */

	/**
	 * Inline "Change logo" controls in the lever's heading row.
	 *
	 * @param bool $enabled Whether the lever is currently enabled.
	 * @return void
	 */
	public function render_extra( $enabled = false ) {
		if ( ! $enabled ) {
			return;
		}

		$has_custom = (int) get_option( self::OPTION, 0 ) > 0;
		?>
		<span class="levers-extra-sep" aria-hidden="true">&bull;</span>
		<a href="#" class="levers-favicon-link" data-levers-login-logo-pick><?php esc_html_e( 'Change logo', 'levers' ); ?></a>
		<?php if ( $has_custom ) : ?>
			<a href="#" class="levers-favicon-link levers-favicon-link--remove" data-levers-login-logo-remove><?php esc_html_e( 'Remove', 'levers' ); ?></a>
		<?php endif; ?>
		<?php
	}

	/**
	 * Make sure the media frame is loaded on the Levers settings page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_levers' !== $hook ) {
			return;
		}

		wp_enqueue_media();
	}

	/**
	 * Print the inline JS that drives the picker.
	 *
	 * @return void
	 */
	public function print_picker_script() {
		$screen = get_current_screen();

		if ( ! $screen || 'settings_page_levers' !== $screen->id ) {
			return;
		}

		$cfg = array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'title'   => __( 'Choose a login screen logo', 'levers' ),
			'button'  => __( 'Use this logo', 'levers' ),
			'confirm' => __( 'Remove the custom login logo?', 'levers' ),
			'picked'  => __( 'Login logo updated.', 'levers' ),
			'removed' => __( 'Login logo removed.', 'levers' ),
		);
		?>
		<script>
		/* Levers - login logo picker */
		( function () {
			var cfg = <?php echo wp_json_encode( $cfg ); ?>;
			var frame;

			function openPicker( e ) {
				e.preventDefault();
				if ( ! window.wp || ! window.wp.media ) { return; }

				if ( ! frame ) {
					frame = window.wp.media( {
						title:    cfg.title,
						button:   { text: cfg.button },
						library:  { type: 'image' },
						multiple: false
					} );

					frame.on( 'select', function () {
						var selection = frame.state().get( 'selection' );
						var first     = selection && selection.first();
						var id        = first && first.get ? first.get( 'id' ) : null;

						if ( ! id ) {
							toast( 'error', 'Could not read the chosen image.' );
							return;
						}

						postAjax( 'levers_set_login_logo', { attachment_id: String( id ) }, cfg.picked );
					} );
				}

				frame.open();
			}

			function removeLogo( e ) {
				e.preventDefault();
				if ( ! window.confirm( cfg.confirm ) ) { return; }
				postAjax( 'levers_remove_login_logo', {}, cfg.removed );
			}

			function toast( type, message ) {
				if ( ! window.toastr ) { return; }
				window.toastr.options = {
					closeButton:   true,
					progressBar:   true,
					positionClass: 'toast-top-right',
					timeOut:       4000
				};
				window.toastr[ type ]( message );
			}

			function postAjax( action, extra, successMessage ) {
				var body = new URLSearchParams();
				body.append( 'action', action );
				body.append( 'nonce', cfg.nonce );
				Object.keys( extra ).forEach( function ( key ) {
					body.append( key, extra[ key ] );
				} );

				fetch( cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res && res.success ) {
							toast( 'success', successMessage );
							window.setTimeout( function () { window.location.reload(); }, 1000 );
						} else {
							toast( 'error', ( res && res.data && res.data.message ) || 'Something went wrong.' );
						}
					} )
					.catch( function ( err ) {
						toast( 'error', 'Save failed: ' + ( err && err.message ? err.message : 'network error' ) );
					} );
			}

			document.addEventListener( 'click', function ( e ) {
				var pick = e.target.closest ? e.target.closest( '[data-levers-login-logo-pick]' ) : null;
				if ( pick ) { openPicker( e ); return; }
				var rem  = e.target.closest ? e.target.closest( '[data-levers-login-logo-remove]' ) : null;
				if ( rem ) { removeLogo( e ); }
			} );
		}() );
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * AJAX: store the chosen attachment id as the login logo.
	 *
	 * @return void
	 */
	public function ajax_set_logo() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'levers' ) ) );
		}

		$id = isset( $_POST['attachment_id'] ) ? (int) wp_unslash( $_POST['attachment_id'] ) : 0;

		if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Please choose an image.', 'levers' ) ) );
		}

		update_option( self::OPTION, $id, false );
		wp_send_json_success( array( 'id' => $id ) );
	}

	/**
	 * AJAX: clear the custom logo.
	 *
	 * @return void
	 */
	public function ajax_remove_logo() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		delete_option( self::OPTION );
		wp_send_json_success();
	}
}
