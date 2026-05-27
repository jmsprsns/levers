<?php
/**
 * Settings → Levers admin screen.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the menu item and renders / saves the settings page.
 */
class Levers_Admin {

	/**
	 * Plugin instance.
	 *
	 * @var Levers_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix returned by add_submenu_page().
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Levers_Plugin $plugin Plugin instance.
	 */
	public function __construct( Levers_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );

		// Runs last (PHP_INT_MAX), after every other plugin has registered
		// its Settings pages, so "Levers" always wins the top spot.
		add_action( 'admin_menu', array( $this, 'pin_menu_to_top' ), PHP_INT_MAX );

		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( LEVERS_FILE ),
			array( $this, 'add_settings_link' )
		);

		add_action( 'wp_ajax_levers_toggle', array( $this, 'ajax_toggle' ) );
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=levers' ) ),
			esc_html__( 'Settings', 'levers' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add "Levers" under the Settings menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Levers_Icons::get( 'sliders-horizontal', 17, 'levers-menu-icon' );
		$menu_title = $icon . '<span class="levers-menu-label">' . esc_html__( 'Levers', 'levers' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			'options-general.php',
			__( 'Levers', 'levers' ),
			$menu_title,
			'manage_options',
			'levers',
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'handle_save' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}
	}

	/**
	 * Force "Levers" to the very top of the Settings submenu.
	 *
	 * WordPress sorts only the top-level menu after the `admin_menu` hook;
	 * submenus keep whatever order they are left in. Running on the last
	 * possible priority, we rebuild the Settings submenu with our item
	 * first so it stays #1 regardless of what other plugins register.
	 *
	 * @return void
	 */
	public function pin_menu_to_top() {
		global $submenu;

		$parent = 'options-general.php';

		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}

		$ours   = null;
		$others = array();

		foreach ( $submenu[ $parent ] as $item ) {
			if ( isset( $item[2] ) && 'levers' === $item[2] ) {
				$ours = $item;
			} else {
				$others[] = $item;
			}
		}

		// Our item may be missing if the current user lacks the capability.
		if ( null === $ours ) {
			return;
		}

		array_unshift( $others, $ours );
		$submenu[ $parent ] = $others;
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="levers-menu-icon-style">'
			. '#adminmenu .levers-menu-icon{vertical-align:-4px;margin-right:7px;opacity:.7}'
			. '#adminmenu li.current .levers-menu-icon,#adminmenu a:hover .levers-menu-icon,#adminmenu a:focus .levers-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the settings-page styles and scripts, including Toastr.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'levers-toastr',
			LEVERS_URL . 'assets/toastr.min.css',
			array(),
			'2.1.4'
		);
		wp_enqueue_style(
			'levers-admin',
			LEVERS_URL . 'assets/admin.css',
			array( 'levers-toastr' ),
			LEVERS_VERSION
		);

		wp_enqueue_script(
			'levers-toastr',
			LEVERS_URL . 'assets/toastr.min.js',
			array( 'jquery' ),
			'2.1.4',
			true
		);

		wp_enqueue_script(
			'levers-admin',
			LEVERS_URL . 'assets/admin.js',
			array( 'levers-toastr' ),
			LEVERS_VERSION,
			true
		);

		wp_localize_script(
			'levers-admin',
			'leversAdmin',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'levers_toggle' ),
				'strings' => array(
					/* translators: %s: lever title. */
					'enabled'  => __( '%s enabled.', 'levers' ),
					/* translators: %s: lever title. */
					'disabled' => __( '%s disabled.', 'levers' ),
					/* translators: %s: lever title. */
					'failed'   => __( 'Could not save %s. Please try again.', 'levers' ),
				),
			)
		);
	}

	/**
	 * Handle the saved form (runs before the page renders, so we can
	 * redirect cleanly and avoid a resubmit on refresh).
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! isset( $_POST['levers_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'levers' ) );
		}

		check_admin_referer( 'levers_save', 'levers_nonce' );

		$posted   = isset( $_POST['levers'] ) && is_array( $_POST['levers'] ) ? wp_unslash( $_POST['levers'] ) : array();
		$levers   = $this->plugin->get_levers();
		$settings = array();
		$changed  = array();

		// First time the settings page is ever saved, the option doesn't
		// exist yet. Treat that case as a fresh start: every previously-
		// "on" state was just a default, not a real user choice, so
		// on_enable() fires for every lever the user keeps on instead of
		// the defaults silently sliding in with no setup.
		$first_save = ( false === get_option( Levers_Plugin::OPTION, false ) );

		foreach ( $levers as $id => $lever ) {
			$was_enabled = $first_save ? false : $this->plugin->is_enabled( $id );

			// Unavailable levers cannot be enabled, regardless of what was
			// posted (the checkbox is disabled in the UI, but defend in
			// depth against crafted submissions).
			if ( ! $lever->is_available() ) {
				$now_enabled = false;
			} else {
				$now_enabled = ! empty( $posted[ $id ] );
			}

			$settings[ $id ] = $now_enabled ? 1 : 0;

			if ( $now_enabled !== $was_enabled ) {
				$changed[ $id ] = $now_enabled;
			}
		}

		update_option( Levers_Plugin::OPTION, $settings );

		// Notify levers that just flipped, so they can run one-time setup or
		// teardown (e.g. scheduling or clearing a cron event).
		foreach ( $changed as $id => $now_enabled ) {
			if ( $now_enabled ) {
				$levers[ $id ]->on_enable();
			} else {
				$levers[ $id ]->on_disable();
			}
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=levers' ) );
		exit;
	}

	/**
	 * AJAX: flip a single lever and fire its on_enable/on_disable hook.
	 *
	 * Mirrors handle_save()'s per-lever logic so behavior stays identical
	 * whether the change arrives via the (now JS-driven) toggle or via a
	 * form POST to handle_save().
	 *
	 * @return void
	 */
	public function ajax_toggle() {
		check_ajax_referer( 'levers_toggle', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'levers' ) ) );
		}

		$lever_id  = isset( $_POST['lever_id'] ) ? sanitize_key( wp_unslash( $_POST['lever_id'] ) ) : '';
		$requested = ! empty( $_POST['enabled'] ) && '0' !== (string) $_POST['enabled'];

		$levers = $this->plugin->get_levers();

		if ( '' === $lever_id || ! isset( $levers[ $lever_id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown lever.', 'levers' ) ) );
		}

		$lever = $levers[ $lever_id ];

		// Defense in depth: an unavailable lever can never be enabled, even
		// if the checkbox is somehow re-enabled client-side.
		if ( $requested && ! $lever->is_available() ) {
			wp_send_json_error( array( 'message' => __( 'This lever is unavailable on this site.', 'levers' ) ) );
		}

		// Mirror handle_save()'s first-save semantics: when no option exists
		// yet, treat the previous state as "off" so on_enable fires for the
		// first lever the user explicitly turns on.
		$first_save  = ( false === get_option( Levers_Plugin::OPTION, false ) );
		$was_enabled = $first_save ? false : $this->plugin->is_enabled( $lever_id );
		$now_enabled = $requested;

		$settings                = $first_save ? array() : (array) get_option( Levers_Plugin::OPTION, array() );
		$settings[ $lever_id ]   = $now_enabled ? 1 : 0;

		update_option( Levers_Plugin::OPTION, $settings );

		if ( $now_enabled !== $was_enabled ) {
			if ( $now_enabled ) {
				$lever->on_enable();
			} else {
				$lever->on_disable();
			}
		}

		wp_send_json_success(
			array(
				'enabled' => $now_enabled,
				'title'   => $lever->title(),
			)
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		$categories = $this->plugin->get_categories();
		$grouped    = array();

		foreach ( $this->plugin->get_levers() as $id => $lever ) {
			$grouped[ $lever->category() ][ $id ] = $lever;
		}

		// Categories that actually have a lever - used for both the visible
		// list and the sidebar's "Jump to" menu.
		$active_categories = array();
		foreach ( $categories as $slug => $label ) {
			if ( ! empty( $grouped[ $slug ] ) ) {
				$active_categories[ $slug ] = $label;
			}
		}
		?>
		<div class="wrap levers-wrap">
			<div class="levers-layout">
			<div class="levers-main">
			<form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=levers' ) ); ?>">
				<?php wp_nonce_field( 'levers_save', 'levers_nonce' ); ?>

				<div class="levers-list">
					<?php foreach ( $active_categories as $cat_slug => $cat_label ) : ?>
						<section class="levers-category levers-cat--<?php echo esc_attr( $cat_slug ); ?>" id="levers-cat-<?php echo esc_attr( $cat_slug ); ?>">
							<h2 class="levers-category__title"><?php echo esc_html( $cat_label ); ?></h2>
							<div class="levers-category__items">
								<?php foreach ( $grouped[ $cat_slug ] as $id => $lever ) : ?>
									<?php
									$field_id  = 'lever-' . $id;
									$available = $lever->is_available();
									$enabled   = $available && $this->plugin->is_enabled( $id );
									$row_class = 'levers-item' . ( $available ? '' : ' levers-item--unavailable' );
									// A lever's extra is reload-worthy iff the lever overrides
									// render_extra(). Saves marking every lever individually.
									$has_extra = 'Levers_Lever' !== ( new ReflectionMethod( $lever, 'render_extra' ) )->getDeclaringClass()->getName();
									?>
									<div class="<?php echo esc_attr( $row_class ); ?>">
										<span class="levers-item__icon"><?php echo Levers_Icons::get( $lever->icon(), 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted inline SVG. ?></span>
										<div class="levers-item__info">
											<div class="levers-item__heading">
												<label class="levers-item__title" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $lever->title() ); ?></label>
												<?php if ( $available ) : ?>
													<?php $lever->render_extra( $enabled ); ?>
												<?php elseif ( '' !== $lever->unavailable_reason() ) : ?>
													<span class="levers-extra-sep" aria-hidden="true">&bull;</span>
													<span class="levers-item__inline-note"><?php echo esc_html( $lever->unavailable_reason() ); ?></span>
												<?php endif; ?>
											</div>
											<p class="levers-item__desc"><?php echo esc_html( $lever->description() ); ?></p>
										</div>
										<?php $tooltip = ( ! $available && '' !== $lever->unavailable_reason() ) ? $lever->unavailable_reason() : ''; ?>
										<label class="levers-toggle"<?php if ( '' !== $tooltip ) : ?> data-tooltip="<?php echo esc_attr( $tooltip ); ?>"<?php endif; ?>>
											<input
												type="checkbox"
												id="<?php echo esc_attr( $field_id ); ?>"
												name="levers[<?php echo esc_attr( $id ); ?>]"
												value="1"
												data-lever-id="<?php echo esc_attr( $id ); ?>"
												data-lever-title="<?php echo esc_attr( $lever->title() ); ?>"
												<?php if ( $has_extra ) : ?>data-reload-on-toggle="1"<?php endif; ?>
												<?php checked( $enabled ); ?>
												<?php disabled( ! $available ); ?>
											/>
											<span class="levers-toggle__track" aria-hidden="true">
												<span class="levers-toggle__thumb"></span>
											</span>
										</label>
									</div>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endforeach; ?>
				</div>

			</form>
			</div><!-- /.levers-main -->

			<aside class="levers-sidebar">
				<div class="levers-sidebar__brand">
					<span class="levers-sidebar__brand-mark" aria-hidden="true">
						<span class="levers-sidebar__brand-track">
							<span class="levers-sidebar__brand-thumb"></span>
						</span>
					</span>
					<div class="levers-sidebar__brand-text">
						<h1>Levers</h1>
						<p>by <a href="https://www.contentpowered.com" target="_blank" rel="noopener noreferrer">Content Powered</a></p>
					</div>
				</div>
				<p class="levers-sidebar__tagline"><?php esc_html_e( 'Recommended WordPress tweaks for usability, security, performance, spam and bug fixes.', 'levers' ); ?></p>

				<blockquote class="levers-sidebar__quote">
					<p class="levers-sidebar__quote-bubble"><?php echo esc_html_x( '"Why many plugin when one plugin do trick?"', 'sidebar tagline quote', 'levers' ); ?></p>
					<cite class="levers-sidebar__quote-author">- James Parsons, Author</cite>
				</blockquote>

				<?php if ( count( $active_categories ) > 1 ) : ?>
					<nav class="levers-jump" aria-label="<?php esc_attr_e( 'Jump to section', 'levers' ); ?>">
						<h2 class="levers-jump__title"><?php esc_html_e( 'Jump to', 'levers' ); ?></h2>
						<ul>
							<?php foreach ( $active_categories as $jump_slug => $jump_label ) : ?>
								<li>
									<a class="levers-cat--<?php echo esc_attr( $jump_slug ); ?>" href="#levers-cat-<?php echo esc_attr( $jump_slug ); ?>">
										<span class="levers-jump__icon"><?php echo Levers_Icons::get( $this->plugin->get_category_icon( $jump_slug ), 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted inline SVG. ?></span>
										<span class="levers-jump__label"><?php echo esc_html( $jump_label ); ?></span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</nav>
				<?php endif; ?>
			</aside>

			</div><!-- /.levers-layout -->
		</div>
		<?php
	}
}
