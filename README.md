# Classiceduinfo

The WordPress plugin behind [classiceduinfo.com.ng](https://classiceduinfo.com.ng/) —
a CBT practice site for Nigerian students sitting WAEC, NECO and JAMB.

One plugin, nine modules, no build step, no external services beyond the ones
you choose to switch on.

---

## What is in here

| Folder | What it is |
|---|---|
| `classic-edu-suite/` | The plugin itself. This is the part you install. |
| `seed-bank/` | The starter question bank, as JSON and CSV by subject. |
| `tools/question-ingest/` | A Python tool that turns past-paper text into clean question JSON. |
| `web-demo/` | A standalone static CBT demo, deployed on Vercel. |
| `preview/` | A single-file visual preview of the student screens. |
| `build-plugin.ps1` | Packages `classic-edu-suite/` into an installable zip. |
| `deploy-to-vercel.ps1` | Pushes `web-demo/` to Vercel. |

Three guides, written for somebody doing this for the first time:

- **[INSTALL-START-HERE.md](INSTALL-START-HERE.md)** — install it, make the
  pages, switch things on, test it as a student.
- **[DEPLOYMENT.md](DEPLOYMENT.md)** — the server side: PHP limits, ffmpeg,
  real cron, capacity, what the video protection actually does.
- **[CONTRACT.md](CONTRACT.md)** — the internal rules every module follows.
  Read this before changing the code.

## The modules

| Module | What it does |
|---|---|
| **CBT Exams** | A real computer-based test engine. Server-side timing and scoring, autosave that survives a dropped mobile connection, a question palette, explanations on the result screen, CSV import. |
| **Gmail Sign-in & Parent Approval** | Students sign in with Google. Before the site opens up they name a parent or guardian, and that parent confirms — by tapping a link or reading a six-digit code back to their child. |
| **Consultant Classrooms** | Outside tutors host their own video lessons here and share one private link with their own students. Includes an in-browser recorder, lesson plans, chapters, captions, checkpoints, live sessions and class chat. |
| **Syllabus Videos** | Protected streaming: signed short-lived tokens, no fetchable media URL, per-student watermarking. Self-hosted by default, with Bunny and Cloudflare Stream drivers. |
| **Coin Wallet & Referrals** | An append-only, idempotent coin ledger. Earn by referring, signing up, streaks and passing quizzes; spend to unlock lessons. Anti-fraud checks on referrals. |
| **Study Hub** | Past questions by exam, subject and year; untimed study mode that marks and explains as you go; a resource library; a leaderboard. |
| **Guidance** | School Finder, Department Guidance and Career Guide, tied together by one programme tag. |
| **Parents & Guardians** | One parent WhatsApp number per student, normalised, with a ready-written progress update staff send by hand. |
| **Daily Quotes** | A once-a-day motivational popup that doubles as the referral nudge. |

Each module has its own README inside `classic-edu-suite/modules/<name>/`
explaining its shortcodes, settings, hooks, database use and what an admin has
to do.

## Installing it

```powershell
powershell -ExecutionPolicy Bypass -File build-plugin.ps1
```

That writes `dist/classic-edu-suite-<version>.zip`. In WordPress:
**Plugins → Add New → Upload Plugin**, choose the zip, activate. Then follow
[INSTALL-START-HERE.md](INSTALL-START-HERE.md).

The `dist/` folder is not committed — the zip is a build output, and the
command above regenerates it in a few seconds.

## Requirements

- WordPress 6.4+, PHP 7.4+
- MySQL, and a server that can run a real cron job
- ffmpeg is optional but makes self-hosted video much better
- Integrates with WooCommerce, YITH Membership/Subscription and Profile
  Builder where they are present, and works without them

## A note on the question bank

`seed-bank/` and `web-demo/questions.json` contain past examination questions
**with their answers**, and this repository is public — so anybody can read
both. That is fine for a starter bank you compiled yourself; check you are
happy with it before adding anything you would not want copied, and remember
that past papers published by an examination board may carry their own
restrictions.

## Licence

GPL-2.0-or-later, the same as WordPress.
