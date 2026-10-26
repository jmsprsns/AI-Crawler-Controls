<?php
/**
 * The crawl log on the plugin's Crawl log tab.
 *
 * Every value in a row came from a request, so anyone could have written it:
 * all of it is escaped on the way out.
 *
 * @package AI_Crawler_Controls
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Crawl log list.
 */
final class AI_Crawler_Controls_List_Table extends WP_List_Table {

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 50;

	/**
	 * Filters in use: bot, result, search.
	 *
	 * @var array<string,string>
	 */
	private $filters = array(
		'bot'    => '',
		'result' => '',
		'search' => '',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ai-crawler-hit',
				'plural'   => 'ai-crawler-hits',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'time'    => __( 'Time', 'ai-crawler-controls' ),
			'bot'     => __( 'Crawler', 'ai-crawler-controls' ),
			'request' => __( 'Request', 'ai-crawler-controls' ),
			'result'  => __( 'Result', 'ai-crawler-controls' ),
			'ip'      => __( 'IP address', 'ai-crawler-controls' ),
		);
	}

	/**
	 * Load the rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$bot    = isset( $_REQUEST['bot'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['bot'] ) ) : '';
		$result = isset( $_REQUEST['result'] ) ? sanitize_key( wp_unslash( $_REQUEST['result'] ) ) : '';
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		// phpcs:enable

		$this->filters = array(
			'bot'    => in_array( $bot, $this->bot_choices(), true ) ? $bot : '',
			'result' => in_array( $result, AI_Crawler_Controls_DB::results(), true ) ? $result : '',
			'search' => substr( $search, 0, 200 ),
		);

		$found = AI_Crawler_Controls_DB::query_hits( $this->filters, self::PER_PAGE, $this->get_pagenum() );

		$this->items           = $found['rows'];
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'request' );

		$this->set_pagination_args(
			array(
				'total_items' => $found['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Crawler IDs the filter offers: every known one plus custom ones in the log.
	 *
	 * @return string[]
	 */
	private function bot_choices() {
		return array_values( array_unique( array_merge( array_keys( AI_Crawler_Controls_Bots::all() ), AI_Crawler_Controls_DB::logged_bots() ) ) );
	}

	/**
	 * The crawler and result filters, above the list.
	 *
	 * @param string $which top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$bots = array();
		foreach ( $this->bot_choices() as $id ) {
			$bots[ $id ] = AI_Crawler_Controls_Bots::name( $id );
		}
		natcasesort( $bots );
		?>
		<div class="alignleft actions">
			<label for="aicc-filter-bot" class="screen-reader-text"><?php esc_html_e( 'Filter by crawler', 'ai-crawler-controls' ); ?></label>
			<select name="bot" id="aicc-filter-bot">
				<option value=""><?php esc_html_e( 'All crawlers', 'ai-crawler-controls' ); ?></option>
				<?php foreach ( $bots as $id => $name ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $this->filters['bot'], $id ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="aicc-filter-result" class="screen-reader-text"><?php esc_html_e( 'Filter by result', 'ai-crawler-controls' ); ?></label>
			<select name="result" id="aicc-filter-result">
				<option value=""><?php esc_html_e( 'All results', 'ai-crawler-controls' ); ?></option>
				<?php foreach ( AI_Crawler_Controls_DB::results() as $result ) : ?>
					<option value="<?php echo esc_attr( $result ); ?>" <?php selected( $this->filters['result'], $result ); ?>><?php echo esc_html( AI_Crawler_Controls_Admin::result_label( $result ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'ai-crawler-controls' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Message when the list is empty.
	 *
	 * @return void
	 */
	public function no_items() {
		if ( array_filter( $this->filters ) ) {
			esc_html_e( 'No requests match these filters.', 'ai-crawler-controls' );
			return;
		}

		echo '<span class="aicc-empty">' . AI_Crawler_Controls_Icons::get( 'radar', 16 ) . esc_html__( 'No AI crawlers have visited yet. Requests will appear here as they arrive.', 'ai-crawler-controls' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
	}

	/**
	 * When it happened: "5 mins ago", with the full date on hover.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_time( $item ) {
		$time = (int) $item['hit_time'];
		$full = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );

		if ( time() - $time < DAY_IN_SECONDS ) {
			/* translators: %s: time ago, e.g. "5 mins". */
			$short = sprintf( __( '%s ago', 'ai-crawler-controls' ), human_time_diff( $time ) );
		} else {
			$short = $full;
		}

		return sprintf( '<time datetime="%1$s" title="%2$s">%3$s</time>', esc_attr( gmdate( 'c', $time ) ), esc_attr( $full ), esc_html( $short ) );
	}

	/**
	 * The crawler, its owner and what it's for.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_bot( $item ) {
		$id      = (string) $item['bot'];
		$bot     = AI_Crawler_Controls_Bots::get( $id );
		$purpose = AI_Crawler_Controls_Bots::purpose( $id );

		return sprintf(
			'<strong class="aicc-log-bot">%1$s</strong><span class="aicc-log-meta">%2$s%3$s</span>',
			esc_html( AI_Crawler_Controls_Bots::name( $id ) ),
			AI_Crawler_Controls_Icons::get( AI_Crawler_Controls_Admin::PURPOSE_ICONS[ $purpose ], 12 ),
			esc_html( $bot ? $bot['operator'] . ' · ' . AI_Crawler_Controls_Admin::purpose_label( $purpose ) : AI_Crawler_Controls_Admin::purpose_label( $purpose ) )
		);
	}

	/**
	 * Method, path and the full user agent.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_request( $item ) {
		return sprintf(
			'<span class="aicc-log-request"><span class="aicc-method">%1$s</span><code class="aicc-path">%2$s</code></span><span class="aicc-agent" title="%3$s">%4$s</span>',
			esc_html( (string) $item['method'] ),
			esc_html( (string) $item['path'] ),
			esc_attr( (string) $item['user_agent'] ),
			esc_html( (string) $item['user_agent'] )
		);
	}

	/**
	 * What happened, and the status code sent.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_result( $item ) {
		$icons = array(
			AI_Crawler_Controls_DB::ALLOWED => 'circle-check',
			AI_Crawler_Controls_DB::IGNORED => 'triangle-alert',
			AI_Crawler_Controls_DB::BLOCKED => 'shield-ban',
			AI_Crawler_Controls_DB::ROBOTS  => 'file-text',
		);

		$result = (string) $item['result'];
		$icon   = isset( $icons[ $result ] ) ? $icons[ $result ] : 'circle';
		$title  = AI_Crawler_Controls_DB::IGNORED === $result
			? __( 'robots.txt disallows this crawler, but it asked for the page anyway. Crawlers can take up to a day to notice a robots.txt change.', 'ai-crawler-controls' )
			: '';

		return sprintf(
			'<span class="aicc-result aicc-result-%1$s"%2$s>%3$s%4$s</span><span class="aicc-status-code">%5$s</span>',
			esc_attr( $result ),
			$title ? ' title="' . esc_attr( $title ) . '"' : '',
			AI_Crawler_Controls_Icons::get( $icon, 13 ),
			esc_html( AI_Crawler_Controls_Admin::result_label( $result ) ),
			/* translators: %d: HTTP status code. */
			esc_html( sprintf( __( 'HTTP %d', 'ai-crawler-controls' ), (int) $item['status'] ) )
		);
	}

	/**
	 * IP address.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_ip( $item ) {
		$ip = (string) $item['ip'];

		return '' === $ip ? '<span class="aicc-muted">&mdash;</span>' : '<code class="aicc-ip">' . esc_html( $ip ) . '</code>';
	}
}
