# Start here — putting Classic EDU Suite on your WordPress site

Written for: you, the site owner. No coding, no FTP, no command line. Everything
below happens in the normal WordPress admin screens you already use.

Set aside about 40 minutes. You can stop after step 5 and come back later — the
plugin does nothing to your existing pages until you add its shortcodes.

If you want the deep version — video limits, ffmpeg, cron, capacity planning —
that is in `DEPLOYMENT.md`. This file is the short path to a working site.

---

## What you are installing

One file:

```
C:\Users\user\Desktop\ceduinfo\dist\classic-edu-suite-1.2.0.zip
```

That is a normal WordPress plugin zip. Do not unzip it — WordPress wants the
zip exactly as it is.

If that file is missing, or you have changed something and need a fresh copy,
open PowerShell in the `ceduinfo` folder and run:

```powershell
.\build-plugin.ps1
```

It rebuilds the zip in the `dist` folder and prints where it put it.

---

## Step 1 — Back up first (do not skip this)

The plugin creates its own database tables and a new user role. A backup is
your undo button if anything surprises you.

Use whatever your host gives you — cPanel → **JetBackup** / **Backup Wizard**,
or a plugin like UpdraftPlus. You need **both** the files and the database.

**Better still:** if your cPanel has **WordPress Manager** or **Softaculous**,
use its *Clone* or *Staging* button to make a copy of the site at something
like `staging.classiceduinfo.com.ng`, and do steps 2–6 there first. Then repeat
them on the live site once you are happy.

---

## Step 2 — Upload and activate

1. Log in to `classiceduinfo.com.ng/wp-admin`.
2. Go to **Plugins → Add New**.
3. Click **Upload Plugin** at the top.
4. **Choose File** → pick `classic-edu-suite-1.2.0.zip`.
5. Click **Install Now**, wait, then click **Activate Plugin**.

You should now see a **Classic EDU** item in the left-hand admin menu.

If the screen goes white or you see a fatal error instead, jump to
*If something goes wrong* at the bottom.

---

## Step 3 — Basic settings

Go to **Classic EDU → Settings**. You only need four things right now:

| Setting | What to put |
|---|---|
| Brand colour | Your site blue, `#2c62cd`. Everything — buttons, the dashboard banner, the coin panel, progress bars — recolours from this one field. |
| Login page URL | `/login/` (your existing Profile Builder page) |
| Dashboard page | Leave it for now. You set it in step 4, once the page exists. |
| Delete all data on uninstall | Leave this **off**. |

Save. Leave the coin amounts at their defaults for launch — it is easy to raise
rewards later and painful to take them back.

---

## Step 4 — Create the student pages

The plugin does not create pages for you, on purpose: it does not touch your
site layout. You make a normal page and drop in one shortcode. The plugin fills
the rest of the page in.

For each row below: **Pages → Add New**, type the title, put the shortcode in
the body, **Publish**.

| Page title | Put exactly this in the page body |
|---|---|
| Student Dashboard | `[ces_dashboard]` |
| Study | `[ces_study_hub]` |
| Guidance | `[ces_guidance]` |
| CBT Practice | `[ces_exam_list]` |
| Video Lessons | `[ces_course_list]` |
| Leaderboard | `[ces_leaderboard]` |
| Chat | `[ces_chat]` |
| Find a Tutor | `[ces_consultant_directory]` |
| Teach With Us | `[ces_consultant_apply]` |

**Study** is the big one. That single shortcode draws the whole Study section
— the tile grid, past questions, study mode, the library (novels, syllabuses,
the JAMB brochure, lesson notes) and the leaderboard — all on that one page.
You do not need a page per tile.

**If you use the block editor:** add a **Shortcode** block, or just a
Paragraph block, and paste the shortcode in. Nothing else on the page.

**If you use Elementor:** add a **Shortcode** widget and paste it there.

Spelling matters. `[ces_course_list]` works; `[ces_courses]` does not.

**Now finish the settings you skipped:** go back to **Classic EDU → Settings**
and fill in three page references, then save.

| Setting | What to put |
|---|---|
| Dashboard page | *Student Dashboard* |
| Study page ID | The ID number of your *Study* page |
| Guidance page ID | The ID number of your *Guidance* page |
| Tutor directory page ID | The ID number of your *Find a Tutor* page |

**Where do I find a page ID?** Open the page in the editor and look at the
browser address bar: `…/post.php?post=` **`412`** `&action=edit`. That number
is the ID. Several buttons inside the plugin link to these pages, and this is
how they find them.

---

## Step 5 — Add them to your menu

**Appearance → Menus** → add *Student Dashboard*, *Study*, *Guidance*, *CBT
Practice* and *Video Lessons* to your main menu → **Save Menu**.

At this point students can reach everything. The site works.

---

## Step 6 — Put some content in

Nothing shows up until there is something to show. In the **Classic EDU** menu:

- **Questions** — add exam questions, or use **Import** to bring in a CSV or
  JSON file in bulk. The Import screen has a *Download sample file* link; fill
  that in and upload it. To turn a folder of past-question papers you own into
  those files automatically, see `tools/question-ingest/README.md`.
- **Resources** — novels, lesson notes, the JAMB brochure, the JAMB/WAEC/NECO
  syllabuses, scheme of work, past-question PDFs. Give each one a **Resource
  Type**; that is what puts it behind the right tile on the Study page.
- **Institutions / Departments / Careers** — the Guidance content. Use
  **Guidance Import** for bulk loading from a spreadsheet.
- **Exams** — create an exam, set its duration, pass mark and how many
  questions to draw. Tag it with a **Subject** (Mathematics, English, Biology…).
- **Courses** and **Lessons** — for video. Upload through
  **Classic EDU → Upload**, not the normal media library.
- **Quotes** — the daily popup. There is a **Seed starter quotes** button so
  you do not have to type fifty of them.

### A note on the Subject tag

Tag every exam and course with a Subject. With no featured image the plugin
draws its own artwork from that tag — graph paper and a curve for maths, a
double helix for biology, a flask and benzene ring for chemistry, orbits for
physics, an open book for English. So the cards look finished from day one, and
you only need to make thumbnails later if you want to.

Unrecognised or missing subjects get a neutral book-and-atom drawing, never a
blank grey box.

---

---

## Step 7 — Parents

Every student is asked for a parent or guardian's WhatsApp number. Because
your `/login/` page is Profile Builder's, which renders its own form, the ask
happens on the **student dashboard** at first login rather than at signup —
the Parent tab jumps to the front with a warning icon until it is filled in.

In **Classic EDU → Settings**, section *Parents & Guardians*, check two things:

| Setting | Suggested |
|---|---|
| Collect parent contacts | **On** |
| Block practice until provided | **Off to launch.** Turn it on later if too many students skip it — it stops a student starting a CBT paper until the number is there, which works but costs you some signups. |

Then **Classic EDU → Parents** is your contact book: who has a number, who
does not, what each student has been doing, and an *Open WhatsApp* button that
opens a chat with the progress update already typed. You read it and press
send — nothing is sent automatically, ever.

Leave the WhatsApp Cloud API settings empty unless you already have a WhatsApp
Business account and an approved message template. The one-click link needs
none of that.

---

## Step 7b — Gmail sign-in and parent approval

This is the newest part and the one students notice most: they sign in with
the Gmail account already on their phone, and their parent has to confirm them
before practice opens.

### Get the Google keys (about ten minutes, free)

1. Go to <https://console.cloud.google.com/> and sign in with your own Google
   account. Make a project if you have none — call it anything.
2. In the left menu, **APIs & Services → OAuth consent screen**. Choose
   *External*, fill in the app name (use *Classic Edu Info*), your support
   email, and the links to your privacy policy and terms pages. Save.
3. **APIs & Services → Credentials → Create credentials → OAuth client ID**.
   Application type: **Web application**.
4. Under **Authorised JavaScript origins** add:
   `https://classiceduinfo.com.ng`
5. Under **Authorised redirect URIs** add, exactly:
   `https://classiceduinfo.com.ng/?ces_oauth=google`
   It has to match character for character — `http` instead of `https`, or a
   stray `www`, and Google refuses with *redirect_uri_mismatch*.
6. Press Create. Google shows a **client ID** and a **client secret**. Copy
   both.
7. Back on your site: **Classic EDU → Settings → Gmail Sign-in**. Paste the
   two values in, tick *Let students sign in with Gmail*, and save.
8. On the consent screen page, press **Publish app** when you are ready for
   real students — while it says *Testing*, only accounts you list can sign
   in.

The *Continue with Gmail* button now appears on `/login/`, on `wp-login.php`
and on the dashboard's sign-in panel, without you placing it anywhere.

### Check the setup panel

**Classic EDU → Approvals** has a *Setup* box at the top that tells you in
four lines whether this is actually working, and prints the redirect URI to
paste into Google. Look there first whenever something seems wrong.

### The approval settings

In **Classic EDU → Settings → Parent Approval**:

| Setting | Suggested |
|---|---|
| Require a parent to approve each student | **On** |
| What is locked until approval | **Starting exams, study sessions and videos.** Students can still look around, which is what makes them finish. |
| Leave existing students alone | **On.** Students who signed up before today keep working. |
| Staff must approve as well | **Off** to start. Turn it on if you want to see every account yourself. |
| Check approvals the student carried | **Off** to start. See the note below before you decide. |
| Parent page ID | Optional — see below. |

### How it works, from the student's side

1. They sign in with Gmail and land on **My account**.
2. They type their name, class, and their parent's name and WhatsApp number.
3. A green **Open WhatsApp and send** button appears. They press it, WhatsApp
   opens with the message already written and addressed to their parent, and
   they press send.
4. The parent gets a short message with a link and a six-digit code. They
   either tap the link and press *Yes, this is my child*, or they read the six
   digits out to their child, who types them in.
5. Practice unlocks, and the parent keeps a private link showing how their
   child is getting on.

You send nothing and pay nothing. If you do have a WhatsApp Business API set
up (Step 7's settings), the site messages the parent itself instead.

### One honest thing about this check

When you have no WhatsApp Business API set up — which is the normal case, and
costs you nothing — the student is the one who forwards the request to their
parent. That means the student can see the link and the six-digit code,
because they are carrying the message. A determined student could open their
own link and approve themselves.

It is still worth having. The student has to name a real parent with a real
number, in front of you; that number goes on file where you can ring it; the
parent gets the message either way, so one who did not agree finds out; and
involving the parent becomes the easy path rather than something to dodge.

If you need it to be airtight, tick **Check approvals the student carried** in
the settings. Those approvals then wait in your queue, and the **Approvals**
screen tells you on every row whether we messaged the parent or the student
forwarded it. Ring the ones that matter and press *Confirm by phone*.

---

### Optional: make the parent's page look like your site

Create a normal page — call it *Parent approval*, and leave it out of your
menu — containing only:

```
[ces_parent_portal]
```

Put its page ID in **Parent page ID** in the settings. Without this the plugin
prints its own plain page, which works perfectly well; this just wraps it in
your own header and footer.

---

## Step 7c — Tutors, live classes and chat

This is the part that lets other people teach on your site, and the part where
your students talk to each other. Both are switched on by default; read this
before you tell anybody about them.

### Letting a tutor host their lessons here

A tutor signs up like any student, opens their dashboard, and finds **Teach
here**. They fill in a short application — name, phone, subjects, who they
are — and it lands in **Classic EDU → Consultants** for you to read.

Approve one and they get a **Studio** tab. In it they make a *class*, record or
upload a lesson, and get a join link to send their students on WhatsApp. Their
videos are protected exactly like yours: no downloadable file, and the
student's name drifting across the picture. Their lessons are **only** visible
to students in that class — nobody can buy their way in with coins.

In **Classic EDU → Settings → Consultant Classrooms**:

| Setting | Suggested |
|---|---|
| Let outside tutors host classes | **On**, if you want this at all |
| Accept new consultant applications | **On** |
| Approve applications automatically | **Off.** Leave this off. A consultant uploads video your students watch and runs a chat room with a list of their names in it. That is worth two minutes of your time. |
| Students need the tutor's approval by default | **On.** Share links get forwarded; this is what stops a forwarded link filling somebody's class with strangers. |
| Classes per consultant / Students per class | 10 and 200 are sensible to start |

### Live sessions

A tutor can schedule a session: their whole class opens one page and watches
the same video together, with the tutor's play and pause moving everyone's
player, chat down the side, hands up, and a register that fills itself.

One thing to be straight about: this is **not** a Zoom replacement. A grid of
thirty live cameras needs a media server, which your shared hosting cannot run
at any price. What the room does is the thing that actually works on a
student's data bundle — everyone watching together with live chat. If you do
have Zoom or Google Meet, a session can carry that link too, so the class
gathers in the room with the material and the register while the talking
happens in the call.

### Student chat — read this bit

Your students get chat rooms: one per tutor's class, one per live session, and
eight open subject rooms (Common Room, Maths, English, Physics, Chemistry,
Biology, exams, careers).

The defaults are built for a site full of children:

- **There are no private messages anywhere, and there never will be.** Every
  message a student writes is in a room an adult can read. This is the single
  most effective thing you can do.
- **Links, email addresses and phone numbers are refused.** Talking a child
  into moving the conversation somewhere nobody is watching is how most harm
  starts. Leave this off.
- Five seconds between messages, a blocklist, and a **report** flag on every
  message — three reports hide it immediately.
- Every room is readable at **Classic EDU → Chat**, which carries a count when
  something needs you.

**Somebody must actually look at it.** Open *Classic EDU → Chat* once a day.
If you cannot commit to that, turn **Open the subject rooms to all students**
off and leave chat inside tutors' classes only — a much smaller thing to
watch. If you want it watertight, switch on **Hold every student message for a
moderator**: nothing appears until you release it.

### The helpers in the chat

An empty chat room dies: a student looks in, sees nothing, and never comes
back. So when a room has been quiet for a few minutes *and somebody is looking
at it*, the site posts something worth answering — a subject question, a study
tip, or a true thing that just happened ("A student in SS 3 just scored 88% in
Physics").

The question-askers are automated, they have names (Ada, Tunde, Ngozi, Musa),
and **every one of their messages carries a BOT badge next to the name**. That
is deliberate and cannot be switched off. Your students are children: one who
thinks they are talking to a classmate will say things they would not say to a
machine, and will feel cheated when they work it out — and they do work it
out. A labelled helper makes the room just as lively and keeps your site
honest.

Real scores are named inside a tutor's class, where everyone knows each other,
and anonymised in the open rooms, because a child's exam mark is not for a
room of strangers.

---

## Step 8 — Test it as a student, on your phone

Open an incognito/private window on your phone and walk through this. Do not
test as the administrator — admins skip the very checks you want to see work.

- [ ] Register a brand-new account through `/login/`
- [ ] Press **Continue with Gmail** and sign in with a Gmail account you own
- [ ] You land on **My account**, asked for your parent's details
- [ ] Try to start a practice paper first — it should refuse, politely
- [ ] Fill in the parent form, press **Open WhatsApp and send**, and check the
      message that opens is addressed to the right number and reads well
- [ ] Open the link from that message on another phone, press *Yes* — the
      student's account unlocks and the parent sees a progress page
- [ ] Back as the student, reload: practice is now open
- [ ] The whole thing shows in **Classic EDU → Approvals**, with a history
- [ ] Open the **Chat** tab, post a message, and report one of your own
- [ ] As a second account, apply to be a tutor; approve it from the admin
- [ ] In the Studio, make a class, add a lesson, press **Check my microphone**
- [ ] Record thirty seconds, play it back, and upload it
- [ ] Copy the class join link, open it as a student, and ask to join
- [ ] Let that student in from the Studio; the lesson appears for them
- [ ] Schedule a session, start it as the tutor, and watch the student's player follow
- [ ] The signup bonus coins show on the dashboard
- [ ] The dashboard asks for a parent number, and `0803…` is accepted
- [ ] That number shows in **Classic EDU → Parents**, and *Open WhatsApp*
      opens a chat with the message already written
- [ ] The Study page tiles open, and "Study Past Questions" asks a question,
      marks it, and shows the reason
- [ ] The leaderboard lists your test score after you sit a paper
- [ ] Guidance → School Finder returns a school for a course you imported
- [ ] Take an exam: timer runs, answers stick, submitting gives a score
- [ ] Coins were credited for passing
- [ ] The daily quote appears once and does not come back the same day
- [ ] The referral link copies, and WhatsApp share opens WhatsApp
- [ ] A locked lesson costs coins, unlocks, and plays
- [ ] The watermark on the video shows the test student's name

Then delete the test account.

---

## If something goes wrong

**White screen right after activating.** The plugin is not compatible with
something on the site. Get your admin back: in cPanel → **File Manager**, go to
`wp-content/plugins/`, rename `classic-edu-suite` to
`classic-edu-suite-disabled`. WordPress will deactivate it and your admin
returns. Then turn on error logging (below) and send me what it says.

**To see the actual error**, add these three lines to `wp-config.php` just above
the line that says `/* That's all, stop editing! */`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Errors are written to `wp-content/debug.log`. **Turn `WP_DEBUG` back to
`false` when you are done** — you do not want it on for a live site.

**A page shows the shortcode as plain text** like `[ces_dashboard]`. The plugin
is not active, or the shortcode is misspelled. Check **Plugins** first.

**Video stays stuck on "processing".** That is a server-side thing — real cron
and ffmpeg. See section 4 of `DEPLOYMENT.md`.

**Coins are not being credited.** Almost always real cron not running. Same
place: `DEPLOYMENT.md`, section 4c.

---

## Undoing it

**Deactivating** the plugin stops all of it at once and **keeps every record** —
coin balances, exam results, courses, progress. Reactivate and it picks up
exactly where it was.

**Deleting** the plugin also keeps your data, unless you first switch on
*Delete all data on uninstall* in Settings. That switch is off by default on
purpose: coin balances and exam history should not be destroyable with one
careless click.

Your own pages and menu items are yours — removing the plugin leaves them, they
just stop filling in.
