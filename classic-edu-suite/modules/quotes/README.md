# Quotes module — daily motivational popup + invite nudge

One motivating quote per student per calendar day, with the "invite a friend"
task attached underneath. Referrals are how students earn the coins that unlock
syllabus videos, so the popup is both encouragement and the growth loop's nudge.

Entry point: `CES_Quotes_Module::init()`, called by `CES_Plugin` on
`plugins_loaded` (priority 20).

## Files

| File | Class | Role |
|---|---|---|
| `class-ces-quotes-module.php` | `CES_Quotes_Module` | Static `init()`, wires everything |
| `class-ces-quotes-cpt.php` | `CES_Quotes_Cpt` | `ces_quote` post type, taxonomy, meta box, `create()` |
| `class-ces-quotes-scheduler.php` | `CES_Quotes_Scheduler` | Picks the quote, logs impressions, prunes, reports |
| `class-ces-quotes-popup.php` | `CES_Quotes_Popup` | Eligibility, conditional assets, footer markup |
| `class-ces-quotes-cta.php` | `CES_Quotes_Cta` | Referral link / WhatsApp share resolution (guarded) |
| `class-ces-quotes-rest.php` | `CES_Quotes_Rest` | `ces/v1/quotes/*` |
| `class-ces-quotes-shortcodes.php` | `CES_Quotes_Shortcodes` | The two embedding shortcodes |
| `class-ces-quotes-seeder.php` | `CES_Quotes_Seeder` | The ~28 starter quotes |
| `admin/class-ces-quotes-admin.php` | `CES_Quotes_Admin` | Admin screen, importer, report, preview |
| `assets/quotes-popup.css` · `assets/quotes-popup.js` | — | Popup, handle `ces-quotes-popup` |

Template: `templates/quotes/popup.php`.

## What an admin has to do

1. Nothing, to begin with. On first load the module installs ~28 starter
   quotes and schedules the rotation cron, so the popup works immediately.
2. **Classic EDU → Daily Quotes** to manage the pool, bulk import, preview the
   popup and read the engagement report.
3. **Classic EDU → Settings → Daily Quotes** to tune the delay, the wording and
   whether guests see it.
4. Make sure `dashboard_page_id` (General settings) points at the page holding
   `[ces_dashboard]`, otherwise the fallback `/student-dashboard/` is used.

## Content model — `ces_quote`

Not public, not publicly queryable, `show_in_menu` is false (the module's own
screen is the entry point; the normal editor is still reachable from it).

| Where | Meaning |
|---|---|
| `post_content` | The quote text, plain text |
| `post_title` | Auto-derived label for list tables — do not hand-edit |
| `post_status` | `publish` = candidate, anything else = out of the pool |
| `_ces_quote_author` | Attribution. Empty is fine. Never invent one |
| `_ces_quote_active` | `'1'` active, `'0'` parked. Missing is treated as active |
| `_ces_quote_scheduled_for` | `Y-m-d` to pin the quote to one day, else `''` |
| `_ces_quote_weight` | 1–10, default 1 |
| `_ces_quote_hash` | md5 of the normalised text, used for import de-dupe |
| `ces_quote_category` | Hierarchical taxonomy, optional |

A quote with `_ces_quote_scheduled_for` set is **excluded from the ordinary
rotation** and only ever appears on that date, where it beats everything else.

## Rotation rules

1. A quote pinned to today wins outright.
2. Otherwise a weighted random pick from the quotes the viewer has **not** been
   shown during their current cycle.
3. When the cycle is exhausted it resets and the whole pool opens up again — so
   nobody sees a repeat until they have seen everything.
4. `quotes_frequency = daily` (default) makes the choice sticky for the
   calendar day: the first impression is logged and re-read on later page
   views, so a student never sees two different quotes on one date.
   `every_visit` re-picks on each page view.

Cycle start is stored in user meta `ces_quote_cycle_start` (guests: a
transient keyed on `CES_Helpers::visitor_hash()`), with `ces_quote_recent`
holding the within-cycle list that covers same-day repeats in `every_visit`
mode.

An empty pool is handled everywhere: no popup, no markup, no assets, no error.

## How the popup avoids being annoying

- **Once per day.** A log row for today, or an `ces_quote_seen=<Y-m-d>` cookie,
  suppresses it completely.
- **Zero cost when it cannot fire.** `CES_Quotes_Popup::should_show()` runs
  before any enqueue, so a student on their fifth page view of the day
  downloads no CSS and no JS for this feature. The cookie check happens before
  any database query.
- **Never blocks the page.** The markup is printed hidden in the footer and
  revealed by JS after `quotes_delay_seconds`; page scroll is never locked, and
  on screens 640px and up the scrim is fully transparent so nothing is covered.
- **Bottom sheet on phones**, corner card on desktop, `max-height` capped with
  internal scrolling so it can never swallow the viewport.
- **Dismissal is remembered** in both the cookie and the `quote_log` row.
- **Keyboard and screen reader sane.** `role="dialog"`, `aria-modal="true"`,
  `aria-labelledby` / `aria-describedby`, focus moved into the dialog, Tab
  trapped inside it, Escape closes, focus restored to whatever had it before.
- **Respects `prefers-reduced-motion`** — no slide-in, no transition.
- **Waits for a visible tab**: if the page is loaded in a background tab the
  timer does not start until the student actually looks at it.
- **Stands down for other popups** (see below).

## Living with Popup Builder

This site already runs Popup Builder, and the two must not stack.

- Everything here is namespaced `ces-`: the element ID is `#ces-quote-popup`,
  every class starts `.ces-quote-popup`, the handles are `ces-quotes-popup`,
  the config global is `window.CESQuotes`. No selector, handle or global is
  shared with `sgpb`.
- `z-index` is `var( --ces-quote-z, 99500 )`. To put the quote popup above or
  below a Popup Builder dialog, redefine it once in the theme:
  `:root { --ces-quote-z: 120000; }`
- **Suppressing duplicates on the same page.** At display time the script
  checks for a visible `.sgpb-popup-dialog-main-div`, `.sgpb-popup-overlay`,
  `.pum-overlay.pum-active` or open `<dialog>`. If one is on screen it stands
  down for that page view entirely and logs nothing, so the student never sees
  two boxes. For a page where a Popup Builder campaign must always win, also
  suppress ours server-side:

  ```php
  add_filter( 'ces_quotes_should_show', function ( $show ) {
      return is_page( array( 'checkout', 'landing-promo' ) ) ? false : $show;
  } );
  ```

  The reverse case (excluding a page in Popup Builder) is configured in that
  plugin's own per-popup "Conditions" panel.

## Settings (`quotes_*`, all pre-existing keys)

| Key | Default | Meaning |
|---|---|---|
| `quotes_enabled` | 1 | Master switch for the popup |
| `quotes_frequency` | `daily` | `daily` or `every_visit` |
| `quotes_delay_seconds` | 8 | Delay after page load, clamped to 0–300 |
| `quotes_show_to_guests` | 1 | Show to logged-out visitors |
| `quotes_referral_cta` | 1 | Attach the invite task |
| `quotes_cta_text` | `Invite a friend and earn {coins} coins` | `{coins}` is filled from `coins_per_referral` |

Also read: `coins_per_referral`, `brand_primary`, `dashboard_page_id`,
`login_page_url`.

## Shortcodes

| Shortcode | Attributes | Output |
|---|---|---|
| `[ces_quote_of_the_day]` | `class`, `show_author` | The site-wide quote for today |
| `[ces_random_quote]` | `class`, `show_author`, `category` | A fresh random quote per render |

Both render an empty string when the pool is empty.

## REST routes — `ces/v1/quotes/`

| Method | Route | Auth | Notes |
|---|---|---|---|
| GET | `/quotes/today` | Logged in, or guests when `quotes_show_to_guests` | Returns the viewer's quote plus CTA data. Logs nothing. 60 reads/min |
| POST | `/quotes/impression` | `wp_rest` nonce + audience check | Records that the popup was displayed. 20 writes/min |
| POST | `/quotes/dismiss` | as above | Sets `dismissed = 1` |
| POST | `/quotes/cta` | as above | Sets `cta_clicked = 1` |

Every write verifies the `X-WP-Nonce` header explicitly (core only enforces a
nonce when one is supplied, which would leave anonymous writes unprotected) and
is rate limited through `CES_Helpers::rate_limit()`. A `quote_id` sent by the
client is only trusted when it is genuinely an eligible quote; otherwise the
server re-resolves it. Browser calls use `window.CES.restUrl` and
`window.CES.nonce` from the `ces-base` handle.

## Cron

| Hook | Schedule | Work |
|---|---|---|
| `ces_rotate_daily_quote` | daily, first run just after local midnight | Flush the pool cache, rotate the site-wide quote of the day, prune log rows older than the retention window |

Already listed in `CES_Deactivator`, so it is cleared on deactivation. The
site-wide quote also self-corrects lazily if cron never fires.

## Hooks

**Filters**

| Filter | Signature | Use |
|---|---|---|
| `ces_quotes_should_show` | `( bool $show, int $user_id )` | Suppress the popup on a page or for a viewer |
| `ces_quotes_popup_heading` | `( string $heading )` | Change the eyebrow heading |
| `ces_quotes_cta_data` | `( array $cta, int $user_id )` | Rewrite the whole invite CTA |
| `ces_referral_link` | `( string $link, int $user_id )` | **The wallet module's cleanest integration point** |
| `ces_quotes_log_retention_days` | `( int $days )` | Log retention, minimum 7, default 180 |

**Actions**

| Action | Signature |
|---|---|
| `ces_quote_engagement` | `( string $column, int $quote_id, int $user_id )` — fired after a dismissal or CTA click is recorded |

## Cross-module integration (all guarded)

Nothing here hard-depends on the wallet. `CES_Quotes_Cta` probes, in order:
`CES_Referral::referral_link|link_for_user|get_referral_link`,
`CES_Wallet_Referral::referral_link|link_for_user`, `CES_Wallet::referral_link`;
then the code providers `CES_Referral::code_for_user|get_code|user_code`,
`CES_Wallet_Referral::code_for_user`, `CES_Wallet::referral_code`, then the
`ces_referral_code` user meta — building `?ref=<code>` from whichever answers.
Every probe is `class_exists()` + `method_exists()`.

The simplest thing for the wallet agent to do is hook `ces_referral_link`.

If nothing answers, the CTA degrades to a plain "Invite friends" link to
`CES_Helpers::dashboard_url( 'referrals' )`. For logged-out visitors it becomes
"Create a free account" pointing at `CES_Helpers::login_url()`.

## The `quote_log` table (schema is frozen)

`wp_ces_quote_log` carries `UNIQUE KEY user_day (user_id, shown_on)`.

- **Logged-in users:** `shown_on` is filled, so that index *is* the
  one-row-per-user-per-day guarantee. Inserts use `INSERT IGNORE`, which makes
  impression logging idempotent.
- **Guests:** they all share `user_id = 0` and would collide on that index, so
  guest rows store `shown_on = NULL` (MySQL treats NULLs as distinct in a
  unique index) and are identified by `visitor_hash`. Their day comes from
  `shown_at`.
- Consequently **every report aggregates on `DATE(shown_at)`, never on
  `shown_on`**.
- Known limitation: because the index allows only one row per user per day,
  `every_visit` mode records a maximum of one impression per student per day.
  Dismissals and CTA clicks still land on that row.

No tables are created by this module.

## Template override

`wp-content/themes/<theme>/classic-edu-suite/quotes/popup.php`

Variables: `$quote` (`id`, `text`, `author`, `categories`), `$cta`, `$foot`,
`$heading`, `$is_preview`, `$dismiss_label`, `$copy_label`, `$copied_label`.
Keep the `id="ces-quote-popup"`, the `data-ces-quote-close`,
`data-ces-quote-cta`, `data-ces-quote-copy` and `data-ces-quote-initial`
attributes and the `role="dialog"` / `aria-modal` wiring, or the JS and the
accessibility behaviour stop working.

## Capability

The admin screen requires `ces_manage_quotes`, granted to administrators and to
the `ces_instructor` role by `CES_Activator`.

## Deliberately left for later

- No per-category targeting of the popup (the shortcode takes `category`, the
  popup does not).
- No A/B testing of CTA wording; the per-quote report is there to inform a
  manual choice.
- No daily time-series chart in the report, only window totals and a per-quote
  table.
- Impressions are not counted more than once per student per day, by design and
  by the frozen index.
