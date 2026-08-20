<?php
/**
 * Lever: Rollback Manager.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets admins roll any wordpress.org plugin back to a previous version.
 *
 * Adds a "Rollback" row action to every plugin on wp-admin/plugins.php.
 * Clicking it opens a Levers-owned admin screen that fetches the version
 * history for that plugin from api.wordpress.org and lists each release
 * with a one-click "Rollback to X.Y.Z" button.
 *
 * The actual install reuses WordPress's own Plugin_Upgrader, pointed at
 * the .org-hosted ZIP for the chosen version. Active plugins are
 * silently re-activated after the swap so the rollback is non-disruptive.
 *
 * Plugins that aren't hosted on .org (premium, custom, GitHub-only)
 * still show the row action - the screen explains there's no version
 * history available and offers no rollback button. We don't probe
 * the API per row to detect this up front because that would mean a
 * remote request for every plugin on every plugins.php load.
 */
class Levers_Lever_Rollback_Manager extends Levers_Lever {

	/** Slug for our hidden admin screen. */
	const PAGE_SLUG = 'levers-rollback';

	/** Transient key prefix for cached version lists. */
	const CACHE_PREFIX = 'levers_rollback_versions_';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'rollback-manager';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Rollback manager', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Adds a "Rollback" link to each plugin row so you can revert any wordpress.org plugin to a previous version in one click.', 'levers' );
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
		return 'history';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		if ( ! is_admin() ) {
			return;
		}

		add_filter( 'plugin_action_links', array( $this, 'add_rollback_link' ), 20, 2 );
		add_filter( 'network_admin_plugin_action_links', array( $this, 'add_rollback_link' ), 20, 2 );
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_levers_do_rollback', array( $this, 'handle_rollback' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_result_notice' ) );
	}

	/* ---------------------------------------------------------------------
	 * Row action
	 * ------------------------------------------------------------------- */

	/**
	 * Inject the "Rollback" link into a plugin row.
	 *
	 * @param array  $actions     Existing row-action links.
	 * @param string $plugin_file Plugin file (e.g. "akismet/akismet.php").
	 * @return array
	 */
	public function add_rollback_link( $actions, $plugin_file ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return $actions;
		}

		// Don't offer rollback on ourselves - footgun.
		if ( plugin_basename( LEVERS_FILE ) === $plugin_file ) {
			return $actions;
		}

		$url = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'plugin' => rawurlencode( $plugin_file ),
			),
			admin_url( 'admin.php' )
		);

		$actions['levers-rollback'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Rollback', 'levers' )
		);

		return $actions;
	}

	/* ---------------------------------------------------------------------
	 * Admin screen
	 * ------------------------------------------------------------------- */

	/**
	 * Register the hidden rollback page (accessible only by URL).
	 *
	 * @return void
	 */
	public function register_page() {
		add_submenu_page(
			'',
			__( 'Rollback plugin', 'levers' ),
			__( 'Rollback plugin', 'levers' ),
			'update_plugins',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the version list for the requested plugin.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage plugin versions.', 'levers' ) );
		}

		$plugin_file = $this->requested_plugin_file();

		if ( ! $plugin_file ) {
			wp_die( esc_html__( 'No plugin specified.', 'levers' ) );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		if ( ! isset( $plugins[ $plugin_file ] ) ) {
			wp_die( esc_html__( 'That plugin is not installed.', 'levers' ) );
		}

		$plugin_data     = $plugins[ $plugin_file ];
		$slug            = $this->plugin_slug( $plugin_file );
		$versions        = $slug ? $this->fetch_versions( $slug ) : array();
		$current_version = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '';
		?>
		<div class="wrap">
			<h1>
				<?php
				/* translators: %s: plugin name. */
				printf( esc_html__( 'Rollback: %s', 'levers' ), esc_html( $plugin_data['Name'] ) );
				?>
			</h1>

			<p>
				<?php
				/* translators: %s: version string. */
				printf( esc_html__( 'Currently installed: %s', 'levers' ), '<strong>' . esc_html( $current_version ) . '</strong>' );
				?>
				&nbsp;|&nbsp;
				<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">
					<?php esc_html_e( '&larr; Back to Plugins', 'levers' ); ?>
				</a>
			</p>

			<?php if ( empty( $versions ) ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'No previous versions are available for this plugin. It may not be hosted on the WordPress.org plugin repository (premium, custom, or GitHub-hosted plugins are not rollback-able through this tool).', 'levers' ); ?>
					</p>
				</div>
			<?php else : ?>
				<p><?php esc_html_e( 'Pick a version below. The plugin files will be replaced with that release. If the plugin was active, it stays active.', 'levers' ); ?></p>

				<table class="widefat striped" style="max-width:540px;">
					<thead>
						<tr>
							<th style="width:30%;"><?php esc_html_e( 'Version', 'levers' ); ?></th>
							<th><?php esc_html_e( 'Action', 'levers' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $versions as $version => $download_url ) :
						if ( 'trunk' === $version || empty( $download_url ) ) {
							continue;
						}

						$is_current   = ( $version === $current_version );
						$rollback_url = wp_nonce_url(
							add_query_arg(
								array(
									'action'  => 'levers_do_rollback',
									'plugin'  => rawurlencode( $plugin_file ),
									'version' => rawurlencode( $version ),
								),
								admin_url( 'admin-post.php' )
							),
							'levers_rollback_' . $plugin_file . '_' . $version
						);

						/* translators: %s: version string. */
						$confirm_message = sprintf( __( 'Rollback to version %s? The current plugin files will be replaced.', 'levers' ), $version );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $version ); ?></strong>
								<?php if ( $is_current ) : ?>
									<em style="color:#a7aaad;">&nbsp;(<?php esc_html_e( 'current', 'levers' ); ?>)</em>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $is_current ) : ?>
									<span style="color:#a7aaad;">&mdash;</span>
								<?php else : ?>
									<a
										href="<?php echo esc_url( $rollback_url ); ?>"
										class="button levers-rollback-btn"
										data-confirm="<?php echo esc_attr( $confirm_message ); ?>"
									>
										<?php
										/* translators: %s: version string. */
										printf( esc_html__( 'Rollback to %s', 'levers' ), esc_html( $version ) );
										?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<p style="margin-top:1em;color:#666;">
					<?php esc_html_e( 'Versions are fetched from api.wordpress.org and cached for one hour.', 'levers' ); ?>
				</p>

				<script>
				( function () {
					var busyLabel = <?php echo wp_json_encode( __( 'Rolling back, please wait…', 'levers' ) ); ?>;

					document.addEventListener( 'click', function ( e ) {
						var btn = e.target.closest( '.levers-rollback-btn' );
						if ( ! btn ) { return; }

						// Already submitted - block further clicks.
						if ( btn.classList.contains( 'is-busy' ) ) {
							e.preventDefault();
							return;
						}

						if ( ! window.confirm( btn.dataset.confirm || '' ) ) {
							e.preventDefault();
							return;
						}

						// Swap label for an in-progress state. The browser keeps
						// rendering this page until admin-post.php redirects, so
						// the spinner stays visible the entire time.
						btn.classList.add( 'is-busy' );
						btn.innerHTML =
							'<span class="spinner is-active" style="float:none;margin:-4px 6px 0 0;vertical-align:middle;visibility:visible;"></span>' +
							busyLabel.replace( /</g, '&lt;' );

						// Disable every other rollback button so the user can't
						// fire a second install while the first is still running.
						document.querySelectorAll( '.levers-rollback-btn' ).forEach( function ( other ) {
							if ( other === btn ) { return; }
							other.classList.add( 'disabled' );
							other.setAttribute( 'aria-disabled', 'true' );
							other.style.pointerEvents = 'none';
							other.style.opacity = '0.5';
						} );
					} );
				}() );
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Rollback handler
	 * ------------------------------------------------------------------- */

	/**
	 * Perform the rollback when the user clicks "Rollback to X".
	 *
	 * @return void
	 */
	public function handle_rollback() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage plugin versions.', 'levers' ) );
		}

		$plugin_file = $this->requested_plugin_file();
		$version     = isset( $_GET['version'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['version'] ) ) ) : '';

		if ( ! $plugin_file || ! $version ) {
			wp_die( esc_html__( 'Missing plugin or version.', 'levers' ) );
		}

		check_admin_referer( 'levers_rollback_' . $plugin_file . '_' . $version );

		$slug     = $this->plugin_slug( $plugin_file );
		$versions = $slug ? $this->fetch_versions( $slug ) : array();

		if ( empty( $versions[ $version ] ) ) {
			$this->redirect_with_result( $plugin_file, $version, 'unavailable' );
		}

		$download_url = $versions[ $version ];

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

		$was_active         = is_plugin_active( $plugin_file );
		$was_network_active = is_multisite() && is_plugin_active_for_network( $plugin_file );

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );

		$result = $upgrader->install(
			$download_url,
			array(
				'overwrite_package' => true,
				'clear_destination' => true,
			)
		);

		// Re-activate so the rollback is non-disruptive.
		if ( ! is_wp_error( $result ) && $result ) {
			if ( $was_network_active ) {
				activate_plugin( $plugin_file, '', true, true );
			} elseif ( $was_active ) {
				activate_plugin( $plugin_file, '', false, true );
			}
		}

		// Bust our cached version list so a re-visit shows the new "current".
		if ( $slug ) {
			delete_transient( self::CACHE_PREFIX . md5( $slug ) );
		}

		$status = ( ! is_wp_error( $result ) && $result ) ? 'success' : 'error';
		$this->redirect_with_result( $plugin_file, $version, $status );
	}

	/**
	 * Bounce back to the Plugins screen with a status flag.
	 *
	 * @param string $plugin_file Plugin file.
	 * @param string $version     Version we tried to install.
	 * @param string $status      success | error | unavailable.
	 * @return void
	 */
	private function redirect_with_result( $plugin_file, $version, $status ) {
		$url = add_query_arg(
			array(
				'levers_rollback' => $status,
				'plugin'          => rawurlencode( $plugin_file ),
				'version'         => rawurlencode( $version ),
			),
			admin_url( 'plugins.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render a one-shot success/error notice on plugins.php after a rollback.
	 *
	 * @return void
	 */
	public function maybe_render_result_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag from our own redirect.
		if ( empty( $_GET['levers_rollback'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag from our own redirect.
		$status  = sanitize_key( wp_unslash( $_GET['levers_rollback'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag from our own redirect.
		$version = isset( $_GET['version'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['version'] ) ) ) : '';

		switch ( $status ) {
			case 'success':
				$class   = 'notice-success';
				/* translators: %s: version string. */
				$message = sprintf( __( 'Plugin rolled back to version %s.', 'levers' ), $version );
				break;

			case 'unavailable':
				$class   = 'notice-warning';
				$message = __( 'That version is no longer available on WordPress.org.', 'levers' );
				break;

			default:
				$class   = 'notice-error';
				$message = __( 'Rollback failed. Check the plugin folder permissions and try again.', 'levers' );
				break;
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Pull and validate the requested plugin file from the URL.
	 *
	 * Defends against path-traversal by requiring "<slug>/<file>.php".
	 *
	 * @return string Empty string if invalid.
	 */
	private function requested_plugin_file() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is checked separately in handle_rollback; render_page is read-only.
		if ( empty( $_GET['plugin'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$raw = sanitize_text_field( rawurldecode( wp_unslash( $_GET['plugin'] ) ) );

		// Strip anything that would let the value escape the plugins dir.
		if ( false !== strpos( $raw, '..' ) || 0 === strpos( $raw, '/' ) ) {
			return '';
		}

		if ( ! preg_match( '#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\.php$#', $raw ) ) {
			// Single-file plugins (no slash) aren't rollback-able anyway - they
			// aren't from .org in any case worth supporting.
			return '';
		}

		return $raw;
	}

	/**
	 * Extract the wordpress.org slug from a plugin file path.
	 *
	 * @param string $plugin_file e.g. "akismet/akismet.php".
	 * @return string Slug ("akismet") or empty string.
	 */
	private function plugin_slug( $plugin_file ) {
		$parts = explode( '/', $plugin_file, 2 );

		return ( count( $parts ) === 2 && '' !== $parts[0] ) ? $parts[0] : '';
	}

	/**
	 * Fetch and cache the version => download_url map for a slug.
	 *
	 * @param string $slug Plugin slug.
	 * @return array Ordered newest-first; empty if not on .org.
	 */
	private function fetch_versions( $slug ) {
		$cache_key = self::CACHE_PREFIX . md5( $slug );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url = add_query_arg(
			array(
				'action'  => 'plugin_information',
				'request' => array(
					'slug'   => $slug,
					'fields' => array( 'versions' => true ),
				),
			),
			'https://api.wordpress.org/plugins/info/1.0/'
		);

		// The plugins.info/1.0/ endpoint returns serialized PHP when called
		// the old-school way; the JSON shape lives at /1.2/. Use 1.2.
		$response = wp_remote_get(
			'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=' . rawurlencode( $slug ) . '&fields[]=versions',
			array( 'timeout' => 10 )
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $cache_key, array(), 15 * MINUTE_IN_SECONDS );
			return array();
		}

		$data     = json_decode( wp_remote_retrieve_body( $response ), true );
		$versions = ( is_array( $data ) && ! empty( $data['versions'] ) && is_array( $data['versions'] ) )
			? $data['versions']
			: array();

		// Drop "trunk" entries; useless for rollback.
		unset( $versions['trunk'] );

		// Sort newest-first.
		uksort( $versions, 'version_compare' );
		$versions = array_reverse( $versions, true );

		set_transient( $cache_key, $versions, HOUR_IN_SECONDS );

		return $versions;
	}
}
