# Wallet Module — Coins & Referrals

The coin economy for Classic EDU Suite. Students earn coins (signup, daily
login, streaks, quiz passes, referrals) and spend them in the LMS module to
unlock syllabus videos. This module owns the ledger; every other module talks
to it through `CES_Wallet`.

Loaded **first** by `CES_Plugin`, so `CES_Wallet` always exists before the CBT,
LMS and Quotes modules register their hooks.

---

## Files

| File | Class | Role |
|---|---|---|
| `class-ces-wallet-module.php` | `CES_Wallet_Module` | Static `init()`; wires every hook, cron and asset |
| `class-ces-wallet.php` | `CES_Wallet` | Append-only ledger; the cross-module API |
| `class-ces-referral.php` | `CES_Referral` | Codes, attribution, qualification, anti-fraud |
| `class-ces-rewards.php` | `CES_Rewards` | Signup bonus, daily login, streaks, quiz passes |
| `class-ces-wallet-rest.php` | `CES_Wallet_Rest` | `/ces/v1/wallet/*` endpoints |
| `class-ces-wallet-dashboard.php` | `CES_Wallet_Dashboard` | "My Coins" and "Invite Friends" tabs |
| `class-ces-wallet-shortcodes.php` | `CES_Wallet_Shortcodes` | The three shortcodes |
| `admin/class-ces-wallet-admin.php` | `CES_Wallet_Admin` | Admin screen, overview cards, settings fields |
| `assets/wallet.css`, `assets/wallet.js` | — | Phone-first styles, vanilla JS |

No tables are created here. The schema is frozen in `CES_Activator`; this
module writes to `wp_ces_wallet_ledger` and `wp_ces_referrals` only.

---

## Public API — `CES_Wallet`

Other modules **must** use exactly these signatures, guarded with
`class_exists( 'CES_Wallet' )`.

```php
CES_Wallet::balance( int $user_id ): int

CES_Wallet::credit( int $user_id, int $amount, string $type, array $args = array() ): int|WP_Error
CES_Wallet::debit(  int $user_id, int $amount, string $type, array $args = array() ): int|WP_Error

CES_Wallet::history( int $user_id, int $limit = 50, int $offset = 0 ): array
```

`$args` keys: `ref_type` (string, ≤40), `ref_id` (int), `note` (string, ≤255),
`idempotency_key` (string, ≤100).

Both `credit()` and `debit()` take **positive** amounts and return the new
balance as an `int`, or a `WP_Error`:

| Error code | When |
|---|---|
| `ces_invalid_amount` | Not a whole number, ≤ 0, or above the `ces_wallet_max_amount` ceiling (default 1,000,000) |
| `ces_invalid_user` | User ID ≤ 0 or no such row in `wp_users` |
| `ces_invalid_type` | Empty transaction type |
| `ces_insufficient_funds` | Debit larger than the balance. Error data carries `balance`, `required`, `shortfall` |
| `ces_ledger_write_failed` | The insert failed for a reason other than a duplicate key |

A repeated `idempotency_key` is a **silent no-op**: no row is written, no
action fires, and the current balance is returned as a normal `int`.

### Additional helpers (safe to use, not part of the frozen contract)

```php
CES_Wallet::history_count( int $user_id ): int
CES_Wallet::recalculate( int $user_id ): int          // repair: rebuild the cached balance
CES_Wallet::find_by_key( string $key ): ?object
CES_Wallet::sum_for_day( int $user_id, string $type, string $date = '' ): int
CES_Wallet::sum_for_types( int $user_id, array $types ): int
CES_Wallet::total_in_circulation(): int
CES_Wallet::types(): array
CES_Wallet::type_label( string $type ): string
```

### Transaction types

`signup_bonus`, `referral_reward`, `referral_milestone`, `daily_login`,
`streak_bonus`, `quiz_pass`, `video_unlock`, `admin_adjust`.

### Example: spending coins from the LMS

```php
if ( class_exists( 'CES_Wallet' ) ) {
	$result = CES_Wallet::debit(
		$user_id,
		$cost,
		'video_unlock',
		array(
			'ref_type'        => 'lesson',
			'ref_id'          => $lesson_id,
			'note'            => 'Unlocked: ' . get_the_title( $lesson_id ),
			// Deterministic: a double-tap cannot charge twice.
			'idempotency_key' => 'video_unlock:' . $user_id . ':' . $lesson_id . ':' . $unlock_window,
		)
	);

	if ( is_wp_error( $result ) ) {
		// 'ces_insufficient_funds' => send them to the Invite Friends tab.
	}
}
```

> **Important for callers that spend coins:** always pass a deterministic
> `idempotency_key`. Make it unique per *logical purchase* — include a window
> or period identifier if the same thing can legitimately be bought again
> later (as `$unlock_window` does above). Without a key the wallet generates a
> random one, which is correct for one-off awards but gives a double-click no
> protection beyond the lock and transaction.

---

## How debiting is made atomic

Money-like writes get three independent layers. Any one of them is enough in
most cases; together they cover the shared-hosting failure modes.

1. **Per-user advisory lock.** `CES_Wallet::lock()` takes a MySQL
   `GET_LOCK( 'ces<hash>w<user_id>', 5 )`, namespaced with a hash of the table
   prefix and `DB_NAME` so two installs on one MySQL account cannot collide.
   - `1` — lock held, released with `RELEASE_LOCK` in every exit path.
   - `0` — the lock works but we waited 5s. We log it and proceed, because
     layers 2 and 3 still hold and failing a student's click is worse.
   - `NULL` — `GET_LOCK` is not granted on this host. We fall back to a
     transient spin lock (15 × 100 ms). This one is best-effort only.

2. **Transaction + row lock.** `START TRANSACTION`, then the balance is read
   with `SELECT balance_after … ORDER BY id DESC LIMIT 1 FOR UPDATE`, so a
   concurrent request blocks on the row instead of reading a stale balance.
   The insufficient-funds check happens *inside* the transaction, and every
   failure path `ROLLBACK`s. Requires InnoDB — which is the default, but on a
   MyISAM table the statement is a harmless no-op and layers 1 and 3 carry it.

3. **The unique `idempotency_key` index — the real guarantee.** This is the
   only layer that cannot be defeated by a lock timeout, a dropped connection
   or two PHP workers on different hosts. Errors are suppressed around the
   insert, `$wpdb->last_error` is inspected, and a duplicate-entry (1062)
   error is swallowed: the transaction rolls back, the balance is recomputed
   from the ledger, and the call returns that balance as a plain `int`. The
   caller cannot tell a replay from a first attempt, which is exactly right.

Because the schema's unique index covers `idempotency_key` with a `''`
default, **the column can never be blank** — two blank keys would collide. If
a caller passes no key, the wallet generates
`auto:{type}:{user}:{microtime}:{random}`.

### Balance caching and repair

`balance_after` on the newest ledger row is the truth. It is mirrored into the
`ces_coin_balance` user meta purely as a read cache, and
`CES_Wallet::balance()` falls back to a full recomputation whenever that meta
is missing or non-numeric.

`CES_Wallet::recalculate( $user_id )` is the repair path: it re-reads the
newest row, cross-checks it against `SUM(delta)`, logs any drift through
`CES_Helpers::log()`, and rewrites the cache. It is exposed on the admin
screen as **Rebuild balance from ledger**. The ledger itself is never
rewritten — it is append-only.

---

## Referrals

### Codes and links

- Every user gets a code on first request, stored in the `ces_referral_code`
  user meta and generated lazily by `CES_Referral::get_code()`.
- 7 characters from `23456789ABCDEFGHJKMNPQRSTUVWXYZ` — no `0`, `O`, `1`, `I`
  or `L`, so a code read aloud over WhatsApp is unambiguous. Uniqueness is
  checked against user meta, with a user-ID-derived fallback after 12
  collisions. Length is filterable via `ces_referral_code_length`.
- Link: `https://classiceduinfo.com.ng/?ref=CODE` (built from `home_url()`,
  filterable with `ces_referral_link`).

### Attribution

1. `?ref=CODE` on **any** page load is captured into the `ces_ref` cookie for
   30 days. **First-touch**: an existing cookie is never overwritten, so a
   second referrer cannot steal an invite already in flight.
2. At `user_register`, `CES_Referral::on_user_register()` looks for a code in
   `$_POST`/`$_GET` (`ces_referral_code`, `referral_code`, `ref_code`, `ref` —
   filterable with `ces_referral_request_keys`) and then falls back to the
   cookie.
3. A row is inserted into `wp_ces_referrals` with `status = pending`, the
   signup IP, UA and device hash. The cookie is then cleared so the next
   signup from that browser is not attributed for free.

Because attribution hangs off `user_register`, it works with **Profile Builder
Pro**, WooCommerce and the stock WordPress form without touching any
Profile Builder internals. On a Profile Builder site, add a custom field named
`ces_referral_code` to the registration form and it will be picked up
automatically; the plain WordPress form gets the field injected via
`register_form`.

### Qualification — paid on merit, not on signup

A referral is paid only when **all** of these hold:

- (a) the referred account is **verified/confirmed** —
  `CES_Referral::is_account_verified()`; and
- (b) the referred student has completed **at least one CBT attempt** — tracked
  from the `ces_quiz_completed` action into the `ces_completed_attempts` user
  meta, with a query against `wp_ces_attempts` as a fallback for older
  accounts; and
- (c) the fraud screen returns clean.

Verification is deliberately defensive. We look for the signals Profile
Builder leaves in user meta (`wppb_approved`, `wppb_ec_password`,
`wppb_email_confirmation_key`), honour a `ces_email_verified` override, and
treat **"no signal at all" as verified** so a site with no email confirmation
still pays referrals. Override the whole decision with the
`ces_referral_account_verified` filter.

Re-checks happen on `ces_quiz_completed`, on `wp_login` (a confirmation email
is often clicked after the first quiz) and once a day from cron.

On qualification: `coins_per_referral` is credited with the deterministic key
`referral_reward:{referred_id}`, the row flips to `qualified` with
`reward_paid = 1` and a `qualified_at` stamp, and `ces_referral_qualified`
fires. The credit happens **before** `reward_paid` is set, so a crash in
between leaves the ledger correct and the idempotency key blocks a second
payment on retry.

### Milestone bonus

Every `coins_milestone_every` qualified referrals pays
`coins_referral_milestone` coins, keyed
`referral_milestone:{referrer_id}:{count}` so a given milestone pays once.

### Anti-fraud rules

Checked in `CES_Referral::fraud_check()`, which runs twice: immediately at
signup (obvious abuse is rejected before it can ripen) and again before any
payout. A rejection writes the slug into `reject_reason` and sets
`status = rejected`.

| Reason slug | Rule | Tunable |
|---|---|---|
| `self_referral` | Referrer and referred are the same user ID | — |
| `same_email` | Same email after normalisation (lowercased, `+tag` stripped, Gmail dots collapsed, `googlemail.com` → `gmail.com`) | — |
| `disposable_email` | Referred address is on the throwaway-domain blocklist (~25 domains) | `ces_disposable_email_domains` |
| `same_ip_as_referrer` | Signup IP matches the referrer's `ces_signup_ip` or `ces_last_ip` | — |
| `ip_velocity` | More than **3** referral signups from one IP inside **24h** | `ces_referral_ip_limit`, `ces_referral_ip_window` |
| `duplicate_device` | The `device_hash` has already produced a referral signup (limit **1**) | `ces_referral_device_limit` |
| `daily_cap` | The referrer has already been paid for **10** referrals today | `ces_referral_daily_cap` |
| `account_missing` / `referrer_missing` | Either account has been deleted | — |
| `manual` | Rejected by an administrator | — |

`ces_referral_fraud_reason` lets a site add its own rule.

**`daily_cap` is deferrable, not fatal.** It is the one rule that describes a
rate rather than abuse, so the row stays `pending`, the reason is recorded for
the admin to see, and the nightly sweep retries it the next day. Every other
reason is terminal until an administrator approves the row by hand.

The **device hash** is `hash_hmac( 'sha256', device_id|user_agent|accept_language,
stream_secret )`, where `device_id` is a random 32-hex value in the `ces_dev`
cookie (1 year). It is a random first-party identifier, not a browser
fingerprint, and it never leaves the install. The cookie is only set alongside
a referral capture, so ordinary cached page views are unaffected.

---

## Earning paths — `CES_Rewards`

| Reward | Setting | Idempotency key | Notes |
|---|---|---|---|
| Signup bonus | `coins_signup_bonus` | `signup_bonus:{user}` | On `user_register` |
| Daily login | `coins_daily_login` | `daily_login:{user}:{Y-m-d}` | Once per calendar day, site time |
| Streak bonus | `coins_streak_bonus` | `streak_bonus:{user}:{Y-m-d}` | Every 7 consecutive days; resets to 1 on a miss |
| Quiz pass | `coins_per_quiz_pass` | `quiz_pass:{attempt_id}` | On `ces_quiz_completed` with `$passed = true` |

Daily login fires on `wp_login` **and** on `init` for logged-in users, because
a student returning on a remembered cookie never triggers `wp_login`. The day
is recorded in `ces_last_daily_login` **before** the credit is attempted, so a
failed credit cannot be retried into a double payment later the same day.

Quiz coins are capped per day at `coins_quiz_daily_cap`: the remaining
allowance is computed with `CES_Wallet::sum_for_day()` and the award is
trimmed to fit (or skipped once the cap is reached). Set the cap to 0 to
remove it. Streak state lives in `ces_login_streak` and
`ces_login_streak_best`.

---

## Dashboard tabs

Registered through the `ces_dashboard_tabs` filter:

| Slug | Label | Position | Contents |
|---|---|---|---|
| `coins` | My Coins | **10** | Balance hero, streak progress, how-to-earn list, transaction history with a REST-driven "Load more" |
| `referrals` | Invite Friends | **40** | Code, copyable link, WhatsApp share button, milestone progress, list of invited students with status |

Both are phone-first and fully server-rendered; `wallet.js` only enhances.

## Shortcodes

| Shortcode | Attributes | Output |
|---|---|---|
| `[ces_coin_balance]` | `label` (default empty), `link` (`1`/`0`, default `1`) | A coin chip, linked to the My Coins tab |
| `[ces_referral_link]` | `whatsapp` (`1`/`0`, default `1`) | Read-only link input, Copy button, WhatsApp button |
| `[ces_referral_stats]` | — | Three tiles: qualified, pending, coins earned |

Each renders a login link for logged-out visitors.

## REST routes

Namespace `ces/v1`. All read-only, logged-in, nonce-checked (WordPress cookie
auth enforces `X-WP-Nonce`; we re-verify it) and rate limited to 60 requests
per minute per user via `CES_Helpers::rate_limit()`.

| Method | Route | Args | Returns |
|---|---|---|---|
| `GET` | `/wallet/balance` | — | `balance`, `formatted`, `streak` |
| `GET` | `/wallet/history` | `page`, `per_page` (≤100) | `items[]`, `total`, `page`, `per_page`, `total_pages`, `balance` |
| `GET` | `/wallet/referrals` | `page`, `per_page` (≤100) | `stats{}`, `items[]` |

There is deliberately **no write endpoint**. Coins are never minted or spent
from the browser; spending lives in the module that owns the purchase.

---

## Hooks

### Fired by this module

```php
do_action( 'ces_coins_credited',     $user_id, $amount, $type, $new_balance );
do_action( 'ces_coins_debited',      $user_id, $amount, $type, $new_balance );
do_action( 'ces_referral_qualified', $referrer_id, $referred_id );
do_action( 'ces_referral_recorded',  $referrer_id, $referred_id );   // extra: pending row created
do_action( 'ces_referral_rejected',  $row_id, $reason );             // extra
do_action( 'ces_streak_completed',   $user_id, $streak );            // extra
```

### Consumed from other modules

```php
do_action( 'ces_quiz_completed', $user_id, $attempt_id, $score, $passed );
```

`CES_Rewards::on_quiz_completed()` pays the quiz reward; also used by
`CES_Referral` as the "has taken a quiz" signal. `ces_video_completed` is not
consumed — video coins are spent, not earned.

### Filters

`ces_wallet_max_amount`, `ces_referral_code_length`, `ces_referral_link`,
`ces_referral_share_message`, `ces_referral_request_keys`,
`ces_referral_account_verified`, `ces_referral_fraud_reason`,
`ces_referral_ip_limit`, `ces_referral_ip_window`,
`ces_referral_device_limit`, `ces_referral_daily_cap`,
`ces_disposable_email_domains`, `ces_coin_earning_methods`.

Also filtered *into* the shared layers: `ces_dashboard_tabs`,
`ces_admin_submenus`, `ces_overview_cards`, `ces_settings_sections`.

### JS event

```js
document.dispatchEvent( new CustomEvent( 'ces:coins-changed' ) );
```

Any module can fire this after spending coins and every `[data-ces-balance]`
element on the page re-reads the balance from `/wallet/balance`.

---

## Cron

One daily event, `ces_daily_maintenance`, scheduled by this module if nothing
else has scheduled it. `CES_Referral::sweep()` is attached: it retries up to
500 pending referrals from the last 90 days, catching rows that were held by
the daily cap or whose email confirmation arrived after the student's last
page view.

The hook name is deliberately the shared one that `CES_Deactivator` already
unschedules, so deactivation leaves no orphaned event behind.

---

## Settings

Registered via `ces_settings_sections` as **Coin Wallet & Referrals** on
*Classic EDU → Settings*. All keys already exist in `CES_Settings::defaults()`:

`coins_signup_bonus` (20), `coins_per_referral` (25),
`coins_milestone_every` (5), `coins_referral_milestone` (100),
`coins_daily_login` (2), `coins_streak_bonus` (10),
`coins_per_quiz_pass` (5), `coins_quiz_daily_cap` (20).

Setting any amount to `0` disables that reward. `coins_milestone_every = 0`
disables milestones; `coins_quiz_daily_cap = 0` removes the quiz cap.

## Admin screen

*Classic EDU → Coin Wallet*, capability `ces_manage_wallet` (granted to
administrators by `CES_Activator::add_roles()`), menu position 40.

- **Students tab** — search by username, email, nicename or user ID; see
  balance, streak, referral code and referral counts; paginated ledger;
  manual credit/debit with a required note (type `admin_adjust`, `ref_type`
  `admin`, `ref_id` = the acting admin's user ID); **Rebuild balance from
  ledger**.
- **Referral report tab** — filter by status, see the live fraud flag for
  every pending row, the signup IP and the reject reason, and **Approve**
  (override the fraud screen and pay) or **Reject** (`manual`) any row.

Every write goes through `admin-post.php` with a capability check and a
per-row nonce. Manual adjustments intentionally get an auto-generated
idempotency key so an admin can apply the same adjustment twice if they mean
to.

### Overview cards

Contributed to `ces_overview_cards`: **Coins in circulation**
(`SUM(delta)` across the whole ledger) and **Qualified referrals this month**.

---

## Admin setup checklist

1. Activate the plugin (creates the tables, the `ces_manage_wallet` cap and
   the default settings).
2. *Classic EDU → Settings* → **Coin Wallet & Referrals**: set the coin
   amounts. The defaults are sane for launch.
3. Set **Dashboard page** (`dashboard_page_id`) to the page holding
   `[ces_dashboard]`, so the My Coins and Invite Friends tabs are reachable
   and the wallet assets load in the page head.
4. **Profile Builder**: add a text custom field named `ces_referral_code`
   (label e.g. "Referral code — optional") to the registration form. Nothing
   breaks without it; the `ces_ref` cookie still attributes the referral.
5. Put `[ces_coin_balance]` in the header or menu so students always see their
   balance, and `[ces_referral_link]` anywhere you want a quick share button.
6. Confirm `wp-cron` runs (or wire a real cron to `wp-cron.php` — this is
   LiteSpeed shared hosting) so `ces_daily_maintenance` fires and pending
   referrals get swept.

---

## Known limitations / deliberately left for later

- **No coin expiry or decay.** Coins live forever. Adding expiry would need a
  new ledger type and a cron pass; the schema already supports it.
- **No admin bulk operations.** No "credit every student 10 coins" button and
  no CSV export of the ledger. Both are admin conveniences, not correctness.
- **Email-based duplicate-account detection is pairwise only.** We compare the
  referred student's normalised email against the referrer's, not against
  every other account the referrer has invited. Doing that properly means N
  user lookups per check; `duplicate_device` and `ip_velocity` already catch
  the realistic version of this attack.
- **`is_account_verified()` fails open.** With no verification plugin signal
  present, an account counts as verified. That is the right default for a site
  that may turn email confirmation off, but it means condition (a) is only as
  strong as Profile Builder's configuration. Tighten it with
  `ces_referral_account_verified` if needed.
- **The transient spin lock is best-effort.** It only matters on hosts that
  deny `GET_LOCK`, and the idempotency key still prevents double-spending
  there. It is not a correctness guarantee on its own.
- **Disposable-domain list is static.** ~25 hard-coded domains, extensible via
  filter. No remote blocklist lookup, deliberately — no outbound HTTP on a
  registration request.
- **`sweep()` caps at 500 rows per run.** Fine at current scale; it would need
  offset tracking to grow past that.
