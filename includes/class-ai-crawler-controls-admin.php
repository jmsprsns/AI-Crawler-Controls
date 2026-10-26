<?php
/**
 * Admin side: the Content Powered > AI Crawler Controls page and the Plugins-screen link.
 *
 * @package AI_Crawler_Controls
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page controller.
 */
final class AI_Crawler_Controls_Admin {

	/**
	 * Page slug, also the settings group of the Settings tab.
	 */
	const PAGE = 'ai-crawler-controls';

	/**
	 * Settings group of the Crawlers tab (blocked crawlers and custom tokens).
	 */
	const CRAWLERS_GROUP = 'ai-crawler-controls-crawlers';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'bot';

	/**
	 * Capability needed for everything on the page.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Tabs: id => Lucide icon.
	 */
	const TABS = array(
		'crawlers' => 'bot',
		'log'      => 'scroll-text',
		'settings' => 'settings-2',
	);

	/**
	 * Crawler groups: purpose => Lucide icon.
	 */
	const PURPOSE_ICONS = array(
		AI_Crawler_Controls_Bots::TRAINING => 'brain-circuit',
		AI_Crawler_Controls_Bots::SEARCH   => 'search',
		AI_Crawler_Controls_Bots::USER     => 'mouse-pointer-click',
		AI_Crawler_Controls_Bots::CUSTOM   => 'tag',
	);

	/**
	 * Days the stats and the chart cover.
	 */
	const STATS_DAYS = 30;

	/**
	 * Transient holding the last robots.txt test.
	 */
	const CHECK_TRANSIENT = 'ai_crawler_controls_robots_check';

	/**
	 * Plugin instance.
	 *
	 * @var AI_Crawler_Controls_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * The crawl log list, on the Crawl log tab.
	 *
	 * @var AI_Crawler_Controls_List_Table|null
	 */
	private $table = null;

	/**
	 * Whether the custom tokens' errors have been reported in this request.
	 *
	 * @var bool
	 */
	private $custom_reported = false;

	/**
	 * Constructor.
	 *
	 * @param AI_Crawler_Controls_Plugin $plugin Plugin instance.
	 */
	public function __construct( AI_Crawler_Controls_Plugin $plugin ) {
		$this->plugin = $plugin;

		AI_Crawler_Controls_Menu::init();
		AI_Crawler_Controls_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );

		// WP Rocket keeps a copy of the crawlers it mustn't serve from cache.
		foreach ( array( AI_Crawler_Controls_Plugin::OPTION, AI_Crawler_Controls_Plugin::BLOCKED_OPTION, AI_Crawler_Controls_Plugin::CUSTOM_OPTION ) as $option ) {
			add_action( 'add_option_' . $option, array( 'AI_Crawler_Controls_Guard', 'refresh_page_cache' ) );
			add_action( 'update_option_' . $option, array( 'AI_Crawler_Controls_Guard', 'refresh_page_cache' ) );
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( AI_CRAWLER_CONTROLS_FILE ),
			array( $this, 'add_settings_link' )
		);
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
			esc_url( self::url() ),
			esc_html__( 'Settings', 'ai-crawler-controls' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * URL of a tab on the page.
	 *
	 * @param string $tab  Tab ID.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( $tab = 'crawlers', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = AI_Crawler_Controls_Icons::get( self::ICON, 17, 'ai-crawler-controls-menu-icon' );
		$menu_title = $icon . '<span class="ai-crawler-controls-menu-label">' . esc_html__( 'AI Crawler Controls', 'ai-crawler-controls' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			AI_Crawler_Controls_Menu::SLUG,
			__( 'AI Crawler Controls', 'ai-crawler-controls' ),
			$menu_title,
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'load_page' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
			add_filter( 'screen_options_show_screen', array( $this, 'hide_screen_options' ), 10, 2 );
		}
	}

	/**
	 * No Screen Options tab: the log's columns are all needed, and the tab
	 * would sit on top of the page header.
	 *
	 * @param bool      $show   Whether to show the tab.
	 * @param WP_Screen $screen Current screen.
	 * @return bool
	 */
	public function hide_screen_options( $show, $screen ) {
		return $screen instanceof WP_Screen && $screen->id === $this->hook_suffix ? false : $show;
	}

	/**
	 * Mark the page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' aicc-page' : $classes;
	}

	/**
	 * The tab being viewed.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'crawlers'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		return array_key_exists( $tab, self::TABS ) ? $tab : 'crawlers';
	}

	// Actions -----------------------------------------------------------------

	/**
	 * Before the page is drawn: handle the buttons, then set up the log list.
	 *
	 * @return void
	 */
	public function load_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$this->handle_preset();
		$this->handle_clear();
		$this->handle_check();

		if ( 'log' !== $this->current_tab() ) {
			return;
		}

		require_once AI_CRAWLER_CONTROLS_DIR . 'includes/class-ai-crawler-controls-list-table.php';
		$this->table = new AI_Crawler_Controls_List_Table();

		// Like core's list screens: drop the nonce and referer a plain search
		// or page change leaves in the address bar.
		if ( ! empty( $_GET['_wp_http_referer'] ) && isset( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce' ), esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) );
			exit;
		}

		$this->table->prepare_items();
	}

	/**
	 * Quick setup: block a preset group of crawlers in one click.
	 *
	 * @return void
	 */
	private function handle_preset() {
		if ( empty( $_POST['aicc_preset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below.
			return;
		}

		check_admin_referer( 'ai_crawler_controls_preset' );

		$presets = self::presets();
		$preset  = sanitize_key( wp_unslash( $_POST['aicc_preset'] ) );

		if ( ! isset( $presets[ $preset ] ) ) {
			$this->redirect( 'crawlers' );
		}

		update_option( AI_Crawler_Controls_Plugin::BLOCKED_OPTION, $presets[ $preset ] );

		$this->redirect(
			'crawlers',
			array(
				'aicc_notice' => 'preset',
				'aicc_count'  => count( $this->plugin->blocked_ids() ),
			)
		);
	}

	/**
	 * Clear log: empty the crawl log and the totals.
	 *
	 * @return void
	 */
	private function handle_clear() {
		if ( empty( $_POST['aicc_clear'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below.
			return;
		}

		check_admin_referer( 'ai_crawler_controls_clear' );

		AI_Crawler_Controls_DB::clear();

		$this->redirect( 'log', array( 'aicc_notice' => 'cleared' ) );
	}

	/**
	 * Test robots.txt: fetch the live file and see whether the rules are in it.
	 *
	 * @return void
	 */
	private function handle_check() {
		if ( empty( $_POST['aicc_check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below.
			return;
		}

		check_admin_referer( 'ai_crawler_controls_check' );

		set_transient( self::CHECK_TRANSIENT, $this->check_robots(), DAY_IN_SECONDS );

		$tab = isset( $_POST['aicc_tab'] ) ? sanitize_key( wp_unslash( $_POST['aicc_tab'] ) ) : 'crawlers';

		$this->redirect( array_key_exists( $tab, self::TABS ) ? $tab : 'crawlers' );
	}

	/**
	 * Fetch this site's robots.txt and look for each blocked crawler in it.
	 *
	 * The URL is the site's own and fixed, never something a user typed.
	 *
	 * @return array{time: int, status: string, code: int, missing: string[], error: string}
	 */
	private function check_robots() {
		$result = array(
			'time'    => time(),
			'status'  => 'unreachable',
			'code'    => 0,
			'missing' => array(),
			'error'   => '',
		);

		$response = wp_remote_get(
			home_url( '/robots.txt' ),
			array(
				'timeout'             => 10,
				'redirection'         => 3,
				'limit_response_size' => 512 * KB_IN_BYTES,
				'headers'             => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			return $result;
		}

		$result['code'] = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $result['code'] ) {
			return $result;
		}

		$body = (string) wp_remote_retrieve_body( $response );

		foreach ( $this->plugin->blocked_tokens() as $token ) {
			if ( ! preg_match( '/^\s*user-agent\s*:\s*' . preg_quote( $token, '/' ) . '\s*$/im', $body ) ) {
				$result['missing'][] = $token;
			}
		}

		$result['status'] = $result['missing'] ? 'missing' : 'ok';

		return $result;
	}

	/**
	 * The quick setup choices: id => blocked crawler IDs.
	 *
	 * @return array<string,string[]>
	 */
	private static function presets() {
		return array(
			'training' => AI_Crawler_Controls_Bots::ids_for( AI_Crawler_Controls_Bots::TRAINING ),
			'all'      => array_keys( AI_Crawler_Controls_Bots::all() ),
			'none'     => array(),
		);
	}

	/**
	 * Redirect back to a tab and stop.
	 *
	 * @param string $tab  Tab ID.
	 * @param array  $args Query arguments for the notice.
	 * @return void
	 */
	private function redirect( $tab, array $args = array() ) {
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	// Settings ----------------------------------------------------------------

	/**
	 * Register the settings and the Settings tab's fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			AI_Crawler_Controls_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => AI_Crawler_Controls_Plugin::defaults(),
			)
		);

		register_setting(
			self::CRAWLERS_GROUP,
			AI_Crawler_Controls_Plugin::BLOCKED_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_blocked' ),
				'default'           => array(),
			)
		);

		register_setting(
			self::CRAWLERS_GROUP,
			AI_Crawler_Controls_Plugin::CUSTOM_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_custom' ),
				'default'           => '',
			)
		);

		add_settings_section( 'aicc_settings', __( 'Settings', 'ai-crawler-controls' ), '__return_false', self::PAGE );

		$fields = array(
			'robots'  => __( 'robots.txt', 'ai-crawler-controls' ),
			'enforce' => __( 'Refuse requests', 'ai-crawler-controls' ),
			'signals' => __( 'AI opt-out signals', 'ai-crawler-controls' ),
			'log'     => __( 'Crawl log', 'ai-crawler-controls' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field( 'aicc_' . $key, $label, array( $this, 'render_' . $key ), self::PAGE, 'aicc_settings' );
		}
	}

	/**
	 * Clean the Settings tab before it's saved.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$retention = isset( $input['retention'] ) ? absint( $input['retention'] ) : 30;

		return array(
			'robots'    => empty( $input['robots'] ) ? 0 : 1,
			'enforce'   => empty( $input['enforce'] ) ? 0 : 1,
			'signals'   => empty( $input['signals'] ) ? 0 : 1,
			'log'       => empty( $input['log'] ) ? 0 : 1,
			'retention' => in_array( $retention, AI_Crawler_Controls_Plugin::retention_choices(), true ) ? $retention : 30,
			'log_ip'    => empty( $input['log_ip'] ) ? 0 : 1,
		);
	}

	/**
	 * Keep only the IDs of known crawlers.
	 *
	 * @param mixed $input Submitted IDs.
	 * @return string[]
	 */
	public function sanitize_blocked( $input ) {
		$ids = is_array( $input ) ? array_map( 'sanitize_key', $input ) : array();

		return array_values( array_intersect( array_keys( AI_Crawler_Controls_Bots::all() ), $ids ) );
	}

	/**
	 * Keep the custom tokens that are safe to use, and say which weren't.
	 *
	 * @param mixed $input Submitted text, one token per line.
	 * @return string
	 */
	public function sanitize_custom( $input ) {
		$lines   = is_string( $input ) ? preg_split( '/[\r\n,]+/', $input ) : array();
		$known   = array();
		$tokens  = array();
		$refused = array(
			'syntax'    => array(),
			'protected' => array(),
			'known'     => array(),
		);

		foreach ( AI_Crawler_Controls_Bots::all() as $bot ) {
			foreach ( array_merge( $bot['tokens'], $bot['match'] ) as $token ) {
				$known[ strtolower( $token ) ] = true;
			}
		}

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$reason = '';
			$token  = AI_Crawler_Controls_Bots::clean_custom_token( $line, $reason );

			if ( '' === $token ) {
				$refused[ $reason ][] = sanitize_text_field( substr( $line, 0, 64 ) );
			} elseif ( isset( $known[ strtolower( $token ) ] ) ) {
				$refused['known'][] = $token;
			} elseif ( ! isset( $tokens[ strtolower( $token ) ] ) ) {
				$tokens[ strtolower( $token ) ] = $token;
			}
		}

		$tokens = array_slice( array_values( $tokens ), 0, AI_Crawler_Controls_Bots::MAX_CUSTOM );

		// WordPress can run this twice on the first save; report once.
		if ( ! $this->custom_reported ) {
			$this->custom_reported = true;
			$this->report_refused_tokens( $refused );
		}

		return implode( "\n", $tokens );
	}

	/**
	 * Tell the admin which custom tokens weren't saved, and why.
	 *
	 * @param array<string,string[]> $refused Reason => tokens.
	 * @return void
	 */
	private function report_refused_tokens( array $refused ) {
		if ( ! array_filter( $refused ) ) {
			return;
		}

		// WordPress drops its own "Settings saved." when there's a warning.
		add_settings_error( AI_Crawler_Controls_Plugin::CUSTOM_OPTION, 'aicc-saved', __( 'Settings saved.', 'ai-crawler-controls' ), 'success' );

		$messages = array(
			/* translators: %s: comma-separated list of tokens. */
			'syntax'    => __( 'Not added, because a user-agent token is 3 to 64 letters, digits, dots, hyphens or underscores, with no spaces: %s', 'ai-crawler-controls' ),
			/* translators: %s: comma-separated list of tokens. */
			'protected' => __( 'Not added, because they also match web browsers, search engines or link previews, and blocking them would turn away real visitors or drop your site from search: %s', 'ai-crawler-controls' ),
			/* translators: %s: comma-separated list of tokens. */
			'known'     => __( 'Not added, because they\'re already in the list above. Use their switches instead: %s', 'ai-crawler-controls' ),
		);

		foreach ( $messages as $reason => $message ) {
			if ( $refused[ $reason ] ) {
				add_settings_error(
					AI_Crawler_Controls_Plugin::CUSTOM_OPTION,
					'aicc-custom-' . $reason,
					// Core prints these messages as HTML, and the tokens are what the admin typed.
					esc_html( sprintf( $message, implode( ', ', array_unique( $refused[ $reason ] ) ) ) ),
					'warning'
				);
			}
		}
	}

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function field_name( $key ) {
		return AI_Crawler_Controls_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * A checkbox setting with help text.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Checkbox label.
	 * @param string $description Help text.
	 * @return void
	 */
	private function render_checkbox( $key, $label, $description ) {
		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label><p class="description">%4$s</p>',
			esc_attr( self::field_name( $key ) ),
			checked( 1, (int) $this->plugin->get_setting( $key ), false ),
			esc_html( $label ),
			esc_html( $description )
		);
	}

	/**
	 * robots.txt setting.
	 *
	 * @return void
	 */
	public function render_robots() {
		$this->render_checkbox(
			'robots',
			__( 'Disallow blocked crawlers in robots.txt', 'ai-crawler-controls' ),
			__( 'Adds a "Disallow: /" rule for each blocked crawler to the robots.txt WordPress serves. It\'s the standard way to opt out, and every major AI company says its crawlers follow it. Crawlers can take up to a day to notice a change.', 'ai-crawler-controls' )
		);
	}

	/**
	 * Refuse setting.
	 *
	 * @return void
	 */
	public function render_enforce() {
		$this->render_checkbox(
			'enforce',
			__( 'Refuse blocked crawlers with a 403 error', 'ai-crawler-controls' ),
			__( 'robots.txt is a request, and not every crawler honors it. With this on, a blocked crawler that names itself in its user agent gets "403 Forbidden" instead of your page, while it can still read robots.txt. Your dashboard and login page are never refused. Pages your host or a caching plugin serves before WordPress loads skip this check; WP Rocket is set up for you.', 'ai-crawler-controls' )
		);
	}

	/**
	 * Opt-out signals setting.
	 *
	 * @return void
	 */
	public function render_signals() {
		$this->render_checkbox(
			'signals',
			__( 'Ask AI companies not to use any of your content', 'ai-crawler-controls' ),
			__( 'Adds noai and noimageai to your robots meta tag and X-Robots-Tag header, plus TDM-Reservation: 1, a machine-readable opt-out designed for the EU\'s text and data mining rules. They cover every crawler, including ones not in the list, but only companies that choose to honor them do. Search engines ignore them, so your rankings aren\'t affected.', 'ai-crawler-controls' )
		);
	}

	/**
	 * Crawl log settings.
	 *
	 * @return void
	 */
	public function render_log() {
		$retention = (int) $this->plugin->get_setting( 'retention' );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::field_name( 'log' ) ); ?>" value="1" <?php checked( 1, (int) $this->plugin->get_setting( 'log' ) ); ?>>
			<?php esc_html_e( 'Log visits from AI crawlers', 'ai-crawler-controls' ); ?>
		</label>
		<div class="aicc-subfields">
			<label for="aicc-retention"><?php esc_html_e( 'Keep the log for', 'ai-crawler-controls' ); ?></label>
			<select id="aicc-retention" name="<?php echo esc_attr( self::field_name( 'retention' ) ); ?>">
				<?php foreach ( AI_Crawler_Controls_Plugin::retention_choices() as $days ) : ?>
					<option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( $retention, $days ); ?>>
						<?php
						/* translators: %s: number of days. */
						echo esc_html( sprintf( _n( '%s day', '%s days', $days, 'ai-crawler-controls' ), number_format_i18n( $days ) ) );
						?>
					</option>
				<?php endforeach; ?>
			</select>
			<label class="aicc-subfield-check">
				<input type="checkbox" name="<?php echo esc_attr( self::field_name( 'log_ip' ) ); ?>" value="1" <?php checked( 1, (int) $this->plugin->get_setting( 'log_ip' ) ); ?>>
				<?php esc_html_e( 'Record IP addresses', 'ai-crawler-controls' ); ?>
			</label>
		</div>
		<p class="description">
			<?php
			printf(
				/* translators: %s: number of requests. */
				esc_html__( 'Only requests whose user agent names an AI crawler are logged; regular visitors never are. The log keeps up to %s requests per crawler per day, and the totals count every one. IP addresses help you spot crawlers that fake their name.', 'ai-crawler-controls' ),
				esc_html( number_format_i18n( AI_Crawler_Controls_DB::log_cap() ) )
			);
			?>
		</p>
		<?php
	}

	// Assets ------------------------------------------------------------------

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="ai-crawler-controls-menu-icon-style">'
			. '#adminmenu a:has(>.ai-crawler-controls-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .ai-crawler-controls-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .ai-crawler-controls-menu-icon,#adminmenu a:hover .ai-crawler-controls-menu-icon,#adminmenu a:focus .ai-crawler-controls-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the page's styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'ai-crawler-controls-admin', AI_CRAWLER_CONTROLS_URL . 'assets/admin.css', array(), AI_CRAWLER_CONTROLS_VERSION );
		wp_enqueue_script( 'ai-crawler-controls-admin', AI_CRAWLER_CONTROLS_URL . 'assets/admin.js', array(), AI_CRAWLER_CONTROLS_VERSION, true );

		wp_localize_script(
			'ai-crawler-controls-admin',
			'ai_crawler_controls_admin',
			array(
				/* translators: 1: number of blocked crawlers, 2: number of crawlers. */
				'groupCount' => __( '%1$s of %2$s blocked', 'ai-crawler-controls' ),
			)
		);
	}

	// Labels ------------------------------------------------------------------

	/**
	 * Translated tab label.
	 *
	 * @param string $id Tab ID.
	 * @return string
	 */
	private function tab_label( $id ) {
		$labels = array(
			'crawlers' => __( 'Crawlers', 'ai-crawler-controls' ),
			'log'      => __( 'Crawl log', 'ai-crawler-controls' ),
			'settings' => __( 'Settings', 'ai-crawler-controls' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/**
	 * Name of a crawler group.
	 *
	 * @param string $purpose training, search, user or custom.
	 * @return string
	 */
	public static function purpose_label( $purpose ) {
		$labels = array(
			AI_Crawler_Controls_Bots::TRAINING => __( 'AI training', 'ai-crawler-controls' ),
			AI_Crawler_Controls_Bots::SEARCH   => __( 'AI search', 'ai-crawler-controls' ),
			AI_Crawler_Controls_Bots::USER     => __( 'AI assistants and agents', 'ai-crawler-controls' ),
			AI_Crawler_Controls_Bots::CUSTOM   => __( 'Your own', 'ai-crawler-controls' ),
		);

		return isset( $labels[ $purpose ] ) ? $labels[ $purpose ] : $purpose;
	}

	/**
	 * What a crawler group does, and what blocking it means.
	 *
	 * @param string $purpose training, search or user.
	 * @return string
	 */
	private static function purpose_intro( $purpose ) {
		$intros = array(
			AI_Crawler_Controls_Bots::TRAINING => __( 'These collect pages to train AI models. Blocking them keeps your content out of future training data. It doesn\'t remove your site from AI search answers, or from what models have already learned.', 'ai-crawler-controls' ),
			AI_Crawler_Controls_Bots::SEARCH   => __( 'These index pages so AI search tools can quote and link to them. Blocking them can stop your pages appearing as sources in ChatGPT search, Claude, Perplexity and others, which also means fewer visitors from them.', 'ai-crawler-controls' ),
			AI_Crawler_Controls_Bots::USER     => __( 'These fetch a page when a person asks an AI assistant or agent to read it. Some, such as Perplexity-User, say they don\'t follow robots.txt for these requests, so they\'re only stopped when Refuse requests is on in Settings.', 'ai-crawler-controls' ),
		);

		return isset( $intros[ $purpose ] ) ? $intros[ $purpose ] : '';
	}

	/**
	 * One-line description of a known crawler.
	 *
	 * @param string $id Crawler ID.
	 * @return string
	 */
	public static function bot_about( $id ) {
		$about = array(
			'gptbot'                => __( 'Collects pages that may be used to train OpenAI\'s GPT models. Blocking it doesn\'t keep you out of ChatGPT search.', 'ai-crawler-controls' ),
			'claudebot'             => __( 'Collects pages that may be used to train Anthropic\'s Claude models.', 'ai-crawler-controls' ),
			'google-extended'       => __( 'Tells Google whether Gemini may train on your pages and use them to ground its answers. Google Search, including AI Overviews, isn\'t affected. Google crawls with Googlebot, so this is a robots.txt rule only.', 'ai-crawler-controls' ),
			'applebot-extended'     => __( 'Tells Apple whether the pages Applebot crawls may train its AI models. Siri and Spotlight results aren\'t affected. Apple crawls with Applebot, so this is a robots.txt rule only.', 'ai-crawler-controls' ),
			'meta-externalagent'    => __( 'Collects pages to train Meta\'s AI models and improve its products.', 'ai-crawler-controls' ),
			'ccbot'                 => __( 'Builds Common Crawl, a free public archive of the web that many AI models are trained on.', 'ai-crawler-controls' ),
			'bytespider'            => __( 'ByteDance\'s crawler, which collects pages to train its AI models.', 'ai-crawler-controls' ),
			'amazonbot'             => __( 'Amazon\'s crawler. Amazon uses what it collects to improve its services, including Alexa answers and training AI models.', 'ai-crawler-controls' ),
			'cohere'                => __( 'Collects pages to train Cohere\'s language models.', 'ai-crawler-controls' ),
			'ai2bot'                => __( 'Collects pages for the open datasets and models of the Allen Institute for AI.', 'ai-crawler-controls' ),
			'diffbot'               => __( 'Turns pages into structured data for Diffbot\'s knowledge graph and AI products.', 'ai-crawler-controls' ),
			'pangubot'              => __( 'Collects pages to train Huawei\'s PanGu models.', 'ai-crawler-controls' ),
			'omgili'                => __( 'Webz.io\'s crawler. Webz.io sells the web data it collects, including for AI training.', 'ai-crawler-controls' ),
			'imagesiftbot'          => __( 'Collects public images for Hive\'s image search and AI tools.', 'ai-crawler-controls' ),
			'oai-searchbot'         => __( 'Finds pages to show and link to in ChatGPT search. OpenAI says it isn\'t used for training.', 'ai-crawler-controls' ),
			'claude-searchbot'      => __( 'Indexes pages to improve the search results Claude gives.', 'ai-crawler-controls' ),
			'perplexitybot'         => __( 'Indexes pages so Perplexity can show and link to them in its answers. Perplexity says it isn\'t used for training.', 'ai-crawler-controls' ),
			'duckassistbot'         => __( 'Fetches pages for DuckDuckGo\'s AI-assisted answers. DuckDuckGo says it isn\'t used for training.', 'ai-crawler-controls' ),
			'youbot'                => __( 'Indexes pages for You.com\'s AI search.', 'ai-crawler-controls' ),
			'chatgpt-user'          => __( 'Visits a page when someone asks ChatGPT, or a custom GPT, to read it.', 'ai-crawler-controls' ),
			'claude-user'           => __( 'Visits a page when someone asks Claude to read it.', 'ai-crawler-controls' ),
			'perplexity-user'       => __( 'Visits a page when someone asks Perplexity about it.', 'ai-crawler-controls' ),
			'meta-externalfetcher'  => __( 'Visits a link when someone asks Meta AI about it.', 'ai-crawler-controls' ),
			'mistralai-user'        => __( 'Visits a page when someone asks Mistral\'s Le Chat to read it.', 'ai-crawler-controls' ),
			'google-cloudvertexbot' => __( 'Crawls a site for an AI agent built with Google Cloud\'s Vertex AI, usually one the site\'s own owner set up.', 'ai-crawler-controls' ),
		);

		return isset( $about[ $id ] ) ? $about[ $id ] : '';
	}

	/**
	 * Name of a crawl log result.
	 *
	 * @param string $result An AI_Crawler_Controls_DB result.
	 * @return string
	 */
	public static function result_label( $result ) {
		$labels = array(
			AI_Crawler_Controls_DB::ALLOWED => __( 'Allowed', 'ai-crawler-controls' ),
			AI_Crawler_Controls_DB::IGNORED => __( 'Ignored robots.txt', 'ai-crawler-controls' ),
			AI_Crawler_Controls_DB::BLOCKED => __( 'Refused', 'ai-crawler-controls' ),
			AI_Crawler_Controls_DB::ROBOTS  => __( 'Read robots.txt', 'ai-crawler-controls' ),
		);

		return isset( $labels[ $result ] ) ? $labels[ $result ] : $result;
	}

	// Page --------------------------------------------------------------------

	/**
	 * Render the page: a header with the tabs, then WordPress's two-column
	 * box layout (the tab on the left, robots.txt and status on the right).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tab    = $this->current_tab();
		$totals = AI_Crawler_Controls_DB::totals_by_bot( self::STATS_DAYS );
		$badges = array(
			'crawlers' => count( $this->plugin->blocked_ids() ) + count( $this->plugin->custom_tokens() ),
		);
		?>
		<div class="aicc-header">
			<div class="aicc-header-title">
				<span class="aicc-header-icon"><?php echo AI_Crawler_Controls_Icons::get( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="aicc-version"><?php echo esc_html( 'v' . AI_CRAWLER_CONTROLS_VERSION ); ?></span>
			</div>
			<nav class="aicc-tabs" aria-label="<?php esc_attr_e( 'AI Crawler Controls sections', 'ai-crawler-controls' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="<?php echo esc_url( self::url( $id ) ); ?>" class="aicc-tab<?php echo $tab === $id ? ' is-active' : ''; ?>"<?php echo $tab === $id ? ' aria-current="page"' : ''; ?>>
						<?php echo AI_Crawler_Controls_Icons::get( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
						<?php if ( ! empty( $badges[ $id ] ) ) : ?>
							<span class="aicc-count" title="<?php esc_attr_e( 'Crawlers blocked', 'ai-crawler-controls' ); ?>"><?php echo esc_html( number_format_i18n( $badges[ $id ] ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap aicc-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors();
			$this->render_notice();
			?>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<?php
						$this->render_stats( $totals );

						if ( 'settings' === $tab ) {
							$this->render_settings();
						} elseif ( 'log' === $tab ) {
							$this->render_chart();
							$this->render_log_list();
						} else {
							$this->render_presets();
							$this->render_crawlers( $totals );
						}
						?>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<div class="aicc-sidebar">
							<?php
							$this->render_robots_box( $tab );
							$this->render_how_box();
							$this->render_updates_box();
							?>
						</div>
					</div>
				</div>
				<br class="clear">
			</div>
		</div>
		<?php
	}

	/**
	 * The notice after a button.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = isset( $_GET['aicc_notice'] ) ? sanitize_key( wp_unslash( $_GET['aicc_notice'] ) ) : '';
		$count  = isset( $_GET['aicc_count'] ) ? absint( $_GET['aicc_count'] ) : 0;
		// phpcs:enable

		switch ( $notice ) {
			case 'preset':
				$message = $count
					/* translators: %s: number of crawlers. */
					? sprintf( _n( 'Done: %s crawler is blocked. Its robots.txt rule is live now.', 'Done: %s crawlers are blocked. Their robots.txt rules are live now.', $count, 'ai-crawler-controls' ), number_format_i18n( $count ) )
					: __( 'Done: every AI crawler in the list is allowed.', 'ai-crawler-controls' );
				break;
			case 'cleared':
				$message = __( 'The crawl log and its totals have been cleared.', 'ai-crawler-controls' );
				break;
			default:
				return;
		}

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * The row of numbers above each tab.
	 *
	 * @param array $totals Totals per crawler from AI_Crawler_Controls_DB::totals_by_bot().
	 * @return void
	 */
	private function render_stats( array $totals ) {
		$sum = array(
			'hits'    => 0,
			'blocked' => 0,
			'ignored' => 0,
		);

		foreach ( $totals as $row ) {
			foreach ( $sum as $key => $value ) {
				$sum[ $key ] = $value + $row[ $key ];
			}
		}

		$blocked = count( $this->plugin->blocked_ids() );
		$known   = count( AI_Crawler_Controls_Bots::all() );

		$stats = array(
			array( 'activity', number_format_i18n( $sum['hits'] ), __( 'Visits in 30 days', 'ai-crawler-controls' ), '' ),
			/* translators: 1: blocked crawlers, 2: crawlers in the list. */
			array( 'bot-off', sprintf( __( '%1$s of %2$s', 'ai-crawler-controls' ), number_format_i18n( $blocked ), number_format_i18n( $known ) ), __( 'Crawlers blocked', 'ai-crawler-controls' ), $blocked ? 'good' : '' ),
			array( 'shield-ban', number_format_i18n( $sum['blocked'] ), __( 'Requests refused', 'ai-crawler-controls' ), $sum['blocked'] ? 'good' : '' ),
			array( 'triangle-alert', number_format_i18n( $sum['ignored'] ), __( 'Ignored robots.txt', 'ai-crawler-controls' ), $sum['ignored'] ? 'alert' : '' ),
		);
		?>
		<ul class="aicc-stats">
			<?php foreach ( $stats as $stat ) : ?>
				<li class="aicc-stat<?php echo $stat[3] ? ' aicc-stat-' . esc_attr( $stat[3] ) : ''; ?>">
					<span class="aicc-stat-icon"><?php echo AI_Crawler_Controls_Icons::get( $stat[0], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="aicc-stat-text">
						<strong><?php echo esc_html( $stat[1] ); ?></strong>
						<span><?php echo esc_html( $stat[2] ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Quick setup: one-click choices above the list.
	 *
	 * @return void
	 */
	private function render_presets() {
		$blocked = $this->plugin->blocked_ids();
		sort( $blocked );

		$choices = array(
			'training' => array( 'brain-circuit', __( 'Block AI training', 'ai-crawler-controls' ), __( 'Keep your content out of training data and stay visible in AI search. Recommended for most sites.', 'ai-crawler-controls' ) ),
			'all'      => array( 'bot-off', __( 'Block all AI crawlers', 'ai-crawler-controls' ), __( 'Training, AI search and assistants. Your pages won\'t appear as sources in AI answers.', 'ai-crawler-controls' ) ),
			'none'     => array( 'bot', __( 'Allow all AI crawlers', 'ai-crawler-controls' ), __( 'Let every AI crawler in. Visits are still logged.', 'ai-crawler-controls' ) ),
		);
		?>
		<div class="postbox aicc-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo AI_Crawler_Controls_Icons::get( 'wand-sparkles', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Quick setup', 'ai-crawler-controls' ); ?>
				</h2>
			</div>
			<div class="inside">
				<form method="post" action="<?php echo esc_url( self::url( 'crawlers' ) ); ?>" class="aicc-presets">
					<?php wp_nonce_field( 'ai_crawler_controls_preset' ); ?>
					<?php
					foreach ( self::presets() as $id => $ids ) :
						sort( $ids );
						$current = $ids === $blocked;
						?>
						<button type="submit" name="aicc_preset" value="<?php echo esc_attr( $id ); ?>" class="aicc-preset<?php echo $current ? ' is-current' : ''; ?>"<?php echo $current ? ' aria-pressed="true"' : ''; ?>>
							<span class="aicc-preset-icon"><?php echo AI_Crawler_Controls_Icons::get( $choices[ $id ][0], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
							<span class="aicc-preset-text">
								<strong>
									<?php echo esc_html( $choices[ $id ][1] ); ?>
									<?php if ( $current ) : ?>
										<span class="aicc-preset-current"><?php echo AI_Crawler_Controls_Icons::get( 'check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Current', 'ai-crawler-controls' ); ?></span>
									<?php endif; ?>
								</strong>
								<span><?php echo esc_html( $choices[ $id ][2] ); ?></span>
							</span>
						</button>
					<?php endforeach; ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * The Crawlers tab: a switch for every crawler, in groups, and the
	 * custom crawlers box, saved together.
	 *
	 * @param array $totals Totals per crawler.
	 * @return void
	 */
	private function render_crawlers( array $totals ) {
		$blocked = $this->plugin->blocked_ids();
		$seen    = AI_Crawler_Controls_DB::last_seen();
		?>
		<form action="options.php" method="post" class="aicc-crawlers-form" data-aicc-crawlers>
			<?php settings_fields( self::CRAWLERS_GROUP ); ?>

			<?php
			foreach ( array( AI_Crawler_Controls_Bots::TRAINING, AI_Crawler_Controls_Bots::SEARCH, AI_Crawler_Controls_Bots::USER ) as $purpose ) :
				$ids = AI_Crawler_Controls_Bots::ids_for( $purpose );
				if ( ! $ids ) {
					continue;
				}
				$group_blocked = count( array_intersect( $ids, $blocked ) );
				?>
				<div class="postbox aicc-box aicc-group" data-aicc-group>
					<div class="postbox-header">
						<h2 class="hndle">
							<?php echo AI_Crawler_Controls_Icons::get( self::PURPOSE_ICONS[ $purpose ], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<?php echo esc_html( self::purpose_label( $purpose ) ); ?>
							<span class="aicc-group-count" data-aicc-group-count>
								<?php
								/* translators: 1: number of blocked crawlers, 2: number of crawlers. */
								echo esc_html( sprintf( __( '%1$s of %2$s blocked', 'ai-crawler-controls' ), number_format_i18n( $group_blocked ), number_format_i18n( count( $ids ) ) ) );
								?>
							</span>
						</h2>
						<div class="aicc-group-actions" hidden data-aicc-group-actions>
							<button type="button" class="button button-small" data-aicc-set="1"><?php esc_html_e( 'Block all', 'ai-crawler-controls' ); ?></button>
							<button type="button" class="button button-small" data-aicc-set="0"><?php esc_html_e( 'Allow all', 'ai-crawler-controls' ); ?></button>
						</div>
					</div>
					<div class="inside">
						<p class="aicc-intro"><?php echo esc_html( self::purpose_intro( $purpose ) ); ?></p>
						<ul class="aicc-bots">
							<?php
							foreach ( $ids as $id ) {
								$this->render_bot_row( $id, in_array( $id, $blocked, true ), isset( $totals[ $id ] ) ? $totals[ $id ] : null, isset( $seen[ $id ] ) ? $seen[ $id ] : 0 );
							}
							?>
						</ul>
					</div>
				</div>
			<?php endforeach; ?>

			<?php $this->render_custom_box( $totals ); ?>

			<div class="aicc-savebar">
				<?php submit_button( __( 'Save Changes', 'ai-crawler-controls' ), 'primary', 'submit', false ); ?>
				<span class="aicc-savebar-note" data-aicc-dirty-note><?php esc_html_e( 'Changes reach robots.txt as soon as you save.', 'ai-crawler-controls' ); ?></span>
			</div>
		</form>
		<?php
	}

	/**
	 * One crawler in the list.
	 *
	 * @param string     $id      Crawler ID.
	 * @param bool       $blocked Whether it's blocked.
	 * @param array|null $total   Its totals for the last 30 days.
	 * @param int        $seen    When it was last logged (0 for never).
	 * @return void
	 */
	private function render_bot_row( $id, $blocked, $total, $seen ) {
		$bot    = AI_Crawler_Controls_Bots::get( $id );
		$field  = 'aicc-bot-' . $id;
		$tokens = $bot['tokens'];
		?>
		<li class="aicc-bot<?php echo $blocked ? ' is-blocked' : ''; ?>">
			<div class="aicc-bot-main">
				<div class="aicc-bot-name">
					<label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $bot['name'] ); ?></label>
					<span class="aicc-operator"><?php echo esc_html( $bot['operator'] ); ?></span>
					<?php if ( ! $bot['match'] ) : ?>
						<span class="aicc-badge aicc-badge-robots"><?php echo AI_Crawler_Controls_Icons::get( 'file-text', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'robots.txt only', 'ai-crawler-controls' ); ?></span>
					<?php endif; ?>
				</div>
				<p class="aicc-bot-about" id="<?php echo esc_attr( $field . '-about' ); ?>"><?php echo esc_html( self::bot_about( $id ) ); ?></p>
				<p class="aicc-bot-tokens">
					<?php echo esc_html( _n( 'robots.txt token:', 'robots.txt tokens:', count( $tokens ), 'ai-crawler-controls' ) ); ?>
					<?php foreach ( $tokens as $token ) : ?>
						<code><?php echo esc_html( $token ); ?></code>
					<?php endforeach; ?>
				</p>
			</div>
			<div class="aicc-bot-stats">
				<?php if ( ! $bot['match'] ) : ?>
					<span class="aicc-muted"><?php esc_html_e( 'Its visits come from a search crawler, so they aren\'t counted.', 'ai-crawler-controls' ); ?></span>
				<?php elseif ( $total && $total['hits'] ) : ?>
					<span>
						<strong>
							<?php
							/* translators: %s: number of visits. */
							echo esc_html( sprintf( _n( '%s visit', '%s visits', $total['hits'], 'ai-crawler-controls' ), number_format_i18n( $total['hits'] ) ) );
							?>
						</strong>
						<span class="aicc-muted"><?php esc_html_e( 'in 30 days', 'ai-crawler-controls' ); ?></span>
					</span>
					<?php if ( $seen ) : ?>
						<span class="aicc-seen" title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $seen ) ); ?>">
							<?php
							/* translators: %s: time ago, e.g. "2 hours". */
							echo esc_html( sprintf( __( 'Last seen %s ago', 'ai-crawler-controls' ), human_time_diff( $seen ) ) );
							?>
						</span>
					<?php endif; ?>
					<?php if ( $total['ignored'] ) : ?>
						<span class="aicc-flag">
							<?php echo AI_Crawler_Controls_Icons::get( 'triangle-alert', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<?php
							/* translators: %s: number of requests. */
							echo esc_html( sprintf( _n( 'Ignored robots.txt %s time', 'Ignored robots.txt %s times', $total['ignored'], 'ai-crawler-controls' ), number_format_i18n( $total['ignored'] ) ) );
							?>
						</span>
					<?php endif; ?>
				<?php else : ?>
					<span class="aicc-muted"><?php esc_html_e( 'No visits in 30 days', 'ai-crawler-controls' ); ?></span>
				<?php endif; ?>
			</div>
			<label class="aicc-switch" for="<?php echo esc_attr( $field ); ?>">
				<input type="checkbox" role="switch" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( AI_Crawler_Controls_Plugin::BLOCKED_OPTION ); ?>[]" value="<?php echo esc_attr( $id ); ?>" aria-describedby="<?php echo esc_attr( $field . '-about' ); ?>" <?php checked( $blocked ); ?>>
				<span class="aicc-switch-track" aria-hidden="true"></span>
				<span class="aicc-switch-on" aria-hidden="true"><?php esc_html_e( 'Blocked', 'ai-crawler-controls' ); ?></span>
				<span class="aicc-switch-off" aria-hidden="true"><?php esc_html_e( 'Allowed', 'ai-crawler-controls' ); ?></span>
				<span class="screen-reader-text">
					<?php
					/* translators: %s: crawler name. */
					echo esc_html( sprintf( __( 'Block %s', 'ai-crawler-controls' ), $bot['name'] ) );
					?>
				</span>
			</label>
		</li>
		<?php
	}

	/**
	 * Other crawlers to block, by user-agent token.
	 *
	 * @param array $totals Totals per crawler.
	 * @return void
	 */
	private function render_custom_box( array $totals ) {
		$tokens = $this->plugin->custom_tokens();
		?>
		<div class="postbox aicc-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo AI_Crawler_Controls_Icons::get( self::PURPOSE_ICONS[ AI_Crawler_Controls_Bots::CUSTOM ], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<label for="aicc-custom"><?php esc_html_e( 'Other crawlers to block', 'ai-crawler-controls' ); ?></label>
				</h2>
			</div>
			<div class="inside">
				<p class="aicc-intro"><?php esc_html_e( 'Found a crawler in your server logs that isn\'t listed? Add its user-agent token, one per line. Each one is disallowed in robots.txt and, with Refuse requests on, refused whenever its name appears in a user agent.', 'ai-crawler-controls' ); ?></p>
				<textarea id="aicc-custom" name="<?php echo esc_attr( AI_Crawler_Controls_Plugin::CUSTOM_OPTION ); ?>" rows="4" class="large-text code" placeholder="<?php echo esc_attr( "ExampleBot\nanother-crawler" ); ?>"><?php echo esc_textarea( implode( "\n", $tokens ) ); ?></textarea>
				<?php if ( $tokens ) : ?>
					<ul class="aicc-custom-stats">
						<?php
						foreach ( $tokens as $token ) :
							$id   = AI_Crawler_Controls_Bots::custom_id( $token );
							$hits = isset( $totals[ $id ] ) ? $totals[ $id ]['hits'] : 0;
							?>
							<li>
								<code><?php echo esc_html( $token ); ?></code>
								<span class="aicc-muted">
									<?php
									/* translators: %s: number of visits. */
									echo esc_html( sprintf( _n( '%s visit in 30 days', '%s visits in 30 days', $hits, 'ai-crawler-controls' ), number_format_i18n( $hits ) ) );
									?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The Crawl log tab's chart: visits per day, served and refused, with a
	 * table view of the same numbers.
	 *
	 * @return void
	 */
	private function render_chart() {
		$days  = AI_Crawler_Controls_DB::totals_by_day( self::STATS_DAYS );
		$peak  = 0;
		$total = 0;

		foreach ( $days as $day ) {
			$peak   = max( $peak, $day['hits'] );
			$total += $day['hits'];
		}

		$scale = self::nice_ceiling( $peak );
		$keys  = array_keys( $days );
		?>
		<div class="postbox aicc-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo AI_Crawler_Controls_Icons::get( 'chart-no-axes-column', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Visits from AI crawlers, last 30 days', 'ai-crawler-controls' ); ?>
				</h2>
			</div>
			<div class="inside">
				<?php if ( ! $total ) : ?>
					<p class="aicc-empty"><?php echo AI_Crawler_Controls_Icons::get( 'radar', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'No visits from AI crawlers yet. They\'ll show up here as they arrive, usually within a few days.', 'ai-crawler-controls' ); ?></p>
				<?php else : ?>
					<figure class="aicc-chart" data-aicc-chart>
						<div class="aicc-legend">
							<span><i class="aicc-key aicc-key-served" aria-hidden="true"></i><?php esc_html_e( 'Served', 'ai-crawler-controls' ); ?></span>
							<span><i class="aicc-key aicc-key-refused" aria-hidden="true"></i><?php esc_html_e( 'Refused (403)', 'ai-crawler-controls' ); ?></span>
						</div>
						<div class="aicc-plot">
							<div class="aicc-yaxis" aria-hidden="true">
								<span><?php echo esc_html( self::compact_number( $scale ) ); ?></span>
								<span><?php echo esc_html( self::compact_number( $scale / 2 ) ); ?></span>
								<span>0</span>
							</div>
							<div class="aicc-bars">
								<?php
								foreach ( $days as $date => $day ) :
									$served = $day['hits'] - $day['blocked'];
									$label  = sprintf(
										/* translators: 1: date, 2: visits, 3: refused requests. */
										__( '%1$s: %2$s visits, %3$s refused', 'ai-crawler-controls' ),
										self::day_label( $date ),
										number_format_i18n( $day['hits'] ),
										number_format_i18n( $day['blocked'] )
									);
									?>
									<div class="aicc-col" tabindex="0" aria-label="<?php echo esc_attr( $label ); ?>" data-tip="<?php echo esc_attr( $label ); ?>">
										<span class="aicc-col-bar">
											<?php if ( $served > 0 ) : ?>
												<span class="aicc-seg aicc-seg-served" style="height: <?php echo esc_attr( self::percent( $served, $scale ) ); ?>%"></span>
											<?php endif; ?>
											<?php if ( $day['blocked'] > 0 ) : ?>
												<span class="aicc-seg aicc-seg-refused" style="height: <?php echo esc_attr( self::percent( $day['blocked'], $scale ) ); ?>%"></span>
											<?php endif; ?>
										</span>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
						<div class="aicc-xaxis" aria-hidden="true">
							<span><?php echo esc_html( self::day_label( $keys[0] ) ); ?></span>
							<span><?php echo esc_html( self::day_label( $keys[ (int) floor( count( $keys ) / 2 ) ] ) ); ?></span>
							<span><?php esc_html_e( 'Today', 'ai-crawler-controls' ); ?></span>
						</div>
						<div class="aicc-tooltip" role="presentation" hidden data-aicc-tooltip></div>
						<details class="aicc-table-view">
							<summary><?php esc_html_e( 'Show as a table', 'ai-crawler-controls' ); ?></summary>
							<table class="widefat striped">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Day', 'ai-crawler-controls' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Visits', 'ai-crawler-controls' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Refused', 'ai-crawler-controls' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( array_reverse( $days, true ) as $date => $day ) : ?>
										<tr>
											<td><?php echo esc_html( self::day_label( $date ) ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $day['hits'] ) ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $day['blocked'] ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</details>
					</figure>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * A round number at or above $value for the top of the chart, whose half
	 * (the middle gridline) is round too: 400 for 330, 1,500 for 1,240.
	 *
	 * @param int $value Largest value.
	 * @return int
	 */
	private static function nice_ceiling( $value ) {
		if ( $value <= 4 ) {
			return 4;
		}

		$power = pow( 10, floor( log10( $value ) ) );

		foreach ( array( 1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10 ) as $step ) {
			if ( $step * $power >= $value ) {
				return (int) max( 4, round( $step * $power ) );
			}
		}

		return (int) ( 10 * $power );
	}

	/**
	 * Share of the chart's height, to one decimal.
	 *
	 * @param int $value Value.
	 * @param int $scale Top of the chart.
	 * @return string
	 */
	private static function percent( $value, $scale ) {
		return number_format( $scale ? min( 100, 100 * $value / $scale ) : 0, 1, '.', '' );
	}

	/**
	 * 1,200 => "1.2K", for the axis.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private static function compact_number( $value ) {
		if ( $value >= 1000000 ) {
			return number_format_i18n( $value / 1000000, $value % 1000000 ? 1 : 0 ) . 'M';
		}

		if ( $value >= 1000 ) {
			return number_format_i18n( $value / 1000, (int) $value % 1000 ? 1 : 0 ) . 'K';
		}

		return number_format_i18n( $value, floor( $value ) === (float) $value ? 0 : 1 );
	}

	/**
	 * A Y-m-d day as "Oct 3" in the site's language.
	 *
	 * @param string $date Y-m-d.
	 * @return string
	 */
	private static function day_label( $date ) {
		return date_i18n( _x( 'M j', 'chart day', 'ai-crawler-controls' ), strtotime( $date . ' 12:00:00' ) );
	}

	/**
	 * The Crawl log tab's list.
	 *
	 * @return void
	 */
	private function render_log_list() {
		if ( ! $this->table ) {
			return;
		}
		?>
		<div class="postbox aicc-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo AI_Crawler_Controls_Icons::get( 'scroll-text', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Crawl log', 'ai-crawler-controls' ); ?>
				</h2>
				<form method="post" action="<?php echo esc_url( self::url( 'log' ) ); ?>" class="aicc-clear-form">
					<?php wp_nonce_field( 'ai_crawler_controls_clear' ); ?>
					<button type="submit" name="aicc_clear" value="1" class="button button-small aicc-clear" data-aicc-confirm="<?php esc_attr_e( 'Delete every entry in the crawl log and reset the totals? This can\'t be undone.', 'ai-crawler-controls' ); ?>">
						<?php echo AI_Crawler_Controls_Icons::get( 'eraser', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php esc_html_e( 'Clear log', 'ai-crawler-controls' ); ?>
					</button>
				</form>
			</div>
			<div class="inside">
				<p class="aicc-intro">
					<?php
					if ( $this->plugin->get_setting( 'log' ) ) {
						printf(
							/* translators: %s: number of days. */
							esc_html__( 'Every request from an AI crawler in the last %s days, newest first. Anyone can put a crawler\'s name in their user agent, so a few entries may be impostors; the IP address helps tell.', 'ai-crawler-controls' ),
							esc_html( number_format_i18n( (int) $this->plugin->get_setting( 'retention' ) ) )
						);
					} else {
						esc_html_e( 'Logging is off, so new visits aren\'t recorded. Turn it on in Settings.', 'ai-crawler-controls' );
					}
					?>
				</p>
				<form method="get" class="aicc-list-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
					<input type="hidden" name="tab" value="log">
					<?php
					$this->table->search_box( __( 'Search log', 'ai-crawler-controls' ), 'aicc-search' );
					$this->table->display();
					?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * The Settings tab.
	 *
	 * @return void
	 */
	private function render_settings() {
		?>
		<div class="postbox aicc-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo AI_Crawler_Controls_Icons::get( 'settings-2', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Settings', 'ai-crawler-controls' ); ?>
				</h2>
			</div>
			<div class="inside">
				<form action="options.php" method="post">
					<?php settings_fields( self::PAGE ); ?>
					<table class="form-table" role="presentation">
						<?php do_settings_fields( self::PAGE, 'aicc_settings' ); ?>
					</table>
					<?php submit_button( __( 'Save Changes', 'ai-crawler-controls' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	// Sidebar -----------------------------------------------------------------

	/**
	 * Sidebar: whether the rules reach crawlers, what they are, and a test.
	 *
	 * @param string $tab Current tab, to come back to after a test.
	 * @return void
	 */
	private function render_robots_box( $tab ) {
		$tokens  = $this->plugin->blocked_tokens();
		$rules   = AI_Crawler_Controls_Guard::robots_rules( $tokens );
		$count   = count( $this->plugin->blocked_ids() ) + count( $this->plugin->custom_tokens() );
		$problem = AI_Crawler_Controls_Plugin::robots_problem();
		$check   = get_transient( self::CHECK_TRANSIENT );
		$enabled = (bool) $this->plugin->get_setting( 'robots' );
		?>
		<div class="postbox aicc-robots-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo AI_Crawler_Controls_Icons::get( 'file-text', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'robots.txt', 'ai-crawler-controls' ); ?>
				</h2>
			</div>
			<div class="inside">
				<?php
				if ( ! $enabled ) {
					$this->status_line( 'circle-alert', 'warn', __( 'robots.txt rules are off, so crawlers aren\'t asked to stay away. Turn them on in Settings.', 'ai-crawler-controls' ) );
				} elseif ( 'file' === $problem ) {
					$this->status_line( 'circle-alert', 'warn', __( 'Your server has a robots.txt file, which replaces the one WordPress makes, so the rules can\'t be added for you. Copy them into that file.', 'ai-crawler-controls' ) );
				} elseif ( 'subfolder' === $problem ) {
					$this->status_line( 'circle-alert', 'warn', __( 'This site isn\'t at the root of its domain, and crawlers only read robots.txt there. Copy the rules into the robots.txt at the root of the domain.', 'ai-crawler-controls' ) );
				} elseif ( 'permalinks' === $problem ) {
					$this->status_line( 'circle-alert', 'warn', __( 'WordPress only serves robots.txt with pretty permalinks. Choose any structure other than Plain under Settings > Permalinks.', 'ai-crawler-controls' ) );
				} elseif ( $count ) {
					/* translators: %s: number of crawlers. */
					$this->status_line( 'circle-check', 'good', sprintf( _n( '%s crawler is disallowed in your robots.txt.', '%s crawlers are disallowed in your robots.txt.', $count, 'ai-crawler-controls' ), number_format_i18n( $count ) ) );
				} else {
					$this->status_line( 'info', '', __( 'Nothing is disallowed yet. Block a crawler and its rule appears here.', 'ai-crawler-controls' ) );
				}
				?>

				<?php if ( $rules ) : ?>
					<details class="aicc-rules"<?php echo $enabled && $problem ? ' open' : ''; ?>>
						<summary><?php esc_html_e( 'Rules for blocked crawlers', 'ai-crawler-controls' ); ?></summary>
						<textarea class="code" readonly rows="8" wrap="off" aria-label="<?php esc_attr_e( 'Rules for blocked crawlers', 'ai-crawler-controls' ); ?>"><?php echo esc_textarea( $rules ); ?></textarea>
					</details>
				<?php endif; ?>

				<?php if ( is_array( $check ) ) : ?>
					<div class="aicc-check">
						<?php
						$ago = human_time_diff( (int) $check['time'] );

						if ( 'ok' === $check['status'] ) {
							$this->status_line(
								'circle-check',
								'good',
								$tokens
									/* translators: %s: time ago, e.g. "5 mins". */
									? sprintf( __( 'Tested %s ago: your live robots.txt has every rule.', 'ai-crawler-controls' ), $ago )
									/* translators: %s: time ago, e.g. "5 mins". */
									: sprintf( __( 'Tested %s ago: your robots.txt loads.', 'ai-crawler-controls' ), $ago )
							);
						} elseif ( 'missing' === $check['status'] ) {
							$this->status_line(
								'circle-x',
								'bad',
								sprintf(
									/* translators: 1: time ago, 2: comma-separated tokens. */
									__( 'Tested %1$s ago: your live robots.txt is missing %2$s. A robots.txt file, another plugin or a cache may be serving an older copy.', 'ai-crawler-controls' ),
									$ago,
									implode( ', ', array_slice( $check['missing'], 0, 5 ) ) . ( count( $check['missing'] ) > 5 ? '…' : '' )
								)
							);
						} else {
							$this->status_line(
								'circle-x',
								'bad',
								sprintf(
									/* translators: 1: time ago, 2: HTTP status code or error message. */
									__( 'Tested %1$s ago: robots.txt couldn\'t be loaded (%2$s).', 'ai-crawler-controls' ),
									$ago,
									$check['code'] ? 'HTTP ' . $check['code'] : $check['error']
								)
							);
						}
						?>
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( self::url( $tab ) ); ?>" class="aicc-robots-actions">
					<?php wp_nonce_field( 'ai_crawler_controls_check' ); ?>
					<input type="hidden" name="aicc_tab" value="<?php echo esc_attr( $tab ); ?>">
					<a class="button" href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener">
						<?php echo AI_Crawler_Controls_Icons::get( 'external-link', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php esc_html_e( 'View', 'ai-crawler-controls' ); ?>
					</a>
					<button type="submit" name="aicc_check" value="1" class="button">
						<?php echo AI_Crawler_Controls_Icons::get( 'scan-search', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php esc_html_e( 'Test robots.txt', 'ai-crawler-controls' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * A line of status text with an icon.
	 *
	 * @param string $icon Lucide icon.
	 * @param string $tone good, warn, bad or ''.
	 * @param string $text Plain text.
	 * @return void
	 */
	private function status_line( $icon, $tone, $text ) {
		printf(
			'<p class="aicc-status%1$s">%2$s<span>%3$s</span></p>',
			$tone ? ' aicc-status-' . esc_attr( $tone ) : '',
			AI_Crawler_Controls_Icons::get( $icon, 16 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			esc_html( $text )
		);
	}

	/**
	 * Sidebar: how blocked crawlers are kept out, as the settings stand.
	 *
	 * @return void
	 */
	private function render_how_box() {
		$settings = $this->plugin->get_settings();
		$items    = array(
			array( 'file-text', $settings['robots'], $settings['robots'] ? __( 'Blocked crawlers are disallowed in robots.txt', 'ai-crawler-controls' ) : __( 'robots.txt rules are off', 'ai-crawler-controls' ) ),
			array( 'shield-ban', $settings['enforce'], $settings['enforce'] ? __( 'Blocked crawlers that name themselves get a 403', 'ai-crawler-controls' ) : __( 'Blocked crawlers are asked, not refused', 'ai-crawler-controls' ) ),
			array( 'tags', $settings['signals'], $settings['signals'] ? __( 'noai and TDM opt-out signals on every page', 'ai-crawler-controls' ) : __( 'No noai or TDM signals', 'ai-crawler-controls' ) ),
			array(
				'scroll-text',
				$settings['log'],
				$settings['log']
					/* translators: %s: number of days. */
					? sprintf( __( 'Visits are logged for %s days', 'ai-crawler-controls' ), number_format_i18n( (int) $settings['retention'] ) )
					: __( 'Visits aren\'t logged', 'ai-crawler-controls' ),
			),
		);
		?>
		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo AI_Crawler_Controls_Icons::get( 'list-checks', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'How crawlers are kept out', 'ai-crawler-controls' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="aicc-checklist">
					<?php foreach ( $items as $item ) : ?>
						<li class="<?php echo $item[1] ? 'is-on' : 'is-off'; ?>">
							<?php echo AI_Crawler_Controls_Icons::get( $item[0], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<span><?php echo esc_html( $item[2] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="aicc-how-link"><a href="<?php echo esc_url( self::url( 'settings' ) ); ?>"><?php esc_html_e( 'Change in Settings', 'ai-crawler-controls' ); ?></a></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( AI_CRAWLER_CONTROLS_REPO ) );
		$check_url  = AI_Crawler_Controls_Updater::check_now_url();
		?>
		<div class="postbox aicc-updates-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo AI_Crawler_Controls_Icons::get( 'refresh-cw', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Updates', 'ai-crawler-controls' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'ai-crawler-controls' ), '<strong>' . esc_html( AI_CRAWLER_CONTROLS_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! AI_Crawler_Controls_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'ai-crawler-controls' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( AI_CRAWLER_CONTROLS_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( AI_Crawler_Controls_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'ai-crawler-controls' ),
							'<a href="' . esc_url( AI_CRAWLER_CONTROLS_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>AI_CRAWLER_CONTROLS_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'ai-crawler-controls' ),
							'<a href="' . esc_url( AI_CRAWLER_CONTROLS_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'ai-crawler-controls' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
