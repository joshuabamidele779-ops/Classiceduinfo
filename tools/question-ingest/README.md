# Question Ingest

Turns past-question papers **you hold the rights to** into the CSV and JSON
that *Classic EDU → Import / Export* accepts: grouped by subject, deduplicated,
validated, with a report telling you what it could not read.

It reads files from your own disk and never fetches anything from the
internet. What goes in is your decision and your licence: official past
papers you have bought, material a publisher has licensed to you, questions
your own teachers wrote, or any source whose terms allow you to reproduce it.

---

## Install

Python 3.9 or newer.

```powershell
cd tools\question-ingest
pip install -r requirements.txt
```

If your papers are plain text, CSV or JSON, you can skip the install — the
dependencies are only for PDF and Word files.

---

## Run it

```powershell
python ingest.py --input .\papers --out .\out
```

`--input` takes a single file or a whole folder, read recursively.

```powershell
# One subject, forced
python ingest.py -i .\papers\maths-2019.pdf -o .\out --subject Mathematics --exam-type JAMB --year 2019

# A whole archive, letting the file names speak
python ingest.py -i "D:\JAMB archive" -o .\out

# Keep questions whose answer key is missing, as drafts to finish in WordPress
python ingest.py -i .\papers -o .\out --keep-unanswered
```

Try it on the included sample first:

```powershell
python ingest.py -i .\samples -o .\out
```

---

## What comes out

```
out/
  by-subject/
    mathematics.csv        <- upload these, one at a time
    biology.csv
    english-language.csv
  all-questions.json       <- combined, for backup or diffing
  rejects.csv              <- rows it would not publish, and why
  report.md                <- counts per subject, and what to check
```

Upload a subject file at **Classic EDU → Import / Export**. One subject at a
time keeps each upload inside your host's PHP timeout.

---

## What it reads

| Type | Needs | Notes |
|---|---|---|
| `.txt` `.md` | nothing | The most reliable input by far |
| `.csv` `.tsv` | nothing | Already-structured banks; columns are mapped straight through |
| `.json` | nothing | A list, or `{"questions": [...]}` |
| `.pdf` | `pdfplumber` or `pypdf` | Must have a text layer |
| `.docx` | `python-docx` | Paragraphs and tables |

**Scanned PDFs will not work.** A scan is a picture of a page; there is no
text to extract. Run OCR over it first (`ocrmypdf in.pdf out.pdf`) and feed
the result in.

---

## The shapes it understands

Answer under each question:

```
1. What is the capital of Nigeria?
A. Lagos
B. Abuja
C. Kano
D. Enugu
Answer: B
Explanation: Abuja has been the capital since 1991.
```

`Ans - B`, `ANS: B`, `Correct answer: B` and `(B)` all work too.

Options printed inline:

```
3) Find x if 3x - 7 = 11  (a) 4 (b) 5 (c) 6 (d) 7
Answer: C
```

An answer key at the end of the paper:

```
ANSWERS
1. B  2. D  3. A  4. C  5. A
```

Per-question answers win where a paper has both.

---

## Subject, exam and year

Taken from the file or folder name when you do not pass them:

```
papers/JAMB/Mathematics/2019.pdf      -> JAMB · Mathematics · 2019
papers/waec-biology-2021.txt          -> WAEC · Biology · 2021
```

`--subject`, `--exam-type`, `--year` and `--topic` override the guess for
every file in the run. The subject name must match the Subject term in
WordPress, or the importer creates a new term — "Maths" and "Mathematics"
become two different subjects, which quietly splits your bank in half.

---

## What gets rejected

A question is rejected, not guessed at, when:

- there is no answer key for it
- the answer letter is not one of the options
- fewer than two options were found
- two options are identical
- the text is implausibly short or long (usually two questions run together)

All of it lands in `rejects.csv` with the source file and question number, so
you can fix the original and re-run over just those files.

Duplicates are dropped on two passes: an exact match after normalising case
and punctuation, then a fuzzy match within each subject (93% similar by
default, `--threshold` to change, `--no-fuzzy` to skip).

---

## Before you trust a run

Open one subject CSV and check ten answer letters against the original paper.
Extraction from a PDF can shift a column, and a bank with wrong answers is
worse than no bank at all — it teaches the wrong thing and students stop
believing the site. Ten rows takes two minutes.
