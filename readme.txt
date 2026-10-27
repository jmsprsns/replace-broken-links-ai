=== Replace Broken Links With AI ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: broken links, link checker, 404, ai, seo
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds links in your posts that return 404 or 410, waits until they're truly dead, then uses AI and a Google search to swap each one for the best live page on the same topic.

== Description ==

Every site collects dead links. The pages you cited move or disappear, and readers (and search engines) hit a 404 where your article promised something useful. Most broken link plugins can only tell you about them, or strip the link out.

Replace Broken Links With AI finds the best live page to link to instead. It reads what the old page was about, asks an AI to write a Google search for it, looks at the top results, lets the AI pick the best match, checks that page, and swaps the link. No more broken link, and a better one than a homepage or nothing at all.

= How it works =

1. Find: every link in your posts and pages is checked the way a browser would. Only 404 Not Found and 410 Gone count, twice in a row. Timeouts, server errors, rate limits and "forbidden" never do.
2. Confirm: you choose how long a link has to keep failing before it counts as dead (right away, 24 hours, 3, 7, 14 or 30 days). It's checked again when the wait is over, and once more right before anything is swapped.
3. Remember: the Wayback Machine's last good copy says what the page used to be about.
4. Search: the AI writes a Google search. The dead link's own website is searched first, in case the page just moved. Links to your own site are matched against your own published posts and never use Google.
5. Choose: the AI picks the best of Google's top 10 (via Serper.dev), says how sure it is, and why.
6. Verify: the new page has to load (200), not be noindexed or parked, and is linked without redirects.
7. Swap: replaced automatically when the AI is sure enough, or held for your approval.

= Features =

* Works with OpenAI (ChatGPT) or Anthropic (Claude): pick the provider and model. A cheaper model writes the searches; a stronger one chooses.
* Both prompts can be edited under Advanced, with placeholders and a test lookup that shows every step.
* Flags dead domains that still answer 200: parked and for-sale pages, domains taken over by spam, redirects to another website's homepage, and domains that no longer exist. These always wait for your approval.
* One lookup per dead URL, however many posts link to it.
* Preferred sources (.gov, .edu, or domains you list) and a "never link to" list for competitors.
* If no live page matches, it can offer the Wayback Machine copy instead.
* Only the link address changes. The link text, rel, target and every other attribute stay as they were, the post's "last modified" date doesn't move, and each change saves a revision.
* Every change is logged and can be undone. Pick another search result, or paste your own link, at any time.
* Daily limits on lookups and on estimated API spending, with usage and cost estimates on the Overview.
* API keys are stored encrypted, or can be defined in wp-config.php.

You need two API keys, paid to the providers directly: an OpenAI or Anthropic key, and a Serper.dev key (new accounts get free searches). A typical lookup costs a few cents at most.

Find it under Content Powered > Replace Broken Links in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Archived pages from the Internet Archive's [Wayback Machine](https://web.archive.org).
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `replace-broken-links-ai` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress. The first scan for broken links starts in the background.
3. Go to Content Powered > Replace Broken Links > Connections and add an OpenAI or Anthropic API key and a Serper.dev API key.
4. Choose how long a link has to fail before it's replaced, and whether replacements go in automatically, under Settings.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Which links count as broken? =

Only pages that answer 404 Not Found or 410 Gone, to two requests in a row that look like an ordinary browser. A site that's down for a moment, a timeout, a server error or a page that blocks bots is never treated as broken. Links whose domain changed hands (parked, for sale, taken over, or gone from DNS) are flagged separately.

= Could it replace a link I wanted to keep? =

Only if the page has been dead for your whole grace period, the replacement loads, and the AI is confident (you choose how confident). Everything else waits in Needs review. Every change can be undone, and any domain can be listed under "Never check".

= What does the AI see? =

The dead link, its link text, a few sentences around it, the post's title, the Wayback Machine summary, and the search results. Nothing else from your site is sent, and the AI only ever answers with the number of a search result, so it can't put any other link into your posts.

= How much does it cost? =

Each dead link uses two AI requests and one or two searches: usually well under five cents with the default models, and a fraction of a cent with the cheapest ones. The Overview shows an estimate, and you can set a daily spending limit.

= Does it check links in page builders? =

It checks the post content that the block editor and classic editor save. Page builders that store their layouts separately (Elementor, Divi, Beaver Builder) aren't scanned.

= Can I keep my API keys out of the database? =

Yes. Define REPLACE_BROKEN_LINKS_AI_OPENAI_KEY, REPLACE_BROKEN_LINKS_AI_ANTHROPIC_KEY and REPLACE_BROKEN_LINKS_AI_SERPER_KEY in wp-config.php. Keys saved in the settings are encrypted with your site's salts.

== Changelog ==

= 1.0.1 =
* Wayback Machine lookups use the CDX API first (one request lists the last good copies before the link died), with the availability API as a fallback, so archive.org rate limits matter less.
* Archived summaries no longer repeat a description that's the first paragraph cut short.
* The "Used in" column is left-aligned again (it shared a class name with a core column).

= 1.0.0 =
* First release.

See https://github.com/jmsprsns/replace-broken-links-ai/releases for the full release history.
