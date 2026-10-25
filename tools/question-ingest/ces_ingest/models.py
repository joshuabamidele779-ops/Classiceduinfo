"""Record types shared by every stage of the pipeline.

A Question here is deliberately close to the CSV the WordPress importer
wants, so the writer stage is a dumb mapping rather than another round of
interpretation.
"""

from __future__ import annotations

import hashlib
import re
from dataclasses import dataclass, field
from typing import List, Optional

# Option letters the plugin understands: option_a .. option_j.
LETTERS = "ABCDEFGHIJ"

_WHITESPACE = re.compile(r"\s+")
_NON_WORD = re.compile(r"[^a-z0-9 ]+")


def fingerprint(text: str) -> str:
    """A stable key for near-identical question bodies.

    Lower-cased, punctuation dropped, whitespace collapsed. Two papers that
    print the same question with different spacing or a different quote mark
    land on the same fingerprint, which is what the deduper needs.
    """
    flat = _WHITESPACE.sub(" ", _NON_WORD.sub(" ", text.lower())).strip()
    return hashlib.sha1(flat.encode("utf-8")).hexdigest()  # noqa: S324 - not security


@dataclass
class Option:
    """One answer option."""

    text: str

    def is_empty(self) -> bool:
        return not self.text.strip()


@dataclass
class Question:
    """One question, ready to become a row in the importer's CSV."""

    body: str
    options: List[Option] = field(default_factory=list)
    correct: List[str] = field(default_factory=list)
    explanation: str = ""
    subject: str = ""
    exam_type: str = ""
    topic: str = ""
    year: str = ""
    difficulty: str = "medium"
    marks: float = 1.0
    qtype: str = "single"
    source: str = ""
    number: Optional[int] = None

    # Filled by the validator.
    problems: List[str] = field(default_factory=list)

    @property
    def key(self) -> str:
        """Dedupe fingerprint, scoped to the subject."""
        return f"{self.subject.lower()}|{fingerprint(self.body)}"

    def letter_for(self, index: int) -> str:
        return LETTERS[index] if index < len(LETTERS) else ""

    def to_row(self) -> dict:
        """Map onto the importer's column names."""
        row = {
            "question": self.body.strip(),
            "type": self.qtype,
            "correct": ",".join(self.correct),
            "explanation": self.explanation.strip(),
            "subject": self.subject,
            "exam_type": self.exam_type,
            "topic": self.topic,
            "year": self.year,
            "difficulty": self.difficulty,
            "marks": self.marks,
        }

        for index, option in enumerate(self.options):
            row[f"option_{LETTERS[index].lower()}"] = option.text.strip()

        return row

    def to_json(self) -> dict:
        """The JSON shape the importer accepts, options as a list."""
        return {
            "question": self.body.strip(),
            "type": self.qtype,
            "options": [option.text.strip() for option in self.options],
            "correct": list(self.correct),
            "explanation": self.explanation.strip(),
            "subject": self.subject,
            "exam_type": self.exam_type,
            "topic": self.topic,
            "year": self.year,
            "difficulty": self.difficulty,
            "marks": self.marks,
        }
