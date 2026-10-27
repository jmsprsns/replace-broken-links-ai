# Replace Broken Links With AI

Finds links in your posts that return 404 or 410, waits until they're truly dead, then uses AI and a Google search to swap each one for the best live page on the same topic.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## Features

- **Only truly dead links.** A link counts as broken only when it answers 404 Not Found or 410 Gone to two GET requests in a row that look like an ordinary browser. Timeouts, DNS hiccups, server errors (5xx), rate limits (429) and "forbidden" (401, 403) never count.
- **A grace period you choose.** Right away, 24 hours, 3, 7 (default), 14 or 30 days. A failing link is checked daily, again when the wait is over, and once more right before anything is swapped (including approvals). A page that comes back is dropped from the list.
- **Dead domains that still answer 200.** Parked and for-sale pages, domain marketplaces, a deep link redirected to another website's homepage, a page taken over by gambling or pharma spam, and domains with no DNS records at all are flagged as "Domain changed hands". They get the same grace period and their replacements always wait for approval.
- **What the page was about.** The Wayback Machine's last good copy before the link started failing gives its title, description and opening paragraphs.
- **Original site first.** For links to other websites, a `site:` search of the dead link's own domain (up to 5 results) is offered ahead of Google's top 10, so a page that just moved is found. Links to your own site never use Google: they're matched against your own published posts and pages (slug and words).
- **The AI picks a number, not a URL.** Step 1 (a cheaper model) writes the search. Step 2 picks the best result by its number, with a backup, a confidence (high, medium or low) and a reason. The link can only become one of the results.
- **Verified replacements.** The new page has to answer 200, not be `noindex` (header or meta tag), and not be parked. The link goes to where the page ends up after redirects, so it never starts a redirect chain. A page behind bot protection (401/403) is offered for review only.
- **Automatic or approved.** Replaced automatically when the AI's confidence meets your threshold (medium or high), otherwise held in Needs review. "Ask me first" holds everything. Pick another result or paste your own link any time.
- **Preferences.** Preferred sources (`.gov` and `.edu` by default, plus any domains) are marked for the AI. "Never link to" domains are removed before it sees the results. "Never check" domains are skipped entirely.
- **Archived fallback.** When no live page matches, the Wayback Machine copy can be suggested (default), used automatically, or not at all.
- **Careful edits.** Only the `href` value changes (and a block's own copy of the URL in its `<!-- wp:... -->` attributes). Link text, `rel`, `target` and every other attribute stay. Root-relative links stay root-relative. Each change saves a revision, and the post's "last modified" date doesn't move.
- **Undo.** Every change is logged with who made it (or "automatically"). Undo restores exactly the spots it changed, if the post hasn't been edited since.
- **Cost controls.** A daily lookup limit and a daily spending limit (estimated from token counts and list prices). One lookup per dead URL, however many posts use it. Usage and estimated cost on the Overview.

## How a lookup works

1. Batches run on WP-Cron: posts saved since the last batch, then the full scan, then failing links that are due for a check (20 seconds of checking per batch), then up to 3 lookups. **Scan now** runs the checking part over AJAX with a progress bar.
2. Each URL is checked once per scan however many posts use it (`{prefix}rblai_urls`). Every request is a GET with redirects followed one hop at a time, a 10 second timeout and a 64 KB cap.
3. A confirmed-dead URL (`{prefix}rblai_links`, one row per URL) is looked up: Wayback copy, the AI's search, the original site and Google (Serper.dev), the AI's pick, then the checks on the pick and its backup.
4. Each post that uses it (`{prefix}rblai_uses`) is updated through `wp_update_post()`. Posts open in the editor are left until the next batch.
5. A rejected key, an empty account or an unknown model pauses lookups (with a notice) instead of failing every link; failed lookups are retried after 6 hours, up to 3 times.

## Security

- **No requests to private networks.** Link checks and replacement checks use `wp_safe_remote_get()` with redirects off, so WordPress validates every hop: http(s) only, ports 80, 443 and 8080, no loopback, private or reserved addresses (only the site's own host may resolve locally, and only on its own port). The `replace_broken_links_ai_request_args` filter can't turn this off. The AI, Serper.dev and Wayback requests go to fixed hosts only.
- **Nothing the AI writes reaches a post.** It answers with a result number; the address comes from the search results, must pass a strict URL check (absolute http(s), no credentials, quotes, spaces or angle brackets, at most 2,000 characters), `esc_url_raw()`, and is written with `esc_attr()`. Search results are untrusted: titles and snippets are reduced to plain text, and the prompt tells the AI to ignore instructions in them. Placeholders are filled in a single `strtr()` pass, so post text can't inject other placeholders.
- **Keys.** Saved keys are encrypted with libsodium (key derived from `AUTH_KEY`/`AUTH_SALT`), never printed back (only the last four characters), and never logged; provider error messages are scrubbed of anything key-shaped. Keys can live in `wp-config.php` instead.
- **Admins only.** The page, settings, every row and bulk action and every AJAX endpoint need `manage_options` and a nonce; replacing or undoing a link also needs `edit_post` for each post. Row actions redirect afterwards, so a refresh can't repeat them.
- **Content.** Posts are saved without kses on these updates (only a URL changes, and kses would strip markup an administrator was allowed to add during background batches); a regex failure returns the original content, never an empty one.
- **Cost.** Lookups only run in background batches or when an admin clicks, inside a lock, within the daily limits.
- **Clean uninstall.** Deleting the plugin drops the three tables and removes its options (including the keys) and scheduled events on every site of a network.

## Hooks

```php
// Leave a post alone: its links are never checked or replaced.
add_filter( 'replace_broken_links_ai_skip_post', function ( $skip, $post ) {
	return $skip || has_term( 'archive', 'category', $post );
}, 10, 2 );

// Change the link checker's request arguments (timeout, user agent, headers).
// redirection = 0 and reject_unsafe_urls = true are always re-applied afterwards.
add_filter( 'replace_broken_links_ai_request_args', function ( $args, $url ) {
	$args['timeout'] = 15;
	return $args;
}, 10, 2 );

// Model prices (USD per million input and output tokens) for the spending estimate.
add_filter( 'replace_broken_links_ai_prices', function ( $prices ) {
	$prices['my-fine-tuned-model'] = array( 3.0, 12.0 );
	return $prices;
} );

// Estimated cost of one Serper.dev search, in millionths of a dollar (default 1000 = $0.001).
add_filter( 'replace_broken_links_ai_search_cost', function () {
	return 300;
} );
```

Keys in `wp-config.php` (they win over saved ones):

```php
define( 'REPLACE_BROKEN_LINKS_AI_OPENAI_KEY', 'sk-...' );
define( 'REPLACE_BROKEN_LINKS_AI_ANTHROPIC_KEY', 'sk-ant-...' );
define( 'REPLACE_BROKEN_LINKS_AI_SERPER_KEY', '...' );
```

## Automatic updates

Updates come from https://github.com/jmsprsns/replace-broken-links-ai through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `replace-broken-links-ai.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `replace-broken-links-ai.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version:

   ```bash
   gh release create v1.0.1 --title 1.0.1 --generate-notes
   # or, without gh, a version tag is enough:
   git tag -a v1.0.1 -m "1.0.1" && git push origin v1.0.1
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'REPLACE_BROKEN_LINKS_AI_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'REPLACE_BROKEN_LINKS_AI_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
replace-broken-links-ai.php                       Plugin header, constants, boot, activation
uninstall.php                                     Drops the tables, removes options, keys and scheduled events
readme.txt                                        WordPress readme; feeds the "View details" popup
includes/
  class-replace-broken-links-ai-plugin.php        Main singleton, settings, keys, pause state
  class-replace-broken-links-ai-scanner.php       Batches, queue, locks, grace period, lookups, replacing, undo
  class-replace-broken-links-ai-checker.php       Link checks: 404/410, dead domains, noindex, safe URLs
  class-replace-broken-links-ai-finder.php        The lookup: Wayback, search, own posts, the AI's pick, checks
  class-replace-broken-links-ai-ai.php            OpenAI Responses API and Anthropic Messages API, prices
  class-replace-broken-links-ai-serper.php        Serper.dev (Google results)
  class-replace-broken-links-ai-wayback.php       Wayback Machine snapshots
  class-replace-broken-links-ai-prompts.php       Default prompts, placeholders, reply formats
  class-replace-broken-links-ai-content.php       Finds links and their context, replaces and reverts them
  class-replace-broken-links-ai-db.php            The three tables and their queries
  class-replace-broken-links-ai-usage.php         Daily usage and spending counts
  class-replace-broken-links-ai-crypto.php        Encrypts the API keys
  class-replace-broken-links-ai-admin.php         Content Powered > Replace Broken Links page, settings, actions, AJAX
  class-replace-broken-links-ai-list-table.php    The Broken Links list
  class-replace-broken-links-ai-menu.php          Shared top-level "Content Powered" menu
  class-replace-broken-links-ai-feedback.php      Shared Submit Feedback and Report a Bug pages
  class-replace-broken-links-ai-updater.php       GitHub update checks (PUC wiring)
  class-replace-broken-links-ai-icons.php         Inline Lucide icon helper
assets/
  admin.css, admin.js                             Loaded only on the plugin's page
  icon.svg                                        Icon on the update screens
  content-powered.svg                             Sumo logo for the Content Powered menu
icons/                                            The Lucide icons the page uses (plus LICENSE)
plugin-update-checker/                            Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every Content Powered plugin puts its page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Replace Broken Links, Content Powered > Redirect Chain Fixer, and so on.

Each plugin carries the same `class-replace-broken-links-ai-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-replace-broken-links-ai-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icons its page uses in `icons/`. Print one inline with:

```php
echo Replace_Broken_Links_AI_Icons::get( 'package', 20 );           // name, size in px
echo Replace_Broken_Links_AI_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Wayback Machine](https://web.archive.org)**: archived copies of dead pages are read from the Internet Archive's public availability API.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
