"""Remove the same question appearing twice.

Past-question compilations repeat themselves constantly: the same item turns
up in three different "JAMB 2015–2020" files, sometimes reworded slightly.
Two passes catch both cases — an exact fingerprint match, then a fuzzy
comparison inside each subject.

The fuzzy pass is deliberately scoped per subject and bucketed by length, so
a few thousand questions compare in seconds rather than quadratically across
the whole bank.
"""

from __future__ import annotations

from difflib import SequenceMatcher
from typing import Dict, List, Tuple

from .models import Question

DEFAULT_THRESHOLD = 0.93


def _similar(left: str, right: str, threshold: float) -> bool:
    # Cheap length guard first: SequenceMatcher is the expensive part.
    shorter, longer = sorted((len(left), len(right)))

    if longer == 0 or (shorter / longer) < threshold:
        return False

    return SequenceMatcher(None, left, right).ratio() >= threshold


def run(
    questions: List[Question],
    threshold: float = DEFAULT_THRESHOLD,
    fuzzy: bool = True,
) -> Tuple[List[Question], List[Question]]:
    """Return (kept, duplicates), preserving the first occurrence of each."""
    kept: List[Question] = []
    duplicates: List[Question] = []
    seen: Dict[str, Question] = {}

    for question in questions:
        key = question.key

        if key in seen:
            question.problems = ["duplicate of an earlier question"]
            duplicates.append(question)
            continue

        seen[key] = question
        kept.append(question)

    if not fuzzy:
        return kept, duplicates

    by_subject: Dict[str, List[Question]] = {}

    for question in kept:
        by_subject.setdefault(question.subject.lower(), []).append(question)

    survivors: List[Question] = []

    for group in by_subject.values():
        accepted: List[Question] = []

        for question in group:
            body = question.body.lower()
            match = None

            for other in accepted:
                if _similar(body, other.body.lower(), threshold):
                    match = other
                    break

            if match is not None:
                question.problems = [
                    "near-duplicate of: " + match.body[:60] + ("…" if len(match.body) > 60 else "")
                ]
                duplicates.append(question)
                continue

            accepted.append(question)

        survivors.extend(accepted)

    # Keep the original order rather than the per-subject grouping.
    order = {id(question): index for index, question in enumerate(kept)}
    survivors.sort(key=lambda question: order.get(id(question), 0))

    return survivors, duplicates
