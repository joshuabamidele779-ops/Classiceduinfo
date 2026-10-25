# Shared public layer — dashboard, shortcodes, assets

Everything in `public/` is shared infrastructure. `CES_Plugin` calls
`CES_Shortcodes::init()`, `CES_Dashboard::init()` and `CES_Assets::init()` on
`plugins_loaded` (20), **after** the four modules, so every module has already
registered its dashboard tab by the time the dashboard renders.

| File | Class | Role |
|---|---|---|
| `class-ces-dashboard.php` | `CES_Dashboard` | `[ces_dashboard]`, the tab system, the Overview tab |
| `class-ces-shortcodes.php` | `CES_Shortcodes` | Cross-module conditional shortcodes |
| `class-ces-assets.php` | `CES_Assets` | The `ces-base` style + script handles |
| `assets/ces-base.css` · `assets/ces-base.js` | — | Design tokens, shared components, `window.CES` |

Templates: `templates/dashboard/wrapper.php`, `overview.php`,
`login-prompt.php`.

---

## 1. The dashboard tab contract

`[ces_dashboard]` on a page (point `dashboard_page_id` at it). Every module
contributes a tab and never edits `class-ces-dashboard.php`:

```php
add_filter( 'ces_dashboard_tabs', function ( $tabs ) {
	$tabs[] = array(
		'slug'     => 'coins',                                    // ?tab=coins
		'label'    => __( 'My Coins', 'classic-edu-suite' ),      // required
		'icon'     => '🪙',                                        // optional
		'callback' => array( 'CES_Wallet_Dashboard', 'render' ),   // echoes markup
		'position' => 10,
	);
	return $tabs;
} );
```

Rules, as implemented:

- `slug` runs through `sanitize_key()` and becomes `?tab=<slug>`.
- `label` is required; a tab without one is dropped.
- `callback` must satisfy `is_callable()`; otherwise the tab is dropped. It is
  invoked with the normalised tab array as its only argument (ignore it if you
  do not need it) and **echoes** its own, already-escaped markup.
- `position` sorts ascending. Ties keep registration order.
- Invalid tabs are dropped **silently** — the dashboard never shows a fatal or
  a warning because a module is half-finished.
- The **first** registration of a slug wins; later duplicates are ignored.
- Callbacks run only for a logged-in viewer and only for the active tab. A
  callback that throws is caught (`Throwable`), logged via `CES_Helpers::log()`
  and replaced with an inline notice, so one broken module cannot take the
  dashboard down.

Reserved positions: **Overview 0** (this file), wallet "My Coins" 10,
CBT "My Results" 20, LMS "My Courses" 30, wallet "Invite Friends" 40.

Build tab URLs with `CES_Helpers::dashboard_url( 'coins' )`, never by hand.

Other behaviour:

- Logged-out visitors get `templates/dashboard/login-prompt.php` — an
  invitation with a `CES_Helpers::login_url( $dashboard )` button, not an error.
  This matters because referral links land here.
- The tab strip is a plain `<nav>` of links with `aria-current="page"` on the
  active one, not an ARIA tablist, because each tab is a real page load. On a
  phone it is one horizontally scrollable, snap-aligned row — it never wraps
  into a broken desktop tab bar. No JavaScript is involved.
- The strip is hidden entirely when only the Overview tab exists.

The Overview tab shows: a greeting, the coin balance (only when
`class_exists( 'CES_Wallet' )` and `method_exists( …, 'balance' )`), today's
quote (only when the quotes module is present), the invite-a-friend prompt and
quick links to every other registered tab. Add to those links with
`ces_dashboard_quick_links`.

---

## 2. Asset handles other modules must use

**Both handles are named `ces-base`** — one style, one script.

```php
wp_enqueue_style(  'ces-cbt-exam', $url, array( 'ces-base' ), CES_VERSION );
wp_enqueue_script( 'ces-cbt-exam', $url, array( 'ces-base' ), CES_VERSION, true );
```

Declaring the dependency is enough: WordPress enqueues `ces-base` for you and
prints `window.CES` before your file runs. If you need it without a dependency
(inline markup, a widget), call `CES_Assets::enqueue_base()`.

`ces-base` is **registered** on every request (free) and **enqueued** only
when something needs it: the quote popup can fire, the post contains one of our
shortcodes, or a module asked for it. To force it:

```php
add_filter( 'ces_needs_base_assets', '__return_true' );
// or add your shortcode to the sniff list:
add_filter( 'ces_base_asset_shortcodes', function ( $tags ) {
	$tags[] = 'ces_my_thing';
	return $tags;
} );
```

### Design tokens

`ces-base.css` declares the tokens on `:root` as literals, and
`CES_Assets::tokens_css()` re-emits them inline from the `brand_primary`
setting (default `#2c62cd`, the site's existing brand blue), so the brand
colour is editable without a rebuild:

```
--ces-primary  --ces-primary-dark  --ces-primary-soft  --ces-primary-border
--ces-surface  --ces-surface-alt   --ces-text          --ces-text-muted
--ces-border   --ces-muted-bg      --ces-muted-bg-strong
--ces-radius   --ces-coin
```

Use `var( --ces-primary, #2c62cd )` in module CSS — with the fallback, so the
file also works if it is ever loaded without `ces-base`. Add or override tokens
with the `ces_design_tokens` filter. Every selector in `ces-base.css` is
class-scoped and `ces-`-prefixed with no bare element selectors, which is why
it is safe to load in wp-admin too.

Shared component classes you can reuse: `.ces-btn` (`--primary`, `--whatsapp`,
`--ghost`, `--block`), `.ces-panel` / `.ces-panel__title`, `.ces-stat` /
`.ces-stat__value` / `.ces-stat__label` / `.ces-stat__hint`, `.ces-notice`
(`--error`, `--success`), `.ces-gate`, `.ces-links`, `.ces-sr-only`.

### `window.CES`

Printed by an inline script **before** `ces-base.js`, so it is defined for
every dependent script. Real JSON types — booleans are booleans, `userId` is a
number.

```js
window.CES = {
	restUrl:      'https://site/wp-json/ces/v1/',  // trailing slash included
	nonce:        '…',                              // wp_rest, for X-WP-Nonce
	isLoggedIn:   true,
	userId:       42,                               // 0 for a guest
	dashboardUrl: 'https://site/student-dashboard/',
	loginUrl:     'https://site/login/',
	version:      '1.0.0'
}
```

`ces-base.js` then adds:

```js
CES.rest( path, { method, data, query, headers, keepalive } )  // → Promise
CES.get(  path, query )                                        // → Promise
CES.post( path, data )                                         // → Promise
CES.ready( fn )
CES.copy( text )                                               // → Promise<bool>
```

`CES.rest()` prefixes `restUrl`, sends `X-WP-Nonce` and same-origin cookies,
JSON-encodes `data`, resolves with the parsed body, and rejects with
`{ code, message, status, data }` on a non-2xx — so a module can show the
server's own `WP_Error` message. Example:

```js
CES.post( 'wallet/spend', { lesson_id: 12 } )
	.then( function ( res ) { … } )
	.catch( function ( err ) { console.warn( err.code, err.message ); } );
```

`ces-base.js` also wires one piece of shared UI: any element with
`data-ces-copy="<text>"` copies that text on click, briefly swapping its label
for `data-ces-copy-done` when one is set. Used by the dashboard's "Copy my
link" button.

**Caching note:** `nonce` is baked into the page, so a full-page cache can
serve a stale one. Writes return HTTP 403 with code `ces_quotes_bad_nonce` (or
the module's equivalent) rather than failing silently; exclude pages carrying
`[ces_dashboard]` from full-page caching.

---

## 3. Shortcodes owned here

| Shortcode | Attributes | Behaviour |
|---|---|---|
| `[ces_dashboard]` | `title` | The student dashboard |
| `[ces_login_required]…[/ces_login_required]` | `message`, `label` | Inner content for signed-in users; otherwise a `.ces-gate` prompt linking to the login page with `redirect_to` set to the current URL |
| `[ces_if_subscriber]…[/ces_if_subscriber]` | — | Inner content only when `CES_Helpers::is_subscriber()` |
| `[ces_if_not_subscriber]…[/ces_if_not_subscriber]` | — | Inner content for everyone else, logged-out visitors included |

Inner content is passed through `do_shortcode()`, so these nest. No shortcode
owned by the wallet, CBT, LMS or quotes modules is registered here.

---

## 4. Template overrides

`CES_Helpers::render()` resolves a theme copy first:
`wp-content/themes/<theme>/classic-edu-suite/<path>`.

| Path | Variables |
|---|---|
| `dashboard/wrapper.php` | `$tabs`, `$active`, `$content`, `$title`, `$nav_label` |
| `dashboard/overview.php` | `$greeting`, `$intro`, `$wallet`, `$quote`, `$quick_links`, `$invite`, `$links_label`, `$quote_label`, `$invite_label` |
| `dashboard/login-prompt.php` | `$login_url`, `$heading`, `$message`, `$bullets`, `$cta_label` |
| `quotes/popup.php` | see `modules/quotes/README.md` |

Templates contain no logic beyond loops and `empty()` guards: every value,
including the cross-module `class_exists()` checks, is resolved in
`CES_Dashboard::overview_data()`. `$wallet` and `$quote` are `null` when those
modules are absent. Keep it that way in an override.

---

## 5. Admin stylesheet

`admin/assets/admin.css` (handle `ces-admin`) is enqueued by the frozen
`CES_Admin_Menu::assets()` on any screen whose hook contains `classic-edu` or
`ces-`. It styles the classes that file renders — `.ces-wrap`, `.ces-sub`,
`.ces-cards`, `.ces-card`, `.ces-card__value`, `.ces-card__label`,
`.ces-card__link` — plus the module-screen extras `.ces-admin-tabs`,
`.ces-actions`, `.ces-code`, `.ces-quotes-table`, `.ces-popup-preview`,
`.ul-disc`. Everything is scoped under `.ces-wrap` so it cannot leak into other
plugins' screens. Module admin screens should reuse `.ces-cards` / `.ces-card`
for their own stat rows.

---

## Deliberately left for later

- No client-side tab switching: tabs are page loads, which keeps them
  shareable, bookmarkable and functional without JS.
- No dark-mode token set; the tokens are structured so one can be added under
  `prefers-color-scheme` later.
- `CES_Assets::needed()` only sniffs the queried post's content for shortcodes.
  Elementor widgets, block patterns and template-part rendering should use the
  `ces_needs_base_assets` filter.
