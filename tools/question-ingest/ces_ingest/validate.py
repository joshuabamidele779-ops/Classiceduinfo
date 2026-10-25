"""Decide whether a parsed question is safe to publish.

The rule of thumb: a question that is merely untidy gets cleaned and kept; a
question whose *answer* cannot be trusted is rejected. A bank full of wrong
answer keys teaches students the wrong thing and destroys their trust in the
site, which is far more expensive than a smaller bank.
"""

from __future__ import annotations

import re
from typing import List, Tuple

from .models import LETTERS, Option, Question

MIN_BODY = 8
MAX_BODY = 4000
MIN_OPTIONS = 2

# Rubbish an option sometimes picks up from a PDF column break.
_TRAILING_JUNK = re.compile(r"[\s\.…]+$")


def tidy(question: Question) -> Question:
    """Clean up whitespace and obvious extraction artefacts, in place."""
    question.body = re.sub(r"\s+", " ", question.body).strip()
    question.explanation = re.sub(r"\s+", " ", question.explanation).strip()

    cleaned: List[Option] = []

    for option in question.options:
        text = re.sub(r"\s+", " ", option.text).strip()
        text = _TRAILING_JUNK.sub("", text)

        if text:
            cleaned.append(Option(text=text))

    question.options = cleaned

    # True/false questions are better stored as the plugin's own type: it
    # generates the two options itself and marking stays consistent.
    labels = [option.text.strip().lower() for option in question.options]

    if labels in (["true", "false"], ["t", "f"]):
        question.qtype = "truefalse"

    question.correct = [letter.strip().upper() for letter in question.correct if letter.strip()]

    if len(question.correct) > 1 and question.qtype == "single":
        question.qtype = "multi"

    question.subject = question.subject.strip()
    question.exam_type = question.exam_type.strip()
    question.year = question.year.strip()

    return question


def check(question: Question) -> List[str]:
    """Return the reasons a question cannot be published. Empty means fine."""
    problems: List[str] = []

    body = question.body.strip()

    if len(body) < MIN_BODY:
        problems.append("question text is too short to be real")

    if len(body) > MAX_BODY:
        problems.append("question text is implausibly long — probably two questions merged")

    if question.qtype != "truefalse":
        if len(question.options) < MIN_OPTIONS:
            problems.append(f"only {len(question.options)} option(s) found")

        if len(question.options) > len(LETTERS):
            problems.append("more options than the plugin supports")

        texts = [option.text.strip().lower() for option in question.options]

        if len(set(texts)) != len(texts):
            problems.append("two options are identical")

    if not question.correct:
        problems.append("no answer key found")
    else:
        valid = set(LETTERS[: max(len(question.options), 2)])

        for letter in question.correct:
            if letter not in valid:
                problems.append(f"answer '{letter}' is not one of the options")

    return problems


def run(questions: List[Question]) -> Tuple[List[Question], List[Question]]:
    """Tidy and validate a batch. Returns (accepted, rejected)."""
    accepted: List[Question] = []
    rejected: List[Question] = []

    for question in questions:
        tidy(question)
        question.problems = check(question)

        if question.problems:
            rejected.append(question)
        else:
            accepted.append(question)

    return accepted, rejected
