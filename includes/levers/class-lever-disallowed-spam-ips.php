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
 * Adds a "Disallowed spam IPs" textarea directly below WordPress's
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
		// Whitelist the option on the 'discussion' settings group AND drop
		// a hidden fallback input into the form, so:
		//   - options.php accepts the option on save, and
		//   - if JS is off and the user saves the page, the current value
		//     round-trips through the form instead of getting wiped.
		add_action( 'admin_init', array( $this, 'register_settings_field' ) );

		// Inline JS on options-discussion.php builds the real <tr> and
		// inserts it directly after the Disallowed Comment Keys row, then
		// removes the hidden fallback so we don't submit two values.
		add_action( 'admin_print_footer_scripts-options-discussion.php', array( $this, 'print_injection_script' ) );

		// Real-time: auto-spam new comments whose IP is on the list.
		add_filter( 'pre_comment_approved', array( $this, 'flag_known_spam_ip' ), 30, 2 );

		// Learn from moderation: add on spam, remove on unspam.
		add_action( 'transition_comment_status', array( $this, 'sync_list_with_status' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Settings UI
	 * ------------------------------------------------------------------- */

	/**
	 * Register the option and a no-title section whose only output is a
	 * hidden input pre-seeded with the current value. The section gets no
	 * registered fields, so do_settings_sections() emits nothing else for
	 * it (no h2, no form-table).
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
			'',
			array( $this, 'render_form_fallback' ),
			'discussion'
		);
	}

	/**
	 * Hidden input rendered inside the Settings > Discussion form so that
	 * a JS-disabled save preserves the current value instead of clearing
	 * the option.
	 *
	 * @return void
	 */
	public function render_form_fallback() {
		$value = (string) get_option( self::OPTION, '' );

		printf(
			'<input type="hidden" name="%1$s" value="%2$s" data-levers-spam-ips-fallback="1">',
			esc_attr( self::OPTION ),
			esc_attr( $value )
		);
	}

	/**
	 * Print the inline script that builds a textarea row matching core's
	 * Disallowed Comment Keys markup and inserts it right after that row.
	 *
	 * The script also removes the hidden fallback input so the form
	 * doesn't submit two values under the same name.
	 *
	 * @return void
	 */
	public function print_injection_script() {
		$config = array(
			'optionName' => self::OPTION,
			'title'      => __( 'Disallowed spam IPs', 'levers' ),
			'label'      => __( 'When a comment or WooCommerce review is submitted from an IP address on this list, it is marked as spam automatically. Marking a comment as spam adds its IP here; approving a spam comment removes it. One IP address per line.', 'levers' ),
			'value'      => (string) get_option( self::OPTION, '' ),
		);
		?>
		<script>
		(function () {
			var cfg = <?php echo wp_json_encode( $config ); ?>;

			function inject() {
				var anchor = document.getElementById( 'disallowed_keys' );
				if ( ! anchor || ! anchor.closest ) { return; }

				var anchorRow = anchor.closest( 'tr' );
				if ( ! anchorRow || ! anchorRow.parentNode ) { return; }

				// Already injected (e.g., script ran twice) - bail.
				if ( document.getElementById( cfg.optionName ) ) { return; }

				var row = document.createElement( 'tr' );

				var th = document.createElement( 'th' );
				th.setAttribute( 'scope', 'row' );
				th.textContent = cfg.title;
				row.appendChild( th );

				var td = document.createElement( 'td' );

				var fs = document.createElement( 'fieldset' );
				var legend = document.createElement( 'legend' );
				legend.className = 'screen-reader-text';
				var legendSpan = document.createElement( 'span' );
				legendSpan.textContent = cfg.title;
				legend.appendChild( legendSpan );
				fs.appendChild( legend );

				var labelP = document.createElement( 'p' );
				var labelEl = document.createElement( 'label' );
				labelEl.setAttribute( 'for', cfg.optionName );
				labelEl.textContent = cfg.label;
				labelP.appendChild( labelEl );
				fs.appendChild( labelP );

				var taP = document.createElement( 'p' );
				var ta = document.createElement( 'textarea' );
				ta.setAttribute( 'name', cfg.optionName );
				ta.setAttribute( 'id', cfg.optionName );
				ta.setAttribute( 'rows', '10' );
				ta.setAttribute( 'cols', '50' );
				ta.className = 'large-text code';
				ta.value = cfg.value;
				taP.appendChild( ta );
				fs.appendChild( taP );

				td.appendChild( fs );
				row.appendChild( td );

				anchorRow.parentNode.insertBefore( row, anchorRow.nextSibling );

				// Drop the hidden fallback so the textarea is the only
				// input submitting this option.
				var fallback = document.querySelector( 'input[data-levers-spam-ips-fallback="1"]' );
				if ( fallback && fallback.parentNode ) {
					fallback.parentNode.removeChild( fallback );
				}
			}

			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', inject );
			} else {
				inject();
			}
		}());
		</script>
		<?php
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
