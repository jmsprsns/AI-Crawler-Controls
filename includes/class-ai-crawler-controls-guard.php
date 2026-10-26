<?php
/**
 * Front-end side: the robots.txt rules, the AI opt-out signals, refusing
 * blocked crawlers and logging their visits.
 *
 * Normal visitors cost one regex on their user agent. Only requests from a
 * recognised AI crawler do anything more.
 *
 * @package AI_Crawler_Controls
 */

defined( 'ABSPATH' ) || exit;

/**
 * Request handling.
 */
final class AI_Crawler_Controls_Guard {

	/**
	 * Plugin instance.
	 *
	 * @var AI_Crawler_Controls_Plugin
	 */
	private $plugin;

	/**
	 * The current request's crawler, if it is one.
	 *
	 * @var string
	 */
	private $bot = '';

	/**
	 * What happened to the current request (an AI_Crawler_Controls_DB result).
	 *
	 * @var string
	 */
	private $result = '';

	/**
	 * Whether the current request has been logged.
	 *
	 * @var bool
	 */
	private $logged = false;

	/**
	 * Set while the plugin is being deactivated, so WP Rocket's rebuilt
	 * config no longer sends any crawler past its cache.
	 *
	 * @var bool
	 */
	private static $released = false;

	/**
	 * Constructor. Runs on plugins_loaded.
	 *
	 * @param AI_Crawler_Controls_Plugin $plugin Plugin instance.
	 */
	public function __construct( AI_Crawler_Controls_Plugin $plugin ) {
		$this->plugin = $plugin;

		if ( $plugin->get_setting( 'robots' ) ) {
			// Late, so the rules land after anything an SEO plugin writes.
			add_filter( 'robots_txt', array( $this, 'robots_txt' ), 100 );
		}

		if ( $plugin->get_setting( 'signals' ) ) {
			add_filter( 'wp_robots', array( $this, 'add_robots_directives' ) );
			add_action( 'wp_head', array( $this, 'print_tdm_meta' ), 1 );
			add_action( 'send_headers', array( $this, 'send_signal_headers' ) );
		}

		// Always hooked: it reads the current settings when WP Rocket rebuilds its config.
		add_filter( 'rocket_cache_reject_ua', array( $this, 'page_cache_reject_agents' ) );

		$this->watch_request();
	}

	// robots.txt ---------------------------------------------------------------

	/**
	 * Add the rules to WordPress's robots.txt.
	 *
	 * @param string $output robots.txt so far.
	 * @return string
	 */
	public function robots_txt( $output ) {
		$rules = self::robots_rules( $this->plugin->blocked_tokens() );

		if ( '' === $rules ) {
			return $output;
		}

		$output = rtrim( (string) $output );

		return ( '' === $output ? '' : $output . "\n\n" ) . $rules;
	}

	/**
	 * robots.txt groups that disallow each token from the whole site.
	 *
	 * One group per crawler, which every parser reads the same way. Tokens
	 * are checked again here, so nothing but a plain user-agent token can
	 * reach the file.
	 *
	 * @param string[] $tokens User-agent tokens.
	 * @return string '' when there are none.
	 */
	public static function robots_rules( array $tokens ) {
		$groups = array();

		foreach ( $tokens as $token ) {
			$token = AI_Crawler_Controls_Bots::token_syntax( $token );
			if ( '' !== $token ) {
				$groups[] = "User-agent: {$token}\nDisallow: /";
			}
		}

		if ( ! $groups ) {
			return '';
		}

		return "# AI Crawler Controls: AI crawlers this site doesn't allow\n" . implode( "\n\n", $groups ) . "\n";
	}

	// Opt-out signals ---------------------------------------------------------

	/**
	 * Add noai and noimageai to the robots meta tag WordPress prints.
	 *
	 * @param array $robots Directive => value.
	 * @return array
	 */
	public function add_robots_directives( $robots ) {
		$robots = is_array( $robots ) ? $robots : array();

		$robots['noai']      = true;
		$robots['noimageai'] = true;

		return $robots;
	}

	/**
	 * The TDM Reservation Protocol meta tag. It's in the page itself, so it
	 * reaches crawlers even when a cache serves the page without PHP.
	 *
	 * @return void
	 */
	public function print_tdm_meta() {
		echo '<meta name="tdm-reservation" content="1">' . "\n";
	}

	/**
	 * The same signals as HTTP headers, for crawlers that read headers
	 * (and files such as images that are served through WordPress).
	 *
	 * @return void
	 */
	public function send_signal_headers() {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-Robots-Tag: noai, noimageai', false );
		header( 'TDM-Reservation: 1' );
	}

	// Page caches -------------------------------------------------------------

	/**
	 * Tell WP Rocket to send refused crawlers to WordPress instead of a cached
	 * page, so they get the 403.
	 *
	 * @param mixed $agents User-agent patterns WP Rocket never serves from cache.
	 * @return array
	 */
	public function page_cache_reject_agents( $agents ) {
		$agents = is_array( $agents ) ? $agents : array();

		if ( self::$released || ! $this->plugin->get_setting( 'enforce' ) ) {
			return $agents;
		}

		foreach ( $this->plugin->refused_agents() as $agent ) {
			$agents[] = preg_quote( $agent, '#' );
		}

		return $agents;
	}

	/**
	 * Rebuild WP Rocket's config after the blocked crawlers change, so its
	 * cache serves (or stops serving) them straight away.
	 *
	 * @return void
	 */
	public static function refresh_page_cache() {
		if ( function_exists( 'rocket_generate_config_file' ) ) {
			rocket_generate_config_file();
		}
	}

	/**
	 * On deactivation: rebuild WP Rocket's config without this plugin's crawlers.
	 *
	 * @return void
	 */
	public static function release_page_cache() {
		self::$released = true;
		self::refresh_page_cache();
	}

	// Requests ----------------------------------------------------------------

	/**
	 * Look at the current request: refuse it if it's from a blocked crawler
	 * and refusing is on, and log it if it's from any AI crawler.
	 *
	 * @return void
	 */
	private function watch_request() {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return;
		}

		$this->bot = AI_Crawler_Controls_Bots::detect( self::server( 'HTTP_USER_AGENT' ), $this->plugin->custom_tokens() );

		if ( '' === $this->bot ) {
			return;
		}

		$blocked   = $this->plugin->is_blocked( $this->bot );
		$is_robots = self::is_robots_request();

		if ( $blocked && ! $is_robots && $this->plugin->get_setting( 'enforce' ) && self::can_refuse() ) {
			$this->result = AI_Crawler_Controls_DB::BLOCKED;
			$this->log( 403 );
			$this->refuse();
		}

		if ( ! $this->plugin->get_setting( 'log' ) ) {
			return;
		}

		if ( $is_robots ) {
			$this->result = AI_Crawler_Controls_DB::ROBOTS;
		} elseif ( $blocked && $this->plugin->robots_live() ) {
			$this->result = AI_Crawler_Controls_DB::IGNORED;
		} else {
			$this->result = AI_Crawler_Controls_DB::ALLOWED;
		}

		// At the end, so the log has the real status code (200, 404, 301...).
		add_action( 'shutdown', array( $this, 'log_on_shutdown' ) );
	}

	/**
	 * Whether this request is for robots.txt. Blocked crawlers can always
	 * read it, or they'd never see the rules.
	 *
	 * @return bool
	 */
	private static function is_robots_request() {
		$path = wp_parse_url( self::server( 'REQUEST_URI' ), PHP_URL_PATH );

		return is_string( $path ) && '/robots.txt' === substr( $path, -11 );
	}

	/**
	 * Whether this request may be refused. The dashboard and the login page
	 * never are, so a mistake can always be undone.
	 *
	 * @return bool
	 */
	private static function can_refuse() {
		global $pagenow;

		return ! is_admin() && 'wp-login.php' !== $pagenow;
	}

	/**
	 * Answer with a short 403 and stop.
	 *
	 * The response must never be cached: a cache keyed on the URL alone would
	 * otherwise show the error to every visitor.
	 *
	 * @return void
	 */
	private function refuse() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ! headers_sent() ) {
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}

		if ( 'HEAD' !== self::server( 'REQUEST_METHOD' ) ) {
			echo "403 Forbidden\n\nThis website doesn't allow this crawler. See /robots.txt.\n";
		}

		exit;
	}

	/**
	 * shutdown: log the request with its final status code.
	 *
	 * @return void
	 */
	public function log_on_shutdown() {
		$status = http_response_code();

		$this->log( $status ? (int) $status : 200 );
	}

	/**
	 * Write the current request to the crawl log, once.
	 *
	 * @param int $status HTTP status code sent.
	 * @return void
	 */
	private function log( $status ) {
		if ( $this->logged || ! $this->plugin->get_setting( 'log' ) ) {
			return;
		}

		$this->logged = true;

		AI_Crawler_Controls_DB::record(
			array(
				'bot'        => $this->bot,
				'result'     => $this->result,
				'status'     => $status,
				'method'     => substr( strtoupper( preg_replace( '/[^A-Za-z]/', '', self::server( 'REQUEST_METHOD' ) ) ), 0, 10 ),
				'path'       => self::printable( self::server( 'REQUEST_URI' ) ),
				'ip'         => $this->plugin->get_setting( 'log_ip' ) ? self::client_ip() : '',
				'user_agent' => self::printable( self::server( 'HTTP_USER_AGENT' ) ),
			)
		);
	}

	/**
	 * The visitor's IP address, as the server saw it.
	 *
	 * Forwarded-for headers are ignored, because anyone can send them. Behind
	 * a proxy or CDN this is the proxy's address; the filter can swap in the
	 * real one.
	 *
	 * @return string '' if it isn't a valid IP.
	 */
	private static function client_ip() {
		/**
		 * Filter the IP address logged for a crawler's request.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'ai_crawler_controls_client_ip', self::server( 'REMOTE_ADDR' ) );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * A $_SERVER value as a string, without WordPress's added slashes.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function server( $key ) {
		return isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? (string) wp_unslash( $_SERVER[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Callers clean what they keep, and every value is escaped on output.
	}

	/**
	 * Keep printable ASCII only, at most 255 characters: no control
	 * characters or invalid bytes reach the database.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function printable( $value ) {
		return substr( (string) preg_replace( '/[^\x20-\x7E]/', '', $value ), 0, 255 );
	}
}
