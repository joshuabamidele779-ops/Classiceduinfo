# Parents & Guardians

One parent or guardian WhatsApp number per student, and a short progress
update staff can send in one click.

---

## What it does

**Collects the number** in three places, because no single one covers every
student:

1. The WordPress and WooCommerce registration forms, where those are used.
2. A front-end form on the student dashboard, and the `[ces_parent_contact]`
   shortcode anywhere else. **This is the one that matters on this site**:
   Profile Builder owns `/login/` and renders its own markup, so students who
   register there are asked on the dashboard instead.
3. The admin user profile screen, for staff filling gaps by hand.

**Normalises the number.** Students type `0803 123 4567`, `+234 803 123 4567`,
`234-803-123-4567` and `8031234567`. All four are stored as `2348031234567`,
which is what wa.me and the WhatsApp Cloud API both want. The raw text is kept
alongside for support queries.

**Builds the update.** Exams sat in the last 30 days, average and best score,
strongest and weakest subject, and when the student last studied — four
numbers and one sentence, because a parent reads it on a phone while doing
something else.

**Sends it.** The default path is a wa.me link: staff press *Open WhatsApp*,
the message is already typed, they read it and hit send. Nothing to approve,
nothing to pay for, and a human eye on every message. Where a WhatsApp
Business account exists, a *Send* button appears alongside it.

### Three things worth knowing

1. **Nothing is sent automatically.** There is no cron that messages parents.
   Every message is a deliberate click by a member of staff, and the time and
   count are recorded against the student.
2. **The form needs no JavaScript.** It posts to `admin-post.php` and comes
   back with its errors, so it still works on a cheap phone with a flaky
   connection — which is exactly the student whose parent you most want to
   reach.
3. **Instructors cannot see parent numbers.** `ces_manage_parents` is granted
   to administrators only, not to the EDU Instructor role, because this is the
   one screen in the plugin holding third-party personal data.

---

## Shortcodes

| Shortcode | What it renders |
|---|---|
| `[ces_parent_contact]` | The parent contact form for the logged-in student. Accepts `title`. |

The student dashboard tab is contributed through `ces_dashboard_tabs`. It
sits at position 45 normally, and jumps to position 1 — right after Overview,
with a warning icon — for a student who has not given a number yet.

---

## Where the data lives

User meta on the student, so it travels with the account and WordPress
removes it when the account is deleted. No tables.

| Key | Holds |
|---|---|
| `ces_parent_name` | Parent / guardian name |
| `ces_parent_whatsapp` | Normalised digits, country code included |
| `ces_parent_whatsapp_raw` | Exactly what was typed |
| `ces_parent_relationship` | mother · father · guardian · sibling · sponsor · other |
| `ces_parent_consent` | Whether the student confirmed consent |
| `ces_parent_updated_at` | Last written |
| `ces_parent_last_contacted` | When staff last messaged |
| `ces_parent_contact_count` | How many messages are logged |

---

## Hooks

```php
do_action( 'ces_parent_contact_saved', $user_id, $after, $before );
do_action( 'ces_parent_contact_deleted', $user_id );

apply_filters( 'ces_parent_summary', $summary, $user_id, $days );
apply_filters( 'ces_parent_message', $text, $user_id, $summary );
apply_filters( 'ces_parent_wa_payload', $payload, $phone, $message );
```

---

## Settings

Under **Classic EDU → Settings**, section "Parents & Guardians".

| Key | Default | Meaning |
|---|---|---|
| `parents_capture_enabled` | on | Master switch for the fields and the tab. |
| `parents_required_at_signup` | on | Enforced only on forms that carry our fields. |
| `parents_block_until_provided` | off | No CBT attempt may start until a number is on file. Staff are never blocked. |
| `parents_consent_required` | on | The student must tick that the parent agreed. |
| `parents_default_country` | 234 | Digits only. Local numbers are stored with this in front. |
| `parents_consent_text` | … | The sentence under the fields. |
| `parents_report_template` | empty | Your own wording. Empty uses the built-in. |
| `parents_wa_api_enabled` | off | Turn on Cloud API sending. |
| `parents_wa_phone_id` | empty | WhatsApp phone number ID. |
| `parents_wa_token` | empty | Access token. Server-side only, never printed. |
| `parents_wa_template` | empty | Approved template name, with one body parameter. |
| `parents_wa_language` | en | Must match the approved template. |

### Message placeholders

`{parent}` `{student}` `{first_name}` `{exams}` `{average}` `{best}`
`{passes}` `{coins}` `{videos}` `{questions}` `{days}` `{last_active}`
`{strong}` `{weak}` `{subject_line}` `{site}` `{site_url}` `{dashboard}`

Use `\n` for a line break — the settings screen stores single-line text, so
the sequence is converted when the message is built.

---

## A word about the Cloud API

Meta does not let a business open a conversation with free text. The first
message to a parent who has not written to you in the last 24 hours **must**
be an approved template. So:

- With a template configured, every send uses it, with the summary sentence
  as its single body parameter.
- Without one, sending only works as a reply inside a conversation the parent
  started, and will be refused otherwise.

This is why wa.me is the default. It costs nothing, needs no approval, and a
member of staff sees every message before it goes.

---

## What an admin has to do

1. **Check the settings.** The defaults collect a number and require consent,
   but do not block anything.
2. **Tell students once.** The dashboard tab explains what is shared before
   it asks for the number; the explanation is worth reading yourself, because
   students will ask you about it.
3. **Fill the gaps.** *Classic EDU → Parents*, filter to "Missing a parent
   number", and chase that list.
4. **Send updates.** Filter to "Has a parent number", set the window, press
   *Preview message* on a couple of rows to see the wording, then work down
   the list with *Open WhatsApp*.
5. **Treat the CSV as confidential.** The export is other people's phone
   numbers. Download it only when you need it, keep it off shared drives, and
   delete it afterwards.

### If something looks wrong

- **The fields do not show at signup** — Profile Builder renders its own form
  and will not show ours. That is expected; the student is asked on the
  dashboard at first login.
- **A number is rejected** — it is shorter or longer than a real number once
  the country code is applied. Check for a missing digit.
- **"Open WhatsApp" opens an empty chat** — WhatsApp Web occasionally drops
  the prefilled text on desktop. The message is in *Preview message*, ready
  to copy.
- **Send fails with the API on** — almost always the template name, the
  language code, or a template that has more than one body parameter.
