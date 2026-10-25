=== Classic EDU Suite ===
Contributors: classiceduinfo
Tags: cbt, exam, quiz, lms, video, referral, education, jamb, waec, neco, google-login, chat
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gmail sign-in with parent approval, CBT exam engine, study hub and resource library,
protected syllabus video library, school and career guidance, coin wallet with
referrals, parent contact book, and daily motivational quotes. Built for Classic
Edu Info.

== Description ==

Nine modules in one plugin, built to work together:

**Gmail Sign-in & Parent Approval** — Students sign in with the Gmail account already
on their phone: one button, no password, and an email address Google has verified. Before
the site opens up they name a parent or guardian, and that parent has to confirm it is
really them — by tapping a one-page link, or by reading a six-digit code back to their
child. Where the school has no WhatsApp API the student forwards the request themselves
with one tap, which costs nothing and still cannot be forged. Until a parent answers,
practice stays locked. Staff get an approvals queue, a confirm-by-phone button for the
parent who rings the school, flags for one number used by a dozen accounts, and an
append-only record of who approved whom. Parents keep a private progress page with a
switch to stop the messages and a way to withdraw consent altogether.

**Consultant Classrooms** — Tutors from outside the school host their own video lessons
here and share one private link with their own students. A class has a join link, a
six-character code, a roster, a chat room and live sessions. The Studio records straight
from the browser — screen and voice, or screen with the tutor in the corner — and comes
with the tools that make a lesson land: a plan, a teleprompter, chapter markers,
captions generated from pasted text, multiple-choice checkpoints that stop the video,
and a scored quality review. In a live session the whole class watches together with the
tutor driving everyone's player, hands go up, a poll can stop the room, and the register
fills itself. Students also get moderated subject chat rooms — no private messages, no
links or phone numbers, slow mode, reporting, and clearly badged automated helpers that
keep a quiet room from feeling empty.

**CBT Exams** — A real computer-based test engine with a stored question bank organised by
subject, exam type, topic and year. Server-authoritative timing and scoring, answer
autosave that survives a dropped mobile connection, a question palette with flagging, and
a result screen with explanations. Bulk CSV import so past questions can be loaded in
volume rather than typed one at a time.

**Syllabus Videos** — Instructors upload lesson videos that students watch in the browser
but cannot download. Delivery is through short-lived signed tokens with no fetchable media
URL, served by the web server rather than PHP. Per-student watermarking deters screen
recording by identifying the leaker. Storage is pluggable: self-hosted by default, with
Bunny Stream and Cloudflare Stream drivers available by changing one setting.

**Coin Wallet & Referrals** — An append-only coin ledger with idempotent, atomic
transactions. Students earn coins through referrals, signup bonuses, daily login streaks
and passing quizzes, then spend them to unlock lessons. Referrals pay only on
qualification — the referred student must confirm their account and complete a quiz — with
IP, device and velocity checks against fake-account farming.

**Study Hub** — One page holding the whole study section: past questions browsed by exam
type, subject and year; an untimed study mode that marks each answer and explains it as
the student goes; a resource library for novels, lesson notes, syllabuses, the JAMB
brochure and past-question papers, free or unlocked with coins; and a leaderboard ranked
on a student's best paper with a bonus for the time they did not need.

**Guidance** — School Finder, Department Guidance and Career Guide, all tied together by
one "programme" tag so a student can walk from a career to the courses that lead to it,
to the entry requirements, to the schools that offer it. Bulk CSV import for all three.

**Parents & Guardians** — One parent WhatsApp number per student, collected at signup or
on the dashboard and normalised to international format. Staff get a contact book with a
short progress summary per student and a one-click WhatsApp link with the message already
written. Nothing is ever sent automatically.

**Daily Quotes** — An accessible, once-per-day motivational popup that doubles as the
referral nudge, showing each student their invite link and a WhatsApp share button.

Active subscribers bypass coins entirely and watch everything, which makes the
subscription the frictionless path and coins the free alternative.

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload Plugin, then activate.
2. Visit Classic EDU > Settings and configure each section.
3. Create pages containing `[ces_dashboard]`, `[ces_study_hub]`, `[ces_guidance]`,
   `[ces_exam_list]` and `[ces_course_list]`.
4. Set the dashboard page, the Study page ID and the Guidance page ID in Settings so the
   plugin can link to them.
5. For self-hosted video, see DEPLOYMENT.md for the required PHP limits, the real-cron
   entry, and whether ffmpeg is available on your host.

== Frequently Asked Questions ==

= Can students download the videos? =

Not by ordinary means. There is no fetchable file URL, tokens are short-lived and bound to
one student, and download managers have nothing to point at. Screen recording, however,
cannot be prevented by any software — so each stream is watermarked with the viewer's name
and ID, which identifies anyone who leaks a recording.

= Does it need WooCommerce? =

No. It detects YITH Membership and YITH Subscription to grant unlimited video access when
present, and works fine without them using coins alone.

= Does it replace my login page? =

No. It uses your existing Profile Builder login and hooks into normal WordPress
registration.

= What happens to my data if I delete the plugin? =

Nothing is deleted unless you first enable "Delete all data on uninstall" in Settings.
Coin balances and exam results are kept by default.

== Changelog ==

= 1.3.0 =
* New: Consultant Classrooms - an Educational Consultant role, classes with share links
  and join codes, rosters, and a front-end Studio with no wp-admin access.
* New: in-browser lesson recorder (screen, screen + camera inset, or camera), with a
  microphone level meter, a ten-second test, a teleprompter and a frame grab for the cover.
* New: lesson tools - plans, chapter markers, captions from pasted text, checkpoints marked
  server-side, and a scored quality review.
* New: live sessions - synchronised playback driven by the tutor, hands, polls, a
  self-filling register, and an optional Zoom or Meet link.
* New: moderated student chat - class rooms, session rooms and eight open subject rooms,
  with no private messages, links blocked by default, slow mode, reporting and a
  moderation queue.
* New: badged automated helpers that keep a quiet room from feeling empty, plus real
  activity announcements (named in a class, anonymised in the open rooms).
* Two new tables: class_members and chat_messages.

= 1.2.0 =
* New: Gmail sign-in (Google OAuth 2.0 with PKCE) on wp-login.php, the WooCommerce and
  Profile Builder login forms, and the [ces_google_login] shortcode.
* New: parent approval - a student names a parent or guardian, who confirms by tapping a
  link or reading back a six-digit code, before practice unlocks.
* New: Approvals admin screen with a setup checklist, flags, confirm-by-phone, bulk
  actions, CSV export and a per-student audit trail.
* New: a private progress page for each parent, with message opt-out and consent
  withdrawal.
* New: personal-data exporter and eraser for the account and parent details.
* Student dashboard gains a My Account tab showing exactly what is left to do.
* Two new tables: parent_verifications and account_events.

= 1.1.0 =
* New: Study Hub — tile grid, past-question browser, untimed study mode, resource library
  (novels, lesson notes, JAMB/WAEC/NECO syllabuses, JAMB brochure, past-question papers)
  and an exam-type leaderboard with points, best score and time advantage.
* New: Guidance — School Finder, Department Guidance and Career Guide, with CSV import.
* New: Parents & Guardians — parent WhatsApp capture, progress summaries, one-click
  WhatsApp outreach, CSV export, optional WhatsApp Cloud API sending.
* Student dashboard gains a Study tab and a Parent / Guardian tab.

= 1.0.0 =
* Initial release: CBT engine, protected video LMS, coin wallet with referrals, daily quotes.
