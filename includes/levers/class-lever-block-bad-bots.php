<?php
/**
 * Lever: block the worst-offending bots before WordPress does any real work.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drops vulnerability scanners and brute-force bots at the door by matching
 * their User-Agent header and returning a 403 immediately.
 *
 * Search-engine crawlers (Googlebot, Bingbot, etc.) and AI agents (GPTBot,
 * ClaudeBot, etc.) are deliberately NOT on the list - the goal is to
 * neutralise the egregious offenders that hammer the server looking for
 * known vulnerabilities, not to fight legitimate indexing.
 *
 * The full list ships enabled. Site owners can opt individual bots back
 * in via the "Edit list" modal on the settings screen; their picks are
 * stored as an array of *disabled* patterns in the
 * levers_block_bad_bots_disabled option (an absent key = blocked).
 *
 * Patterns are matched case-insensitively as substrings against the
 * incoming User-Agent header. Empty UAs are ignored (a request with no
 * UA is more likely to be CLI/cron than a malicious bot, and they aren't
 * what this lever is for).
 */
class Levers_Lever_Block_Bad_Bots extends Levers_Lever {

	/** Option storing the array of pattern strings the user has unchecked. */
	const OPTION = 'levers_block_bad_bots_disabled';

	/** Nonce action for the toggle AJAX endpoint. */
	const NONCE = 'levers_block_bad_bots_toggle';

	/**
	 * Always-on AJAX wiring (regardless of lever state) - the editor needs
	 * to be reachable so users can prep their list before flipping the
	 * lever on (matches the other extras-bearing levers).
	 */
	public function __construct() {
		if ( is_admin() ) {
			add_action( 'wp_ajax_levers_toggle_bad_bot', array( $this, 'ajax_toggle' ) );
		}
	}

	/**
	 * Curated bad-bot list. Key = case-insensitive User-Agent substring,
	 * value = human-readable description shown in the "Edit list" modal.
	 *
	 * Sorted alphabetically (case-insensitive natural order) - the modal
	 * renders rows in source order, so keep this sorted when adding new
	 * entries.
	 *
	 * Search engines (Googlebot, Bingbot, DuckDuckBot, Applebot, etc.) and
	 * AI crawlers (GPTBot, ClaudeBot, PerplexityBot, etc.) are deliberately
	 * absent.
	 *
	 * @return array<string,string>
	 */
	protected function bots() {
		return array(
			'80legs'                   => __( 'Scraper-for-hire service (also appears as UA "008").', 'levers' ),
			'Acunetix'                 => __( 'Web vulnerability scanner used in attack recon.', 'levers' ),
			'Arachni'                  => __( 'Web application security scanner.', 'levers' ),
			'Atomic_Email_Hunter'      => __( 'Email harvester for spam lists.', 'levers' ),
			'BackStreet Browser'       => __( 'Whole-site ripper/offline browser.', 'levers' ),
			'Barkrowler'               => __( 'Babbar.tech backlink-index crawler.', 'levers' ),
			'BlackWidow'               => __( 'Site scanner/copier/downloader.', 'levers' ),
			'BLEXBot'                  => __( 'WebMeUp/SE Ranking backlink crawler, no benefit to the site owner.', 'levers' ),
			'BSQLBot'                  => __( 'Blind SQL injection automation bot.', 'levers' ),
			'Bytespider'               => __( 'ByteDance crawler, extremely aggressive (25x GPTBot), routinely ignores robots.txt, no search value to most sites.', 'levers' ),
			'CherryPicker'             => __( 'Classic email harvester for spam lists.', 'levers' ),
			'ChinaClaw'                => __( 'Legacy site-ripping/download tool.', 'levers' ),
			'Cliqzbot'                 => __( 'Cliqz shut down in 2020, so any traffic with this UA is stale or spoofed.', 'levers' ),
			'commix'                   => __( 'Automated command-injection exploitation tool.', 'levers' ),
			'dalfox'                   => __( 'XSS scanning and exploitation tool.', 'levers' ),
			'DataForSeoBot'            => __( 'Bulk SEO data crawler feeding a third-party API.', 'levers' ),
			'Diffbot'                  => __( 'Commercial scraper that resells your content as structured data to third parties.', 'levers' ),
			'dirb'                     => __( 'Directory/file brute-forcing tool.', 'levers' ),
			'DirBuster'                => __( 'Directory/file brute-forcing tool.', 'levers' ),
			'DISCo Pump'               => __( 'Aggressive download manager/grabber.', 'levers' ),
			'Download Demon'           => __( 'Aggressive download manager/grabber.', 'levers' ),
			'EmailCollector'           => __( 'Email harvester for spam lists.', 'levers' ),
			'EmailLeach'               => __( 'Email harvester for spam lists.', 'levers' ),
			'EmailSiphon'              => __( 'Email harvester for spam lists.', 'levers' ),
			'EmailWolf'                => __( 'Email harvester for spam lists.', 'levers' ),
			'ExtractorPro'             => __( 'Email/content harvester.', 'levers' ),
			'feroxbuster'              => __( 'Fast content/directory brute-forcing tool.', 'levers' ),
			'ffuf'                     => __( 'Web fuzzer / content brute-forcing tool.', 'levers' ),
			'fimap'                    => __( 'Local/remote file inclusion (LFI/RFI) exploitation tool.', 'levers' ),
			'FlashGet'                 => __( 'Aggressive download manager/grabber.', 'levers' ),
			'GetRight'                 => __( 'Aggressive download manager/grabber.', 'levers' ),
			'Go!Zilla'                 => __( 'Aggressive download manager/grabber.', 'levers' ),
			'gobuster'                 => __( 'Directory/DNS brute-forcing tool.', 'levers' ),
			'GSA Search Engine Ranker' => __( 'Automated mass link-spam tool (Xrumer successor family).', 'levers' ),
			'Havij'                    => __( 'Automated SQL injection tool.', 'levers' ),
			'HTTrack'                  => __( 'Whole-site ripper/offline browser.', 'levers' ),
			'JetCar'                   => __( 'Aggressive download manager/grabber.', 'levers' ),
			'Jorgee'                   => __( 'Automated vulnerability/exploit scanner.', 'levers' ),
			'katana'                   => __( 'Recon crawler used in automated attack chains.', 'levers' ),
			'l9scan'                   => __( 'LeakIX recon scanner (also l9explore / l9tcpid).', 'levers' ),
			'Mass Downloader'          => __( 'Aggressive download manager/grabber.', 'levers' ),
			'masscan'                  => __( 'High-speed port/internet scanner.', 'levers' ),
			'MauiBot'                  => __( 'Anonymous AWS scraper, no stated purpose, ignores robots.txt.', 'levers' ),
			'MegaIndex'                => __( 'Backlink-index crawler, no benefit to the owner.', 'levers' ),
			'MJ12bot'                  => __( 'Majestic distributed backlink crawler, aggressive, runs on volunteer machines.', 'levers' ),
			'Morfeus'                  => __( 'Automated exploit scanner probing for vulnerable scripts.', 'levers' ),
			'Mozlila'                  => __( 'Malware user agent, a deliberate "Mozilla" misspelling.', 'levers' ),
			'Nessus'                   => __( 'Vulnerability scanner used in attack recon.', 'levers' ),
			'Net Vampire'              => __( 'Aggressive download manager/grabber.', 'levers' ),
			'NetAnts'                  => __( 'Aggressive download accelerator/grabber.', 'levers' ),
			'Netsparker'               => __( 'Web vulnerability scanner used in attack recon.', 'levers' ),
			'Nikto'                    => __( 'Web vulnerability scanner used in attack recon.', 'levers' ),
			'Nmap Scripting Engine'    => __( 'Network/web recon and vulnerability scanning.', 'levers' ),
			'Nuclei'                   => __( 'Template-based vulnerability scanner, heavy in automated attacks.', 'levers' ),
			'Offline Explorer'         => __( 'Whole-site ripper/offline browser.', 'levers' ),
			'Offline Navigator'        => __( 'Whole-site downloader/offline browser.', 'levers' ),
			'omgili'                   => __( 'Webhose/Bright Data web-data-broker scraper, resells your content.', 'levers' ),
			'omgilibot'                => __( 'Companion crawler to omgili, same data-resale purpose.', 'levers' ),
			'OpenVAS'                  => __( 'Open-source vulnerability scanner used in attack recon.', 'levers' ),
			'PiplBot'                  => __( 'People-search data aggregator harvesting documents into a searchable index.', 'levers' ),
			'ScrapeBox'                => __( 'Mass comment-spam and content-scraping tool.', 'levers' ),
			'Seekport'                 => __( 'Low-value crawler, no meaningful search referral.', 'levers' ),
			'Semalt'                   => __( 'Notorious crawl and referrer spam.', 'levers' ),
			'SEOkicks'                 => __( 'Backlink-index crawler, no benefit to the owner.', 'levers' ),
			'serpstatbot'              => __( 'Serpstat SEO data crawler.', 'levers' ),
			'SiteSnagger'              => __( 'Whole-site ripper/offline browser.', 'levers' ),
			'SiteSucker'               => __( 'Whole-site ripper/offline browser (macOS).', 'levers' ),
			'skipfish'                 => __( 'Active web application recon and vulnerability scanner.', 'levers' ),
			'spbot'                    => __( 'OpenLinkProfiler/Seitwert backlink crawler, no benefit to the owner.', 'levers' ),
			'sqlmap'                   => __( 'Automated SQL injection tool.', 'levers' ),
			'SQLninja'                 => __( 'SQL injection exploitation tool.', 'levers' ),
			'Teleport'                 => __( 'Whole-site ripper/offline browser.', 'levers' ),
			'TeleportPro'              => __( 'Whole-site ripper/offline browser.', 'levers' ),
			'VelenPublicWebCrawler'    => __( 'Babbar-affiliated data crawler, no public service.', 'levers' ),
			'w3af'                     => __( 'Web application attack/audit framework.', 'levers' ),
			'Wapiti'                   => __( 'Web application vulnerability scanner.', 'levers' ),
			'WebBandit'                => __( 'Email/content harvester.', 'levers' ),
			'WebCopier'                => __( 'Whole-site ripper/offline browser.', 'levers' ),
			'WebReaper'                => __( 'Whole-site ripper/offline browser.', 'levers' ),
			'WebSauger'                => __( 'Site downloader/ripper.', 'levers' ),
			'Website eXtractor'        => __( 'Site extractor/ripper (spaced variant of WebsiteExtractor).', 'levers' ),
			'WebsiteExtractor'         => __( 'Whole-site ripper/offline browser.', 'levers' ),
			'WebStripper'              => __( 'Whole-site ripper/offline browser.', 'levers' ),
			'WebZIP'                   => __( 'Whole-site ripper/offline browser.', 'levers' ),
			'wfuzz'                    => __( 'Web application fuzzer / brute-forcing tool.', 'levers' ),
			'Xaldon WebSpider'         => __( 'Whole-site ripper/offline browser.', 'levers' ),
			'Xrumer'                   => __( 'Mass forum/comment/guestbook spam tool, built to bypass CAPTCHAs and registration.', 'levers' ),
			'XSStrike'                 => __( 'Cross-site scripting (XSS) detection and exploitation tool.', 'levers' ),
			'zgrab'                    => __( 'Internet-wide mass scanner.', 'levers' ),
			'ZmEu'                     => __( 'Classic automated exploit/vulnerability scanner.', 'levers' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'block-bad-bots';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Block bad bots', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Returns 403 to the worst-offending scanners and brute-force bots before WordPress does any real work. Search engines and AI crawlers stay welcome.', 'levers' );
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
		return 'bot-off';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return;
		}

		$ua = (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched against a curated substring list, not stored or rendered.

		foreach ( $this->patterns() as $pattern ) {
			if ( '' !== $pattern && false !== stripos( $ua, $pattern ) ) {
				$this->block();
			}
		}
	}

	/**
	 * Active block-list: full curated set minus the patterns the user has
	 * unchecked in the modal. Filterable so site owners can extend without
	 * forking.
	 *
	 * @return string[]
	 */
	protected function patterns() {
		$disabled = $this->get_disabled();
		$active   = array();

		foreach ( array_keys( $this->bots() ) as $pattern ) {
			if ( ! in_array( $pattern, $disabled, true ) ) {
				$active[] = $pattern;
			}
		}

		/**
		 * Filter the active list of bad-bot User-Agent substrings.
		 *
		 * @param string[]                                    $active   Patterns currently being blocked.
		 * @param string[]                                    $disabled Patterns the site owner has unchecked.
		 * @param Levers_Lever_Block_Bad_Bots                 $lever    Lever instance.
		 */
		return (array) apply_filters( 'levers_bad_bot_patterns', $active, $disabled, $this );
	}

	/**
	 * Send a 403 with a friendly HTML page and exit before WordPress does
	 * any further work. The page tells a real visitor who got accidentally
	 * caught what to do, and shows their IP so they can quote it back.
	 *
	 * @return void
	 */
	protected function block() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- escaped on output below.

		if ( ! headers_sent() ) {
			header( 'HTTP/1.1 403 Forbidden' );
			header( 'Cache-Control: no-store' );
			header( 'Content-Type: text/html; charset=utf-8' );
		}

		$ip_block = '' === $ip
			? ''
			: '<p class="ip">' . __( 'Your IP:', 'levers' ) . ' <code>' . htmlspecialchars( $ip, ENT_QUOTES, 'UTF-8' ) . '</code></p>';

		echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>'
			, __( 'Access Denied', 'levers' )
			, '</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:560px;margin:80px auto;padding:0 24px;color:#1d2327;line-height:1.5}h1{font-size:28px;margin:0 0 16px}p{margin:0 0 12px}.ip code{background:#f1f1f1;padding:2px 6px;border-radius:3px;font-size:13px}</style></head><body>'
			, '<h1>', __( 'Access Denied', 'levers' ), '</h1>'
			, '<p>', __( 'Your IP address has been blocked due to suspicious activity. If you believe this is a mistake, please contact us and include your IP address.', 'levers' ), '</p>'
			, $ip_block
			, '</body></html>';
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Stored opt-outs
	 * ------------------------------------------------------------------- */

	/**
	 * Patterns the user has unchecked. Filtered to known patterns so a
	 * removed entry can't linger in the option forever.
	 *
	 * @return string[]
	 */
	private function get_disabled() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$known = array_keys( $this->bots() );

		return array_values( array_intersect( $known, array_filter( $stored, 'is_string' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings UI - link + modal
	 * ------------------------------------------------------------------- */

	/**
	 * "Edit list" link in the lever's heading row + the modal markup.
	 *
	 * Only rendered when the lever is on - per UX request, the modal
	 * shouldn't be reachable until the user has switched blocking on.
	 *
	 * @param bool $enabled Whether the lever is currently enabled.
	 * @return void
	 */
	public function render_extra( $enabled = false ) {
		if ( ! $enabled ) {
			return;
		}

		$bots     = $this->bots();
		$disabled = $this->get_disabled();
		?>
		<span class="levers-extra-sep" aria-hidden="true">&bull;</span>
		<a href="#" class="levers-favicon-link" data-levers-bad-bots-edit><?php esc_html_e( 'Edit list', 'levers' ); ?></a>

		<div id="levers-bad-bots-modal" class="levers-modal" hidden>
			<div class="levers-modal__overlay" data-levers-close></div>
			<div class="levers-modal__panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Bot block list', 'levers' ); ?>">
				<div class="levers-modal__head">
					<h2><?php esc_html_e( 'Bot block list', 'levers' ); ?></h2>
					<button type="button" class="levers-modal__close" data-levers-close aria-label="<?php esc_attr_e( 'Close', 'levers' ); ?>">&times;</button>
				</div>
				<div class="levers-modal__body">
					<p class="levers-scripts__intro">
						<?php esc_html_e( 'Every bot below is blocked by default. Uncheck any you want to let through. Changes save automatically.', 'levers' ); ?>
					</p>
					<table class="widefat striped levers-bad-bots-table">
						<thead>
							<tr>
								<th class="levers-bad-bots-table__check" scope="col"><?php esc_html_e( 'Block', 'levers' ); ?></th>
								<th class="levers-bad-bots-table__ua" scope="col"><?php esc_html_e( 'User-Agent pattern', 'levers' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Description', 'levers' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $bots as $pattern => $desc ) : ?>
								<?php $is_blocked = ! in_array( $pattern, $disabled, true ); ?>
								<tr>
									<td class="levers-bad-bots-table__check">
										<label class="screen-reader-text" for="levers-bb-<?php echo esc_attr( md5( $pattern ) ); ?>"><?php
											/* translators: %s: User-Agent pattern. */
											printf( esc_html__( 'Block %s', 'levers' ), esc_html( $pattern ) );
										?></label>
										<input
											type="checkbox"
											id="levers-bb-<?php echo esc_attr( md5( $pattern ) ); ?>"
											class="levers-bad-bots-toggle"
											data-pattern="<?php echo esc_attr( $pattern ); ?>"
											<?php checked( $is_blocked ); ?>
										>
									</td>
									<td class="levers-bad-bots-table__ua"><code><?php echo esc_html( $pattern ); ?></code></td>
									<td><?php echo esc_html( $desc ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>

		<script>
		/* Levers - block bad bots editor */
		( function () {
			var modal = document.getElementById( 'levers-bad-bots-modal' );
			if ( ! modal ) { return; }

			var cfg = <?php echo wp_json_encode( array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'saved'   => __( 'Bot blocking settings updated', 'levers' ),
				'failed'  => __( 'Could not save bot blocking settings.', 'levers' ),
			) ); ?>;

			function open( e ) { if ( e ) { e.preventDefault(); } modal.hidden = false; }
			function close() { modal.hidden = true; }

			document.querySelectorAll( '[data-levers-bad-bots-edit]' ).forEach( function ( link ) {
				link.addEventListener( 'click', open );
			} );

			modal.addEventListener( 'click', function ( e ) {
				if ( e.target.hasAttribute && e.target.hasAttribute( 'data-levers-close' ) ) { close(); }
			} );

			document.addEventListener( 'keyup', function ( e ) {
				if ( 'Escape' === e.key && ! modal.hidden ) { close(); }
			} );

			modal.querySelectorAll( '.levers-bad-bots-toggle' ).forEach( function ( cb ) {
				cb.addEventListener( 'change', function () {
					var previous = ! cb.checked; // pre-change state
					cb.disabled  = true;

					var body = new URLSearchParams();
					body.append( 'action', 'levers_toggle_bad_bot' );
					body.append( 'nonce', cfg.nonce );
					body.append( 'pattern', cb.dataset.pattern );
					body.append( 'block', cb.checked ? '1' : '0' );

					fetch( cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							cb.disabled = false;
							if ( res && res.success ) {
								if ( window.toastr ) { window.toastr.success( cfg.saved ); }
							} else {
								cb.checked = previous; // revert UI
								if ( window.toastr ) {
									window.toastr.error( ( res && res.data && res.data.message ) || cfg.failed );
								} else {
									window.alert( cfg.failed );
								}
							}
						} )
						.catch( function () {
							cb.disabled = false;
							cb.checked  = previous;
							if ( window.toastr ) { window.toastr.error( cfg.failed ); }
						} );
				} );
			} );
		}() );
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * AJAX: toggle a single pattern's blocked state.
	 *
	 * @return void
	 */
	public function ajax_toggle() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'levers' ) ) );
		}

		$pattern = isset( $_POST['pattern'] ) ? (string) wp_unslash( $_POST['pattern'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below by membership in known list.
		$block   = isset( $_POST['block'] ) ? '1' === (string) $_POST['block'] : false;

		if ( ! array_key_exists( $pattern, $this->bots() ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown bot pattern.', 'levers' ) ) );
		}

		$disabled = $this->get_disabled();

		if ( $block ) {
			// Block = remove from disabled list.
			$disabled = array_values( array_diff( $disabled, array( $pattern ) ) );
		} elseif ( ! in_array( $pattern, $disabled, true ) ) {
			// Don't block = append to disabled list.
			$disabled[] = $pattern;
		}

		update_option( self::OPTION, $disabled, false );

		wp_send_json_success();
	}
}
