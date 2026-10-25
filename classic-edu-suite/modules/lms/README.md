# Video LMS

Courses, lessons and protected video playback, with lessons unlocked either by
a subscription, by a free preview, or by spending coins.

The point of this module is that the video is actually protected. No media URL
appears in the page source; every playback URL is a short-lived, signed,
optionally IP-bound token on the site's own streaming endpoint, and the files
live outside the web root in `wp-content/uploads/ces-private/`, which is denied
to direct HTTP requests.

**What this is not:** DRM. Signed expiring links, a concurrent-stream limit and
a visible watermark stop link sharing, hotlinking and download tools. They do
not stop a determined person recording their own screen, and the diagnostics
screen says so plainly rather than implying otherwise.

---

## What it does

**Courses and lessons** — `ces_course` and `ces_lesson` posts. A lesson belongs
to one course; order is the lesson's `menu_order`, set from the course
builder's up/down buttons. Lessons carry a duration, notes, a transcript,
downloadable attachments, a free-preview flag and an optional per-lesson coin
price.

**Chunked upload** — the browser slices the file and each slice is appended
server-side, so the host's `upload_max_filesize` and `post_max_size` stop
mattering. Every chunk is retried with backoff and the server is the authority
on how many bytes it holds, so a connection that drops at 80% resumes instead
of restarting.

**Packaging** — with ffmpeg available, each video becomes an adaptive HLS
ladder (360p/480p/720p by default) on a five-minute cron worker. Without
ffmpeg the single source file is streamed through the same protected,
Range-capable endpoint. That fallback is a real supported mode, not a broken
state: students get one quality instead of a ladder, which costs them more
mobile data.

**Access** — a lesson is watchable when the student is a YITH
member/subscriber, when it is one of the course's free previews, when they have
paid coins for it (for a configurable window), or when they administer the
site. The gate shown otherwise offers the unlock, their balance, and a route to
earn more by inviting friends.

**Progress** — watch time is reported by the player and clamped server-side
against the wall clock. A student who drags the scrubber to the end has a large
furthest-position and almost no watch time, so it does not count. Only genuine
accumulated viewing fires `ces_video_completed` — which matters, because the
wallet pays out on that action.

**Storage drivers** — `local`, `bunny` and `cloudflare` behind one interface.
Each lesson remembers which driver owns its bytes, so switching the setting
sends new uploads elsewhere without orphaning anything already uploaded.

---

## Shortcodes

| Shortcode | What it renders |
|---|---|
| `[ces_course_list]` | Courses as cards, with search and taxonomy filters. Accepts `syllabus`, `level`, `subject`, `per_page`. |
| `[ces_course]` | One course and its lesson list with per-lesson lock state. `id="12"`, or the current course on a single `ces_course` page. |
| `[ces_lesson]` | One lesson's player or its gate. `id="34"`. |
| `[ces_my_courses]` | The logged-in student's courses with progress bars. |

A single `ces_lesson` page appends the player and a single `ces_course` page
appends the lesson list automatically, so neither needs a shortcode. The
dashboard tab (position 30) is contributed through `ces_dashboard_tabs`.

---

## REST routes

Namespace `ces/v1`, prefix `/lms`. Browser calls authenticate with the
`X-WP-Nonce` header; every write requires a logged-in user.

| Method | Route | Purpose |
|---|---|---|
| GET | `/lms/courses` | Paged course list. Accepts `page`, `per_page`, `search`, `syllabus`, `level`, `subject`. |
| GET | `/lms/courses/{id}` | One course with its lessons. |
| GET | `/lms/lessons/{id}` | One lesson with the caller's access state and progress. |
| POST | `/lms/lessons/{id}/unlock` | Spend coins to unlock. Rejected with `ces_insufficient_funds` when the balance is short. |
| POST | `/lms/lessons/{id}/token` | Issue or refresh a playback token. Also touches the concurrency slot — see below. |
| POST | `/lms/lessons/{id}/progress` | One watch heartbeat. Rate-limited and clamped. |
| POST | `/lms/lessons/{id}/release` | Give up a concurrent-stream slot. |
| GET | `/lms/my-courses` | The caller's enrolled courses with progress. |

### The token refresh is also the heartbeat

Playlist tokens are short (`video_token_ttl`, 120s by default) and the player
refreshes at half-life. That same call re-touches the concurrent-stream slot,
so a player that stops refreshing quietly loses its slot. This is why the
refresh timer keeps running while the video is paused, and why a 409 from this
route means "this account is watching somewhere else" rather than a generic
failure.

Segment tokens are deliberately long-lived (token TTL + lesson duration), so a
mid-lesson segment fetch never fails on an expired token.

---

## The streaming endpoint

`/ces-stream/{action}/{lesson}/{file}?t={token}` — falls back to a query string
when the site has no pretty permalinks.

| Action | Serves |
|---|---|
| `p` | An HLS playlist, rewritten so every segment URI is itself signed. |
| `s` | One HLS segment. |
| `f` | The single source file, with Range support (the no-ffmpeg mode). |
| `x` | A fixed 64-byte probe payload, for the delivery test. Self-authenticating; discloses nothing. |

The filename in a source-file URL is ignored: the file served is always the one
recorded against that lesson in post meta, so the URL carries no filesystem
information.

---

## Hooks

### Fired by this module

```php
// Genuine completion, after the server-side watch-time check. The wallet
// listens for this and may award coins.
do_action( 'ces_video_completed', $user_id, $lesson_id );

// A lesson was unlocked with coins.
do_action( 'ces_video_unlocked', $user_id, $lesson_id, $coins_spent );
```

### Filters offered

| Filter | Use |
|---|---|
| `ces_lms_storage_drivers` | Add a storage driver. Map of key => class name; the class must extend `CES_Lms_Storage`. |
| `ces_lms_chunk_size` | Bytes per upload chunk. Derived from the host's PHP limits by default. |
| `ces_lms_max_upload` | Largest single upload in bytes. 2 GB by default, hard-capped at 4 GB. |

### Consumed from the shared framework

`ces_dashboard_tabs`, `ces_settings_sections`, `ces_overview_cards`,
`ces_admin_submenus`.

---

## Cron

| Event | Interval | Does |
|---|---|---|
| `ces_process_video_queue` | every 5 minutes (`ces_five_minutes`) | Packages queued lessons. Holds a 15-minute worker lock so two ticks cannot transcode the same file. |

A lesson is given up on after 3 failed attempts per rendition. On a quiet site
WP-Cron only runs when someone visits; `CES_Lms_Transcode::enqueue()` nudges
cron after an upload so a lesson does not sit for five minutes on an idle site.
The *Transcode Queue* screen can run the worker in the request, which is the
only practical way to see a real ffmpeg error on shared hosting.

---

## Settings

Under **Classic EDU → Settings**, section "Video". Keys live in
`CES_Settings::defaults()`.

| Key | Default | Meaning |
|---|---|---|
| `video_coin_cost` | 10 | Default coins to unlock one lesson. A lesson can override. |
| `video_unlock_hours` | 72 | How long a paid unlock lasts. |
| `video_free_lessons` | 1 | Free preview lessons per course. |
| `subscriber_unlimited` | on | YITH members and subscribers bypass coins entirely. |
| `video_storage_driver` | `local` | `local`, `bunny` or `cloudflare`. Applies to new uploads only. |
| `video_token_ttl` | 120 | Seconds a playlist token stays valid. |
| `video_bind_token_to_ip` | on | Ties a token to the issuing IP. Turn off if students roam between networks mid-lesson. |
| `video_watermark_enabled` | on | Overlay the student's name and id. |
| `video_watermark_text` | `{display_name} · {user_id}` | Supports `{display_name}`, `{user_id}`, `{email}`. |
| `video_concurrent_limit` | 2 | Simultaneous streams per account. |
| `video_hls_segment_seconds` | 6 | HLS segment length. |
| `video_ffmpeg_path` | *(empty)* | Explicit ffmpeg path; empty auto-detects. |
| `video_renditions` | `360p,480p,720p` | The ladder to build. |

Remote driver credentials (`bunny_*`, `cloudflare_*`) are only read when that
driver is selected. No credential is ever sent to the browser.

---

## Post types, taxonomies and capability

- Post types: `ces_course`, `ces_lesson`
- Taxonomies: `ces_syllabus`, `ces_level`, `ces_subject`, `ces_exam_type`
  (the last two shared with the CBT module)
- Capability: `ces_manage_lms`, granted to administrators on activation

Video and lesson meta keys are centralised in `CES_Lms_Meta`. Watch records
live in the `wp_ces_video_views` table created by the activator; this module
creates no tables of its own.

### Video status vocabulary

| Status | Playable | Meaning |
|---|---|---|
| `none` | no | No video uploaded. |
| `queued` / `processing` | no | Waiting for, or inside, the cron worker. |
| `ready` | yes | HLS renditions written. |
| `source_only` | yes | No ffmpeg here; the single file streams directly. |
| `remote` | yes | A remote provider holds and transcodes the media. |
| `failed` | no | Packaging failed; see the error on the lesson or the queue screen. |

---

## Admin screens

| Screen | Slug | For |
|---|---|---|
| Video Library | `ces-lms` | Every lesson with its video status, length and viewer counts. |
| Upload Video | `ces-lms-upload` | Upload to an existing lesson, or create a draft lesson and upload in one pass. |
| Transcode Queue | `ces-lms-queue` | What is waiting, whether a worker is running, retry / remove / run-now. |
| Video Analytics | `ces-lms-analytics` | Viewers, completion rate, average reach, and per-student detail. |
| Video Diagnostics | `ces-lms-diagnostics` | What this host can do, and the delivery test. |

---

## What an admin has to do

1. **Run the diagnostics first.** *Classic EDU → Video Diagnostics*. It tells
   you whether ffmpeg exists, whether PHP may start programs at all, and
   whether the private directory is writable. Knowing this before uploading
   forty lessons saves re-uploading forty lessons.
2. **Run the delivery test, and act on it.** This is the single most important
   step on shared hosting. If delivery is `PHP readfile()`, every student
   watching ties up a PHP worker for the whole video and a class of thirty will
   take the site down. The test requests a known 64-byte file using each
   method and accepts one only when those exact bytes come back — a
   misconfigured `X-Sendfile` returns an empty 200, which looks fine and would
   serve blank video. Pin whichever server-level method passes.
3. **Create a course**, then use the builder box on the course screen to attach
   lessons and order them.
4. **Upload video** from the lesson's own screen or the Upload Video page. Use
   MP4 where you can. MKV is accepted only when ffmpeg is present, because
   without it the file could never play in a browser.
5. **Wait for packaging**, or press *Run the queue now* on the queue screen.
   Lessons show `queued` until the worker reaches them.
6. **Set the price and previews.** Mark one or two lessons per course as free
   previews so a student can see the quality before spending coins, and set a
   per-lesson coin cost where it should differ from the default.
7. **Publish a page** with `[ces_course_list]` and confirm the dashboard page
   is set in the settings.
8. **Watch one lesson as a student** on a phone, with a non-admin account.
   Administrators bypass the gate, so testing as yourself proves nothing about
   what a student sees.

### If something looks wrong

- **"This lesson is not ready to play yet"** — the status is not one of
  `ready`, `source_only` or `remote`. Check the queue screen.
- **Video stalls or never starts** — check the delivery method first. If it was
  pinned to a server-level method that later stopped working, the test will now
  fail on it; re-run it and re-pin.
- **"This account is already watching somewhere else"** — the concurrency
  limit (`video_concurrent_limit`). Slots are released on page exit and expire
  on their own; a student with a crashed tab can wait it out or you can raise
  the limit.
- **Lesson durations are missing** — ffprobe is absent. Type the duration on
  the lesson screen; completion otherwise falls back to how far the student
  actually reached.
- **Uploads fail at the first chunk** — almost always the private directory not
  being writable. The diagnostics screen reports it directly.
