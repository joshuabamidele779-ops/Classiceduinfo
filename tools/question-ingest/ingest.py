#!/usr/bin/env python3
"""Build a Classic EDU question bank from papers you hold the rights to.

    python ingest.py --input ./papers --out ./out
    python ingest.py --input ./papers --out ./out --exam-type JAMB --year 2019
    python ingest.py --input ./papers/maths.pdf --out ./out --subject Mathematics

Reads .txt, .md, .pdf, .docx, .csv, .tsv and .json; writes one CSV per subject
plus a combined JSON, a rejects file and a report.

Source files are yours to supply: official past-question papers you own,
material you have licensed, or questions your own teachers wrote. This tool
deliberately does not fetch anything from the internet.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path
from typing import Dict, List

# Work when run as `python -I ingest.py` too, where the script's own folder is
# kept off sys.path.
sys.path.insert(0, str(Path(__file__).resolve().parent))

from ces_ingest import dedupe, extract, parse, validate, write  # noqa: E402
from ces_ingest.models import Question


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="ingest.py",
        description="Turn past-question files into Classic EDU import files.",
    )

    parser.add_argument("--input", "-i", required=True, help="A file or a folder to read.")
    parser.add_argument("--out", "-o", default="out", help="Where to write the results.")
    parser.add_argument("--subject", default="", help="Force the subject for every question.")
    parser.add_argument("--exam-type", default="", help="Force the exam type, e.g. JAMB.")
    parser.add_argument("--year", default="", help="Force the year.")
    parser.add_argument("--topic", default="", help="Force the topic.")
    parser.add_argument(
        "--difficulty",
        default="medium",
        choices=["easy", "medium", "hard"],
        help="Difficulty written to every row.",
    )
    parser.add_argument(
        "--no-fuzzy",
        action="store_true",
        help="Only drop exact duplicates. Faster on very large banks.",
    )
    parser.add_argument(
        "--threshold",
        type=float,
        default=dedupe.DEFAULT_THRESHOLD,
        help="How similar two questions must be to count as duplicates (0-1).",
    )
    parser.add_argument(
        "--keep-unanswered",
        action="store_true",
        help="Keep questions with no answer key, as drafts, instead of rejecting them.",
    )
    parser.add_argument("--quiet", action="store_true", help="Only print the summary.")

    return parser


def collect_files(root: Path) -> List[Path]:
    if root.is_file():
        return [root] if extract.is_supported(root) else []

    files = [path for path in sorted(root.rglob("*")) if path.is_file() and extract.is_supported(path)]

    return files


def defaults_for(path: Path, args: argparse.Namespace) -> Dict[str, str]:
    guessed = parse.meta_from_name(path)

    return {
        "subject": args.subject or guessed.get("subject", ""),
        "exam_type": args.exam_type or guessed.get("exam_type", ""),
        "year": args.year or guessed.get("year", ""),
        "topic": args.topic,
        "difficulty": args.difficulty,
    }


def main(argv: List[str]) -> int:
    args = build_parser().parse_args(argv)

    source = Path(args.input).expanduser()

    if not source.exists():
        print(f"error: {source} does not exist", file=sys.stderr)
        return 2

    out_dir = Path(args.out).expanduser()
    files = collect_files(source)

    if not files:
        print(f"error: no readable files under {source}", file=sys.stderr)
        print(f"       supported: {', '.join(sorted(extract.SUPPORTED))}", file=sys.stderr)
        return 2

    all_questions: List[Question] = []
    warnings: List[str] = []

    for path in files:
        label = str(path.relative_to(source)) if source.is_dir() else path.name
        defaults = defaults_for(path, args)

        try:
            if path.suffix.lower() in (extract.TABLE_SUFFIXES | extract.JSON_SUFFIXES):
                rows = extract.read_rows(path)
                questions, file_warnings = parse.questions_from_rows(rows, label, defaults)
            else:
                text = extract.read_text(path)
                questions, file_warnings = parse.parse_text(text, label, defaults)
        except extract.ExtractError as error:
            warnings.append(str(error))

            if not args.quiet:
                print(f"  skipped {label}: {error}")

            continue
        except Exception as error:  # noqa: BLE001 - one bad file must not stop the run
            warnings.append(f"{label}: unexpected error: {error}")

            if not args.quiet:
                print(f"  skipped {label}: {error}")

            continue

        warnings.extend(file_warnings)
        all_questions.extend(questions)

        if not args.quiet:
            print(f"  {label}: {len(questions)} question(s)")

    parsed_count = len(all_questions)

    accepted, rejected = validate.run(all_questions)

    # Questions whose only fault is a missing answer can be kept as drafts,
    # for a teacher to fill in inside WordPress.
    if args.keep_unanswered:
        rescued = [
            question
            for question in rejected
            if question.problems == ["no answer key found"]
        ]

        for question in rescued:
            question.problems = []

        accepted.extend(rescued)
        rejected = [question for question in rejected if question.problems]

    accepted, duplicates = dedupe.run(accepted, threshold=args.threshold, fuzzy=not args.no_fuzzy)

    groups = write.group_by_subject(accepted)
    per_subject = {subject: len(items) for subject, items in groups.items()}

    for subject, items in groups.items():
        write.write_csv(out_dir / "by-subject" / f"{write.slugify(subject)}.csv", items)

    if accepted:
        write.write_json(out_dir / "all-questions.json", accepted)

    write.write_rejects(out_dir / "rejects.csv", rejected + duplicates)

    stats = {
        "files": len(files),
        "parsed": parsed_count,
        "accepted": len(accepted),
        "duplicates": len(duplicates),
        "rejected": len(rejected),
    }

    write.write_report(out_dir / "report.md", stats, per_subject, warnings, rejected)

    print("")
    print(f"Files read        : {stats['files']}")
    print(f"Questions parsed  : {stats['parsed']}")
    print(f"Accepted          : {stats['accepted']}")
    print(f"Duplicates dropped: {stats['duplicates']}")
    print(f"Rejected          : {stats['rejected']}")
    print("")

    for subject in sorted(per_subject):
        print(f"  {subject:<32} {per_subject[subject]:>5}")

    print("")
    print(f"Written to {out_dir.resolve()}")
    print("  by-subject/*.csv   upload these, one at a time")
    print("  all-questions.json combined backup")
    print("  rejects.csv        rows to fix by hand")
    print("  report.md          what happened, and what to check")

    if stats["accepted"] == 0:
        print("")
        print("Nothing was accepted. Open report.md — the usual cause is a paper")
        print("whose answers live in a key the parser did not recognise.")
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
