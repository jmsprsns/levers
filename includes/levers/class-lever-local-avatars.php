<?php
/**
 * Lever: local avatars.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets each user upload a local avatar that overrides their Gravatar.
 *
 * Adds a "Local Avatar" section to the Edit User and Profile screens
 * with a WordPress media-library picker; the chosen attachment id is
 * stored in user meta. Anywhere the site asks WordPress for that user's
 * avatar (`get_avatar()`, `get_avatar_url()` and everything that wraps
 * them), the local image is returned instead of the Gravatar URL.
 *
 * Uses the `pre_get_avatar_data` filter so the rest of WordPress's
 * avatar pipeline (sizing, classes, alt text) still applies - we just
 * swap the URL.
 */
class Levers_Lever_Local_Avatars extends Levers_Lever {

	/** User-meta key holding the chosen attachment id. */
	const META_KEY = 'levers_local_avatar_id';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'local-avatars';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Local avatars', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Adds an avatar uploader to the Edit User page. The local image overrides the user's Gravatar everywhere their avatar appears.", 'levers' );
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
		return 'circle-user-round';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Swap the avatar URL for any user that has a local one set.
		add_filter( 'pre_get_avatar_data', array( $this, 'override_avatar_data' ), 10, 2 );

		if ( is_admin() ) {
			// Profile (self) + Edit User (others) screens.
			add_action( 'show_user_profile', array( $this, 'render_avatar_field' ) );
			add_action( 'edit_user_profile', array( $this, 'render_avatar_field' ) );
			add_action( 'personal_options_update', array( $this, 'save_avatar_field' ) );
			add_action( 'edit_user_profile_update', array( $this, 'save_avatar_field' ) );

			// Media frame + picker script.
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'admin_footer', array( $this, 'print_picker_script' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Avatar override
	 * ------------------------------------------------------------------- */

	/**
	 * Swap the avatar URL when the resolved user has a local avatar.
	 *
	 * @param array $args        Avatar args (size, url, ...).
	 * @param mixed $id_or_email User id, WP_User, WP_Comment, WP_Post or email.
	 * @return array
	 */
	public function override_avatar_data( $args, $id_or_email ) {
		$user_id = $this->resolve_user_id( $id_or_email );

		if ( ! $user_id ) {
			return $args;
		}

		$attachment_id = (int) get_user_meta( $user_id, self::META_KEY, true );

		if ( $attachment_id <= 0 ) {
			return $args;
		}

		$size = isset( $args['size'] ) ? (int) $args['size'] : 96;
		$url  = wp_get_attachment_image_url( $attachment_id, array( $size, $size ) );

		if ( ! $url ) {
			$url = wp_get_attachment_url( $attachment_id );
		}

		if ( $url ) {
			$args['url']           = $url;
			$args['found_avatar']  = true;
		}

		return $args;
	}

	/**
	 * Resolve the user id from whatever shape get_avatar() was called with.
	 *
	 * @param mixed $id_or_email Identifier.
	 * @return int 0 if not resolvable.
	 */
	private function resolve_user_id( $id_or_email ) {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}

		if ( $id_or_email instanceof WP_User ) {
			return (int) $id_or_email->ID;
		}

		if ( $id_or_email instanceof WP_Comment ) {
			return ! empty( $id_or_email->user_id ) ? (int) $id_or_email->user_id : 0;
		}

		if ( $id_or_email instanceof WP_Post ) {
			return (int) $id_or_email->post_author;
		}

		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			if ( $user ) {
				return (int) $user->ID;
			}
		}

		return 0;
	}

	/* ---------------------------------------------------------------------
	 * Profile / Edit User UI
	 * ------------------------------------------------------------------- */

	/**
	 * Render the local-avatar uploader on the user's profile page.
	 *
	 * @param WP_User $user User being edited.
	 * @return void
	 */
	public function render_avatar_field( $user ) {
		if ( ! ( $user instanceof WP_User ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$attachment_id = (int) get_user_meta( $user->ID, self::META_KEY, true );
		$preview_url   = $attachment_id > 0 ? wp_get_attachment_image_url( $attachment_id, array( 96, 96 ) ) : '';
		?>
		<h2><?php esc_html_e( 'Local Avatar', 'levers' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="levers-local-avatar-id"><?php esc_html_e( 'Avatar image', 'levers' ); ?></label></th>
				<td>
					<div class="levers-local-avatar-wrap">
						<img
							class="levers-local-avatar-preview"
							src="<?php echo esc_url( $preview_url ); ?>"
							alt=""
							width="96"
							height="96"
							style="<?php echo $preview_url ? 'display:block;' : 'display:none;'; ?>border-radius:50%;width:96px;height:96px;object-fit:cover;margin-bottom:8px;background:#f0f0f1;"
						/>
						<input type="hidden" id="levers-local-avatar-id" name="<?php echo esc_attr( self::META_KEY ); ?>" value="<?php echo esc_attr( (string) $attachment_id ); ?>" />
						<button type="button" class="button levers-local-avatar-pick">
							<?php $attachment_id > 0 ? esc_html_e( 'Change avatar', 'levers' ) : esc_html_e( 'Upload avatar', 'levers' ); ?>
						</button>
						<button type="button" class="button-link levers-local-avatar-remove" style="<?php echo $attachment_id > 0 ? '' : 'display:none;'; ?>margin-left:8px;color:#b32d2e;">
							<?php esc_html_e( 'Remove', 'levers' ); ?>
						</button>
						<p class="description"><?php esc_html_e( 'Overrides the Gravatar for this user wherever the site shows their avatar.', 'levers' ); ?></p>
					</div>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the chosen attachment id from the user-edit form submit.
	 *
	 * @param int $user_id User being saved.
	 * @return void
	 */
	public function save_avatar_field( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by WP's user-edit form handler.
		$raw_id = isset( $_POST[ self::META_KEY ] ) ? (int) wp_unslash( $_POST[ self::META_KEY ] ) : 0;

		if ( $raw_id > 0 && wp_attachment_is_image( $raw_id ) ) {
			update_user_meta( $user_id, self::META_KEY, $raw_id );
		} else {
			delete_user_meta( $user_id, self::META_KEY );
		}
	}

	/* ---------------------------------------------------------------------
	 * Picker assets
	 * ------------------------------------------------------------------- */

	/**
	 * Load the WordPress media frame only on user-edit screens.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}

		wp_enqueue_media();
	}

	/**
	 * Inline JS that wires up the picker on user-edit screens.
	 *
	 * @return void
	 */
	public function print_picker_script() {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, array( 'profile', 'user-edit' ), true ) ) {
			return;
		}

		$cfg = array(
			'title'  => __( 'Choose an avatar image', 'levers' ),
			'button' => __( 'Use as avatar', 'levers' ),
		);
		?>
		<script>
		/* Levers - local avatar picker */
		( function () {
			var wrap = document.querySelector( '.levers-local-avatar-wrap' );
			if ( ! wrap ) { return; }

			var cfg     = <?php echo wp_json_encode( $cfg ); ?>;
			var hidden  = wrap.querySelector( 'input[name="<?php echo esc_js( self::META_KEY ); ?>"]' );
			var preview = wrap.querySelector( '.levers-local-avatar-preview' );
			var pickBtn = wrap.querySelector( '.levers-local-avatar-pick' );
			var killBtn = wrap.querySelector( '.levers-local-avatar-remove' );
			var frame;

			pickBtn.addEventListener( 'click', function ( e ) {
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
						var att = frame.state().get( 'selection' ).first().toJSON();
						hidden.value = String( att.id );
						var src = ( att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url ) || att.url;
						if ( preview ) {
							preview.src = src;
							preview.style.display = 'block';
						}
						if ( killBtn ) { killBtn.style.display = ''; }
					} );
				}

				frame.open();
			} );

			if ( killBtn ) {
				killBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					hidden.value = '0';
					if ( preview ) { preview.style.display = 'none'; }
					killBtn.style.display = 'none';
				} );
			}
		}() );
		</script>
		<?php
	}
}
