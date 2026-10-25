# Study Hub

The Study section: the tile grid students land on, the resource library,
the past-question browser, study mode and the leaderboard.

It owns no questions of its own. The bank belongs to the CBT module; this
module is the way in to it — browsing by subject and year, practising without
a clock, and seeing where you stand.

---

## What it does

**The hub** — one page, one shortcode. `[ces_study_hub]` with no query string
draws the tiles; `?view=` draws a panel on the same page. One page to create,
nothing to wire up, and a back link that always works.

**Resource library** — `ces_resource` posts: novels, lesson notes, the JAMB
brochure, JAMB / WAEC / NECO syllabuses, scheme of work, past-question PDFs.
A resource is either a file, an external link, or a body of text read on the
site. Lesson notes are usually the last of those, so a student on mobile data
is not made to download a PDF to read two pages.

**Past questions** — exam type, then subject, then year. Each subject card
shows how many questions actually exist behind it, so nobody taps into an
empty paper. From there: study them untimed, or sit a timed CBT paper if an
admin has built one.

**Study mode** — one question at a time, no clock, the answer and the reason
revealed the moment the student commits. Progress is kept in a transient, not
a table, so an abandoned session costs nothing and needs no sweeper.

**Leaderboard** — tabs per exam type, ranked on a student's *best* paper with
a bonus for time they did not need.

### Three things worth knowing

1. **Study mode never shows an answer early.** The question payload sent to
   the browser is the same one the CBT runner uses, stripped of the key. The
   correct answer is returned only in the response to the student's own
   answer, resolved in PHP. Reading the page source reveals nothing.
2. **Ranking rewards the best paper, not the most papers.** `points = best
   score + (unused-time ratio × time weight)`. Somebody with one excellent
   fast paper outranks somebody who ground through forty average ones, and
   two students on the same score are separated by the seconds they handed
   back. The weight is a setting; 15 means a perfect fast paper scores ~115.
3. **A coin unlock on a resource is permanent**, unlike a video unlock. A
   student who spends coins on the Chemistry syllabus has bought it, and the
   unlock is recorded in user meta rather than a table.

---

## Shortcodes

| Shortcode | What it renders |
|---|---|
| `[ces_study_hub]` | The whole Study section: tiles, and every panel below. |
| `[ces_resource_library]` | The library on its own. Accepts `type`, `subject`, `exam_type`, `year`, `limit`, `title`. |
| `[ces_past_questions]` | The browser on its own. |
| `[ces_study]` | The study runner on its own. Accepts `count`. |
| `[ces_leaderboard]` | The board on its own. Accepts `tab`, `period`, `limit`, `title`. |

The student dashboard tab (position 15) is contributed through
`ces_dashboard_tabs`.

### Query arguments the hub reads

`view` · `type` · `resource` · `subject` · `exam_type` · `year` · `auto` ·
`s` · `rpage` · `lb` · `lbp`

---

## REST routes

Namespace `ces/v1`, prefix `/hub`. Every route requires a logged-in student
and confirms the session belongs to them.

| Method | Route | Purpose |
|---|---|---|
| POST | `/hub/study` | Start a session from a set of filters. Returns a session key and a total. |
| GET | `/hub/study/{session}/question?index=` | One question, without its answer. |
| POST | `/hub/study/{session}/answer` | Mark one answer and reveal the result. |
| POST | `/hub/study/{session}/finish` | End the session and return the summary. |

---

## Hooks

### Fired by this module

```php
do_action( 'ces_study_completed', $user_id, $summary );
do_action( 'ces_resource_unlocked', $resource_id, $user_id, $coins );
do_action( 'ces_resource_opened', $resource_id, $user_id );
```

### Filters offered

| Filter | Use |
|---|---|
| `ces_hub_tiles` | Add, remove or reorder the tiles on the hub. |
| `ces_leaderboard_tabs` | Change which exam types get a tab. |

### Consumed from the shared framework

`ces_dashboard_tabs`, `ces_settings_sections`, `ces_overview_cards`,
`ces_admin_submenus`, `ces_base_asset_shortcodes`.

---

## Settings

Under **Classic EDU → Settings**, section "Study Hub".

| Key | Default | Meaning |
|---|---|---|
| `hub_page_id` | 0 | ID of the page holding `[ces_study_hub]`. The dashboard links to it. |
| `hub_study_questions` | 20 | Study session length when the student does not choose. Max 100. |
| `hub_resource_coin_cost` | 5 | Price of a coin-locked resource with no price of its own. |
| `hub_points_time_weight` | 15 | Leaderboard bonus for finishing with the clock unused. |
| `hub_leaderboard_members_only` | off | Hide the board from guests. |

The board also honours the CBT module's `cbt_leaderboard_enabled`: switch that
off and `[ces_leaderboard]` renders nothing.

---

## Post types, taxonomies and capability

- Post type: `ces_resource`
- Taxonomy of its own: `ces_resource_type`
- Shared with CBT: `ces_subject`, `ces_exam_type`, `ces_year`
- Capability: `ces_manage_hub`, granted to administrators and EDU Instructors

No new database tables. Study sessions live in transients, lifetime counters
and resource unlocks in user meta, rankings are computed from
`wp_ces_attempts` and cached for five minutes.

---

## What an admin has to do

1. **Make the page.** *Pages → Add New*, title it "Study", body
   `[ces_study_hub]`, publish. Note its ID from the URL
   (`post=123`) and set **Study page ID** in the settings.
2. **Add resources.** *Classic EDU → Resources → Add New*. Give it a
   **Resource Type** — that is what puts it behind the right tile — and a
   Subject, Exam Type and Year where they apply. Then either choose a file,
   paste an external link, or just write the note in the editor.
3. **Decide who can open it.** Free, signed-in only, coins, or subscribers.
4. **Check the tiles.** A resource type with nothing in it still shows, faded,
   with its description — except past-question papers, which hide until there
   is one.
5. **Tag the question bank properly.** The past-question browser counts
   questions per subject and year; untagged questions are invisible to it
   even though they exist.

### If something looks wrong

- **A tile is faded** — that resource type has no published resources yet.
- **"No questions available"** — questions exist but are not tagged with that
  subject, exam type or year.
- **The leaderboard is empty** — nobody has submitted a CBT attempt yet, or
  `cbt_leaderboard_enabled` is off. Study mode never appears on the board, by
  design: it has no clock to compare.
- **Study mode says the session expired** — sessions last six hours. Starting
  a new one is the fix; nothing is lost but the current run.
