<?php
/**
 * The AI crawlers the plugin knows, and how to recognise them.
 *
 * Each crawler has the robots.txt token(s) it obeys and the text its user
 * agent contains. Google-Extended and Applebot-Extended are robots.txt tokens
 * only: Google and Apple crawl with their search bots and read these tokens to
 * decide what the pages may be used for, so their visits can't be told apart
 * or refused, only disallowed.
 *
 * Nothing here is translated, because detection runs on plugins_loaded,
 * before translations may load. The descriptions shown in the admin are in
 * AI_Crawler_Controls_Admin::bot_about().
 *
 * @package AI_Crawler_Controls
 */

defined( 'ABSPATH' ) || exit;

/**
 * Crawler catalog and user-agent matching.
 */
final class AI_Crawler_Controls_Bots {

	/**
	 * What a crawler's visits are for.
	 */
	const TRAINING = 'training';
	const SEARCH   = 'search';
	const USER     = 'user';
	const CUSTOM   = 'custom';

	/**
	 * Prefix for the IDs of crawlers added under "Other crawlers to block".
	 */
	const CUSTOM_PREFIX = 'custom:';

	/**
	 * Most custom crawlers a site can add.
	 */
	const MAX_CUSTOM = 50;

	/**
	 * The catalog, once built.
	 *
	 * @var array<string,array>|null
	 */
	private static $bots = null;

	/**
	 * Built matchers, keyed by the custom tokens they include.
	 *
	 * @var array<string,array{pattern: string, map: array<string,string>}>
	 */
	private static $matchers = array();

	/**
	 * Every known crawler: id => name, operator, purpose, robots.txt tokens and
	 * user-agent substrings (empty for robots.txt-only tokens).
	 *
	 * @return array<string,array{name: string, operator: string, purpose: string, tokens: string[], match: string[]}>
	 */
	public static function all() {
		if ( null !== self::$bots ) {
			return self::$bots;
		}

		$bots = array(
			// AI training.
			'gptbot'                => array( 'GPTBot', 'OpenAI', self::TRAINING, array( 'GPTBot' ), array( 'GPTBot' ) ),
			'claudebot'             => array( 'ClaudeBot', 'Anthropic', self::TRAINING, array( 'ClaudeBot', 'anthropic-ai' ), array( 'ClaudeBot', 'anthropic-ai' ) ),
			'google-extended'       => array( 'Google-Extended', 'Google', self::TRAINING, array( 'Google-Extended' ), array() ),
			'applebot-extended'     => array( 'Applebot-Extended', 'Apple', self::TRAINING, array( 'Applebot-Extended' ), array() ),
			'meta-externalagent'    => array( 'Meta-ExternalAgent', 'Meta', self::TRAINING, array( 'meta-externalagent' ), array( 'meta-externalagent' ) ),
			'ccbot'                 => array( 'CCBot', 'Common Crawl', self::TRAINING, array( 'CCBot' ), array( 'CCBot' ) ),
			'bytespider'            => array( 'Bytespider', 'ByteDance', self::TRAINING, array( 'Bytespider' ), array( 'Bytespider' ) ),
			'amazonbot'             => array( 'Amazonbot', 'Amazon', self::TRAINING, array( 'Amazonbot' ), array( 'Amazonbot' ) ),
			'cohere'                => array( 'cohere-ai', 'Cohere', self::TRAINING, array( 'cohere-ai', 'cohere-training-data-crawler' ), array( 'cohere-ai', 'cohere-training-data-crawler' ) ),
			'ai2bot'                => array( 'AI2Bot', 'Ai2', self::TRAINING, array( 'AI2Bot', 'Ai2Bot-Dolma' ), array( 'AI2Bot' ) ),
			'diffbot'               => array( 'Diffbot', 'Diffbot', self::TRAINING, array( 'Diffbot' ), array( 'Diffbot' ) ),
			'pangubot'              => array( 'PanguBot', 'Huawei', self::TRAINING, array( 'PanguBot' ), array( 'PanguBot' ) ),
			'omgili'                => array( 'omgili', 'Webz.io', self::TRAINING, array( 'omgili', 'omgilibot' ), array( 'omgili' ) ),
			'imagesiftbot'          => array( 'ImagesiftBot', 'Hive', self::TRAINING, array( 'ImagesiftBot' ), array( 'ImagesiftBot' ) ),

			// AI search.
			'oai-searchbot'         => array( 'OAI-SearchBot', 'OpenAI', self::SEARCH, array( 'OAI-SearchBot' ), array( 'OAI-SearchBot' ) ),
			'claude-searchbot'      => array( 'Claude-SearchBot', 'Anthropic', self::SEARCH, array( 'Claude-SearchBot' ), array( 'Claude-SearchBot' ) ),
			'perplexitybot'         => array( 'PerplexityBot', 'Perplexity', self::SEARCH, array( 'PerplexityBot' ), array( 'PerplexityBot' ) ),
			'duckassistbot'         => array( 'DuckAssistBot', 'DuckDuckGo', self::SEARCH, array( 'DuckAssistBot' ), array( 'DuckAssistBot' ) ),
			'youbot'                => array( 'YouBot', 'You.com', self::SEARCH, array( 'YouBot' ), array( 'YouBot' ) ),

			// Fetched when a person asks an assistant or agent to read a page.
			'chatgpt-user'          => array( 'ChatGPT-User', 'OpenAI', self::USER, array( 'ChatGPT-User' ), array( 'ChatGPT-User' ) ),
			'claude-user'           => array( 'Claude-User', 'Anthropic', self::USER, array( 'Claude-User' ), array( 'Claude-User' ) ),
			'perplexity-user'       => array( 'Perplexity-User', 'Perplexity', self::USER, array( 'Perplexity-User' ), array( 'Perplexity-User' ) ),
			'meta-externalfetcher'  => array( 'Meta-ExternalFetcher', 'Meta', self::USER, array( 'meta-externalfetcher' ), array( 'meta-externalfetcher' ) ),
			'mistralai-user'        => array( 'MistralAI-User', 'Mistral AI', self::USER, array( 'MistralAI-User' ), array( 'MistralAI-User' ) ),
			'google-cloudvertexbot' => array( 'Google-CloudVertexBot', 'Google', self::USER, array( 'Google-CloudVertexBot' ), array( 'Google-CloudVertexBot' ) ),
		);

		$catalog = array();
		foreach ( $bots as $id => $bot ) {
			$catalog[ $id ] = array(
				'name'     => $bot[0],
				'operator' => $bot[1],
				'purpose'  => $bot[2],
				'tokens'   => $bot[3],
				'match'    => $bot[4],
			);
		}

		/**
		 * Filter the crawlers the plugin knows.
		 *
		 * Entries need a name, operator, purpose (training, search or user),
		 * robots.txt tokens and user-agent substrings. Tokens that aren't
		 * plain user-agent tokens are dropped, so nothing else can reach
		 * robots.txt.
		 *
		 * @param array $catalog id => crawler.
		 */
		$filtered = apply_filters( 'ai_crawler_controls_bots', $catalog );

		self::$bots = is_array( $filtered ) ? self::validate( $filtered ) : $catalog;

		return self::$bots;
	}

	/**
	 * Keep only well-formed catalog entries.
	 *
	 * @param array $catalog id => crawler.
	 * @return array<string,array>
	 */
	private static function validate( array $catalog ) {
		$valid = array();

		foreach ( $catalog as $id => $bot ) {
			$id = sanitize_key( (string) $id );

			if ( '' === $id || 0 === strpos( $id, 'custom' ) || ! is_array( $bot ) || empty( $bot['name'] ) ) {
				continue;
			}

			$purpose = isset( $bot['purpose'] ) ? (string) $bot['purpose'] : '';
			$tokens  = isset( $bot['tokens'] ) ? array_values( array_filter( array_map( array( __CLASS__, 'token_syntax' ), (array) $bot['tokens'] ) ) ) : array();
			$match   = isset( $bot['match'] ) ? array_values( array_filter( array_map( array( __CLASS__, 'token_syntax' ), (array) $bot['match'] ) ) ) : array();

			// Same guard as custom tokens: a filtered crawler that matched
			// browsers would refuse every visitor once it's blocked.
			$match = array_values( array_filter( $match, array( __CLASS__, 'is_unprotected' ) ) );

			if ( ! $tokens || ! in_array( $purpose, array( self::TRAINING, self::SEARCH, self::USER ), true ) ) {
				continue;
			}

			$valid[ $id ] = array(
				'name'     => sanitize_text_field( (string) $bot['name'] ),
				'operator' => isset( $bot['operator'] ) ? sanitize_text_field( (string) $bot['operator'] ) : '',
				'purpose'  => $purpose,
				'tokens'   => $tokens,
				'match'    => $match,
			);
		}

		return $valid;
	}

	/**
	 * One known crawler.
	 *
	 * @param string $id Crawler ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$bots = self::all();

		return isset( $bots[ $id ] ) ? $bots[ $id ] : null;
	}

	/**
	 * IDs of the known crawlers with a purpose.
	 *
	 * @param string $purpose training, search or user.
	 * @return string[]
	 */
	public static function ids_for( $purpose ) {
		$ids = array();

		foreach ( self::all() as $id => $bot ) {
			if ( $purpose === $bot['purpose'] ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Display name for a crawler ID, including custom ones.
	 *
	 * @param string $id Crawler ID.
	 * @return string
	 */
	public static function name( $id ) {
		$bot = self::get( $id );

		if ( $bot ) {
			return $bot['name'];
		}

		return 0 === strpos( (string) $id, self::CUSTOM_PREFIX ) ? substr( (string) $id, strlen( self::CUSTOM_PREFIX ) ) : (string) $id;
	}

	/**
	 * Purpose of a crawler ID: training, search, user or custom.
	 *
	 * @param string $id Crawler ID.
	 * @return string
	 */
	public static function purpose( $id ) {
		$bot = self::get( $id );

		return $bot ? $bot['purpose'] : self::CUSTOM;
	}

	/**
	 * ID a custom token is logged under.
	 *
	 * @param string $token User-agent token.
	 * @return string
	 */
	public static function custom_id( $token ) {
		return self::CUSTOM_PREFIX . strtolower( $token );
	}

	/**
	 * Whether a string is a plain user-agent token: letters, digits, dots,
	 * hyphens and underscores, 3 to 64 long. Anything else (spaces, colons,
	 * line breaks) could add its own lines to robots.txt.
	 *
	 * @param mixed $token Candidate.
	 * @return string The token, or '' if it isn't one.
	 */
	public static function token_syntax( $token ) {
		$token = trim( (string) $token );

		// \z, not $: $ would also accept a trailing line break.
		return preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}\z/', $token ) ? $token : '';
	}

	/**
	 * User agents a custom token must never match: everyday browsers, search
	 * engines and link previews. A token that matches one would refuse real
	 * visitors or drop the site from search results.
	 *
	 * @return string[]
	 */
	private static function protected_agents() {
		return array(
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
			'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
			'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36 OPR/85.0',
			'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0',
			'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html) Googlebot-Image/1.0 Googlebot-News Google-InspectionTool Storebot-Google AdsBot-Google Mediapartners-Google APIs-Google FeedFetcher-Google GoogleOther',
			'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) BingPreview msnbot adidxbot',
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.1 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)',
			'DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html) DuckDuckGo-Favicons-Bot',
			'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots) Baiduspider Yahoo! Slurp Sogou web spider SeznamBot Qwantbot Ecosia',
			'facebookexternalhit/1.1 Twitterbot/1.0 LinkedInBot/1.0 Slackbot-LinkExpanding 1.0 Discordbot/2.0 TelegramBot WhatsApp/2.23 Pinterestbot redditbot',
			'WordPress/6.8; https://example.com Jetpack WP-URLDetails WordPress.com',
		);
	}

	/**
	 * Clean a custom token, or explain why it can't be used.
	 *
	 * @param mixed  $token  Candidate.
	 * @param string $reason Set to "syntax" or "protected" when the token is refused.
	 * @return string The token, or '' if it can't be used.
	 */
	public static function clean_custom_token( $token, &$reason = '' ) {
		$clean = self::token_syntax( $token );

		if ( '' === $clean ) {
			$reason = 'syntax';
			return '';
		}

		if ( ! self::is_unprotected( $clean ) ) {
			$reason = 'protected';
			return '';
		}

		return $clean;
	}

	/**
	 * Whether a token stays clear of every browser, search engine and link
	 * preview user agent.
	 *
	 * @param string $token User-agent token.
	 * @return bool
	 */
	private static function is_unprotected( $token ) {
		foreach ( self::protected_agents() as $agent ) {
			if ( false !== stripos( $agent, $token ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Which crawler a user agent belongs to.
	 *
	 * One case-insensitive regex over every known substring plus the custom
	 * tokens, so a normal visitor costs a single preg_match().
	 *
	 * @param string   $user_agent Request user agent.
	 * @param string[] $custom     Custom tokens (already cleaned).
	 * @return string Crawler ID, or '' for anything else.
	 */
	public static function detect( $user_agent, array $custom = array() ) {
		// Real crawler user agents are a few hundred characters; looking no
		// further keeps an oversized header cheap.
		$user_agent = substr( (string) $user_agent, 0, 1024 );

		if ( '' === $user_agent ) {
			return '';
		}

		$matcher = self::matcher( $custom );

		if ( '' === $matcher['pattern'] || ! preg_match( $matcher['pattern'], $user_agent, $found ) ) {
			return '';
		}

		$key = strtolower( $found[0] );

		return isset( $matcher['map'][ $key ] ) ? $matcher['map'][ $key ] : '';
	}

	/**
	 * Build (once per set of custom tokens) the detection regex and the map
	 * from each lower-cased substring back to its crawler.
	 *
	 * @param string[] $custom Custom tokens.
	 * @return array{pattern: string, map: array<string,string>}
	 */
	private static function matcher( array $custom ) {
		$key = implode( "\n", $custom );

		if ( isset( self::$matchers[ $key ] ) ) {
			return self::$matchers[ $key ];
		}

		$map = array();

		foreach ( self::all() as $id => $bot ) {
			foreach ( $bot['match'] as $needle ) {
				$map[ strtolower( $needle ) ] = $id;
			}
		}

		// Known crawlers win: a custom "Claude" doesn't take over Claude-User.
		$known = array_keys( $map );
		foreach ( $custom as $token ) {
			$lower = strtolower( $token );
			if ( ! isset( $map[ $lower ] ) ) {
				$map[ $lower ] = self::custom_id( $token );
			}
		}

		// Longest first, so a longer name wins where two start at the same place.
		$needles = array_keys( $map );
		usort(
			$needles,
			function ( $a, $b ) use ( $known ) {
				$a_known = in_array( $a, $known, true );
				$b_known = in_array( $b, $known, true );

				if ( $a_known !== $b_known ) {
					return $a_known ? -1 : 1;
				}

				return strlen( $b ) - strlen( $a );
			}
		);

		$quoted = array();
		foreach ( $needles as $needle ) {
			$quoted[] = preg_quote( $needle, '/' );
		}

		self::$matchers[ $key ] = array(
			'pattern' => $quoted ? '/' . implode( '|', $quoted ) . '/i' : '',
			'map'     => $map,
		);

		return self::$matchers[ $key ];
	}
}
