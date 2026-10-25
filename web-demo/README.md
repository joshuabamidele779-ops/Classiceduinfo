# Web Demo — Classic EDU CBT

A static mock-exam page that runs the real seed question bank. Pick a subject,
sit a 10, 20 or 40 question paper against a timer, submit, and read every
explanation.

It exists so the question bank and the exam flow can be checked in a browser
without a WordPress install. **It is not the plugin** and never ships in the
plugin zip.

```
web-demo/
  index.html       the whole app — no build step, no dependencies
  questions.json   a copy of seed-bank/out/all-questions.json (650 items)
  vercel.json      clean URLs and a cache header
```

---

## Current deployment

**Live:** <https://web-demo-two-pi.vercel.app>

- Vercel project `joshuabamidele779-7448/web-demo`, deployed with the CLI.
- **Deployment Protection is on**, so only the signed-in Vercel account owner
  can open that URL. To let anyone else see it: Vercel dashboard → the project →
  Settings → Deployment Protection → turn off Vercel Authentication. Read the
  warning further down this file before you do, because that exposes the whole
  bank.
- `web-demo\.vercel` links this folder to that project, so redeploys go to the
  same place.

Redeploy after changing anything:

```powershell
powershell -ExecutionPolicy Bypass -File "C:\Users\user\Desktop\ceduinfo\deploy-to-vercel.ps1"
```

The first deployment was assigned to production automatically. Later ones are
preview deployments unless `--prod` is passed, which that script does.

## Deploy to Vercel

Option 1 below needs nothing installed and is the quickest route. The CLI and
Git routes need `node`/`npm` and `git`, none of which are installed on this
machine, and `vercel login` needs an interactive browser sign-in to your own
account.

### Option 1 — Vercel Drop (no installs, no token)

1. Open <https://vercel.com/drop>.
2. Drag in `dist/classic-edu-cbt-demo.zip`, or drag the `web-demo` folder
   itself.
3. Pick the team and a project name.
4. Click **Deploy**.

The zip is built with `index.html`, `questions.json` and `vercel.json` at its
**root**, not inside a `web-demo/` folder. That matters: Vercel serves the top
of what you drop, and if `index.html` is not there it stops to ask which page
should be the entry point.

There is no framework and no build step, so the files go up as they are.

Rebuild the zip after changing anything:

```powershell
cd C:\Users\user\Desktop\ceduinfo
Remove-Item dist\classic-edu-cbt-demo.zip
Compress-Archive -Path web-demo\index.html,web-demo\questions.json,web-demo\vercel.json `
  -DestinationPath dist\classic-edu-cbt-demo.zip
```

A dropped project has no Git connection. To publish a change, drop the folder
onto the existing project in the dashboard, or connect a Git repository to it
afterwards for automatic deployments.

### Option 2 — Vercel CLI

```powershell
npm i -g vercel
cd C:\Users\user\Desktop\ceduinfo\web-demo
vercel login
vercel --prod
```

It is a plain static directory: answer **no** when asked about a build command
and accept `./` as the output directory. The URL is printed when it finishes.
`vercel login` opens a browser for an interactive sign-in.

### Option 3 — Git

Push this folder as the root of a repository, import it at
<https://vercel.com/new>, and set **Framework Preset** to *Other*. Every push
then redeploys.

---

## Before you make the URL public

A Vercel production URL is public to anyone who has it, and the whole bank —
questions, answer letters and explanations — is in `questions.json`, which the
page fetches in the clear. Anyone who opens the page can read the file.

That is fine for checking your own work. It is not fine as a permanent public
page, because the bank is the product. Options, in order of effort:

1. Keep the URL to yourself and delete the deployment when you are done
   (`vercel remove <project>`).
2. Turn on **Vercel Authentication** under Project → Settings → Deployment
   Protection, so only your Vercel account can open it.
3. Deploy a cut-down `questions.json` — ten items per subject — and keep the
   full bank for WordPress only.

In WordPress none of this applies: `CES_Cbt_Attempt` marks server-side and the
contract forbids sending a correct answer to the browser before submission.

---

## Refreshing the data

`questions.json` is a copy. After changing anything under `seed-bank/source/`:

```powershell
cd tools\question-ingest
python ingest.py -i ..\..\seed-bank\source -o ..\..\seed-bank\out
copy ..\..\seed-bank\out\all-questions.json ..\..\web-demo\questions.json
```

Then redeploy.

---

## Notes on behaviour

- Questions are drawn at random from the chosen subject each time, so two
  attempts rarely match. Option order is left as authored.
- One minute per question; the paper submits itself when the timer reaches zero.
- Pass mark is 50%, matching the plugin's default.
- Marking happens in the browser here, which is **only** acceptable because
  this is a preview. Never do this in the plugin.
- Keyboard: A–D to answer, arrow keys to move, F to flag, Escape to dismiss the
  submit dialog.
