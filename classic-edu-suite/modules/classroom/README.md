# Classroom: consultants, the video studio, live teaching and chat

A tutor from outside the school hosts their video lessons here, shares one
link with their own students, teaches them live, and talks to them in between.

---

## The four things this module is

### 1. A place for educational consultants

A tutor applies, staff approve, and they get the **Studio** — a dashboard tab,
not wp-admin. In it they make a **class**, which is one group they teach
(“SS3 Physics, Saturday group”), and a class has:

- a **share link** to paste into WhatsApp, and a **six-character code** to
  read out over the phone;
- a lesson list, in the order they want it watched;
- a roster, with who has watched what;
- a chat room;
- live sessions.

The videos are the site's own protected streams: no downloadable file, a
signed short-lived token per playback, and every student's name drifting
across the picture. A consultant's lesson is **not** in the school's library
and cannot be bought with coins — it is refused to non-members at the lesson
page, at the REST routes and at the stream endpoint.

### 2. Tools for making a teaching video worth watching

Giving a tutor an upload button produces forty minutes of someone reading
their notes. So the Studio has, in order:

| Tool | Why it exists |
|---|---|
| **Recorder** | Screen + voice, screen + voice + camera inset, or camera only. In the browser, nothing to install. Records WebM, which this site can stream even with no ffmpeg on the host. |
| **Pre-flight check** | A microphone level meter that says *in words* whether the level is right, a camera preview, a resolution readout, and a ten-second test to play back. Almost every unusable recording is unusable because of audio, and audio is always discovered too late. |
| **Teleprompter** | Scrolls the script from the lesson plan at an adjustable speed, so they look at the camera instead of down at their notes. |
| **Lesson plan** | A fixed shape — one objective, hook, prior knowledge, steps, worked example, check, common mistakes, recap, homework. The objective and recap become the student-facing lesson notes automatically. |
| **Chapters** | Typed `2:15 Solving for x`, one per line, exactly as everyone already writes them on YouTube. A student revising jumps to the bit they need instead of giving up. |
| **Captions** | Paste a `.vtt` or `.srt`, or paste plain text and the Studio spreads the timings across the video. Rough beats absent by a wide margin for a student watching with the sound off. |
| **Checkpoints** | A multiple-choice question pinned to a timestamp. Answers are marked **server-side**; the student's copy never contains the right answer. |
| **Frame grab** | One button turns the current frame into the lesson's cover image. |
| **Quality review** | Eleven weighted checks, a score out of 100 and specific advice. Advisory, never a gate. |

### 3. Group teaching on the site

A **session** is one scheduled lesson of a class. In the room:

- everyone watches the same video at the same moment, with the **tutor
  driving** — their play, pause and seek move every student's player;
- **chat** runs beside it on a laptop, beneath it on a phone;
- **hands** go up, and the tutor sees them as a queue in the order they were
  raised;
- a **poll** can stop the room mid-explanation; vote counts are hidden from
  students until it closes, so nobody just follows the crowd;
- a **register** fills itself from who was actually present, and for how long.

**On real two-way video.** A grid of thirty cameras needs a media server
relaying streams, which cannot run on LiteSpeed shared hosting at any price,
and the services that do it are billed per minute per participant. This module
does not pretend otherwise. What it does instead is the thing that teaches well
on a student's data plan — synchronised one-way video with live chat — and a
session can carry a Zoom or Meet link alongside, so the room is where the
class gathers with the material, the chat and the register while the talking
happens in the call.

Synchronisation is deliberately crude and therefore reliable: the host writes
`{ position, playing, updated }`, and each student corrects their own player
only when it has drifted more than 1.5 seconds. No sockets, no clock
negotiation, and a student who drops out for a minute simply catches up.

### 4. Student chat

Three kinds of room, one table, one read pattern:

- **Class rooms** — the tutor's students, the tutor, and site moderators.
- **Session rooms** — live, for the duration, then a record.
- **The open rooms** — Common Room, Mathematics, English, Physics, Chemistry,
  Biology, exams, careers. Any student who is through the parent-approval gate.

#### The safeguarding defaults, and why

This is a site full of secondary-school students, so the room is built for
that and not for a general social network:

1. **No private messages, anywhere.** There is no DM feature and none is
   planned. Every message a student writes is in a room an adult can read,
   which is the single most effective safeguarding measure available.
2. **No links, emails or phone numbers** (`classroom_chat_allow_links` off by
   default). Getting a child to move the conversation somewhere unwatched is
   how most harm starts.
3. **Slow mode**, five seconds, which stops both flooding and the pile-on a
   bored class produces.
4. **Report on every message.** Three reports hide it immediately and queue it
   for a moderator — blunt on purpose.
5. **A blocklist** that folds `sh1t` and `f*ck` onto their plain spellings,
   extendable by the school, plus optional full pre-moderation.
6. **Shouting is softened**, not refused: a long all-caps message is written
   back as a sentence.
7. **Display names only.** No avatars from outside, no profile pages.

#### Keeping a room from feeling empty — honestly

An empty chat room kills itself: a student looks in, sees nothing, never comes
back. So when a room has been quiet for a few minutes **and somebody is
looking at it**, this module posts something worth answering. Two sources:

- **Automated helpers** — several named voices (Ada, Tunde, Ngozi, Musa) with
  different jobs: one asks subject questions, one gives study tips, one
  encourages, one gives exam facts. They talk to the room and to each other,
  so a student arriving at the Physics room finds a conversation going rather
  than a blank screen.
- **Real activity** — true things that have just happened. A real score, a
  real student joining. Named inside a class, where everyone knows each other;
  **anonymised** in the open rooms (“A student in SS 3 just scored 88%…”),
  because a child's exam score is not for a room of strangers.

**Every automated message carries a BOT badge beside the name, every time.**
That is not configurable, and the reason is worth stating plainly: these are
children. A student who believes they are talking to a classmate will say
things to “them” they would not say to a machine, and will feel cheated when
they find out — and they do find out. A labelled study bot that asks good
questions makes a room lively; an unlabelled one makes the whole site
untrustworthy the first time somebody notices. The badge costs nothing and the
liveliness is identical.

Nothing runs on a timer: the work happens when a room is read, so a room
nobody is in stays silent and costs nothing. The hourly ceiling per room
defaults to six.

---

## Shortcodes

| Shortcode | What it renders |
|---|---|
| `[ces_class]` | A class page. Members get the lessons; everyone else gets one button. Accepts `id`. Also injected automatically into a class's own URL. |
| `[ces_class_join]` | The six-character code box. |
| `[ces_my_classes]` | A student's classes, anything live right now, and the code box. |
| `[ces_chat]` | The open rooms with a room switcher. Accepts `room`. |
| `[ces_live_room]` | A session room. Accepts `session`. Injected automatically into a session's URL. |
| `[ces_studio]` | The consultant workspace. |
| `[ces_consultant_apply]` | The application form. |
| `[ces_consultant_directory]` | Approved tutors and their public classes. |

Dashboard tabs contributed through `ces_dashboard_tabs`: **Studio** (position
5, with a waiting count), **My classes** (25, shows LIVE when a session is
running) and **Chat** (55).

---

## Where the data lives

### Post types

- **`ces_class`** — one tutor's group. Publicly queryable so `/class/<slug>/`
  is a shareable link, but the page itself is guarded.
- **`ces_session`** — one scheduled live lesson, at `/live/<slug>/`.

Both hang their capabilities off `ces_class` / `ces_classes`. The Educational
Consultant role carries those **without** the `*_others_*` variants, so
WordPress's own `map_meta_cap()` confines a tutor to their own classes — there
is no ownership check of ours to get wrong.

### Tables

Both created by `CES_Activator`, like every other table in the plugin.

- **`ces_class_members`** — `class_id, user_id, role, status, source,
  lessons_done, minutes_watched, joined_at, approved_at, last_seen_at, meta`,
  unique on `(class_id, user_id)`.
- **`ces_chat_messages`** — `room, user_id, author_kind, author_name, body,
  status, reply_to, flags, ip_address, created_at`, indexed on `(room, id)`.

Rooms are strings: `class:12`, `session:34`, `lobby:physics`.

### Meta

Class: `_ces_class_code`, `_ces_class_visibility`, `_ces_class_approval`,
`_ces_class_chat`, `_ces_class_lessons`, `_ces_class_members`,
`_ces_class_welcome`, `_ces_class_meet_url`, `_ces_class_archived`.

Session: `_ces_session_class`, `_ces_session_lesson`, `_ces_session_start`,
`_ces_session_minutes`, `_ces_session_state`, `_ces_session_playback`,
`_ces_session_hands`, `_ces_session_poll`, `_ces_session_attendance`.

On an LMS lesson: `_ces_lesson_class`, `_ces_lesson_chapters`,
`_ces_lesson_checkpoints`, `_ces_lesson_plan`, `_ces_lesson_vtt`.

Consultant: `ces_consultant_status`, `_name`, `_bio`, `_subjects`, `_org`,
`_phone`, `_site`, `_experience`, `_reason`, `_class_limit`.

---

## How it reuses the rest of the plugin

Nothing here reimplements video. In particular:

- **Access** is granted by writing the LMS's own `video_views` row with
  `access_source = class`, refreshed when a member opens a lesson. The tutor
  gets the LMS's watch analytics for free, because those are the rows it
  already counts.
- **Upload** posts to the LMS's chunked, resumable uploader — which sniffs the
  real file type, picks the storage driver and queues the HLS packaging. The
  one wrinkle: that uploader is gated on `ces_manage_lms`, which also means
  “may watch every video free”. So the module **lends** that capability for
  the duration of one of four admin-ajax upload actions only, where nothing
  evaluates video access, and the uploader's own `edit_post` check confines
  the tutor to their own lessons. See `CES_Classroom_Module::lend_upload_cap()`.
- **The player** in a live room is `CES_Lms_Player::render()`, unmodified.
- **Emails** use the accounts module's `CES_Accounts_Notify` shell.
- **Consultant decisions** are written to the accounts module's audit trail.
- **Phone numbers** are normalised by `CES_Parent_Contact::normalize_phone()`.
- **Consultants are exempt** from the parent-approval gate, through the
  published `ces_accounts_blocked` filter.

---

## Settings

Five sections under **Classic EDU → Settings**: *Consultant Classrooms*,
*Video Studio*, *Live Sessions*, *Student Chat* and *Chat Liveliness*. The ones
worth thinking about before launch:

| Key | Default | Why it matters |
|---|---|---|
| `classroom_auto_approve` | off | Leave it off. A consultant uploads video children watch and runs a chat room with a roster of them. |
| `classroom_require_approval` | on | Share links get forwarded. This is what stops a forwarded link filling somebody's class with strangers. |
| `classroom_chat_allow_links` | off | Leave it off. |
| `classroom_chat_premod` | off | Safest and slowest. Students see their own held message; nobody else does. |
| `classroom_session_sync` | on | Off, students watch at their own pace with the chat still shared. |
| `classroom_crowd_enabled` | on | Automated helpers, always badged. |
| `classroom_recorder_minutes` | 30 | The browser holds the recording in memory. Thirty is generous; a lesson should be shorter anyway. |

---

## REST

Three prefixes inside `ces/v1`, because they are read at very different rates.

```
POST   /class/classes                         create a class
POST   /class/classes/<id>                    update one
POST   /class/classes/<id>/lessons            set the lesson order
GET    /class/classes/<id>/roster             the roster
POST   /class/classes/<id>/members/<user>     approve | block | remove
POST   /class/join                            join by code
POST   /class/classes/<id>/leave              leave

POST   /class/lessons                         create a draft lesson
POST   /class/lessons/<id>/plan               save the plan
POST   /class/lessons/<id>/chapters           save chapters
POST   /class/lessons/<id>/captions           save captions
POST   /class/lessons/<id>/checkpoints        save checkpoints
POST   /class/lessons/<id>/publish            publish | unpublish
POST   /class/lessons/<id>/thumbnail          a captured frame
POST   /class/lessons/<id>/delete             trash it
GET    /class/lessons/<id>/review             the quality review
GET    /class/lessons/<id>/watch              chapters, captions, checkpoints
POST   /class/lessons/<id>/answer             mark a checkpoint

GET    /chat/messages?room=&after=            everything after message N
POST   /chat/messages                         say something
POST   /chat/messages/<id>/report             report it
POST   /chat/messages/<id>/moderate           moderators only

POST   /session                               schedule one
GET    /session/<id>/state?after=&secs=       the room, in one request
POST   /session/<id>/start | /end             host only
POST   /session/<id>/playback                 host only
POST   /session/<id>/hand | /clear-hands
POST   /session/<id>/poll | /vote
```

## Hooks

```php
do_action( 'ces_class_joined', $class_id, $user_id, $status );
do_action( 'ces_class_approved', $class_id, $user_id );
do_action( 'ces_session_started', $session_id, $class_id );
do_action( 'ces_session_ended', $session_id );
do_action( 'ces_chat_posted', $message_id, $room, $user_id );
do_action( 'ces_consultant_status_changed', $user_id, $status );
do_action( 'ces_consultant_approved', $user_id );
do_action( 'ces_consultant_refused', $user_id, $status, $reason );

apply_filters( 'ces_chat_lobbies', $rooms );
apply_filters( 'ces_chat_blocklist', $words );
apply_filters( 'ces_chat_personas', $personas );     // the badge is not optional
apply_filters( 'ces_chat_crowd_lines', $lines, $voice, $subject, $room );
apply_filters( 'ces_lesson_review_checks', $checks, $lesson_id );
```

## Cron

`ces_classroom_maintenance`, hourly: prunes each room back to four times the
history setting, and sends the one reminder for sessions starting within the
hour.

---

## What an admin has to do

1. **Switch it on.** *Classic EDU → Settings → Consultant Classrooms*.
2. **Leave auto-approve off** and work *Classic EDU → Consultants*. The
   application gives you their name, phone, subjects, organisation and what
   they wrote about themselves.
3. **Decide about the open chat rooms.** `classroom_lobby_enabled` on gives
   every approved student eight subject rooms. Off, chat exists only inside a
   tutor's class, which is a much smaller thing to watch.
4. **Appoint a moderator.** `ces_moderate_chat` goes to administrators and EDU
   Instructors. Somebody should open *Classic EDU → Chat* daily; the menu
   carries a count when anything is waiting.
5. **Optionally make two pages** — one with `[ces_consultant_directory]` so
   students can find tutors, one with `[ces_consultant_apply]` to link from
   your marketing.

### What a consultant does

1. Dashboard → **Teach here** → apply.
2. Once approved, **Studio** → create a class.
3. Add a lesson: write the plan first, then press *Check my microphone*,
   then record. Or upload a file they already have.
4. Add chapters and captions, one checkpoint, and press Publish.
5. Copy the join link, send it on WhatsApp, and let students in as they ask.
6. Schedule a session for Saturday and teach the class together.

### If something looks wrong

- **The recorder says the browser cannot record** — Safari's MediaRecorder
  support is partial and screen capture needs Chrome, Edge or Firefox on a
  computer. Record on a phone and use the Upload tab instead.
- **The video uploaded but will not play** — it is being packaged. The lesson
  shows its status; large files take a few minutes, and with no ffmpeg on the
  host only browser-playable formats work (MP4, MOV, WebM, M4V).
- **Students see “this lesson belongs to a tutor's class”** — they are not in
  the roster, or they are still waiting to be let in.
- **The room's video will not start for students** — browsers refuse to
  autoplay. The room shows a *Tap to join the lesson* button; after one tap
  every later sync is silent.
- **Nothing happens in a new chat room** — automated helpers only speak after
  the quiet period and only when someone is reading. Check
  `classroom_crowd_enabled` and that the hourly ceiling is not zero.
- **A student says a bot was pretending to be a classmate** — it was not: the
  BOT badge is rendered from the message's own author kind and cannot be
  switched off. Show them the badge, and the rules panel under the chat.
