# CBT Exam Engine

The computer-based test module: a question bank, exam definitions that draw
from it, and a phone-first exam runner that marks server-side.

Nothing in the browser ever learns a correct answer until the paper has been
submitted, and even then only if the exam allows it. Scoring, the clock and
retake rules are all resolved in PHP.

---

## What it does

**Question bank** — `ces_question` posts. Single answer, multiple answer or
true/false; optional diagram per question and per option; difficulty, marks,
and an explanation shown on the result screen. Options are positional: the
editor stores them in order and letters A, B, C are assigned top-down, so
reordering or deleting a row never detaches a tick from its answer.

**Exams** — `ces_exam` posts. Each exam decides its own duration, question
count, pass mark, shuffle behaviour and attempt limit, falling back to the
global settings where it does not. Questions are picked three ways: by
taxonomy filter, by difficulty, or by an explicit list of ids.

**The runner** — one `[ces_exam]` shortcode. Sticky timer, question palette,
flag-for-review, answer autosave with retry, and a lockdown warning when the
student leaves the tab.

**Results** — per-attempt review with the correct answer and explanation
(when enabled), a student's own history, an optional leaderboard, and an admin
results screen with per-question statistics.

### Three things worth knowing

1. **The clock is the server's.** The runner is handed `expiresAt` as a real
   timestamp plus the server's current time, keeps the difference as an offset,
   and re-syncs that offset from every response. Changing the device clock does
   nothing. A paper whose time ran out while the tab was closed is submitted by
   cron, not by the browser.
2. **Answers survive a dropped connection.** Each answer is queued in
   `localStorage` and retried with backoff, so a reload, a dead battery or
   Nigerian mobile data cutting out mid-paper does not lose work. The save
   indicator in the status bar tells the student the truth about what is held.
3. **Shuffling never breaks marking.** Option letters stay attached to their
   text, and the per-attempt question order is stored with the attempt, so two
   students sitting the same paper in different orders are marked identically.

---

## Shortcodes

| Shortcode | What it renders |
|---|---|
| `[ces_exam]` | The runner. `id="12"` for a specific exam; on a single `ces_exam` page it uses that exam. Shows the intro screen, then the paper, then the result. |
| `[ces_exam_list]` | Available exams as cards. Accepts `subject`, `exam_type`, `year`, `limit`. |
| `[ces_cbt_results]` | The logged-in student's own attempt history. |
| `[ces_cbt_leaderboard]` | Top scores. Accepts `exam`, `limit`, `period`. Honours the `cbt_leaderboard_enabled` setting. |

The student dashboard tab (position 20) is contributed through
`ces_dashboard_tabs`; the dashboard file is not edited.

---

## REST routes

Namespace `ces/v1`, prefix `/cbt`. Browser calls authenticate with the
`X-WP-Nonce` header. Every student route requires a logged-in user and
confirms the attempt belongs to that user.

| Method | Route | Purpose |
|---|---|---|
| GET | `/cbt/exams` | List published, active exams. |
| GET | `/cbt/exams/{id}` | One exam's public configuration. |
| POST | `/cbt/attempts` | Start an attempt. Enforces retake and cooldown rules. |
| GET | `/cbt/attempts/{id}` | Attempt state and remaining time. |
| GET | `/cbt/attempts/{id}/questions` | The paper, **without** correct answers. |
| POST | `/cbt/attempts/{id}/answer` | Save one answer. Idempotent per question. |
| POST | `/cbt/attempts/{id}/flag` | Flag or unflag a question for review. |
| POST | `/cbt/attempts/{id}/submit` | Submit and mark. Fires `ces_quiz_completed`. |
| POST | `/cbt/attempts/{id}/event` | Log a lockdown event (tab blur). |
| GET | `/cbt/attempts/{id}/result` | Score, and the review when revealing is allowed. |
| GET | `/cbt/results` | The caller's own history. |
| GET | `/cbt/leaderboard` | Top scores. |

---

## Hooks

### Fired by this module

```php
// After a paper is marked. The wallet listens and may award coins.
do_action( 'ces_quiz_completed', $user_id, $attempt_id, $score, $passed );

// After an attempt row is created.
do_action( 'ces_cbt_attempt_started', $user_id, $attempt_id, $exam_id );
```

### Filters offered

| Filter | Use |
|---|---|
| `ces_cbt_select_questions` | Replace question selection entirely. Receives the ids and the exam config. |
| `ces_cbt_grade` | Adjust marking. Receives the computed result array. |
| `ces_cbt_reveal_answers` | Decide per attempt whether the review shows correct answers. |
| `ces_cbt_question_payload` | Change what reaches the browser for one question. Correct answers are stripped before this runs; do not add them back. |
| `ces_cbt_allowed_html` | The `wp_kses` allow-list used for question and option bodies. |
| `ces_cbt_math_script_url` | URL of a self-hosted MathJax or KaTeX bundle. Empty by default — the plugin ships no renderer and does not hotlink a CDN. The runner typesets after every render when one is present. |

### Consumed from the shared framework

`ces_dashboard_tabs`, `ces_settings_sections`, `ces_overview_cards`,
`ces_admin_submenus`.

---

## Cron

| Event | Interval | Does |
|---|---|---|
| `ces_expire_stale_attempts` | every 5 minutes (`ces_five_minutes`) | Auto-submits and marks attempts whose clock ran out while nobody was looking. |

Registered on `init`, cleared by `CES_Deactivator`. On a quiet site WP-Cron
only runs when someone visits, so a paper may be marked a few minutes after
its deadline — the recorded time is the deadline, not the sweep.

---

## Settings

All under **Classic EDU → Settings**, section "CBT Exam Engine". Keys live in
`CES_Settings::defaults()`.

| Key | Default | Meaning |
|---|---|---|
| `cbt_default_duration` | 30 | Minutes, when an exam sets none. |
| `cbt_default_questions` | 20 | Question count, when an exam sets none. |
| `cbt_pass_mark` | 50 | Percent. An exam can override. |
| `cbt_shuffle_questions` | on | Each student gets a different order. |
| `cbt_shuffle_options` | on | Option letters stay with their text. True/false is never shuffled. |
| `cbt_show_answers_after` | on | Reveal correct answers on the result screen. Turn off for live mocks. |
| `cbt_allow_retake` | on | When off, one attempt per exam per student. |
| `cbt_retake_cooldown` | 0 | Minutes between attempts. 0 means no wait. |
| `cbt_leaderboard_enabled` | on | Controls the shortcode and its route. |
| `cbt_lockdown_mode` | on | Warn and log on tab-switch. A deterrent, not proctoring. |

Coin rewards for passing (`coins_per_quiz_pass`, `coins_quiz_daily_cap`) belong
to the wallet module and are configured in its section.

---

## Post types, taxonomies and capability

- Post types: `ces_question`, `ces_exam`
- Shared taxonomies: `ces_subject`, `ces_exam_type`, plus `ces_topic` and
  `ces_year` for questions
- Capability: `ces_manage_cbt`, granted to administrators on activation

Exam meta keys are `_ces_exam_*`; question data is stored as structured post
meta. Attempts and answers live in the `wp_ces_attempts` and
`wp_ces_attempt_answers` tables created by the activator.

---

## What an admin has to do

1. **Build the bank.** *Classic EDU → Question Bank → Add New*. Write the
   question, add options, tick the correct one, and tag it with subject, topic
   and year. Tagging is what makes an exam able to draw questions
   automatically, so it is worth doing properly from the start.
2. **Or import.** *Classic EDU → Import / Export* takes CSV or JSON. Download
   the sample CSV first and match its columns; the importer reports the line
   number for every row it rejects rather than failing the whole file.
3. **Create an exam.** *Classic EDU → Exams → Add New*. Set duration, question
   count and pass mark, then choose how questions are picked:
   - **By filter** — pick subject/topic/year/difficulty and a count. The paper
     is drawn fresh for each student, which is what you want for practice.
   - **By id list** — paste specific question ids for a fixed paper, which is
     what you want for a mock everyone must sit identically.
4. **Tick "Active".** An exam that is not active does not appear or start.
5. **Publish a page** containing `[ces_exam_list]`, and make sure the student
   dashboard page exists and is set in the settings.
6. **Check the result screen** by sitting the exam yourself once. If this is a
   live mock, turn `cbt_show_answers_after` off first.

### If something looks wrong

- **An exam will not start** — it is probably not marked active, or the filter
  matches fewer questions than the requested count.
- **Scores look too low** — check the marks per question; scores are reported
  as a percentage of the paper total, not of the question count.
- **A paper is stuck "in progress"** — the sweeper submits it within five
  minutes of expiry, provided WP-Cron is running. The *Results* screen shows
  the attempt's expiry so you can tell the difference between stuck and
  simply not yet swept.
