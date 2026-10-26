=== AI Crawler Controls ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: ai crawlers, robots.txt, gptbot, claudebot, ai training
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Block or allow AI crawlers such as GPTBot, ClaudeBot and CCBot one by one, through robots.txt rules, opt-out headers and an optional 403, and see every visit they make in a crawl log.

== Description ==

AI companies send crawlers to collect web pages: some to train their models, some to power AI search, and some to read a page when a person asks an assistant about it. AI Crawler Controls lists 25 of them, explains what each one does, and gives each a switch.

Blocking a crawler adds a rule for it to your robots.txt, the standard opt-out every major AI company says its crawlers follow. For crawlers that don't listen, you can also refuse their requests with a 403 error. And a crawl log shows every visit they make, so you can see who's crawling, how often, and whether your rules are respected.

= Features =

* **25 crawlers in three groups:** AI training (GPTBot, ClaudeBot, Google-Extended, Applebot-Extended, Meta-ExternalAgent, CCBot, Bytespider, Amazonbot and more), AI search (OAI-SearchBot, Claude-SearchBot, PerplexityBot and more) and AI assistants (ChatGPT-User, Claude-User, Perplexity-User and more).
* **Quick setup:** block AI training and stay visible in AI search in one click, or block or allow everything.
* **robots.txt rules:** a "Disallow: /" rule for each blocked crawler, added to the robots.txt WordPress serves, after anything your SEO plugin writes. A built-in test fetches your live robots.txt to confirm the rules are there.
* **Refuse requests (optional):** blocked crawlers that name themselves get "403 Forbidden" instead of your page. They can still read robots.txt, your dashboard and login page are never refused, and WP Rocket is set up to send them past its cache.
* **AI opt-out signals (optional):** noai and noimageai in your robots meta tag and X-Robots-Tag header, plus the TDM reservation signal.
* **Crawl log:** every request from an AI crawler with the page, result, status code, IP address and user agent, a 30-day chart, and per-crawler visit counts and "last seen" times. Filter by crawler or result and search.
* **Your own crawlers:** add any other user-agent token to block. Tokens that would match web browsers, search engines or link previews are refused, so you can't lock out real visitors by mistake.
* Nothing is blocked until you choose, so activating the plugin doesn't change what crawlers see.

Find it under Content Powered > AI Crawler Controls in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Brand icons by [Simple Icons](https://simpleicons.org), CC0 1.0. Brand names and logos are trademarks of their respective owners.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `ai-crawler-controls` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Content Powered > AI Crawler Controls and choose which crawlers to block.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Will blocking AI crawlers hurt my Google rankings? =

No. None of the crawlers in the list is Googlebot or Bingbot, and the opt-out signals are ignored by search engines. Google-Extended only controls whether Gemini may use your pages; it doesn't affect Google Search or AI Overviews.

= Which crawlers should I block? =

Most sites block AI training and allow AI search, which is what "Block AI training" does. That keeps your content out of future training data while ChatGPT search, Perplexity and others can still quote and link to you.

= Do AI crawlers really follow robots.txt? =

OpenAI, Anthropic, Google, Apple, Meta, Perplexity, Common Crawl and others say their crawlers do. Some don't, and some assistants don't apply robots.txt when a person asked for the page. The crawl log shows any blocked crawler that requests pages anyway, and Refuse requests stops the ones that name themselves.

= Can a crawler get around the block? =

A crawler that hides its name behind a normal browser user agent can't be recognized by any plugin. The crawl log records IP addresses so you can spot impostors and stragglers.

= Why aren't my rules in robots.txt? =

WordPress can only add rules to the robots.txt it makes. If there's a robots.txt file on your server, if the site is in a subfolder, or if permalinks are set to Plain, the plugin tells you and shows the rules to copy in yourself.

= Does the log slow my site down? =

No. Regular visitors only cost one check of their user agent. Requests from AI crawlers are written to the log, which keeps up to 1,000 requests per crawler per day and deletes entries after 7, 30 or 90 days.

== Changelog ==

See https://github.com/jmsprsns/AI-Crawler-Controls/releases for the full release history.
