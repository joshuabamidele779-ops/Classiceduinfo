# Classic EDU Suite — Build Contract

Shared rules for every module. The foundation files already exist and are **frozen** —
do not edit them. Code against the interfaces below.

## Target environment

- WordPress 7.1.3, PHP 7.4+ compatible (no enums, no readonly props, no `never` return type)
- LiteSpeed server, shared hosting, MySQL
- Theme: GeneratePress + GP Premium · Page builder: Elementor
- Active plugins we integrate with: WooCommerce 10.4.3, YITH WC Membership Premium,
  YITH WC Subscription Premium, Profile Builder Pro (owns `/login/`), Popup Builder
- **No local PHP/MySQL/Node/ffmpeg available.** Code cannot be executed or linted here.
  Write defensively and re-read your own files before finishing.

## Frozen foundation (read, never edit)

| File | Provides |
|---|---|
| `classic-edu-suite/classic-edu-suite.php` | Constants: `CES_VERSION`, `CES_PATH`, `CES_URL`, `CES_BASENAME`, `CES_REST_NAMESPACE` (`ces/v1`) |
| `includes/class-ces-autoloader.php` | Autoloads `CES_*` classes |
| `includes/class-ces-activator.php` | **All** database tables, roles, caps, private storage dir |
| `includes/class-ces-settings.php` | `CES_Settings::defaults()`, `::save()`, `::OPTION` |
| `includes/class-ces-helpers.php` | Shared utilities (below) |
| `includes/class-ces-plugin.php` | Boots modules via static `init()` |
| `admin/class-ces-admin-menu.php` | Top-level menu, settings screen renderer |

### Naming and autoloading

Class `CES_Cbt_Attempt` → file `class-ces-cbt-attempt.php`. Lowercase the class name and
swap `_` for `-`. Only these directories are searched:

```
includes/  admin/  public/
modules/cbt/        modules/cbt/admin/
modules/lms/        modules/lms/admin/   modules/lms/storage/
modules/wallet/     modules/wallet/admin/
modules/quotes/     modules/quotes/admin/
modules/hub/        modules/hub/admin/
modules/guidance/   modules/guidance/admin/
modules/parents/    modules/parents/admin/
modules/accounts/   modules/accounts/admin/
modules/classroom/  modules/classroom/admin/
```

Do not create class files outside those directories. Assets and templates may live
anywhere under your module.

### Module entry point

Every module exposes exactly one static entry point, called on `plugins_loaded` (priority 20):

```php
class CES_Cbt_Module {
    public static function init() { /* register all hooks here */ }
}
```

Required module class names, in load order: `CES_Wallet_Module`, `CES_Cbt_Module`,
`CES_Lms_Module`, `CES_Quotes_Module`, `CES_Hub_Module`, `CES_Guidance_Module`,
`CES_Parents_Module`, `CES_Accounts_Module`, `CES_Classroom_Module`. The wallet is always available to the others, and the hub loads
after CBT because it draws on the question bank.

Modules added after the original four own these capabilities: `ces_manage_hub`,
`ces_manage_guidance` (both granted to administrators and EDU Instructors) and
`ces_manage_parents`, `ces_manage_accounts` and `ces_manage_classroom`
(administrators only — they gate third-party personal data and safeguarding
decisions), plus `ces_moderate_chat` and `ces_consultant`, which instructors
also get. The Educational Consultant role (`ces_consultant`) carries the
lesson, course and class post-type capabilities **without** the `*_others_*`
variants, which is what confines a tutor to their own content.
Each of those modules also filters `user_has_cap` so an install upgraded by copying
files, which never re-ran the activator, does not lock an administrator out.

## Helper API (`CES_Helpers`)

```php
CES_Helpers::settings()                   // full settings array
CES_Helpers::setting( $key, $default )    // one setting
CES_Helpers::setting_int( $key, $default )
CES_Helpers::enabled( $key )              // bool
CES_Helpers::table( 'attempts' )          // => wp_ces_attempts
CES_Helpers::now()                        // MySQL datetime, site time
CES_Helpers::ip()                         // client IP (Cloudflare aware)
CES_Helpers::user_agent()
CES_Helpers::visitor_hash()               // pseudonymous guest key
CES_Helpers::stream_secret()              // HMAC signing secret
CES_Helpers::is_subscriber( $user_id )    // YITH membership/subscription => unlimited
CES_Helpers::can_manage( $cap )
CES_Helpers::dashboard_url( $tab )
CES_Helpers::login_url( $redirect_to )
CES_Helpers::format_coins( $n )
CES_Helpers::log( $msg, $context )        // only when WP_DEBUG
CES_Helpers::rate_limit( $action, $limit, $window )  // true = allowed
CES_Helpers::render( 'cbt/exam.php', $vars )         // template with theme override
```

## Database tables (frozen — do not add tables)

All created by `CES_Activator`. Use `CES_Helpers::table( '<short name>' )`.

- **`attempts`** — `id, user_id, exam_id, status, question_ids, total_questions,
  correct_count, score, passed, duration_limit, time_spent, started_at, expires_at,
  submitted_at, ip_address, meta`
- **`attempt_answers`** — `id, attempt_id, question_id, given_answer, is_correct, flagged,
  answered_at` · unique `(attempt_id, question_id)`
- **`wallet_ledger`** — `id, user_id, delta, balance_after, type, ref_type, ref_id,
  idempotency_key, note, created_at` · unique `idempotency_key`
- **`referrals`** — `id, referrer_id, referred_id, code, status, reward_paid, signup_ip,
  signup_ua, device_hash, reject_reason, qualified_at, created_at` · unique `referred_id`
- **`video_views`** — `id, user_id, lesson_id, course_id, access_source, coins_spent,
  seconds_watched, furthest_position, completed, unlocked_at, unlocked_until, last_seen_at`
  · unique `(user_id, lesson_id)`
- **`quote_log`** — `id, user_id, visitor_hash, quote_id, shown_on, dismissed, cta_clicked,
  shown_at` · unique `(user_id, shown_on)`
- **`parent_verifications`** — `id, user_id, channel, destination, code_hash, token_hash,
  status, attempts, send_count, decided_by, decline_reason, ip_address, created_at,
  expires_at, last_sent_at, reminded_at, decided_at, meta`
- **`account_events`** — `id, user_id, actor_id, event, channel, detail, ip_address,
  user_agent, created_at` · append-only audit trail
- **`class_members`** — `id, class_id, user_id, role, status, source, lessons_done,
  minutes_watched, joined_at, approved_at, last_seen_at, meta` · unique
  `(class_id, user_id)`
- **`chat_messages`** — `id, room, user_id, author_kind, author_name, body, status,
  reply_to, flags, ip_address, created_at` · key `(room, id)`

If you need extra per-row data, use the existing `meta` longtext column (JSON) or post meta.
**Never** call `dbDelta` or `CREATE TABLE` in a module.

## Wallet API — the cross-module contract

Owned by the wallet agent, consumed by CBT, LMS and Quotes. Implement and use exactly this:

```php
CES_Wallet::balance( int $user_id ): int

// Positive $amount only. $idempotency_key must be unique per logical award;
// a repeat key is a silent no-op that returns the current balance. Returns
// the new balance, or WP_Error on failure.
CES_Wallet::credit( int $user_id, int $amount, string $type, array $args = array() )

// Positive $amount only. Returns WP_Error( 'ces_insufficient_funds' ) when the
// balance is too low. Must be atomic against concurrent requests.
CES_Wallet::debit( int $user_id, int $amount, string $type, array $args = array() )

CES_Wallet::history( int $user_id, int $limit = 50, int $offset = 0 ): array
```

`$args` accepts `ref_type`, `ref_id`, `note`, `idempotency_key`.

Transaction `type` values in use: `signup_bonus`, `referral_reward`, `referral_milestone`,
`daily_login`, `streak_bonus`, `quiz_pass`, `video_unlock`, `admin_adjust`.

Fired by the wallet, available to all modules:

```php
do_action( 'ces_coins_credited', $user_id, $amount, $type, $new_balance );
do_action( 'ces_coins_debited',  $user_id, $amount, $type, $new_balance );
do_action( 'ces_referral_qualified', $referrer_id, $referred_id );
```

Other modules fire these; the wallet listens and pays out:

```php
do_action( 'ces_quiz_completed', $user_id, $attempt_id, $score, $passed );
do_action( 'ces_video_completed', $user_id, $lesson_id );
```

Guard every call so a missing wallet never fatals:

```php
if ( class_exists( 'CES_Wallet' ) ) { CES_Wallet::credit( ... ); }
```

## REST conventions

Namespace `CES_REST_NAMESPACE` (`ces/v1`). Register inside `rest_api_init`.

- Always set `permission_callback`. Student endpoints require `is_user_logged_in()`;
  admin endpoints require a `ces_manage_*` capability. Never use `__return_true` for
  anything that writes.
- Authenticate browser calls with the `X-WP-Nonce` header (`wp_create_nonce( 'wp_rest' )`),
  passed to JS via `wp_localize_script`.
- Route prefixes, one per module, no overlap:
  `/cbt/*` · `/lms/*` · `/wallet/*` · `/quotes/*` · `/hub/*` · `/class/*` · `/chat/*` ·
  `/session/*`
- Return `WP_Error` with an HTTP status for failures; never `wp_die()` in REST.
- Sanitize every argument with `args` + `sanitize_callback`. Escape on output.

## Security rules (non-negotiable)

1. **Never trust the client on anything that scores or spends.** Correct answers and coin
   maths are resolved server-side only. Never send a correct answer to the browser before
   the attempt is submitted.
2. Every `$wpdb` call uses `$wpdb->prepare()`. No string interpolation of user input.
   Table names come from `CES_Helpers::table()` and are safe to interpolate.
3. Escape all output: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`.
4. Capability check *and* nonce check on every write, including admin-post and AJAX.
5. No secret, API key or signing secret ever reaches the browser.
6. Rate-limit anything a student can spam (`CES_Helpers::rate_limit`).

## Settings

Register your fields by filtering `ces_settings_sections` (see the docblock in
`admin/class-ces-admin-menu.php` for the array shape). Keys must already exist in
`CES_Settings::defaults()` — they all do; read that file and use the keys for your module.
Add stat cards with `ces_overview_cards`, admin screens with `ces_admin_submenus`.

## Front-end conventions

- CSS variable for brand colour: `--ces-primary`, seeded from the `brand_primary` setting.
  Prefix every class `ces-`. Mobile-first: most students are on phones.
- Enqueue only on pages that need it. Version assets with `CES_VERSION`.
- Vanilla JS, no build step, no jQuery dependency. No external CDN requests except
  `hls.js` (LMS only), which ships bundled in the plugin rather than hotlinked.
- Shortcodes you own must be listed in your module's `init()`.

## Student dashboard tabs

The dashboard lives in `public/class-ces-dashboard.php` (owned by the quotes/dashboard
agent) and renders the `[ces_dashboard]` shortcode. Every other module contributes its
own tab rather than editing the dashboard file:

```php
add_filter( 'ces_dashboard_tabs', function ( $tabs ) {
    $tabs[] = array(
        'slug'     => 'coins',                        // used as ?tab=coins
        'label'    => __( 'My Coins', 'classic-edu-suite' ),
        'icon'     => '🪙',                            // optional
        'callback' => array( 'CES_Wallet_Dashboard', 'render' ), // echoes its own markup
        'position' => 10,
    );
    return $tabs;
} );
```

Tab callbacks run only for logged-in users and only when that tab is active. Positions:
wallet 10, CBT 20, LMS 30, referrals 40.

## Code style

WordPress Coding Standards: tabs for indentation, Yoda conditions not required but
docblocks are. Every class and public method gets a docblock. Text domain
`classic-edu-suite` on every translatable string. No `error_log` outside
`CES_Helpers::log()`.

## Deliverable per agent

1. All PHP/CSS/JS files for your module, inside your own directories only.
2. A `modules/<name>/README.md`: what you built, every shortcode, REST route, hook,
   setting and cron event, plus what an admin has to do to use it.
3. Re-read each file you wrote before reporting done. You cannot run PHP, so a careful
   self-review is the only check that exists. Confirm: balanced braces, every called
   method actually defined, no edits outside your directories.
