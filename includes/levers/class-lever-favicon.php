<?php
/**
 * Lever: favicon.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sets a favicon on both the front end and the WordPress admin.
 *
 * Behaviour:
 *   - Custom upload  - if the admin has picked an image via the lever's
 *                      "Change favicon" link, that image is emitted on both
 *                      the front-end and the admin, with the correct MIME
 *                      type for its file extension (PNG, ICO, SVG, ...).
 *   - No upload      - the lever falls back to a /favicon.ico at the site
 *                      root, mirroring it into the admin's <head> so the
 *                      dashboard's browser tab gets the same icon the
 *                      front end shows.
 */
class Levers_Lever_Favicon extends Levers_Lever {

	/** Option storing the chosen attachment id (0 when none). */
	const OPTION = 'levers_favicon_id';

	/** Nonce action for the picker's AJAX endpoints. */
	const NONCE = 'levers_favicon';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'favicon';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Favicon', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Shows your favicon on the WordPress admin too, not just on the front end. Use "Change favicon" to pick a custom one - PNG, ICO, SVG, etc.', 'levers' );
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
		return 'app-window';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Run late so our link sits after WP's site_icon and theme output -
		// browsers use the last matching <link rel="icon"> they find.
		add_action( 'wp_head', array( $this, 'output_favicon' ), 999 );
		add_action( 'admin_head', array( $this, 'output_favicon' ), 999 );

		if ( is_admin() ) {
			add_action( 'wp_ajax_levers_set_favicon', array( $this, 'ajax_set_favicon' ) );
			add_action( 'wp_ajax_levers_remove_favicon', array( $this, 'ajax_remove_favicon' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
			add_action( 'admin_footer', array( $this, 'print_picker_script' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Output
	 * ------------------------------------------------------------------- */

	/**
	 * Emit the favicon <link> in the page's <head>.
	 *
	 * @return void
	 */
	public function output_favicon() {
		$url = $this->custom_url();

		if ( '' !== $url ) {
			printf(
				'<link rel="icon" type="%1$s" href="%2$s" />' . "\n",
				esc_attr( $this->mime_for_url( $url ) ),
				esc_url( $url )
			);
			return;
		}

		// No upload: mirror /favicon.ico into the admin head so the
		// dashboard tab gets whatever the front end already serves.
		if ( is_admin() && file_exists( ABSPATH . 'favicon.ico' ) ) {
			printf(
				'<link rel="icon" type="image/x-icon" href="%s" />' . "\n",
				esc_url( home_url( '/favicon.ico' ) )
			);
		}
	}

	/**
	 * URL of the currently selected favicon attachment, or '' if none.
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

	/**
	 * MIME type to advertise for a favicon URL, based on the file extension.
	 *
	 * @param string $url URL of the favicon image.
	 * @return string
	 */
	private function mime_for_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );

		$map = array(
			'ico'  => 'image/x-icon',
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif'  => 'image/gif',
			'svg'  => 'image/svg+xml',
			'webp' => 'image/webp',
			'bmp'  => 'image/bmp',
			'avif' => 'image/avif',
		);

		return isset( $map[ $ext ] ) ? $map[ $ext ] : 'image/png';
	}

	/* ---------------------------------------------------------------------
	 * Settings UI
	 * ------------------------------------------------------------------- */

	/**
	 * Inline "Change favicon" controls in the lever's heading row.
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
		<a href="#" class="levers-favicon-link" data-levers-favicon-pick><?php esc_html_e( 'Change favicon', 'levers' ); ?></a>
		<?php if ( $has_custom ) : ?>
			<a href="#" class="levers-favicon-link levers-favicon-link--remove" data-levers-favicon-remove><?php esc_html_e( 'Remove', 'levers' ); ?></a>
		<?php endif; ?>
		<?php
	}

	/**
	 * Make sure the WordPress media frame's scripts are loaded on the
	 * Levers settings page.
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
	 * Print the inline JS that drives the picker and the remove button.
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
			'title'   => __( 'Choose a favicon', 'levers' ),
			'button'  => __( 'Use this favicon', 'levers' ),
			'confirm' => __( 'Remove the current favicon?', 'levers' ),
			'picked'  => __( 'Favicon updated.', 'levers' ),
			'removed' => __( 'Favicon removed.', 'levers' ),
		);
		?>
		<script>
		/* Levers - favicon picker */
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

						saveFavicon( id );
					} );
				}

				frame.open();
			}

			function saveFavicon( id ) {
				postAjax( 'levers_set_favicon', { attachment_id: String( id ) }, cfg.picked );
			}

			function removeFavicon( e ) {
				e.preventDefault();
				if ( ! window.confirm( cfg.confirm ) ) { return; }
				postAjax( 'levers_remove_favicon', {}, cfg.removed );
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
						if ( window.console && window.console.log ) {
							window.console.log( '[Levers favicon] AJAX response:', res );
						}
						if ( ! res || ! res.success ) {
							toast( 'error', ( res && res.data && res.data.message ) || 'Something went wrong.' );
							return;
						}

						var data = res.data || {};
						// 'data.id' is set for save, absent for remove. Verify the
						// post-write read-back matches what we just told the server.
						if ( 'undefined' !== typeof data.id && data.read_back !== data.id ) {
							window.toastr.options.timeOut       = 0;
							window.toastr.options.extendedTimeOut = 0;
							toast(
								'error',
								'Save did not persist. Sent id=' + data.id +
								', updated=' + data.updated +
								', read_back=' + data.read_back +
								'. Likely a pre_update_option_* filter or busted object cache on this host.'
							);
							return;
						}

						toast( 'success', successMessage );
						window.setTimeout( function () { window.location.reload(); }, 1000 );
					} )
					.catch( function ( err ) {
						toast( 'error', 'Save failed: ' + ( err && err.message ? err.message : 'network error' ) );
					} );
			}

			document.addEventListener( 'click', function ( e ) {
				var pick = e.target.closest ? e.target.closest( '[data-levers-favicon-pick]' ) : null;
				if ( pick ) { openPicker( e ); return; }
				var rem  = e.target.closest ? e.target.closest( '[data-levers-favicon-remove]' ) : null;
				if ( rem ) { removeFavicon( e ); }
			} );
		}() );
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * AJAX: store the chosen attachment id as the favicon.
	 *
	 * @return void
	 */
	public function ajax_set_favicon() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'levers' ) ) );
		}

		$id = isset( $_POST['attachment_id'] ) ? (int) wp_unslash( $_POST['attachment_id'] ) : 0;

		if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Please choose an image.', 'levers' ) ) );
		}

		$result = $this->force_save_option( $id );

		wp_send_json_success(
			array(
				'id'        => $id,
				'updated'   => 'failed' !== $result['strategy'],
				'read_back' => $result['read_back'],
				'strategy'  => $result['strategy'],
				'db_value'  => $result['db_value'],
			)
		);
	}

	/**
	 * AJAX: clear the custom favicon.
	 *
	 * @return void
	 */
	public function ajax_remove_favicon() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$this->force_delete_option();
		wp_send_json_success();
	}

	/**
	 * Persist the favicon attachment id against hosts where
	 * update_option is short-circuited (pre_update_option_* filters,
	 * staging-mode option locks, busted object-cache drop-ins, etc.).
	 *
	 * Tries the standard API first, then a delete+add path that runs
	 * through different filters, and finally a direct $wpdb write that
	 * bypasses the option API entirely.
	 *
	 * @param int $id Attachment id.
	 * @return array{strategy:string,read_back:int,db_value:int}
	 */
	private function force_save_option( $id ) {
		global $wpdb;

		$bust = function () {
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		};

		update_option( self::OPTION, $id, false );
		$bust();
		if ( (int) get_option( self::OPTION, 0 ) === $id ) {
			return array( 'strategy' => 'update_option', 'read_back' => $id, 'db_value' => $id );
		}

		delete_option( self::OPTION );
		$bust();
		add_option( self::OPTION, $id, '', false );
		$bust();
		if ( (int) get_option( self::OPTION, 0 ) === $id ) {
			return array( 'strategy' => 'delete_add', 'read_back' => $id, 'db_value' => $id );
		}

		$wpdb->replace(
			$wpdb->options,
			array(
				'option_name'  => self::OPTION,
				'option_value' => (string) $id,
				'autoload'     => 'no',
			),
			array( '%s', '%s', '%s' )
		);
		$bust();

		$db_value  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION
			)
		);
		$read_back = (int) get_option( self::OPTION, 0 );

		return array(
			'strategy'  => $read_back === $id ? 'direct_db' : 'failed',
			'read_back' => $read_back,
			'db_value'  => $db_value,
		);
	}

	/**
	 * Clear the favicon option, defeating the same kinds of blockers as
	 * force_save_option(): standard delete first, then a direct $wpdb
	 * DELETE if the option survives.
	 *
	 * @return void
	 */
	private function force_delete_option() {
		global $wpdb;

		delete_option( self::OPTION );
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		if ( (int) get_option( self::OPTION, 0 ) > 0 ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => self::OPTION ), array( '%s' ) );
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}
}
