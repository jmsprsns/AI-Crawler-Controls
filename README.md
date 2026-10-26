# AI Crawler Controls

Block or allow AI crawlers such as GPTBot, ClaudeBot and CCBot one by one, through robots.txt rules, opt-out headers and an optional 403, and see every visit they make in a crawl log.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## Features

- **25 crawlers, one switch each**, in three groups: **AI training** (GPTBot, ClaudeBot, Google-Extended, Applebot-Extended, Meta-ExternalAgent, CCBot, Bytespider, Amazonbot, cohere-ai, AI2Bot, Diffbot, PanguBot, omgili, ImagesiftBot), **AI search** (OAI-SearchBot, Claude-SearchBot, PerplexityBot, DuckAssistBot, YouBot) and **AI assistants and agents** (ChatGPT-User, Claude-User, Perplexity-User, Meta-ExternalFetcher, MistralAI-User, Google-CloudVertexBot). Each row says what the crawler is for, its robots.txt tokens, its visits in the last 30 days and when it was last seen.
- **Quick setup.** "Block AI training" (training off, search and assistants on), "Block all AI crawlers" or "Allow all", in one click.
- **robots.txt rules.** One `User-agent: X` / `Disallow: /` group per blocked token, appended to WordPress's virtual robots.txt at priority 100, after SEO plugins. **Test robots.txt** fetches the live file and lists any token that's missing. The sidebar warns when the rules can't reach crawlers (a robots.txt file on the server, a site in a subfolder, Plain permalinks) and shows the rules to paste in.
- **Refuse requests** (off by default). A blocked crawler whose user agent names it gets a plain-text `403 Forbidden` on `plugins_loaded`. robots.txt, wp-admin, admin-ajax and wp-login.php are never refused.
- **AI opt-out signals** (off by default). `noai, noimageai` through core's `wp_robots` filter and an `X-Robots-Tag` header, plus `TDM-Reservation: 1` as a header and `<meta name="tdm-reservation">`.
- **Crawl log.** Every request from a recognised crawler: time, crawler, method, path, result (Allowed, Ignored robots.txt, Refused, Read robots.txt), status code, IP and user agent. A 30-day stacked chart (served / refused) with a table view, filters by crawler and result, search, and Clear log.
- **Custom crawlers.** Add other user-agent tokens to block. They're disallowed in robots.txt and refused (with Refuse requests on) when the token appears in a user agent.
- Nothing is blocked on activation, so installing the plugin never changes what crawlers see.

## How it works

1. On `plugins_loaded`, the user agent is matched against one case-insensitive regex of every known substring and custom token (`AI_Crawler_Controls_Bots::detect()`). Normal visitors stop there.
2. A request from a blocked crawler is refused if Refuse requests is on (except robots.txt, admin and login), and logged as `blocked`.
3. Otherwise it's logged on `shutdown` with the real status code: `robots` for robots.txt, `ignored` for a blocked crawler that asked for a page while the rules were live, `allowed` for the rest.
4. Each request adds to the day's totals (`wp_ai_crawler_controls_daily`, kept 400 days, exact) and, under the day's caps (1,000 per crawler, 10,000 in all), to the log (`wp_ai_crawler_controls_hits`, pruned daily after 7, 30 or 90 days). The stats and chart read the totals.
5. Google-Extended and Applebot-Extended are robots.txt tokens only: Google and Apple crawl with Googlebot and Applebot, so those visits can't be told apart, counted or refused.
6. With WP Rocket active, refused crawlers are added to its "never serve cached pages to these user agents" list (`rocket_cache_reject_ua`) and its config is rebuilt when the settings change, so the 403 reaches them. Other page caches and CDNs that answer before WordPress loads skip the refusal and the log; robots.txt still applies.

## Security

- **Logged data is untrusted.** User agent, path, method and IP come from the request. They're reduced to printable ASCII and 255 characters before they're stored, written with `$wpdb->insert()` / `prepare()`, and escaped on every output (`esc_html`, `esc_attr`). IPs come from `REMOTE_ADDR` only (forwarded headers are spoofable) and must pass `FILTER_VALIDATE_IP`.
- **Nothing reaches robots.txt or headers but plain tokens.** Every robots.txt token, built-in, filtered or custom, must match `^[A-Za-z0-9][A-Za-z0-9._-]{2,63}\z`, checked when it's saved and again when robots.txt is built, so no line breaks or directives can be injected. Header values are constants. Rejected custom tokens are escaped in the settings notice, which core prints as HTML.
- **No lockouts.** Custom tokens that appear in common browser, search engine or link-preview user agents (Mozilla, Chrome, Googlebot, bingbot, facebookexternalhit, Twitterbot...) are refused with an explanation, and the same check drops such user-agent matches from crawlers added with the `ai_crawler_controls_bots` filter. The 403 never applies to wp-admin, admin-ajax or wp-login.php, so an admin can always undo a mistake. The 403 sends `Cache-Control: no-store, private`, `DONOTCACHEPAGE` and `X-LiteSpeed-Cache-Control: no-cache` so a cache never shows it to other visitors.
- **Bounded database growth.** Spoofed crawler traffic can add at most 1,000 log rows per crawler and 10,000 in all per day (`ai_crawler_controls_log_cap` sets the first; the second is 10 times it), plus one totals row per crawler per day. At the longest retention (90 days) that's at most 900,000 rows. The totals still count every request.
- **Cheap for every visitor.** Detection is one regex over the first 1,024 characters of the user agent: a plain alternation of quoted literals, so it can't backtrack badly (about 0.05 ms for a normal visitor, 0.25 ms for an oversized 8 KB header).
- **Admins only.** The page needs `manage_options`. Quick setup, Clear log and Test robots.txt need a nonce and redirect afterwards; settings save through `options.php` with core's nonce and capability check. Test robots.txt only fetches the site's own `home_url( '/robots.txt' )`.
- **Clean uninstall.** Deleting the plugin drops both tables and removes its options, transient and scheduled event, on every site of a multisite network.

## Hooks

```php
// Add or change crawlers. Tokens must be plain user-agent tokens; anything else is dropped.
// Add this from a plugin or mu-plugin: detection runs on plugins_loaded, before the theme loads.
add_filter( 'ai_crawler_controls_bots', function ( $bots ) {
	$bots['examplebot'] = array(
		'name'     => 'ExampleBot',
		'operator' => 'Example Inc.',
		'purpose'  => 'training', // training, search or user
		'tokens'   => array( 'ExampleBot' ), // robots.txt user-agent tokens
		'match'    => array( 'ExampleBot' ), // user-agent substrings; empty for robots.txt-only tokens
	);
	return $bots;
} );

// Log the visitor's real IP behind a trusted proxy or CDN (validate it yourself).
add_filter( 'ai_crawler_controls_client_ip', function ( $ip ) {
	return $ip;
} );

// Requests per crawler per day kept in the log (the totals count every request).
add_filter( 'ai_crawler_controls_log_cap', function () {
	return 5000;
} );
```

## Automatic updates

Updates come from https://github.com/jmsprsns/AI-Crawler-Controls through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `ai-crawler-controls.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `ai-crawler-controls.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version:

   ```bash
   gh release create v1.0.1 --title 1.0.1 --generate-notes
   ```

   Or, without the GitHub CLI, push a version tag:

   ```bash
   git tag -a v1.0.1 -m 1.0.1 && git push origin v1.0.1
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'AI_CRAWLER_CONTROLS_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'AI_CRAWLER_CONTROLS_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
ai-crawler-controls.php                       Plugin header, constants, boot, activation
uninstall.php                                 Drops the tables and options when it's deleted
readme.txt                                    WordPress readme; feeds the "View details" popup
includes/
  class-ai-crawler-controls-plugin.php        Main singleton and settings access
  class-ai-crawler-controls-bots.php          The crawler catalog and user-agent matching
  class-ai-crawler-controls-guard.php         robots.txt rules, opt-out signals, 403s and logging
  class-ai-crawler-controls-db.php            Crawl log and daily totals tables
  class-ai-crawler-controls-admin.php         Content Powered > AI Crawler Controls page and Plugins-screen link
  class-ai-crawler-controls-list-table.php    The crawl log list
  class-ai-crawler-controls-menu.php          Shared top-level "Content Powered" menu
  class-ai-crawler-controls-feedback.php      Shared Submit Feedback and Report a Bug pages
  class-ai-crawler-controls-updater.php       GitHub update checks (PUC wiring)
  class-ai-crawler-controls-icons.php         Inline Lucide icon helper
assets/
  admin.css, admin.js                         Loaded only on the plugin's page
  icon.svg                                    Icon on the update screens
  content-powered.svg                         Sumo logo for the Content Powered menu
icons/                                        Lucide icon set (plus LICENSE)
plugin-update-checker/                        Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-ai-crawler-controls-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-ai-crawler-controls-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icon set in `icons/`. Print one inline with:

```php
echo AI_Crawler_Controls_Icons::get( 'package', 20 );           // name, size in px
echo AI_Crawler_Controls_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Simple Icons](https://simpleicons.org) brand icons** (`icons/simpleicons/`): CC0 1.0 (public domain). Brand names and logos are trademarks of their respective owners. Using one doesn't imply endorsement.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
