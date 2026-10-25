# Deployment Guide — Classic EDU Suite on classiceduinfo.com.ng

Written for: whoever administers the site and its hosting (you, or your developer "Dynasty").

> **Just want it installed?** `INSTALL-START-HERE.md` is the short, plain-language
> version: upload, activate, make four pages, test. Come back here for the video,
> cron and hosting-capacity detail.

Your live stack, as detected on 18 Oct 2026:

| Component | Version / value |
|---|---|
| WordPress | 7.1.3 |
| Server | LiteSpeed (shared hosting) |
| Theme | GeneratePress + GP Premium |
| Builder | Elementor 3.34.1 (+ ElementsKit Lite, Essential Addons, Essential Blocks) |
| Commerce | WooCommerce 10.4.3 |
| Memberships | YITH WC Membership Premium + YITH WC Subscription Premium |
| Accounts | Profile Builder Pro (owns `/login/` and `/password-reset/`) |
| Popups | Popup Builder |
| Other | Contact Form 7, Sassy Social Share, Google Site Kit |

---

## 1. Before you install anything

**Take a full backup.** Database *and* files. This plugin creates six database tables, adds
a user role, and writes into `wp-content/uploads/`. A backup is your undo button.

**Install on staging first, not live.** Your host's cPanel almost certainly has a
"WordPress Manager" or "Softaculous" clone/staging feature. Clone the site to
`staging.classiceduinfo.com.ng`, install there, and only push to live once you have
clicked through a real exam and a real video as a test student.

If you have no staging option, install during your lowest-traffic hours and keep the
backup restore page open in another tab.

---

## 2. Install

1. Build the zip: run `.\build-plugin.ps1` in this folder. It writes
   `dist\classic-edu-suite-1.1.0.zip`.
2. WP Admin → **Plugins → Add New → Upload Plugin** → choose that zip → Install → Activate.
3. On activation the plugin creates its tables, the `EDU Instructor` role, and the private
   media folder `wp-content/uploads/ces-private/` (locked to direct HTTP access).
4. Go to **Classic EDU → Settings** and work down the page.

If activation fails with a fatal error, rename the plugin folder over FTP to
`classic-edu-suite-disabled` to get your admin back, then send me the error from
`wp-content/debug.log`.

### Turn on error logging while testing

In `wp-config.php`, above the `/* That's all */` line:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Errors land in `wp-content/debug.log`. **Turn `WP_DEBUG` back off on live** once you're done.

---

## 3. Pages to create

Create these as normal WordPress pages and put the shortcode in a plain paragraph/HTML
block. Elementor works too — use a "Shortcode" widget.

| Page | Shortcode | Notes |
|---|---|---|
| Student Dashboard | `[ces_dashboard]` | The hub. Set its page ID in Settings → `dashboard_page_id`. |
| CBT Practice | `[ces_exam_list]` | Replaces the current AI-modal CBT. |
| Video Lessons | `[ces_course_list]` | The syllabus video library. |
| Leaderboard | `[ces_cbt_leaderboard]` | Optional, but students love it. |

Then set **Settings → Dashboard page** to the dashboard page you created, and check
**Login page URL** is `/login/` (your Profile Builder page).

Add "Dashboard", "CBT Practice" and "Video Lessons" to your main menu.

---

## 4. Self-hosted video: what your server needs

You chose to host video on your own LiteSpeed account rather than Bunny or Cloudflare.
That works, and the plugin is built for it — but the limits below are real, so plan around
them rather than discovering them during a busy exam week.

### 4a. PHP limits for large uploads

Shared hosts cap uploads well below video size. In cPanel → **MultiPHP INI Editor** (or
ask your host), set at least:

```ini
upload_max_filesize = 512M
post_max_size = 512M
max_execution_time = 600
memory_limit = 512M
max_input_time = 600
```

The plugin's uploader chunks large files to work around a low `upload_max_filesize`, but
raising it still makes life easier. Check the live values in
**Classic EDU → Diagnostics**.

### 4b. ffmpeg (optional but much better)

With ffmpeg available the plugin segments each video into HLS, which means adaptive
quality, faster start, and no single fetchable file. Most shared hosts do **not** provide
ffmpeg and block `shell_exec`.

Ask your host two questions:

> 1. Is the `ffmpeg` binary available to my account, and what is its full path?
> 2. Are `shell_exec` / `proc_open` enabled for my PHP user?

- **Yes to both** → put the path in Settings → `video_ffmpeg_path`. You get full HLS.
- **No** → the plugin falls back to serving the original file through the same protected,
  token-gated endpoint. Still protected, still not downloadable by normal means, but no
  adaptive quality, and students on weak connections will buffer more. In that case, upload
  videos **already compressed** (see 4d).

**Diagnostics tells you which mode you're in.** Check it after activating.

### 4c. Real cron, not WP-Cron

WP-Cron only fires when someone visits the site, which makes video processing unreliable.
In cPanel → **Cron Jobs**, add one entry every 5 minutes:

```
*/5 * * * * cd /home/YOUR_CPANEL_USER/public_html && /usr/local/bin/php wp-cron.php > /dev/null 2>&1
```

Then disable the built-in one in `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

(Replace `YOUR_CPANEL_USER` and confirm the PHP path with your host.)

### 4d. Compress before you upload

This is the single highest-value habit for self-hosted video. Target roughly:

| Lesson length | 480p target | 720p target |
|---|---|---|
| 10 min | ~40–60 MB | ~90–130 MB |
| 30 min | ~120–180 MB | ~270–400 MB |

Record at 720p, export at a 600–1200 kbps bitrate, mono or 64 kbps audio — lecture content
does not need stereo music fidelity. A free tool like HandBrake does this well. Halving
your bitrate halves your bandwidth bill and doubles how many students can stream at once.

### 4e. The honest capacity ceiling

Rough expectations for a typical Nigerian shared-hosting plan. Treat these as estimates to
monitor against, not guarantees — your host's actual limits govern.

- **Disk:** at ~150 MB per 30-minute lesson, 100 lessons ≈ 15 GB. Check your plan's quota
  *before* building a library. This is usually the first wall you hit.
- **Concurrency:** with LiteSpeed internal-redirect streaming working, expect roughly
  20–40 simultaneous viewers. If the plugin has to fall back to PHP-based delivery, that
  collapses to a handful, because each stream occupies a PHP worker. Diagnostics reports
  which path is active — this is the number that matters most.
- **Bandwidth:** "unlimited" shared hosting is never unlimited for video. 500 students
  watching one 150 MB lesson is 75 GB. Read your host's fair-use clause, and watch for
  throttling or suspension warnings.

**When to move to a CDN:** if you pass ~20 GB of video, or ~50 concurrent viewers, or your
host emails you about resource usage. The plugin's storage layer is pluggable — switch
Settings → **Video storage driver** to `bunny` or `cloudflare`, add the API keys, and
re-upload. No code changes, no rebuild. Budget roughly $5–20/month at that stage, which is
far cheaper than the upgraded hosting plan the alternative requires.

---

## 5. What the video protection actually does

Be straight with yourself about this, and with your students:

**Stopped:**
- Right-click → "Save video as" (no fetchable file URL exists)
- Sharing a video link with a non-paying friend (tokens are short-lived, single-user, and optionally IP-bound)
- Download managers and `yt-dlp`-style tools (no static URL to point them at)
- Hotlinking your videos from another site
- Account sharing at scale (concurrent-stream limit)

**Not stopped — and nothing can stop it:**
- **Screen recording.** Any video that plays can be filmed. This is true of Netflix, Udemy
  and every LMS on earth.

The counter-measure is deterrence, not prevention: the per-student watermark overlays the
viewer's name and user ID on the video as it plays, drifting so it can't be cropped out. A
leaked recording identifies who leaked it. Tell students the watermark is there — that
knowledge is most of the deterrent.

---

## 6. Coins, referrals and subscriptions — the model you chose

- **YITH subscribers watch everything free.** No coins involved. This is your upsell: the
  subscription is the frictionless path.
- **Everyone else spends coins** to unlock a lesson for a set window (default 72 hours).
  Re-watching inside that window is free.
- **Coins are earned, never bought** in this configuration: signup bonus, daily login,
  study streaks, passing quizzes, and above all **referrals**.
- **Referrals pay on qualification, not on signup.** A referred student must confirm their
  account and complete one CBT attempt before the referrer is paid. This is deliberate: it
  prevents the fake-account farming that destroys every naive referral scheme, and it means
  every coin paid out corresponds to a student who actually used the site.

Tune the amounts in Settings. Start conservative — it is easy to raise rewards later and
painful to devalue coins students already hold.

### A word on the economics

Watch the ratio between what a referral pays and what a video costs. If a referral pays 25
coins and a lesson costs 10, every successful invite buys 2.5 lessons. That is generous by
design at launch, to seed growth. Review it monthly against your actual bandwidth cost per
lesson — on self-hosted video, every unlocked lesson has a real bandwidth price, so coins
are not free to you.

---

## 7. Giving your team access without making them administrators

The plugin adds an **EDU Instructor** role: it can create questions, courses and lessons,
upload video, and view reports — but cannot install plugins, change site settings, or see
user data it shouldn't. Use it for anyone making syllabus videos. Keep `administrator` for
you alone.

---

## 7b. Gmail sign-in and parent approval

**Google credentials.** Create an OAuth client ID (type *Web application*) at
<https://console.cloud.google.com/apis/credentials>, then:

| Google console field | Value |
|---|---|
| Authorised JavaScript origins | `https://classiceduinfo.com.ng` |
| Authorised redirect URIs | `https://classiceduinfo.com.ng/?ces_oauth=google` |

Paste the client ID and secret into **Classic EDU → Settings → Gmail
Sign-in**. The exact redirect URI for this install is printed at the top of
**Classic EDU → Approvals**; copy it from there rather than typing it, because
a single character's difference gives `redirect_uri_mismatch`.

Only `openid`, `email` and `profile` are requested — the non-sensitive scopes,
which need no Google verification review. Publish the consent screen when you
are ready for real students; while it says *Testing*, only listed accounts can
sign in.

**The approval path costs nothing.** With no WhatsApp Cloud API configured the
student forwards the request to their parent with one tap, and the parent
answers on their own phone. Nothing is sent by the server and nothing is
charged. Configure `parents_wa_*` only if you already hold a WhatsApp Business
account and an approved template.

**Two things to decide before launch.**

- *What is locked until approval* — `practice` leaves browsing open, which is
  what makes students finish the signup. `full` closes everything.
- *Leave existing students alone* — on, every account registered before the
  upgrade stays approved. Off, every one of them is asked. On is almost always
  right mid-term.

**If the gate misfires**, set *What is locked until approval* to **Nothing**.
Access is restored on the next page load while you investigate, and no data is
lost: statuses carry on being recorded.

**Real cron matters here too.** The hourly `ces_accounts_maintenance` event
expires stale requests and sends the one reminder to a silent parent. On a
quiet site WP-Cron may not fire for hours; the server cron in section 4c
covers this event as well.

---

## 7c. Consultant classrooms, live sessions and chat

**What this adds operationally.** Outside tutors uploading video to your disk,
and students talking to each other in rooms somebody has to read. Both are
real commitments, not features you switch on and forget.

**Disk and bandwidth.** A consultant's lesson lands in the same private
storage as yours and counts against the same capacity ceiling in section 4e.
Ten tutors at ten lessons each is a hundred videos. Watch `du -sh` on
`wp-content/uploads/ces-private` and set `classroom_max_classes` with that in
mind.

**The borrowed capability.** A consultant must never hold `ces_manage_lms`,
because `CES_Lms_Access::is_staff()` reads it as free access to every video on
the site. The classroom module therefore lends it for the duration of one of
four `admin-ajax` upload actions only, inside which nothing evaluates video
access, and the uploader's own `edit_post` check keeps them to lessons they
authored. If you audit one thing in this module, audit
`CES_Classroom_Module::lend_upload_cap()`.

**Chat load.** Each open room polls every six seconds by default, and each
poll is one indexed lookup. Thirty students with a room open is roughly five
requests a second — fine for LiteSpeed, but raise `classroom_chat_poll` if you
see load. The live room is harder: `classroom_session_poll` defaults to four
seconds and returns playback, chat, hands and the poll in one response
precisely so a room of thirty is thirty requests per tick rather than a
hundred and twenty.

**Chat growth.** `chat_messages` is pruned hourly to four times
`classroom_chat_history` per room, so it cannot grow without limit. That needs
the hourly `ces_classroom_maintenance` event, which the server cron in section
4c covers.

**Moderation is a duty, not a setting.** `ces_moderate_chat` goes to
administrators and EDU Instructors. Somebody opens *Classic EDU → Chat* daily.
If nobody will, switch `classroom_lobby_enabled` off and keep chat inside
tutors' classes, or switch `classroom_chat_premod` on and release messages by
hand. There are no private messages in the plugin at all, which is what makes
any of this defensible for a site used by minors.

**If chat has to go off in a hurry**, `classroom_chat_enabled` off closes
every room immediately and leaves the messages in place for you to read.

---

## 8. Post-install checklist

Walk through this as a real student, in an incognito window, on a phone:

- [ ] Register a brand-new test account through `/login/`
- [ ] **Continue with Gmail** signs in, and lands on *My account*
- [ ] Starting a paper before approval is refused with an explanation, not an error
- [ ] The parent WhatsApp button opens a correctly addressed, readable message
- [ ] The approval link approves on another device, and the code path works too
- [ ] After approval, practice opens without signing out and back in
- [ ] **Classic EDU → Approvals** shows the whole history, including your own actions
- [ ] A consultant application arrives in **Classic EDU → Consultants** and approving it opens their Studio
- [ ] A consultant can record in the browser, upload, and the video plays for a member
- [ ] A non-member is refused a class lesson at its own URL, not offered it for coins
- [ ] Chat refuses a link, an email address and a phone number
- [ ] Reporting a message three times hides it, and it appears in **Classic EDU → Chat**
- [ ] Automated chat messages show a BOT badge
- [ ] Signup bonus coins appear on the dashboard
- [ ] Take a CBT exam: timer counts, answers autosave, submit scores correctly
- [ ] Result screen shows the right score and explanations
- [ ] Coins were credited for passing
- [ ] Daily quote popup appears once, dismisses, and does not return the same day
- [ ] Referral link copies, and the WhatsApp share button opens WhatsApp
- [ ] A lesson costs coins, unlocks, and plays
- [ ] Watermark shows the test student's name
- [ ] Open the browser's network tab: no plain `.mp4` or `.m3u8` URL is fetchable on its own
- [ ] Copy a stream URL into a fresh incognito window — it must fail
- [ ] A YITH subscriber account watches without spending coins
- [ ] Check `wp-content/debug.log` is free of plugin errors
- [ ] Re-check **Classic EDU → Diagnostics** once real video is uploaded

---

## 9. If something goes wrong

| Symptom | First thing to check |
|---|---|
| White screen after activation | Rename the plugin folder over FTP, then read `debug.log` |
| Video never leaves "processing" | Real cron running? ffmpeg path correct? See Diagnostics |
| Video plays for admin but not students | Access gating + token TTL; check the concurrent-stream limit |
| Buffering for everyone | Internal redirect not active, or bitrate too high — see 4d/4e |
| Coins not credited | Real cron running; check the wallet ledger in the admin |
| Popup never appears | Quote pool empty, or Popup Builder is also firing on that page |
| Students report "insufficient coins" immediately | Signup bonus set to 0, or referral rewards still pending qualification |

---

## 10. Rollback

Deactivating the plugin stops all of it immediately and **keeps every record** — balances,
exam results, courses. Reactivating picks up where you left off.

Deleting the plugin also keeps your data unless you first tick **"Delete all data on
uninstall"** in Settings. That switch is off by default, on purpose: coin balances and exam
history are records a school should not be able to destroy with a careless click.
