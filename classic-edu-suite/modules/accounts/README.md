# Accounts: Gmail sign-in & parent approval

Students sign in with the Gmail account already on their phone. Before the
site opens up, they name a parent or guardian — and that parent has to
confirm it is really them.

---

## What it does

**Sign in with Gmail.** One button, no password. OAuth 2.0 authorization code
flow with PKCE; the token exchange is a server-to-server call carrying the
client secret, and the `id_token` is checked for audience, issuer, expiry and
`email_verified` before anybody is signed in. The verified email address is
what makes everything downstream possible.

**Ask for a parent.** A new student lands on a two-step screen: who they are
(name, class, optionally date of birth and their own number) and who their
parent is (name, relationship, WhatsApp number, optionally an email address),
plus the consent tick. The parent fields reuse `CES_Parent_Contact`, so there
is one phone normaliser and one set of validation rules in the plugin.

**Ask the parent.** The parent gets a message with two ways to answer:

1. **Tap the link** — a page showing their ward's name, what the school will
   send them, and two buttons: *Yes, this is my child* and *No, I do not know
   this person*.
2. **Read back the code** — six digits the parent reads to the student, who
   types them on their own screen. This is the path that works when a parent
   will not tap links, which is more parents than you would hope.

**Get the message there.** In order of what is configured:

| Channel | When it is used |
|---|---|
| WhatsApp Cloud API | Where the school has one (`parents_wa_*`). |
| Email | Where the student gave a parent email address. |
| **The student forwards it** | **The default.** We hand them a WhatsApp link already addressed to the parent with the message written. One tap, costs nothing, needs no API approval. |

### What the parent's answer actually proves

Be clear about this before you rely on it, because it changes with how the
message got there. The module records which, shows it in the queue, and says
so on each student's detail screen.

**Delivered by us** (WhatsApp Cloud API, or a parent email address): the link
and the code never pass through the student's hands, so an answer really does
come from whoever holds that number or inbox. This is proof.

**Forwarded by the student** (the default, and what happens with no API and no
parent email): the student can read the link and the code, because they are
carrying the message. A determined student can therefore open their own link
and approve themselves. This is a *declaration*, not a proof.

The courier path is still worth having, and is the right default for most
schools:

- it asks for a real parent with a real number, in front of the student;
- it puts that number on file where staff can ring it;
- it makes involving the parent the obvious path rather than an obstacle to
  work around;
- a parent who did not agree finds out, because the message lands on their
  phone either way.

If you need the stronger guarantee, you have three options, and they stack:

1. **`accounts_review_couriered`** — a couriered approval is flagged
   `weak_proof` and waits for staff instead of opening the account.
2. **`accounts_require_admin_review`** — every approval waits for staff.
3. **Configure the WhatsApp Cloud API** (`parents_wa_*`), or make the parent
   email address required, and every request becomes a delivered one.

**Hold the account until then.** The gate is built entirely from filters the
other modules already publish — no file outside this module is touched:

| Hook | What it closes |
|---|---|
| `rest_pre_dispatch` | The calls that start an exam, open a study session or unlock a video. |
| `do_shortcode_tag` | The shortcodes that serve content, replaced with an explanation. |
| `parse_request` | The signed video stream endpoint. |
| `ces_dashboard_tabs` | Every tab except Overview, the approval tab and Parent / Guardian. |

**Afterwards, give the parent something.** Approving issues them a permanent,
revocable link to a progress page — papers sat, average, best, strongest and
weakest subject — with a switch to stop the messages and a way to withdraw
consent, which closes the account and deletes their number.

### Five things worth knowing

1. **Staff are never gated.** Anyone who can `manage_options`,
   `edit_others_posts` or any `ces_manage_*` capability is always treated as
   approved. An administrator cannot lock themselves out by switching this on.
2. **Existing students are left alone.** Accounts registered before this
   module first ran stay approved. Switch `accounts_grandfather_existing` off
   to put every student through the process, including the ones already
   studying.
3. **Browsing stays open at the default level.** A student can see the exams,
   the courses and the leaderboards and start none of them, which is the best
   argument for finishing the signup.
4. **No JavaScript is required anywhere.** Every form posts to
   `admin-post.php` and comes back with its errors. The student most likely to
   be stuck here is the one on the worst phone.
5. **Every decision is a POST.** WhatsApp, Gmail and most link scanners fetch
   a URL to build a preview; a GET that approved an account would mean
   WhatsApp's crawler approving students.

---

## Setting up Gmail sign-in

1. Go to **Google Cloud Console → APIs & Services → Credentials**, and create
   an **OAuth client ID** of type *Web application*.
2. Under **Authorised JavaScript origins**, add your home page URL
   (`https://example.com`).
3. Under **Authorised redirect URIs**, add the URI printed at the top of
   **Classic EDU → Approvals**, exactly as shown:
   `https://example.com/?ces_oauth=google`
4. Fill in the **OAuth consent screen**: app name, support email, logo, and
   the privacy policy and terms URLs. While it is in *Testing* only accounts
   you list can sign in, so publish it when you are ready.
5. Paste the client ID and secret into **Classic EDU → Settings → Gmail
   Sign-in**, and tick *Let students sign in with Gmail*.

Only the `openid`, `email` and `profile` scopes are requested, which is the
non-sensitive set — no Google verification review is needed for them.

---

## Shortcodes

| Shortcode | What it renders |
|---|---|
| `[ces_google_login]` | The "Continue with Gmail" button. Accepts `label`, `redirect_to`, `note`. Renders nothing for a signed-in visitor or when Gmail sign-in is off. |
| `[ces_account_setup]` | Whichever onboarding step the signed-in student is on. The dashboard tab uses this. |
| `[ces_parent_portal]` | The parent's approval and progress page, for the page whose ID goes in `accounts_portal_page_id`. |

The button is also added automatically to `wp-login.php`, the WooCommerce
login form, the page set as `login_page_url` (which is how it reaches Profile
Builder's form) and the `[ces_login_required]` gate — switch
`accounts_inject_login_button` off to place it by hand instead.

The student dashboard tab is contributed through `ces_dashboard_tabs`. It sits
at position 1 with a warning icon until the student is approved, then drops to
60 and becomes a record of who confirmed them and when.

---

## Where the data lives

### User meta, on the student

| Key | Holds |
|---|---|
| `ces_account_status` | `onboarding` · `awaiting_parent` · `awaiting_admin` · `approved` · `declined` · `suspended` |
| `ces_account_status_at` | When it last changed |
| `ces_account_approved_at` / `_by` | When, and which member of staff (0 = the parent) |
| `ces_account_decline_reason` | Shown back to the student |
| `ces_parent_verified_at` / `_channel` | When the parent confirmed, and how |
| `ces_account_flags` | Review flags, an array |
| `ces_student_class` · `ces_student_dob` · `ces_student_phone` | What the student told us |
| `ces_login_provider` | `google` or `password` |
| `ces_google_id` · `ces_google_avatar` · `ces_google_linked_at` | The Google link |
| `ces_parent_email` | Parent email, when given |
| `ces_parent_approval_token` | Plain approval token, deleted on decision |
| `ces_parent_portal_token` | The parent's progress link, until revoked |
| `ces_parent_opt_out` | The parent asked for no more messages |

The parent's name, number, relationship and consent live in the parents
module's own meta keys and are read and written only through
`CES_Parent_Contact`.

### Tables

Both created by `CES_Activator`, like every other table in the plugin.

- **`ces_parent_verifications`** — one row per request. `user_id, channel,
  destination, code_hash, token_hash, status, attempts, send_count,
  decided_by, decline_reason, ip_address, created_at, expires_at,
  last_sent_at, reminded_at, decided_at, meta`
- **`ces_account_events`** — append-only audit trail. `user_id, actor_id,
  event, channel, detail, ip_address, user_agent, created_at`

### What is hashed and what is not

- The **six-digit code** is stored only as an HMAC hash, keyed with the
  per-install `ces_stream_secret`. The plain code exists for the length of one
  request and is written nowhere. That is also why a resend issues a *new*
  code: the old one cannot be recovered to compare against.
- The **approval link's token** is a bearer credential, like a password-reset
  link. Its hash is what the table is searched by, and the plain token sits in
  the student's own user meta so the "send to my parent" button survives a
  page reload. It is deleted the moment the parent decides.

---

## Settings

**Classic EDU → Settings**, sections "Gmail Sign-in" and "Parent Approval".

### Gmail Sign-in

| Key | Default | Meaning |
|---|---|---|
| `accounts_google_enabled` | off | Master switch. Needs the ID and secret. |
| `accounts_google_client_id` | empty | From Google Cloud Console. |
| `accounts_google_client_secret` | empty | Server-side only, never printed. |
| `accounts_button_label` | Continue with Gmail | |
| `accounts_inject_login_button` | on | Add it to the login forms automatically. |
| `accounts_google_create_users` | on | Create an account on first sign-in, even with WordPress registration closed. |
| `accounts_google_link_existing` | on | Match a Gmail address to an existing account. |
| `accounts_google_only` | off | Refuse password logins for Gmail accounts. Staff keep theirs. |
| `accounts_google_avatar` | on | Use the Google profile picture. |
| `accounts_require_gmail` | off | Only `@gmail.com` / `@googlemail.com`. |
| `accounts_allowed_domains` | empty | Comma separated allow-list. Empty = any Google account. |
| `accounts_default_role` | subscriber | A role that can manage the site is refused. |

### Parent Approval

| Key | Default | Meaning |
|---|---|---|
| `accounts_approval_enabled` | on | Master switch. |
| `accounts_gate_level` | practice | `none` · `practice` · `full` |
| `accounts_grandfather_existing` | on | Accounts older than this module stay approved. |
| `accounts_require_admin_review` | off | A parent's yes queues the account instead of opening it. |
| `accounts_review_couriered` | off | Flag an approval the student carried themselves, so it waits for staff. See *what the parent's answer actually proves*. |
| `accounts_grace_minutes` | 0 | Look-around time for a brand new student. |
| `accounts_class_required` | on | Ask for the class. |
| `accounts_dob_required` | off | Ask for the date of birth. |
| `accounts_minor_age` | 18 | Under this, the account always waits for staff. |
| `accounts_parent_email_enabled` | on | Also ask for a parent email address. |
| `accounts_code_ttl_minutes` | 60 | How long the code lasts. |
| `accounts_link_ttl_hours` | 72 | How long the link lasts. |
| `accounts_code_max_attempts` | 6 | Tries at the code before a resend is needed. |
| `accounts_resend_cooldown` | 120 | Seconds between sends. |
| `accounts_resend_daily_cap` | 6 | Sends per student per day. |
| `accounts_reminder_hours` | 24 | One reminder only, ever. 0 = off. |
| `accounts_auto_decline_days` | 0 | Give up on an unfinished signup. 0 = never. |
| `accounts_max_per_phone` | 3 | Flag a parent number used by more accounts than this. |
| `accounts_notify_admin` | on | Email staff when an account needs review. |
| `accounts_notify_admin_email` | empty | Empty = the site admin address. |
| `accounts_portal_page_id` | 0 | Page carrying `[ces_parent_portal]`. 0 = plugin prints its own plain page. |
| `accounts_terms_url` | empty | Set it and students must tick to accept. |
| `accounts_privacy_url` | empty | Linked from the signup form. |

---

## Hooks

```php
// Status.
do_action( 'ces_account_status_changed', $user_id, $status, $before );
do_action( 'ces_parent_verified', $user_id, $channel );
do_action( 'ces_student_approved', $user_id, $actor_id );   // $actor_id 0 = the parent
do_action( 'ces_student_declined', $user_id, $reason, $actor_id );

// Decisions you can override.
apply_filters( 'ces_accounts_allow_login', true, $user, $profile );
apply_filters( 'ces_accounts_blocked', true, $user_id );
apply_filters( 'ces_accounts_gated_shortcodes', $tags, $level );
apply_filters( 'ces_accounts_allowed_tabs', $slugs );
apply_filters( 'ces_accounts_onboarding_errors', $errors, $input );

// Content.
apply_filters( 'ces_accounts_parent_message', $text, $user_id, $link );
apply_filters( 'ces_accounts_class_options', $options );
```

## Cron

`ces_accounts_maintenance`, hourly. Expires requests past their time, sends
the one reminder to a silent parent (or nudges the student where we cannot
reach the parent ourselves), and auto-declines abandoned signups where
`accounts_auto_decline_days` asks for it.

## REST

None of its own. It only guards other modules' routes, which is why it
registers nothing: a module that adds no surface cannot be attacked through
one.

## Privacy

Registers a WordPress personal-data **exporter** and **eraser** against the
student's account, covering the account status, the class and date of birth,
the parent's contact details and the readable history. The eraser deletes the
parent's details and every request, and resets the student to `onboarding` —
because an approval resting on a number we no longer hold is not an approval.
The audit trail itself survives, without the number, and goes when the account
is deleted.

---

## What an admin has to do

1. **Set up the Google client** (above), then switch Gmail sign-in on.
2. **Check the Setup panel** at the top of *Classic EDU → Approvals*. It
   answers "is this configured" in four lines and prints the redirect URI to
   paste into Google.
3. **Decide the gate level.** `practice` is the recommendation. Leave
   *Leave existing students alone* on unless you really do want to chase every
   student you already have.
4. **Optionally make the parent page.** A page containing
   `[ces_parent_portal]`, its ID in the settings, and the parent's approval
   page wears your theme instead of the plain built-in one.
5. **Work the queue.** *Classic EDU → Approvals*, filter to "Everyone still
   waiting". Most rows need nothing from you — the parent answers and the
   account opens itself. The ones that stall need one of:
   - **Ask again** — a fresh code and link.
   - **Open WhatsApp to this parent** — the request written for you; read it,
     then send.
   - **Confirm by phone** — for the commonest real case, a parent who rang the
     school. Put what you did in the note box; it goes in the audit trail
     against your name.
   - **Start over** — for a mistyped number.
6. **Watch the flagged list.** One number on fifteen accounts, a student whose
   "parent" number is their own, a number that has refused somebody before.
   Siblings are normal; fifteen accounts are not.
7. **Treat the CSV as confidential.** It is other people's phone numbers.
   Download it when you need it, keep it off shared drives, delete it after.

### If something looks wrong

- **The Gmail button does not appear** — the client ID or secret is missing,
  or Gmail sign-in is off. The Setup panel says which.
- **"That sign-in was issued for a different website"** — the client ID in the
  settings is not the one that issued the sign-in. Check you pasted the whole
  thing, ending `.apps.googleusercontent.com`.
- **Google says `redirect_uri_mismatch`** — the URI in the Google console is
  not character-for-character the one in the Setup panel. `http` vs `https`
  and a missing or extra `www` both count.
- **A parent never gets the message** — with no WhatsApp API and no parent
  email, nothing is sent by us at all: the student forwards it. Check they
  pressed the green button, and that the number is the one WhatsApp is on.
- **"That code is not right" on a code the parent just read out** — a resend
  was pressed in between, and only the newest message works. Press *Ask
  again* and have them use the new one.
- **A student is stuck on "waiting for staff review"** — either
  `accounts_require_admin_review` is on, or the account is flagged. Both land
  in the Approvals queue.
- **Everyone is suddenly locked out after an upgrade** — the schema upgrade
  runs on the first `admin_init` after the files change. Load any admin page
  once. If the gate is catching students it should not,
  `accounts_gate_level` → *Nothing* restores access immediately while you
  look.
