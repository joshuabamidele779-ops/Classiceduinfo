"""Write the finished bank in the shapes the WordPress importer accepts.

One CSV per subject is the format to upload: the plugin's import screen takes
a file at a time, and a subject-sized file is small enough to finish inside a
shared-hosting PHP timeout. The combined JSON is there for backup and for
anyone wanting to diff two runs.
"""

from __future__ import annotations

import csv
import json
import re
from pathlib import Path
from typing import Dict, Iterable, List

from .models import LETTERS, Question

# The importer's canonical column order.
COLUMNS = (
    ["question", "type"]
    + [f"option_{letter.lower()}" for letter in LETTERS[:5]]
    + ["correct", "explanation", "subject", "exam_type", "topic", "year", "difficulty", "marks"]
)

REJECT_COLUMNS = ["source", "number", "subject", "problems", "question", "options", "correct"]


def slugify(value: str) -> str:
    slug = re.sub(r"[^a-z0-9]+", "-", (value or "").lower()).strip("-")

    return slug or "unsorted"


def group_by_subject(questions: Iterable[Question]) -> Dict[str, List[Question]]:
    groups: Dict[str, List[Question]] = {}

    for question in questions:
        groups.setdefault(question.subject or "Unsorted", []).append(question)

    return groups


def _columns_for(questions: List[Question]) -> List[str]:
    """Widen the option columns when some question needs more than five."""
    widest = max((len(question.options) for question in questions), default=0)
    widest = max(widest, 4)

    return (
        ["question", "type"]
        + [f"option_{letter.lower()}" for letter in LETTERS[:widest]]
        + ["correct", "explanation", "subject", "exam_type", "topic", "year", "difficulty", "marks"]
    )


def write_csv(path: Path, questions: List[Question]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    columns = _columns_for(questions)

    with path.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, extrasaction="ignore")
        writer.writeheader()

        for question in questions:
            row = question.to_row()
            writer.writerow({column: row.get(column, "") for column in columns})


def write_json(path: Path, questions: List[Question]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)

    payload = {"questions": [question.to_json() for question in questions]}

    with path.open("w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)


def write_rejects(path: Path, rejected: List[Question]) -> None:
    if not rejected:
        return

    path.parent.mkdir(parents=True, exist_ok=True)

    with path.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=REJECT_COLUMNS, extrasaction="ignore")
        writer.writeheader()

        for question in rejected:
            writer.writerow(
                {
                    "source": question.source,
                    "number": question.number if question.number is not None else "",
                    "subject": question.subject,
                    "problems": "; ".join(question.problems),
                    "question": question.body,
                    "options": " | ".join(option.text for option in question.options),
                    "correct": ",".join(question.correct),
                }
            )


def write_report(
    path: Path,
    stats: dict,
    per_subject: Dict[str, int],
    warnings: List[str],
    rejected: List[Question],
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)

    lines: List[str] = []
    lines.append("# Question ingest report")
    lines.append("")
    lines.append(f"- Files read: **{stats.get('files', 0)}**")
    lines.append(f"- Questions parsed: **{stats.get('parsed', 0)}**")
    lines.append(f"- Accepted: **{stats.get('accepted', 0)}**")
    lines.append(f"- Duplicates dropped: **{stats.get('duplicates', 0)}**")
    lines.append(f"- Rejected: **{stats.get('rejected', 0)}**")
    lines.append("")

    if per_subject:
        lines.append("## Accepted per subject")
        lines.append("")
        lines.append("| Subject | Questions |")
        lines.append("|---|---:|")

        for subject in sorted(per_subject):
            lines.append(f"| {subject} | {per_subject[subject]} |")

        lines.append("")

    if rejected:
        counts: Dict[str, int] = {}

        for question in rejected:
            for problem in question.problems:
                label = problem.split(":")[0]
                counts[label] = counts.get(label, 0) + 1

        lines.append("## Why rows were rejected")
        lines.append("")

        for label in sorted(counts, key=lambda key: -counts[key]):
            lines.append(f"- {label}: {counts[label]}")

        lines.append("")
        lines.append("Every rejected row is in `rejects.csv` with its source file and")
        lines.append("question number, so it can be fixed by hand and re-run.")
        lines.append("")

    if warnings:
        lines.append("## Warnings")
        lines.append("")

        for warning in warnings[:200]:
            lines.append(f"- {warning}")

        if len(warnings) > 200:
            lines.append(f"- …and {len(warnings) - 200} more")

        lines.append("")

    lines.append("## What to do next")
    lines.append("")
    lines.append("1. Open one subject CSV and read ten rows. Check the answer letters")
    lines.append("   against the original paper before trusting the whole file.")
    lines.append("2. In WordPress: **Classic EDU → Import / Export**, upload one subject")
    lines.append("   file at a time.")
    lines.append("3. Fix anything in `rejects.csv` and run the tool again over just")
    lines.append("   those files.")
    lines.append("")

    path.write_text("\n".join(lines), encoding="utf-8")
