<?php
/**
 * Lever: warn admins when "Discourage search engines" is on.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects the situation where WordPress is asking search engines not
 * to index the site (Settings -> Reading, "Discourage search engines
 * from indexing this site"), and surfaces a persistent admin notice
 * so it can be undone in one click.
 *
 * That checkbox is the single most damaging setting in WordPress: a
 * developer ticks it on a staging site, the site goes live with it
 * still ticked, and a few weeks later rankings collapse. The setting
 * itself isn't broken - it's the silence that's the problem. WP gives
 * no in-dashboard warning, so the misconfiguration sits there
 * indefinitely.
 *
 * This lever closes that gap:
 *
 *   - On every admin screen, if blog_public is 0, render a notice.
 *   - The notice is not dismissible. Anything dismissible gets
 *     dismissed and forgotten.
 *   - It carries a one-click "Fix this now" button (admin-post +
 *     nonce) that flips the option back to 1.
 *   - Notice severity scales with environment: notice-error on
 *     production, notice-warning on local / staging / development,
 *     because the setting is legitimate (and common) off-production.
 *
 * The check happens fresh on every page load, so the notice disappears
 * the moment the option is set back. There's no transient or "fixed
 * for a while" state to keep in sync.
 *
 * Admins only - the notice (and the fix) require manage_options.
 */
class Levers_Lever_Search_Engine_Visibility_Warning extends Levers_Lever {

	/** admin-post action slug used by the Fix button. */
	const ACTION = 'levers_fix_blog_public';

	/** Nonce action for the Fix button. */
	const NONCE = 'levers-fix-blog-public';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'search-engine-visibility-warning';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Search engine visibility warning', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Warns admins when WordPress's \"Discourage search engines\" setting is on. One-click fix; clears as soon as it's resolved.", 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'seo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'search-alert';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_fixed_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_fix' ) );
	}

	/* ---------------------------------------------------------------------
	 * Notice
	 * ------------------------------------------------------------------- */

	/**
	 * Render the warning notice when blog_public is 0.
	 *
	 * @return void
	 */
	public function maybe_show_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 1 === (int) get_option( 'blog_public', 1 ) ) {
			return;
		}

		$is_production = $this->is_production();

		$class = $is_production ? 'notice notice-error' : 'notice notice-warning';

		$headline = $is_production
			? __( 'Your site is hidden from search engines.', 'levers' )
			: __( 'Search engine indexing is currently discouraged.', 'levers' );

		$body = $is_production
			? __( 'WordPress is asking search engines not to index this site. If this is a live site, traffic will drop and existing rankings can be lost.', 'levers' )
			: __( "The \"Discourage search engines\" setting is on. This is expected on staging or local, but a problem if this site ever serves production traffic.", 'levers' );

		$fix_url = wp_nonce_url(
			add_query_arg( 'action', self::ACTION, admin_url( 'admin-post.php' ) ),
			self::NONCE
		);

		$settings_url = admin_url( 'options-reading.php' );

		printf(
			'<div class="%1$s"><p><strong>%2$s</strong> %3$s</p><p><a class="button button-primary" href="%4$s">%5$s</a> <a href="%6$s">%7$s</a></p></div>',
			esc_attr( $class ),
			esc_html( $headline ),
			esc_html( $body ),
			esc_url( $fix_url ),
			esc_html__( 'Fix this now', 'levers' ),
			esc_url( $settings_url ),
			esc_html__( 'Open Reading settings', 'levers' )
		);
	}

	/**
	 * One-shot confirmation notice after the user hits Fix this now.
	 *
	 * Driven by a URL flag the redirect handler sets, so it appears
	 * exactly once and survives no further navigation.
	 *
	 * @return void
	 */
	public function maybe_show_fixed_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag, no state change.
		if ( empty( $_GET['levers_visibility_fixed'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Search engines can now index this site.', 'levers' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Fix handler
	 * ------------------------------------------------------------------- */

	/**
	 * Flip blog_public to 1 and redirect back where the user came from.
	 *
	 * @return void
	 */
	public function handle_fix() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change this setting.', 'levers' ) );
		}

		check_admin_referer( self::NONCE );

		update_option( 'blog_public', 1 );

		$referer = wp_get_referer();
		$target  = $referer ? $referer : admin_url();

		wp_safe_redirect( add_query_arg( 'levers_visibility_fixed', '1', $target ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Whether WordPress thinks this install is production.
	 *
	 * Defaults to true when wp_get_environment_type() isn't available,
	 * so the warning errs on the side of being louder rather than quieter.
	 *
	 * @return bool
	 */
	private function is_production() {
		if ( ! function_exists( 'wp_get_environment_type' ) ) {
			return true;
		}

		return 'production' === wp_get_environment_type();
	}
}
