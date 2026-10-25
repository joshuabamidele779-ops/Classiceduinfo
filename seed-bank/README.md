# Seed Question Bank

650 practice questions — 50 each in 13 subjects — ready to upload at
**Classic EDU → Import / Export**.

Upload these to get the CBT module usable on day one, before any licensed past
papers arrive. The science, arts and commercial tracks are all covered, so a
candidate in any of them has something to practise with.

---

## Provenance — read this before you publish

**These questions were written for this project. They are not past papers and
are not copied from any paper.** They are original items written to the JAMB
syllabus: the same topics, the same style, the same spread of difficulty, but
new wording and new numbers.

That distinction matters twice over:

- **Licensing.** JAMB and WAEC papers are somebody else's copyright. Reselling
  or republishing them without a licence is an infringement, and a site built
  on them can be taken down. Nothing here carries that risk.
- **Accuracy.** "Past questions" reconstructed from memory come out subtly
  wrong — a changed digit, a dropped option, the wrong answer letter. Every
  item here was computed and checked instead of recalled.

Because they are not from real papers, **no `year` is set** on any row. Do not
add one: tagging an original item "2019" tells students it came from the 2019
paper, which would be a lie they will eventually catch. `exam_type` is set to
`JAMB` to mean "aligned to that syllabus", which is what it is.

When you buy or license real papers, run them through `tools/question-ingest`
and import alongside. The two banks coexist; real papers deepen the bank, these
seed it.

---

## Upload

Upload **one subject at a time**, so each request finishes inside your host's
PHP timeout:

```
seed-bank/out/by-subject/
  biology.csv                      economics.csv
  chemistry.csv                    government.csv
  christian-religious-studies.csv  history.csv
  commerce.csv                     islamic-studies.csv
  literature-in-english.csv        mathematics.csv
  physics.csv                      principles-of-accounts.csv
  use-of-english.csv
```

**Classic EDU → Import / Export** → choose file → import. Repeat per subject.

### Match the Subject terms exactly

The importer creates a Subject term it cannot find. If your site already calls
a subject something else, **rename the term or edit the CSV first** — "Maths"
and "Mathematics" become two separate subjects and quietly split your bank in
half. The subjects used here are spelled:

```
Use of English      Mathematics     Physics     Chemistry     Biology
Economics           Government      History     Commerce
Literature-in-English               Principles of Accounts
Christian Religious Studies         Islamic Studies
```

---

## What is in it

### Science track

| Subject | Items | Covers |
|---|---:|---|
| Use of English | 50 | Synonyms, antonyms, sentence interpretation, concord, verb forms, prepositions, oral forms |
| Mathematics | 50 | Number bases, indices, logs, surds, sets, algebra, geometry, trigonometry, calculus, statistics, probability |
| Physics | 50 | Mechanics, pressure, heat, waves, optics, electricity, magnetism, atomic and nuclear physics |
| Chemistry | 50 | Atomic structure, bonding, the mole, acids and bases, redox, electrolysis, rates, equilibrium, organic chemistry |
| Biology | 50 | Cell biology, nutrition, transport, respiration, excretion, coordination, reproduction, genetics, evolution, ecology |

### Arts track

| Subject | Items | Covers |
|---|---:|---|
| Literature-in-English | 50 | Genres, poetic forms, prosody, figures of speech, elements of drama and prose, point of view, tragedy |
| Government | 50 | The state, constitutions, organs of government, systems of government, rule of law, Nigerian constitutional development, international organisations |
| History | 50 | Early Nigerian states, trans-Saharan and Atlantic trade, colonisation, nationalism, independence, civil war, African decolonisation |
| Christian Religious Studies | 50 | Creation and the patriarchs, Exodus and the law, monarchy, prophets, life and teaching of Jesus, parables, the early church |
| Islamic Studies | 50 | Tawhid, the five pillars, the life of the Prophet, the Qur'an, Hadith and Sunnah, sources of Shari'ah, the Caliphate, Islam in Nigeria |

### Commercial track

| Subject | Items | Covers |
|---|---:|---|
| Economics | 50 | Scarcity and choice, demand and supply, elasticity, production and costs, market structures, money and banking, taxation, national income, international trade, population |
| Commerce | 50 | Trade and aids to trade, business units, companies, trade documents, warehousing, transport, insurance, banking, capital market, advertising, consumer protection |
| Principles of Accounts | 50 | Double entry, books of original entry, trial balance and errors, final accounts, depreciation, adjustments, bank reconciliation, partnership and company accounts, ratios, concepts |

Economics and Government each appear once, although both the arts and the
commercial lists call for them.

Every item is:

- `type: single` — one correct answer, four options
- carrying a worked `explanation`, shown to students after they submit
- tagged with a syllabus `topic` (272 distinct topics across the bank)
- tagged `easy` / `medium` / `hard` — 310 / 285 / 55

**The difficulty mix is deliberately gentle.** A first bank that students can
mostly answer builds the habit of coming back; a punishing one does not. As
real past papers come in, the hard end fills out on its own. If you want this
bank reweighted harder, say so and I will add items rather than relabel these.

### Answer key spread

Every subject is exactly **A=13, B=13, C=12, D=12**, and the sequence of
correct letters has no repeating pattern in any subject. Answer positions were
assigned by hashing each question's text and dealing the positions round-robin,
so the key is balanced without being predictable. A student cannot gain anything
by always picking the same letter, and cannot learn a rhythm.

---

## Regenerating the CSVs

The CSVs are built from the JSON in `source/` by the existing ingest tool —
nothing bespoke, so the column order, validation and dedupe come from code that
is already in the repo and already tested:

```powershell
cd tools\question-ingest
python ingest.py -i ..\..\seed-bank\source -o ..\..\seed-bank\out
```

Edit the JSON in `source/`, re-run, re-upload. The run should report
**650 parsed, 650 accepted, 0 rejected, 0 duplicates** — anything else means an
edit broke a row, and `out/rejects.csv` will say which.

`out/all-questions.json` is the combined backup, and `out/report.md` is the run
report.

One thing to know about the deduper: it drops questions whose wording is 93%
similar within a subject. Two genuinely different questions whose stems differ
by a single word ("correct an overcharge" and "correct an undercharge") trip it.
If a subject comes back at 49 instead of 50, that is the cause — reword the stem
rather than passing `--no-fuzzy`, since near-identical stems read badly to
students anyway.

---

## Verifying before you trust it

Every answer letter in this bank was checked programmatically against its own
options, and every calculation was worked by hand — but the standard advice in
`tools/question-ingest/README.md` still applies to you as the person whose name
is on the site: **open one subject CSV and read ten rows against your own
knowledge before publishing.** Two minutes, and it is your site's credibility.

Pay particular attention to the subjects where a fact can date or be disputed:
Government (institutions and seat counts), History (dates), Commerce (the names
of regulators), and the two religious studies papers.

If you find an error, fix it in `source/<subject>.json`, re-run the build, and
re-import — do not patch only the CSV, or your fix is lost on the next run.

---

## Seeing it run

`web-demo/` is a static page that loads this bank and lets you sit a timed mock
paper in a browser, with the explanations shown after you submit. See
`web-demo/README.md` for how to deploy it, and for why its public URL exposes
the whole bank.
