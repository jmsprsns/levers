<?php
/**
 * Main plugin orchestrator: loads levers, applies the enabled ones,
 * and stores their on/off state.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Levers plugin singleton.
 */
final class Levers_Plugin {

	/**
	 * Option key holding the lever on/off map.
	 */
	const OPTION = 'levers_settings';

	/**
	 * Single shared instance.
	 *
	 * @var Levers_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Registered levers, keyed by id.
	 *
	 * @var Levers_Lever[]
	 */
	private $levers = array();

	/**
	 * Category slug => display label. Built lazily by {@see get_categories()}
	 * so the translation calls don't run until after `init` (WP 6.7+ warns
	 * about loading a textdomain earlier, and the plugin boots on
	 * `plugins_loaded`).
	 *
	 * @var array<string,string>|null
	 */
	private $categories = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Levers_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		$this->load_levers();
		$this->maybe_repair_stale_form_saves();
		$this->apply_levers();

		if ( is_admin() ) {
			new Levers_Admin( $this );
		}
	}

	/**
	 * One-time fix for levers that pre-1.1.13 stale-form saves silently
	 * flipped to off. Pre-1.1.13 the bulk save handler wrote 0 for any
	 * registered lever whose checkbox wasn't in the POST body, including
	 * levers added by an update after the settings page was already open
	 * in the browser. The 1.1.13 hidden-marker fix prevents this going
	 * forward; this method repairs the specific levers we know shipped
	 * during that window so they show up "on" again, matching their
	 * default. A user who deliberately toggled one of these off pre-fix
	 * will see it come back on once - they can flip it off again and the
	 * choice will stick from then on.
	 *
	 * @return void
	 */
	private function maybe_repair_stale_form_saves() {
		$flag = 'levers_repair_stale_form_v1';

		if ( get_option( $flag ) ) {
			return;
		}

		$settings = get_option( self::OPTION, false );

		// No prior save - nothing to repair, default_enabled() already
		// drives the UI.
		if ( false === $settings || ! is_array( $settings ) ) {
			update_option( $flag, 1 );
			return;
		}

		// Levers that shipped at the same time as (or right before) the
		// stale-form fix and are the most likely victims of it.
		$affected = array( 'enable-menu-duplication' );

		$dirty = false;

		foreach ( $affected as $id ) {
			if ( ! isset( $this->levers[ $id ] ) ) {
				continue;
			}

			if ( ! array_key_exists( $id, $settings ) ) {
				continue;
			}

			if ( 0 !== (int) $settings[ $id ] ) {
				continue;
			}

			if ( ! $this->levers[ $id ]->default_enabled() ) {
				continue;
			}

			$settings[ $id ] = 1;
			$dirty           = true;
		}

		if ( $dirty ) {
			update_option( self::OPTION, $settings );
		}

		update_option( $flag, 1 );
	}

	/**
	 * Load and register every available lever.
	 *
	 * To add a new lever: drop its class file in includes/levers/ and
	 * register an instance below.
	 *
	 * @return void
	 */
	private function load_levers() {
		require_once LEVERS_DIR . 'includes/levers/class-lever-welcome-greeting.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-remove-uncategorized.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-replace-em-dashes.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-disable-xmlrpc.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-force-ssl.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-limit-login-attempts.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-block-bad-bots.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-close-comment-spam.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-prevent-blog-spam.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-disallowed-spam-ips.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-remove-comment-url-field.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-auto-empty-comment-trash.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-email-obfuscation.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-clean-expired-transients.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-optimize-db-tables.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-delete-expired-sessions.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-limit-post-revisions.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-clean-orphan-meta.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-fix-insecure-content.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-disable-self-pingbacks.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-fix-scheduled-modified-time.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-publish-missed-posts.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-local-avatars.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-hide-admin-notices.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-hide-updates-from-non-admins.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-strip-exif-uploads.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-enable-post-duplication.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-enable-menu-duplication.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-disable-admin-transitions.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-header-footer-scripts.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-custom-frontend-css.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-custom-admin-css.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-hide-admin-footer.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-skip-admin-email-check.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-allow-sanitized-svg.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-favicon.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-custom-login-logo.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-remove-grammarly-bloat.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-disable-post-feeds.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-redirect-attachment-pages.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-open-external-links-new-tab.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-noindex-search-results.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-clean-rel-internal-links.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-search-engine-visibility-warning.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-remove-double-slashes-in-urls.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-image-default-link-none.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-skip-dashicons.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-add-missing-image-dimensions.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-remove-emoji-scripts.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-disable-jquery-migrate.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-swap-google-fonts-display.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-disable-embeds.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-hide-wp-version.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-block-user-enumeration.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-block-php-in-uploads.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-add-security-headers.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-disable-file-editor.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-remove-root-info-files.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-disable-directory-browsing.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-disable-smart-punctuation.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-dynamic-copyright-year.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-stop-nav-jumps.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-user-last-login.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-last-modified-time.php';
		require_once LEVERS_DIR . 'includes/levers/class-lever-rollback-manager.php';

		$this->register( new Levers_Lever_Welcome_Greeting() );
		$this->register( new Levers_Lever_Remove_Uncategorized() );
		$this->register( new Levers_Lever_Favicon() );
		$this->register( new Levers_Lever_Custom_Login_Logo() );
		$this->register( new Levers_Lever_Disable_Post_Feeds() );
		$this->register( new Levers_Lever_Redirect_Attachment_Pages() );
		$this->register( new Levers_Lever_Open_External_Links_New_Tab() );
		$this->register( new Levers_Lever_Noindex_Search_Results() );
		$this->register( new Levers_Lever_Clean_Rel_Internal_Links() );
		$this->register( new Levers_Lever_Search_Engine_Visibility_Warning() );
		$this->register( new Levers_Lever_Remove_Double_Slashes_In_Urls() );
		$this->register( new Levers_Lever_Image_Default_Link_None() );
		$this->register( new Levers_Lever_Disable_Self_Pingbacks() );
		$this->register( new Levers_Lever_Disable_Smart_Punctuation() );
		$this->register( new Levers_Lever_Dynamic_Copyright_Year() );
		$this->register( new Levers_Lever_Stop_Nav_Jumps() );
		$this->register( new Levers_Lever_Replace_Em_Dashes() );
		$this->register( new Levers_Lever_Remove_Grammarly_Bloat() );
		$this->register( new Levers_Lever_Disable_Xmlrpc() );
		$this->register( new Levers_Lever_Hide_Wp_Version() );
		$this->register( new Levers_Lever_Block_User_Enumeration() );
		$this->register( new Levers_Lever_Block_Php_In_Uploads() );
		$this->register( new Levers_Lever_Add_Security_Headers() );
		$this->register( new Levers_Lever_Disable_File_Editor() );
		$this->register( new Levers_Lever_Remove_Root_Info_Files() );
		$this->register( new Levers_Lever_Disable_Directory_Browsing() );
		$this->register( new Levers_Lever_Limit_Login_Attempts() );
		$this->register( new Levers_Lever_Block_Bad_Bots() );
		$this->register( new Levers_Lever_Close_Comment_Spam() );
		$this->register( new Levers_Lever_Prevent_Blog_Spam() );
		$this->register( new Levers_Lever_Disallowed_Spam_Ips() );
		$this->register( new Levers_Lever_Remove_Comment_Url_Field() );
		$this->register( new Levers_Lever_Auto_Empty_Comment_Trash() );
		$this->register( new Levers_Lever_Email_Obfuscation() );
		$this->register( new Levers_Lever_Clean_Expired_Transients() );
		$this->register( new Levers_Lever_Optimize_Db_Tables() );
		$this->register( new Levers_Lever_Delete_Expired_Sessions() );
		$this->register( new Levers_Lever_Limit_Post_Revisions() );
		$this->register( new Levers_Lever_Clean_Orphan_Meta() );
		$this->register( new Levers_Lever_Fix_Scheduled_Modified_Time() );
		$this->register( new Levers_Lever_Publish_Missed_Posts() );
		$this->register( new Levers_Lever_Local_Avatars() );
		$this->register( new Levers_Lever_Hide_Admin_Notices() );
		$this->register( new Levers_Lever_Hide_Updates_From_Non_Admins() );
		$this->register( new Levers_Lever_Strip_Exif_Uploads() );
		$this->register( new Levers_Lever_Enable_Post_Duplication() );
		$this->register( new Levers_Lever_Enable_Menu_Duplication() );
		$this->register( new Levers_Lever_Disable_Admin_Transitions() );
		$this->register( new Levers_Lever_Header_Footer_Scripts() );
		$this->register( new Levers_Lever_Custom_Frontend_Css() );
		$this->register( new Levers_Lever_Custom_Admin_Css() );
		$this->register( new Levers_Lever_Force_Ssl() );
		$this->register( new Levers_Lever_Fix_Insecure_Content() );
		$this->register( new Levers_Lever_Hide_Admin_Footer() );
		$this->register( new Levers_Lever_Skip_Admin_Email_Check() );
		$this->register( new Levers_Lever_Allow_Sanitized_Svg() );
		$this->register( new Levers_Lever_Skip_Dashicons() );
		$this->register( new Levers_Lever_Add_Missing_Image_Dimensions() );
		$this->register( new Levers_Lever_Remove_Emoji_Scripts() );
		$this->register( new Levers_Lever_Disable_Jquery_Migrate() );
		$this->register( new Levers_Lever_Swap_Google_Fonts_Display() );
		$this->register( new Levers_Lever_Disable_Embeds() );
		$this->register( new Levers_Lever_User_Last_Login() );
		$this->register( new Levers_Lever_Last_Modified_Time() );
		$this->register( new Levers_Lever_Rollback_Manager() );
	}

	/**
	 * Add a lever to the registry.
	 *
	 * @param Levers_Lever $lever Lever instance.
	 * @return void
	 */
	public function register( Levers_Lever $lever ) {
		$this->levers[ $lever->id() ] = $lever;
	}

	/**
	 * Run every lever that is currently switched on.
	 *
	 * @return void
	 */
	private function apply_levers() {
		foreach ( $this->levers as $id => $lever ) {
			// Skip levers that refuse to run in the current environment,
			// even if the option was set previously.
			if ( ! $lever->is_available() ) {
				continue;
			}

			if ( $this->is_enabled( $id ) ) {
				$lever->run();
			}
		}
	}

	/**
	 * All registered levers.
	 *
	 * @return Levers_Lever[]
	 */
	public function get_levers() {
		return $this->levers;
	}

	/**
	 * All categories (slug => label).
	 *
	 * @return array<string,string>
	 */
	public function get_categories() {
		if ( null === $this->categories ) {
			$this->categories = array(
				'branding'          => __( 'Branding', 'levers' ),
				'wordpress-cleanup' => __( 'WordPress Cleanup', 'levers' ),
				'frontend'          => __( 'Frontend', 'levers' ),
				'security'          => __( 'Security', 'levers' ),
				'performance'       => __( 'Performance', 'levers' ),
				'seo'               => __( 'SEO', 'levers' ),
				'spam'              => __( 'Anti-Spam', 'levers' ),
				'maintenance'       => __( 'Maintenance', 'levers' ),
				'admin-tools'       => __( 'Admin Tools', 'levers' ),
			);
		}

		return $this->categories;
	}

	/**
	 * Icon (lucide name) used to represent a category in the heading row
	 * and the sidebar's Jump To menu.
	 *
	 * @param string $slug Category slug.
	 * @return string
	 */
	public function get_category_icon( $slug ) {
		$map = array(
			'branding'          => 'palette',
			'wordpress-cleanup' => 'sparkles',
			'frontend'          => 'monitor',
			'security'          => 'shield',
			'performance'       => 'zap',
			'seo'               => 'trending-up',
			'spam'              => 'ban',
			'maintenance'       => 'recycle',
			'admin-tools'       => 'wrench',
		);

		return isset( $map[ $slug ] ) ? $map[ $slug ] : 'sliders-horizontal';
	}

	/**
	 * Is the given lever switched on?
	 *
	 * Falls back to the lever's own default until the user saves the screen.
	 *
	 * @param string $id Lever id.
	 * @return bool
	 */
	public function is_enabled( $id ) {
		$settings = get_option( self::OPTION, array() );

		if ( is_array( $settings ) && array_key_exists( $id, $settings ) ) {
			return (bool) $settings[ $id ];
		}

		return isset( $this->levers[ $id ] ) ? $this->levers[ $id ]->default_enabled() : false;
	}
}
