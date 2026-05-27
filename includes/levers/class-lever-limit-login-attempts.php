<?php
/**
 * Lever: limit login attempts.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Brute-force protection.
 *
 * Throttles failed logins per IP address: a temporary lockout after a run
 * of failures, a permanent ban for repeat offenders, and an admin ban log
 * for reviewing and lifting bans.
 */
class Levers_Lever_Limit_Login_Attempts extends Levers_Lever {

	/** Option holding the per-IP record store. */
	const OPTION = 'levers_login_guard';

	/** Failed logins allowed per IP within the 24 hour window. */
	const MAX_ATTEMPTS = 5;

	/** Temporary lockouts allowed before a permanent ban. */
	const MAX_BLOCKS = 2;

	/** Nonce action for the ban-log AJAX endpoint. */
	const NONCE = 'levers_ban_log';

	/* ---------------------------------------------------------------------
	 * Lever contract
	 * ------------------------------------------------------------------- */

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'limit-login-attempts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Limit login attempts', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Locks out an IP after 5 failed logins in 24 hours. A second lockout bans it permanently. A successful login clears the count.', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'security';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'key-round';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_filter( 'authenticate', array( $this, 'filter_authenticate' ), 50, 3 );

		if ( is_admin() ) {
			add_action( 'wp_ajax_levers_remove_ban', array( $this, 'ajax_remove_ban' ) );
			add_action( 'admin_footer', array( $this, 'render_modal' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Login guard
	 * ------------------------------------------------------------------- */

	/**
	 * Gate every login attempt: block banned IPs, count failures, and clear
	 * the count on success.
	 *
	 * @param WP_User|WP_Error|null $user     Authentication result so far.
	 * @param string                $username Submitted username.
	 * @param string                $password Submitted password.
	 * @return WP_User|WP_Error|null
	 */
	public function filter_authenticate( $user, $username, $password ) {
		$username = is_string( $username ) ? $username : '';
		$password = is_string( $password ) ? $password : '';

		$ip     = $this->get_ip();
		$record = $this->get_record( $ip );

		// Already banned: reject everything, and stop counting.
		if ( $this->is_blocked( $record ) ) {
			return new WP_Error( 'levers_login_blocked', $this->block_message( $record ) );
		}

		// Correct credentials: clear this IP's failure count.
		if ( $user instanceof WP_User ) {
			$this->reset_failures( $ip );
			return $user;
		}

		// A genuine failed attempt: both fields filled, core rejected them.
		if ( is_wp_error( $user ) && '' !== $username && '' !== $password ) {
			$record = $this->register_failure( $ip );

			if ( $this->is_blocked( $record ) ) {
				return new WP_Error( 'levers_login_blocked', $this->block_message( $record ) );
			}

			$remaining = max( 0, self::MAX_ATTEMPTS - (int) $record['fails'] );
			return new WP_Error( 'levers_attempts_left', $this->remaining_message( $remaining ) );
		}

		return $user;
	}

	/**
	 * Resolve the visitor's IP address.
	 *
	 * Forwarded-IP headers (CF-Connecting-IP, X-Forwarded-For) are only
	 * honored when REMOTE_ADDR is itself a known reverse proxy. Otherwise
	 * an attacker reaching the origin directly could forge a fresh header
	 * per request and rotate the rate-limit key every attempt, defeating
	 * the lockout entirely.
	 *
	 * @return string
	 */
	private function get_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		$remote = filter_var( $remote, FILTER_VALIDATE_IP );

		if ( ! $remote ) {
			return '0.0.0.0';
		}

		if ( ! $this->is_trusted_proxy( $remote ) ) {
			return $remote;
		}

		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidate = wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
			$valid     = filter_var( $candidate, FILTER_VALIDATE_IP );
			if ( $valid ) {
				return $valid;
			}
		}

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
			$parts     = explode( ',', $forwarded );
			$candidate = trim( (string) reset( $parts ) );
			$valid     = filter_var( $candidate, FILTER_VALIDATE_IP );
			if ( $valid ) {
				return $valid;
			}
		}

		return $remote;
	}

	/**
	 * Whether REMOTE_ADDR belongs to a reverse proxy whose forwarded-IP
	 * headers can be trusted.
	 *
	 * @param string $ip Validated REMOTE_ADDR.
	 * @return bool
	 */
	private function is_trusted_proxy( $ip ) {
		foreach ( $this->trusted_proxy_ranges() as $range ) {
			if ( $this->ip_in_range( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * CIDR ranges that count as trusted reverse proxies.
	 *
	 * Defaults cover Cloudflare's published edge ranges plus loopback and
	 * RFC1918 / IPv6 ULA private space, so common nginx-in-front-of-PHP
	 * setups work out of the box. Site owners with other proxies (custom
	 * load balancers, a second CDN) can extend the list via the
	 * `levers_login_trusted_proxies` filter.
	 *
	 * @return string[]
	 */
	private function trusted_proxy_ranges() {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$ranges = array_merge(
			// Loopback.
			array( '127.0.0.0/8', '::1/128' ),
			// RFC1918 private + IPv6 unique-local.
			array( '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7' ),
			// Cloudflare IPv4 -- https://www.cloudflare.com/ips-v4/ (refreshed 2026-05-26).
			array(
				'173.245.48.0/20',
				'103.21.244.0/22',
				'103.22.200.0/22',
				'103.31.4.0/22',
				'141.101.64.0/18',
				'108.162.192.0/18',
				'190.93.240.0/20',
				'188.114.96.0/20',
				'197.234.240.0/22',
				'198.41.128.0/17',
				'162.158.0.0/15',
				'104.16.0.0/13',
				'104.24.0.0/14',
				'172.64.0.0/13',
				'131.0.72.0/22',
			),
			// Cloudflare IPv6 -- https://www.cloudflare.com/ips-v6/ (refreshed 2026-05-26).
			array(
				'2400:cb00::/32',
				'2606:4700::/32',
				'2803:f800::/32',
				'2405:b500::/32',
				'2405:8100::/32',
				'2a06:98c0::/29',
				'2c0f:f248::/32',
			)
		);

		/**
		 * Filter the list of trusted reverse-proxy CIDR ranges.
		 *
		 * Forwarded-IP headers (CF-Connecting-IP, X-Forwarded-For) are
		 * only honored when REMOTE_ADDR falls inside one of these ranges.
		 *
		 * @param string[] $ranges Default ranges: loopback + RFC1918 + Cloudflare.
		 */
		$ranges = apply_filters( 'levers_login_trusted_proxies', $ranges );

		$cached = is_array( $ranges ) ? array_values( array_filter( $ranges, 'is_string' ) ) : array();

		return $cached;
	}

	/**
	 * Whether $ip falls inside $range, where $range is a CIDR string
	 * ("10.0.0.0/8", "2400:cb00::/32") or a bare IP. Works for both IPv4
	 * and IPv6 via inet_pton.
	 *
	 * @param string $ip    A validated IP address.
	 * @param string $range CIDR or bare IP.
	 * @return bool
	 */
	private function ip_in_range( $ip, $range ) {
		if ( false === strpos( $range, '/' ) ) {
			$a = @inet_pton( $ip );    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false-return is the documented failure path.
			$b = @inet_pton( $range ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false !== $a && false !== $b && $a === $b;
		}

		list( $subnet, $bits_raw ) = explode( '/', $range, 2 );
		$bits = (int) $bits_raw;

		$ip_packed     = @inet_pton( $ip );     // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$subnet_packed = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $ip_packed || false === $subnet_packed ) {
			return false;
		}

		// Cross-family comparison (IPv4 vs IPv6) is never a match.
		if ( strlen( $ip_packed ) !== strlen( $subnet_packed ) ) {
			return false;
		}

		$max_bits = strlen( $ip_packed ) * 8;
		if ( $bits < 0 || $bits > $max_bits ) {
			return false;
		}

		$full_bytes = intdiv( $bits, 8 );
		$remainder  = $bits % 8;

		if ( $full_bytes > 0 && substr( $ip_packed, 0, $full_bytes ) !== substr( $subnet_packed, 0, $full_bytes ) ) {
			return false;
		}

		if ( 0 !== $remainder ) {
			$mask = ( 0xFF << ( 8 - $remainder ) ) & 0xFF;
			if ( ( ord( $ip_packed[ $full_bytes ] ) & $mask ) !== ( ord( $subnet_packed[ $full_bytes ] ) & $mask ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a record represents a currently banned IP.
	 *
	 * @param array $record IP record.
	 * @return bool
	 */
	private function is_blocked( $record ) {
		if ( ! empty( $record['permanent'] ) ) {
			return true;
		}

		return ! empty( $record['blocked_until'] ) && $record['blocked_until'] > time();
	}

	/**
	 * Record a failed attempt, rolling the 24 hour window and applying a
	 * lockout (or permanent ban) once the limit is reached.
	 *
	 * @param string $ip IP address.
	 * @return array The updated record.
	 */
	private function register_failure( $ip ) {
		$record = $this->get_record( $ip );
		$now    = time();

		// Roll the window when the previous one is older than 24 hours.
		if ( $record['window_start'] && ( $now - $record['window_start'] ) > DAY_IN_SECONDS ) {
			$record['fails']        = 0;
			$record['window_start'] = 0;
		}

		if ( ! $record['window_start'] ) {
			$record['window_start'] = $now;
		}

		$record['fails']++;
		$record['last_seen'] = $now;

		if ( $record['fails'] >= self::MAX_ATTEMPTS ) {
			$record['blocks']++;
			$record['blocked_until'] = $now + DAY_IN_SECONDS;
			$record['fails']         = 0;
			$record['window_start']  = 0;

			if ( $record['blocks'] >= self::MAX_BLOCKS ) {
				$record['permanent'] = true;
			}
		}

		$this->save_record( $ip, $record );

		return $record;
	}

	/**
	 * Clear the failure count for an IP after a successful login.
	 *
	 * The block history is left intact so repeat offenders are still caught.
	 *
	 * @param string $ip IP address.
	 * @return void
	 */
	private function reset_failures( $ip ) {
		$store = $this->get_store();

		if ( ! isset( $store[ $ip ] ) ) {
			return;
		}

		$record                 = array_merge( $this->default_record(), (array) $store[ $ip ] );
		$record['fails']         = 0;
		$record['window_start']  = 0;
		$record['last_seen']     = time();

		$this->save_record( $ip, $record );
	}

	/* ---------------------------------------------------------------------
	 * Record store
	 * ------------------------------------------------------------------- */

	/**
	 * Default shape of an IP record.
	 *
	 * @return array
	 */
	private function default_record() {
		return array(
			'fails'         => 0,
			'window_start'  => 0,
			'blocks'        => 0,
			'blocked_until' => 0,
			'permanent'     => false,
			'last_seen'     => 0,
		);
	}

	/**
	 * The full per-IP store.
	 *
	 * @return array
	 */
	private function get_store() {
		$store = get_option( self::OPTION, array() );

		return is_array( $store ) ? $store : array();
	}

	/**
	 * One IP's record, with defaults filled in.
	 *
	 * @param string $ip IP address.
	 * @return array
	 */
	private function get_record( $ip ) {
		$store = $this->get_store();

		if ( isset( $store[ $ip ] ) && is_array( $store[ $ip ] ) ) {
			return array_merge( $this->default_record(), $store[ $ip ] );
		}

		return $this->default_record();
	}

	/**
	 * Persist one IP's record, pruning stale non-banned entries so the
	 * option cannot grow without bound.
	 *
	 * @param string $ip     IP address.
	 * @param array  $record Record to store.
	 * @return void
	 */
	private function save_record( $ip, $record ) {
		$store        = $this->get_store();
		$store[ $ip ] = $record;
		$now          = time();

		foreach ( $store as $key => $rec ) {
			$rec    = array_merge( $this->default_record(), (array) $rec );
			$banned = ! empty( $rec['permanent'] ) || $rec['blocked_until'] > $now;
			$recent = $rec['last_seen'] && ( $now - $rec['last_seen'] ) < ( 2 * DAY_IN_SECONDS );

			if ( ! $banned && ! $recent ) {
				unset( $store[ $key ] );
			}
		}

		update_option( self::OPTION, $store, false );
	}

	/**
	 * Currently banned IPs, sorted by IP address.
	 *
	 * @return array IP => record.
	 */
	private function banned_list() {
		$store = $this->get_store();
		$now   = time();
		$list  = array();

		foreach ( $store as $ip => $rec ) {
			$rec = array_merge( $this->default_record(), (array) $rec );

			if ( ! empty( $rec['permanent'] ) || $rec['blocked_until'] > $now ) {
				$list[ $ip ] = $rec;
			}
		}

		uksort( $list, 'strnatcmp' );

		return $list;
	}

	/* ---------------------------------------------------------------------
	 * Messages
	 * ------------------------------------------------------------------- */

	/**
	 * Login-failed message naming how many attempts are left.
	 *
	 * @param int $remaining Attempts remaining.
	 * @return string
	 */
	private function remaining_message( $remaining ) {
		return sprintf(
			/* translators: %d: number of login attempts remaining. */
			_n(
				'<strong>Login failed.</strong> %d attempt remaining before this IP address is locked out.',
				'<strong>Login failed.</strong> %d attempts remaining before this IP address is locked out.',
				$remaining,
				'levers'
			),
			$remaining
		);
	}

	/**
	 * Message shown to a blocked IP.
	 *
	 * @param array $record IP record.
	 * @return string
	 */
	private function block_message( $record ) {
		if ( ! empty( $record['permanent'] ) ) {
			return __( '<strong>Access denied.</strong> This IP address has been permanently blocked after repeated failed login attempts.', 'levers' );
		}

		return sprintf(
			/* translators: %s: human-readable time, e.g. "23 hours". */
			__( '<strong>Too many failed login attempts.</strong> This IP address is locked out. Please try again in %s.', 'levers' ),
			human_time_diff( time(), (int) $record['blocked_until'] )
		);
	}

	/* ---------------------------------------------------------------------
	 * Ban log (admin)
	 * ------------------------------------------------------------------- */

	/**
	 * "View ban log" link, shown beneath the lever when it is switched on.
	 *
	 * @param bool $enabled Whether the lever is currently enabled.
	 * @return void
	 */
	public function render_extra( $enabled = false ) {
		if ( ! $enabled ) {
			return;
		}

		$count = count( $this->banned_list() );
		?>
		<span class="levers-extra-sep" aria-hidden="true">&bull;</span>
		<a href="#levers-banlog" class="levers-banlog-link"><?php esc_html_e( 'View ban log', 'levers' ); ?><span class="levers-banlog-count"><?php echo $count ? ' (' . (int) $count . ')' : ''; ?></span></a>
		<?php
	}

	/**
	 * Human-readable status for a banned record.
	 *
	 * @param array $record IP record.
	 * @return string
	 */
	private function status_label( $record ) {
		if ( ! empty( $record['permanent'] ) ) {
			return __( 'Permanent', 'levers' );
		}

		return sprintf(
			/* translators: %s: human-readable time, e.g. "21 hours". */
			__( 'Temporary, %s left', 'levers' ),
			human_time_diff( time(), (int) $record['blocked_until'] )
		);
	}

	/**
	 * Human-readable "last attempt" label for a record.
	 *
	 * @param array $record IP record.
	 * @return string
	 */
	private function last_seen_label( $record ) {
		if ( empty( $record['last_seen'] ) ) {
			return '-';
		}

		return sprintf(
			/* translators: %s: human-readable time, e.g. "2 hours". */
			__( '%s ago', 'levers' ),
			human_time_diff( (int) $record['last_seen'], time() )
		);
	}

	/**
	 * Render the ban-log modal and its script in the footer of the Levers
	 * settings screen.
	 *
	 * @return void
	 */
	public function render_modal() {
		$screen = get_current_screen();

		if ( ! $screen || 'settings_page_levers' !== $screen->id ) {
			return;
		}

		$banned = $this->banned_list();
		?>
		<div id="levers-banlog" class="levers-modal" hidden>
			<div class="levers-modal__overlay" data-levers-close></div>
			<div class="levers-modal__panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Login ban log', 'levers' ); ?>">
				<div class="levers-modal__head">
					<h2><?php esc_html_e( 'Login ban log', 'levers' ); ?></h2>
					<button type="button" class="levers-modal__close" data-levers-close aria-label="<?php esc_attr_e( 'Close', 'levers' ); ?>">&times;</button>
				</div>
				<div class="levers-modal__tools">
					<input type="search" id="levers-banlog-search" placeholder="<?php esc_attr_e( 'Search by IP address', 'levers' ); ?>" />
				</div>
				<div class="levers-modal__body">
					<table class="levers-banlog-table widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'IP address', 'levers' ); ?></th>
								<th><?php esc_html_e( 'Status', 'levers' ); ?></th>
								<th><?php esc_html_e( 'Times blocked', 'levers' ); ?></th>
								<th><?php esc_html_e( 'Last attempt', 'levers' ); ?></th>
								<th class="levers-banlog-action"></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $banned as $ip => $rec ) : ?>
								<tr data-ip="<?php echo esc_attr( $ip ); ?>">
									<td><code><?php echo esc_html( $ip ); ?></code></td>
									<td><?php echo esc_html( $this->status_label( $rec ) ); ?></td>
									<td><?php echo (int) $rec['blocks']; ?></td>
									<td><?php echo esc_html( $this->last_seen_label( $rec ) ); ?></td>
									<td class="levers-banlog-action">
										<button type="button" class="button button-small levers-unban" data-ip="<?php echo esc_attr( $ip ); ?>">
											<?php esc_html_e( 'Remove ban', 'levers' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="levers-banlog-empty"><?php esc_html_e( 'No IP addresses are currently banned.', 'levers' ); ?></p>
				</div>
			</div>
		</div>
		<script>
		/* Levers - ban log modal */
		( function () {
			var modal = document.getElementById( 'levers-banlog' );
			if ( ! modal ) { return; }

			var cfg = <?php echo wp_json_encode(
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE ),
					'confirm' => __( 'Remove the ban on this IP address?', 'levers' ),
					'removed' => __( 'Ban removed.', 'levers' ),
					'failed'  => __( 'The ban could not be removed.', 'levers' ),
				)
			); ?>;
			var search = document.getElementById( 'levers-banlog-search' );

			function openModal( e ) { if ( e ) { e.preventDefault(); } modal.hidden = false; if ( search ) { search.focus(); } }
			function closeModal() { modal.hidden = true; }

			document.querySelectorAll( '.levers-banlog-link' ).forEach( function ( link ) {
				link.addEventListener( 'click', openModal );
			} );

			modal.addEventListener( 'click', function ( e ) {
				if ( e.target.hasAttribute( 'data-levers-close' ) ) { closeModal(); }
			} );
			document.addEventListener( 'keyup', function ( e ) {
				if ( 'Escape' === e.key && ! modal.hidden ) { closeModal(); }
			} );

			if ( search ) {
				search.addEventListener( 'input', function () {
					var q = this.value.toLowerCase();
					modal.querySelectorAll( 'tbody tr' ).forEach( function ( row ) {
						var ip = ( row.getAttribute( 'data-ip' ) || '' ).toLowerCase();
						row.style.display = ( ip.indexOf( q ) !== -1 ) ? '' : 'none';
					} );
				} );
			}

			modal.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest ? e.target.closest( '.levers-unban' ) : null;
				if ( ! btn ) { return; }

				var ip = btn.getAttribute( 'data-ip' );
				if ( ! window.confirm( cfg.confirm ) ) { return; }

				btn.disabled = true;

				var body = new URLSearchParams();
				body.append( 'action', 'levers_remove_ban' );
				body.append( 'nonce', cfg.nonce );
				body.append( 'ip', ip );

				fetch( cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res && res.success ) {
							var row = btn.closest( 'tr' );
							if ( row && row.parentNode ) { row.parentNode.removeChild( row ); }
							refresh();
							if ( window.toastr ) { window.toastr.success( cfg.removed ); }
						} else {
							btn.disabled = false;
							window.alert( ( res && res.data && res.data.message ) || cfg.failed );
						}
					} )
					.catch( function () { btn.disabled = false; window.alert( cfg.failed ); } );
			} );

			function refresh() {
				var rows  = modal.querySelectorAll( 'tbody tr' ).length;
				var empty = modal.querySelector( '.levers-banlog-empty' );
				var table = modal.querySelector( '.levers-banlog-table' );
				if ( empty ) { empty.style.display = rows ? 'none' : 'block'; }
				if ( table ) { table.style.display = rows ? '' : 'none'; }
				document.querySelectorAll( '.levers-banlog-count' ).forEach( function ( el ) {
					el.textContent = rows ? ' (' + rows + ')' : '';
				} );
			}

			refresh();
		}() );
		</script>
		<?php
	}

	/**
	 * AJAX: lift a ban by removing the IP's record entirely.
	 *
	 * @return void
	 */
	public function ajax_remove_ban() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'levers' ) ) );
		}

		$ip = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
		$ip = filter_var( $ip, FILTER_VALIDATE_IP );

		if ( ! $ip ) {
			wp_send_json_error( array( 'message' => __( 'Invalid IP address.', 'levers' ) ) );
		}

		$store = $this->get_store();

		if ( isset( $store[ $ip ] ) ) {
			unset( $store[ $ip ] );
			update_option( self::OPTION, $store, false );
		}

		wp_send_json_success( array( 'ip' => $ip ) );
	}
}
