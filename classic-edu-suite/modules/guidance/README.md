# Guidance

School Finder, Department Guidance and Career Guide — the "what do I do next"
half of the site, as opposed to the "how do I pass" half.

---

## What it does

**School Finder** — pick the course you want to study and see every
institution that offers it, filtered by institution type, state and ownership.

**Department Guidance** — what a department actually studies, its UTME subject
combination, O'level requirement, direct entry route, course length, and where
it leads.

**Career Guide** — what the work is, what it pays, the skills that matter and
what to study for it.

### The one idea holding it together

Everything hangs off a single taxonomy: **Programme**, meaning a course of
study such as "Mechanical Engineering". An institution is tagged with every
programme it offers; a department write-up and a career are tagged with the
programmes they relate to. So a student can walk Career → Course → Department
→ Schools without you maintaining a single cross-reference by hand.

It is called `ces_programme`, not `ces_course`, because `ces_course` already
belongs to the LMS video courses.

---

## Shortcodes

| Shortcode | What it renders |
|---|---|
| `[ces_guidance]` | The whole section: the three cards, and each panel below. |
| `[ces_school_finder]` | The finder on its own. |
| `[ces_department_guide]` | Department guidance on its own. |
| `[ces_career_guide]` | Careers on its own. |

Everything is server-rendered from GET parameters, so it works with
JavaScript off and a shared link lands where it should.

### Query arguments

`g` (finder · departments · careers) · `item` · `programme` · `type` ·
`state` · `ownership` · `s` · `gpage`

---

## Post types, taxonomies and capability

- Post types: `ces_institution`, `ces_department`, `ces_career`
- Taxonomies: `ces_programme` (all three), `ces_institution_type` and
  `ces_state` (institutions)
- Capability: `ces_manage_guidance`, granted to administrators and EDU
  Instructors

None of the post types is publicly queryable; everything renders through the
shortcodes. No database tables of its own.

The 36 states plus the FCT, and the five institution types, are seeded once on
the first admin page load. Terms you delete are not recreated.

---

## Settings

| Key | Default | Meaning |
|---|---|---|
| `guidance_page_id` | 0 | ID of the page holding `[ces_guidance]`. The dashboard links to it. |

---

## Bulk import

**Classic EDU → Guidance Import** takes one CSV per content type and matches
rows on the title, so uploading a corrected file again updates rather than
duplicates. Programmes, institution types and states named in a row are
created as terms on the fly.

Separate several values in one cell with a semicolon or a pipe.

| Type | Columns |
|---|---|
| Institutions | `title, abbr, type, state, ownership, cutoff, website, programmes, summary, description` |
| Departments | `title, programmes, duration, utme, olevel, direct_entry, careers, summary, description` |
| Careers | `title, programmes, salary, skills, study, summary, description` |

`ownership` must be `federal`, `state` or `private`. Sample files for all
three are on the same screen.

---

## What an admin has to do

1. **Make the page.** *Pages → Add New*, title it "Guidance", body
   `[ces_guidance]`, publish. Set **Guidance page ID** in the settings so the
   dashboard links to it.
2. **Load the institutions.** Build the CSV from a source you are entitled to
   use — the JAMB brochure you have bought, each school's own published
   prospectus, or your own research — and import it.
3. **Write the departments that matter first.** Fifteen good write-ups of the
   courses your students actually apply for beat four hundred thin ones.
4. **Tag everything with a Programme.** An institution with no programmes
   never appears in a course search, and a department with none has nowhere
   to send the student next.

### If something looks wrong

- **A course search returns nothing** — the institutions are not tagged with
  that programme. Tagging is what the finder searches; the body text is not.
- **A dropdown is missing** — term dropdowns hide empty terms. Nothing is
  tagged with it yet.
- **"Where you can study it" is empty on a department** — no institution
  carries that department's programme term.
