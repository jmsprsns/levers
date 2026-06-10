<?php
/**
 * Lever: track spam IPs and auto-spam future comments from them.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Learns spam IPs as you moderate.
 *
 * Adds a "Disallowed Spam IPs" textarea directly below WordPress's
 * "Disallowed Comment Keys" on Settings > Discussion. Whenever a comment
 * is marked as spam, its commenter IP is added to the list; any future
 * comment from that IP is automatically marked as spam on submission. If
 * a previously-spam comment is approved, its IP is taken back out, so
 * the unmark sticks.
 *
 * The list is editable by hand: paste in known-bad IPs to pre-seed it,
 * or remove an IP you regret blocking.
 */
class Levers_Lever_Disallowed_Spam_Ips extends Levers_Lever {

	/** Option holding the newline-separated IP list. */
	const OPTION = 'levers_disallowed_spam_ips';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'disallowed-spam-ips';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Track spam IPs', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Auto-spams new comments and WooCommerce reviews from IPs you previously marked as spam. Editable list under Settings > Discussion.', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'spam';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'message-square-warning';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Settings > Discussion field, registered on the discussion option
		// group so options.php will save it for us.
		add_action( 'admin_init', array( $this, 'register_settings_field' ) );

		// Real-time: auto-spam new comments whose IP is on the list.
		add_filter( 'pre_comment_approved', array( $this, 'flag_known_spam_ip' ), 30, 2 );

		// Learn from moderation: add on spam, remove on unspam.
		add_action( 'transition_comment_status', array( $this, 'sync_list_with_status' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Settings UI
	 * ------------------------------------------------------------------- */

	/**
	 * Register the option and add a section + field to Settings > Discussion,
	 * placed right after the built-in "Disallowed Comment Keys" textarea.
	 *
	 * @return void
	 */
	public function register_settings_field() {
		register_setting(
			'discussion',
			self::OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_ip_list' ),
				'default'           => '',
			)
		);

		add_settings_section(
			'levers_disallowed_spam_ips_section',
			__( 'Disallowed Spam IPs', 'levers' ),
			array( $this, 'render_section_intro' ),
			'discussion'
		);

		add_settings_field(
			self::OPTION,
			__( 'Disallowed Spam IPs', 'levers' ),
			array( $this, 'render_field' ),
			'discussion',
			'levers_disallowed_spam_ips_section',
			array( 'label_for' => self::OPTION )
		);
	}

	/**
	 * Description paragraph rendered between the section heading and the
	 * textarea.
	 *
	 * @return void
	 */
	public function render_section_intro() {
		echo '<p>' . esc_html__( 'One IP address per line. New comments from any IP on this list are automatically marked as spam. Marking a comment as spam adds its IP here; approving a spam comment removes it.', 'levers' ) . '</p>';
	}

	/**
	 * The textarea itself.
	 *
	 * @return void
	 */
	public function render_field() {
		$value = (string) get_option( self::OPTION, '' );

		printf(
			'<textarea name="%1$s" id="%1$s" rows="5" cols="50" class="large-text code">%2$s</textarea>',
			esc_attr( self::OPTION ),
			esc_textarea( $value )
		);
	}

	/**
	 * Normalise a submitted IP list: trim each line, drop blanks and
	 * anything that doesn't look like an IP, deduplicate, sort.
	 *
	 * @param mixed $value Raw textarea value.
	 * @return string
	 */
	public function sanitize_ip_list( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$lines = preg_split( '/\r\n|\r|\n/', $value );
		$ips   = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( false === filter_var( $line, FILTER_VALIDATE_IP ) ) {
				continue;
			}

			$ips[ $line ] = true;
		}

		$ips = array_keys( $ips );
		sort( $ips );

		return implode( "\n", $ips );
	}

	/* ---------------------------------------------------------------------
	 * Auto-spam on submission
	 * ------------------------------------------------------------------- */

	/**
	 * Mark a freshly posted comment as spam if its IP is on the list.
	 *
	 * @param int|string $approved    Approval status: 0, 1, 'spam' or 'trash'.
	 * @param array      $commentdata Comment data.
	 * @return int|string
	 */
	public function flag_known_spam_ip( $approved, $commentdata ) {
		// Leave anything already rejected alone.
		if ( 'spam' === $approved || 'trash' === $approved ) {
			return $approved;
		}

		// Never flag a comment from someone who can moderate comments.
		if ( ! empty( $commentdata['user_id'] ) && user_can( (int) $commentdata['user_id'], 'moderate_comments' ) ) {
			return $approved;
		}

		$ip = isset( $commentdata['comment_author_IP'] ) ? trim( (string) $commentdata['comment_author_IP'] ) : '';

		if ( '' === $ip ) {
			return $approved;
		}

		if ( $this->ip_is_listed( $ip ) ) {
			return 'spam';
		}

		return $approved;
	}

	/* ---------------------------------------------------------------------
	 * Learn from moderation
	 * ------------------------------------------------------------------- */

	/**
	 * Keep the IP list in sync with moderation actions.
	 *
	 * @param string     $new_status New status string ('approved','spam','trash','unapproved','delete').
	 * @param string     $old_status Previous status string.
	 * @param WP_Comment $comment    Comment being transitioned.
	 * @return void
	 */
	public function sync_list_with_status( $new_status, $old_status, $comment ) {
		if ( $new_status === $old_status ) {
			return;
		}

		if ( ! is_object( $comment ) || empty( $comment->comment_author_IP ) ) {
			return;
		}

		$ip = trim( (string) $comment->comment_author_IP );

		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return;
		}

		// Newly marked as spam -> remember the IP.
		if ( 'spam' === $new_status ) {
			$this->add_ip( $ip );
			return;
		}

		// Previously spam and now approved -> forget the IP, so the
		// approval actually sticks for future comments from this address.
		if ( 'spam' === $old_status && 'approved' === $new_status ) {
			$this->remove_ip( $ip );
		}
	}

	/* ---------------------------------------------------------------------
	 * List helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Whether an IP is in the saved list.
	 *
	 * @param string $ip Candidate IP.
	 * @return bool
	 */
	private function ip_is_listed( $ip ) {
		$list = $this->get_list();
		return isset( $list[ $ip ] );
	}

	/**
	 * Add an IP to the list. No-op if already present or invalid.
	 *
	 * @param string $ip IP to add.
	 * @return void
	 */
	private function add_ip( $ip ) {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return;
		}

		$list = $this->get_list();

		if ( isset( $list[ $ip ] ) ) {
			return;
		}

		$list[ $ip ] = true;
		$this->save_list( $list );
	}

	/**
	 * Remove an IP from the list. No-op if not present.
	 *
	 * @param string $ip IP to remove.
	 * @return void
	 */
	private function remove_ip( $ip ) {
		$list = $this->get_list();

		if ( ! isset( $list[ $ip ] ) ) {
			return;
		}

		unset( $list[ $ip ] );
		$this->save_list( $list );
	}

	/**
	 * Read the saved list as an [ip => true] map for O(1) lookup.
	 *
	 * @return array<string,true>
	 */
	private function get_list() {
		$raw   = (string) get_option( self::OPTION, '' );
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$out   = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$out[ $line ] = true;
		}

		return $out;
	}

	/**
	 * Persist an [ip => true] map back to the option, sorted.
	 *
	 * @param array<string,true> $list IP map.
	 * @return void
	 */
	private function save_list( $list ) {
		$ips = array_keys( $list );
		sort( $ips );
		update_option( self::OPTION, implode( "\n", $ips ) );
	}
}
