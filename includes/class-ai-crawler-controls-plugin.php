<?php
/**
 * Main plugin orchestrator: owns the settings and boots the guard and admin.
 *
 * @package AI_Crawler_Controls
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class AI_Crawler_Controls_Plugin {

	/**
	 * Option key holding the general settings array.
	 */
	const OPTION = 'ai_crawler_controls_settings';

	/**
	 * Option key holding the IDs of the blocked crawlers.
	 */
	const BLOCKED_OPTION = 'ai_crawler_controls_blocked';

	/**
	 * Option key holding the custom crawlers to block, one token per line.
	 */
	const CUSTOM_OPTION = 'ai_crawler_controls_custom';

	/**
	 * Daily WP-Cron event that prunes the log.
	 */
	const PRUNE_HOOK = 'ai_crawler_controls_prune';

	/**
	 * Single shared instance.
	 *
	 * @var AI_Crawler_Controls_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether the robots.txt rules reach crawlers, once worked out.
	 *
	 * @var bool|null
	 */
	private $robots_live = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return AI_Crawler_Controls_Plugin
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
		// Updates and network activation don't run the activation hook, so
		// the tables and the daily prune are set up here when they're missing.
		if ( is_admin() || wp_doing_cron() ) {
			self::setup();
		}

		add_action( self::PRUNE_HOOK, array( $this, 'prune' ) );

		new AI_Crawler_Controls_Guard( $this );

		if ( is_admin() ) {
			new AI_Crawler_Controls_Admin( $this );
		}
	}

	/**
	 * Create the tables and schedule the daily prune if needed.
	 *
	 * @return void
	 */
	public static function setup() {
		AI_Crawler_Controls_DB::maybe_install();

		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Daily: drop log rows past the retention period.
	 *
	 * @return void
	 */
	public function prune() {
		AI_Crawler_Controls_DB::prune( (int) $this->get_setting( 'retention' ) );
	}

	/**
	 * Default value for every setting.
	 *
	 * Nothing is blocked until an administrator chooses, so activating the
	 * plugin never changes what crawlers see.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'robots'    => 1,
			'enforce'   => 0,
			'signals'   => 0,
			'log'       => 1,
			'retention' => 30,
			'log_ip'    => 1,
		);
	}

	/**
	 * Choices for how long the crawl log is kept, in days.
	 *
	 * @return int[]
	 */
	public static function retention_choices() {
		return array( 7, 30, 90 );
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * IDs of the known crawlers that are blocked.
	 *
	 * @return string[]
	 */
	public function blocked_ids() {
		$saved = get_option( self::BLOCKED_OPTION, array() );

		return array_values( array_intersect( array_keys( AI_Crawler_Controls_Bots::all() ), is_array( $saved ) ? $saved : array() ) );
	}

	/**
	 * Custom crawler tokens to block. Checked again on the way out, so a
	 * value written straight to the database can't reach robots.txt.
	 *
	 * @return string[]
	 */
	public function custom_tokens() {
		$lines  = preg_split( '/\R/', (string) get_option( self::CUSTOM_OPTION, '' ) );
		$tokens = array();

		foreach ( (array) $lines as $line ) {
			$token = AI_Crawler_Controls_Bots::clean_custom_token( $line );
			if ( '' !== $token && ! isset( $tokens[ strtolower( $token ) ] ) ) {
				$tokens[ strtolower( $token ) ] = $token;
			}
		}

		return array_slice( array_values( $tokens ), 0, AI_Crawler_Controls_Bots::MAX_CUSTOM );
	}

	/**
	 * Whether a crawler is blocked. Custom crawlers always are.
	 *
	 * @param string $id Crawler ID.
	 * @return bool
	 */
	public function is_blocked( $id ) {
		if ( 0 === strpos( (string) $id, AI_Crawler_Controls_Bots::CUSTOM_PREFIX ) ) {
			return true;
		}

		return in_array( $id, $this->blocked_ids(), true );
	}

	/**
	 * robots.txt tokens of every blocked crawler, without duplicates.
	 *
	 * @return string[]
	 */
	public function blocked_tokens() {
		$tokens = array();

		foreach ( $this->blocked_ids() as $id ) {
			$bot = AI_Crawler_Controls_Bots::get( $id );
			foreach ( $bot['tokens'] as $token ) {
				$tokens[ strtolower( $token ) ] = $token;
			}
		}

		foreach ( $this->custom_tokens() as $token ) {
			if ( ! isset( $tokens[ strtolower( $token ) ] ) ) {
				$tokens[ strtolower( $token ) ] = $token;
			}
		}

		return array_values( $tokens );
	}

	/**
	 * User-agent substrings of the blocked crawlers that can be refused.
	 *
	 * @return string[]
	 */
	public function refused_agents() {
		$agents = array();

		foreach ( $this->blocked_ids() as $id ) {
			$bot    = AI_Crawler_Controls_Bots::get( $id );
			$agents = array_merge( $agents, $bot['match'] );
		}

		return array_values( array_unique( array_merge( $agents, $this->custom_tokens() ) ) );
	}

	/**
	 * Whether the site's robots.txt is the one WordPress makes, at the root
	 * of the domain, so the rules this plugin adds reach crawlers.
	 *
	 * @return bool
	 */
	public function robots_live() {
		if ( null === $this->robots_live ) {
			$this->robots_live = $this->get_setting( 'robots' ) && ! self::robots_problem();
		}

		return $this->robots_live;
	}

	/**
	 * Why WordPress's robots.txt (and so these rules) can't reach crawlers.
	 *
	 * @return string '' when it can; "file" for a robots.txt file on the
	 *                server, "subfolder" for a site that isn't at the root of
	 *                its domain, "permalinks" for plain permalinks.
	 */
	public static function robots_problem() {
		$home = wp_parse_url( home_url(), PHP_URL_PATH );

		if ( is_string( $home ) && '' !== trim( $home, '/' ) ) {
			return 'subfolder';
		}

		foreach ( self::robots_file_paths() as $path ) {
			if ( @file_exists( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir can make this warn.
				return 'file';
			}
		}

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return 'permalinks';
		}

		return '';
	}

	/**
	 * Where a robots.txt file would replace WordPress's: the WordPress folder
	 * and, when WordPress lives in its own subfolder, the site root above it.
	 *
	 * @return string[]
	 */
	private static function robots_file_paths() {
		$paths = array( ABSPATH . 'robots.txt' );

		if ( function_exists( 'get_home_path' ) ) {
			$paths[] = trailingslashit( get_home_path() ) . 'robots.txt';
		} elseif ( ! is_admin() && isset( $_SERVER['SCRIPT_FILENAME'] ) ) {
			// get_home_path() is admin-only. On the front end the site root is
			// the folder of the index.php that's running.
			$script = (string) wp_unslash( $_SERVER['SCRIPT_FILENAME'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Set by the server; only used as a path.
			if ( 'index.php' === basename( $script ) ) {
				$paths[] = dirname( $script ) . '/robots.txt';
			}
		}

		return array_unique( $paths );
	}
}
