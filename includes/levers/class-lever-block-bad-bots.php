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

	/** Flag set once WP Rocket's advanced-cache.php has been regenerated with our UAs. */
	const ROCKET_MARK = 'levers_block_bad_bots_rocket_integrated';

	/** Flag set once W3 Total Cache's pgcache.reject.ua has been merged with our UAs. */
	const W3TC_MARK = 'levers_block_bad_bots_w3tc_integrated';

	/** Flag set once WP Super Cache's cache_rejected_user_agent has been merged with our UAs. */
	const WPSC_MARK = 'levers_block_bad_bots_wpsc_integrated';

	/** Flag set once the LiteSpeed Cache .htaccess no-cache block has been written. */
	const LITESPEED_MARK = 'levers_block_bad_bots_litespeed_integrated';

	/** insert_with_markers() marker for the LiteSpeed bypass block in the site .htaccess. */
	const HTACCESS_MARKER = 'Levers Bad Bots Nocache';

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
		// Filter registration is safe at plugins_loaded - it's just adding a
		// callback, doesn't touch WP Rocket's container.
		$this->ensure_rocket_filter();

		// CRITICAL: cache-plugin integrations MUST defer to `init`. Cache plugins
		// (WP Rocket especially) finish bootstrapping their DI containers on
		// plugins_loaded too, and calling their regen helpers before they're
		// ready throws fatals (rocket_generate_advanced_cache_file() -> ->get()
		// on null). Hook the integration pass at init priority 99 so every
		// plugin has had a chance to come up.
		if ( did_action( 'init' ) ) {
			$this->maybe_integrate_caches();
		} elseif ( ! has_action( 'init', array( $this, 'maybe_integrate_caches' ) ) ) {
			add_action( 'init', array( $this, 'maybe_integrate_caches' ), 99 );
		}

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
	 * Per-request idempotent cache integration. Called from `init` (deferred
	 * from run()) so cache plugins are fully bootstrapped. Each per-plugin
	 * integration is itself guarded by a marker option, so this is a cheap
	 * no-op once everything is in sync.
	 *
	 * @return void
	 */
	public function maybe_integrate_caches() {
		if ( ! get_option( self::ROCKET_MARK ) ) {
			$this->rebuild_rocket();
		}
		if ( ! get_option( self::W3TC_MARK ) ) {
			$this->integrate_w3tc();
		}
		if ( ! get_option( self::WPSC_MARK ) ) {
			$this->integrate_wpsc();
		}
		if ( ! get_option( self::LITESPEED_MARK ) ) {
			$this->integrate_litespeed();
		}
	}

	/**
	 * Called once when the lever is toggled on. Force a fresh integration with
	 * each supported cache plugin so its cache config knows about our UAs
	 * without waiting for the next page load.
	 */
	public function on_enable() {
		$this->reset_cache_marks();
		$this->ensure_rocket_filter();
		$this->rebuild_rocket();
		$this->integrate_w3tc();
		$this->integrate_wpsc();
		$this->integrate_litespeed();
	}

	/**
	 * Called once when the lever is toggled off. Strip our UAs from each
	 * cache plugin's reject list so normal caching resumes for them.
	 */
	public function on_disable() {
		$this->reset_cache_marks();
		// WP Rocket: rebuild without our filter so our UAs drop out.
		if ( function_exists( 'rocket_generate_advanced_cache_file' ) ) {
			rocket_generate_advanced_cache_file();
		}
		if ( function_exists( 'rocket_generate_config_file' ) ) {
			rocket_generate_config_file();
		}
		$this->disintegrate_w3tc();
		$this->disintegrate_wpsc();
		$this->disintegrate_litespeed();
	}

	/**
	 * Clear every cache-integration marker so the next on_enable / run() pass
	 * re-injects from scratch.
	 *
	 * @return void
	 */
	private function reset_cache_marks() {
		delete_option( self::ROCKET_MARK );
		delete_option( self::W3TC_MARK );
		delete_option( self::WPSC_MARK );
		delete_option( self::LITESPEED_MARK );
	}

	/**
	 * Add our patterns to WP Rocket's reject-UA filter (idempotent).
	 *
	 * @return void
	 */
	private function ensure_rocket_filter() {
		if ( ! has_filter( 'rocket_cache_reject_ua', array( $this, 'rocket_reject_ua' ) ) ) {
			add_filter( 'rocket_cache_reject_ua', array( $this, 'rocket_reject_ua' ) );
		}
	}

	/**
	 * Regenerate WP Rocket's compiled cache files so the reject-UA list on
	 * disk picks up our patterns. No-op when WP Rocket isn't active; the
	 * marker is only set on a successful rebuild so we'll try again next
	 * request if WP Rocket arrives later.
	 *
	 * @return void
	 */
	private function rebuild_rocket() {
		if ( ! function_exists( 'rocket_generate_advanced_cache_file' ) ) {
			return;
		}
		// WP Rocket's regen helpers reach into its DI container; if WP Rocket
		// is only partially bootstrapped (e.g. activating mid-request, or a
		// hook firing too early on a future version) the container is null
		// and ->get() fatals. Swallow it so we never crash the request; the
		// marker stays unset so we'll retry next request.
		try {
			rocket_generate_advanced_cache_file();
			if ( function_exists( 'rocket_generate_config_file' ) ) {
				rocket_generate_config_file();
			}
			update_option( self::ROCKET_MARK, 1, false );
		} catch ( \Throwable $e ) {
			// Intentionally silent.
		}
	}

	/**
	 * Filter callback - merge our patterns into WP Rocket's reject-UA list.
	 * Patterns are regex-escaped because WP Rocket compiles the list into a
	 * single `#(?:p1|p2|...)#i` regex.
	 *
	 * @param array $ua_list WP Rocket's current reject-UA list.
	 * @return array
	 */
	public function rocket_reject_ua( $ua_list ) {
		$patterns = $this->patterns();
		if ( empty( $patterns ) ) {
			return (array) $ua_list;
		}
		$escaped = array_map(
			static function ( $p ) {
				return preg_quote( $p, '#' );
			},
			$patterns
		);
		return array_merge( (array) $ua_list, $escaped );
	}

	/* ---------------------------------------------------------------------
	 * W3 Total Cache integration
	 *
	 * Verified: list lives in pgcache.reject.ua, matched with stristr(),
	 * i.e. case-insensitive substring - feed literals, no escaping.
	 * ------------------------------------------------------------------- */

	/**
	 * Merge our patterns into W3TC's pgcache.reject.ua and trigger a
	 * (best-effort) .htaccess regen for Disk: Enhanced mode.
	 *
	 * @return void
	 */
	private function integrate_w3tc() {
		if ( ! $this->w3tc_active() ) {
			return;
		}
		try {
			$config   = \W3TC\Dispatcher::config();
			$existing = (array) $config->get_array( 'pgcache.reject.ua' );
			$ours     = $this->patterns_for_substring();
			$merged   = array_values( array_unique( array_merge( $existing, $ours ) ) );
			$config->set( 'pgcache.reject.ua', $merged );
			$config->save();
			$this->w3tc_regenerate_htaccess();
			update_option( self::W3TC_MARK, 1, false );
		} catch ( \Throwable $e ) {
			// W3TC API surface changes across versions; never break the request
			// if a method moved or threw. Marker stays unset so we'll retry.
		}
	}

	/**
	 * Subtract only the patterns we added; leave any pre-existing entries
	 * the site owner had alone.
	 *
	 * @return void
	 */
	private function disintegrate_w3tc() {
		if ( ! $this->w3tc_active() ) {
			return;
		}
		try {
			$config   = \W3TC\Dispatcher::config();
			$existing = (array) $config->get_array( 'pgcache.reject.ua' );
			$ours     = array_keys( $this->bots() );
			$cleaned  = array_values( array_diff( $existing, $ours ) );
			$config->set( 'pgcache.reject.ua', $cleaned );
			$config->save();
			$this->w3tc_regenerate_htaccess();
		} catch ( \Throwable $e ) {
		}
	}

	/**
	 * Best-effort .htaccess regen for W3TC's Disk: Enhanced mode. Method
	 * names on PgCache_Environment vary across versions, so probe before
	 * calling; if nothing matches, the user will need to save W3TC settings
	 * once to trigger their own .htaccess rebuild.
	 *
	 * @return void
	 */
	private function w3tc_regenerate_htaccess() {
		if ( ! class_exists( '\W3TC\Dispatcher' ) || ! method_exists( '\W3TC\Dispatcher', 'component' ) ) {
			return;
		}
		try {
			$env    = \W3TC\Dispatcher::component( 'PgCache_Environment' );
			$config = \W3TC\Dispatcher::config();
			if ( ! is_object( $env ) ) {
				return;
			}
			if ( method_exists( $env, 'fix_in_wpadmin' ) ) {
				$env->fix_in_wpadmin( $config );
			} elseif ( method_exists( $env, 'fix_on_event' ) ) {
				$env->fix_on_event( $config, 'config_change' );
			}
		} catch ( \Throwable $e ) {
		}
	}

	private function w3tc_active() {
		return defined( 'W3TC' ) || class_exists( '\W3TC\Dispatcher' );
	}

	/* ---------------------------------------------------------------------
	 * WP Super Cache integration
	 *
	 * Verified: $cache_rejected_user_agent in wp-cache-config.php, edited
	 * via WPSC's own wp_cache_replace_line() helper. Substring match.
	 * ------------------------------------------------------------------- */

	/**
	 * Merge our patterns into WPSC's cache_rejected_user_agent.
	 *
	 * @return void
	 */
	private function integrate_wpsc() {
		global $wp_cache_config_file, $cache_rejected_user_agent;
		if ( ! function_exists( 'wp_cache_replace_line' ) || empty( $wp_cache_config_file ) ) {
			return;
		}
		$existing = is_array( $cache_rejected_user_agent ) ? $cache_rejected_user_agent : array();
		$ours     = $this->patterns_for_substring();
		$merged   = array_values( array_unique( array_merge( $existing, $ours ) ) );
		$this->wpsc_write_ua_list( $merged );
		update_option( self::WPSC_MARK, 1, false );
	}

	/**
	 * Subtract only the patterns we added.
	 *
	 * @return void
	 */
	private function disintegrate_wpsc() {
		global $wp_cache_config_file, $cache_rejected_user_agent;
		if ( ! function_exists( 'wp_cache_replace_line' ) || empty( $wp_cache_config_file ) ) {
			return;
		}
		$existing = is_array( $cache_rejected_user_agent ) ? $cache_rejected_user_agent : array();
		$ours     = array_keys( $this->bots() );
		$cleaned  = array_values( array_diff( $existing, $ours ) );
		$this->wpsc_write_ua_list( $cleaned );
	}

	private function wpsc_write_ua_list( array $list ) {
		global $wp_cache_config_file;
		$text = var_export( $list, true );
		wp_cache_replace_line(
			'^ *\$cache_rejected_user_agent',
			"\$cache_rejected_user_agent = $text;",
			$wp_cache_config_file
		);
	}

	/* ---------------------------------------------------------------------
	 * LiteSpeed Cache integration
	 *
	 * LiteSpeed serves from the web server before PHP, so a PHP filter is
	 * useless. We own a marked block in the site .htaccess that emits a
	 * Cache-Control:no-cache for matching UAs - server-honored, version-
	 * independent. <IfModule LiteSpeed> means the rule is a harmless no-op
	 * on Apache/nginx without LiteSpeed.
	 * ------------------------------------------------------------------- */

	private function integrate_litespeed() {
		if ( ! $this->litespeed_active() ) {
			return;
		}
		$ua_regex = $this->patterns_for_litespeed_regex();
		if ( '' === $ua_regex ) {
			return;
		}
		$insertion = array(
			'<IfModule LiteSpeed>',
			'RewriteEngine On',
			'RewriteCond %{HTTP_USER_AGENT} (' . $ua_regex . ') [NC]',
			'RewriteRule .* - [E=Cache-Control:no-cache]',
			'</IfModule>',
		);
		if ( $this->htaccess_insert( self::HTACCESS_MARKER, $insertion ) ) {
			update_option( self::LITESPEED_MARK, 1, false );
		}
	}

	private function disintegrate_litespeed() {
		// Always attempt removal - even if LiteSpeed isn't currently active,
		// the marker block may have been written previously.
		$this->htaccess_insert( self::HTACCESS_MARKER, array() );
	}

	private function litespeed_active() {
		return defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed\Core' );
	}

	/**
	 * LiteSpeed needs a regex-safe pipe-joined string, with spaces escaped
	 * for mod_rewrite. The '008' alt-UA for 80legs is dropped if ever
	 * present - as a bare alternation token it would false-match version
	 * numbers like "Firefox/110.0.0.8" or "Chrome/008-build" inside
	 * legitimate UAs.
	 *
	 * @return string Pipe-joined regex, or '' if nothing to inject.
	 */
	private function patterns_for_litespeed_regex() {
		$patterns = array_values( array_diff( $this->patterns(), array( '008' ) ) );
		if ( empty( $patterns ) ) {
			return '';
		}
		$escaped = array_map(
			static function ( $p ) {
				$p = preg_quote( $p, '/' );
				return str_replace( ' ', '\\ ', $p );
			},
			$patterns
		);
		return implode( '|', $escaped );
	}

	/**
	 * Substring-match targets (W3TC, WPSC). Returns raw literals - no escaping.
	 * '008' (80legs alt) is dropped because as a bare substring it false-
	 * matches version numbers inside legitimate UAs.
	 *
	 * @return string[]
	 */
	private function patterns_for_substring() {
		return array_values( array_diff( $this->patterns(), array( '008' ) ) );
	}

	/**
	 * Write or remove a marked block in the site .htaccess via WordPress's
	 * insert_with_markers(). Passing an empty insertion removes the block.
	 *
	 * @param string   $marker    Label between BEGIN/END markers.
	 * @param string[] $insertion Lines to insert; empty array removes the block.
	 * @return bool True on success.
	 */
	private function htaccess_insert( $marker, array $insertion ) {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'insert_with_markers' ) || ! function_exists( 'get_home_path' ) ) {
			return false;
		}
		$htaccess = get_home_path() . '.htaccess';
		// insert_with_markers will create the file if missing, but a clean
		// remove on a non-existent file is also a no-op. Either way, only
		// proceed if the directory is writable.
		if ( file_exists( $htaccess ) && ! is_writable( $htaccess ) ) {
			return false;
		}
		if ( ! file_exists( $htaccess ) && ! is_writable( dirname( $htaccess ) ) ) {
			return false;
		}
		return (bool) insert_with_markers( $htaccess, $marker, $insertion );
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

		// This runs at plugins_loaded - before `init` - so calling __() here
		// would JIT-load the levers textdomain too early and trip WP 6.7's
		// _load_textdomain_just_in_time notice. Only translate when init has
		// already fired (it never has on this path); otherwise fall back to
		// the English source strings.
		$late = did_action( 'init' );

		$title_text = $late ? __( 'Access denied', 'levers' ) : 'Access denied';
		$body_text  = $late
			? __( 'Your IP address has been blocked due to suspicious activity. If you believe this is a mistake, please contact us and include your IP address.', 'levers' )
			: 'Your IP address has been blocked due to suspicious activity. If you believe this is a mistake, please contact us and include your IP address.';
		$ip_label   = $late ? __( 'Your IP:', 'levers' ) : 'Your IP:';

		$ip_block = '' === $ip
			? ''
			: '<p class="ip">' . $ip_label . ' <code>' . htmlspecialchars( $ip, ENT_QUOTES, 'UTF-8' ) . '</code></p>';

		echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>'
			, $title_text
			, '</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:560px;margin:80px auto;padding:0 24px;color:#1d2327;line-height:1.5}h1{font-size:28px;margin:0 0 16px}p{margin:0 0 12px}.ip code{background:#f1f1f1;padding:2px 6px;border-radius:3px;font-size:13px}</style></head><body>'
			, '<h1>', $title_text, '</h1>'
			, '<p>', $body_text, '</p>'
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

		// Refresh every cache plugin's reject list so modal toggles take effect
		// for cache-bypass logic immediately (not just for the in-PHP block).
		$this->reset_cache_marks();
		$this->ensure_rocket_filter();
		$this->rebuild_rocket();
		$this->integrate_w3tc();
		$this->integrate_wpsc();
		$this->integrate_litespeed();

		wp_send_json_success();
	}
}
