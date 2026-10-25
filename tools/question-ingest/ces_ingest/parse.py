"""Turn extracted text into Question records.

Past-question files in the wild come in a handful of shapes. This parser
handles the ones that actually turn up:

    1. What is the capital of Nigeria?
    A. Lagos
    B. Abuja
    C. Kano
    D. Enugu
    Answer: B
    Explanation: Abuja has been the capital since 1991.

    2) Solve 2x + 3 = 11  (a) 2 (b) 4 (c) 6 (d) 8
    Ans - B

...and papers that print every answer in a key at the end:

    ANSWERS
    1. B  2. D  3. A  4. C

Anything it cannot read confidently is reported rather than guessed at. A
wrong answer key in a question bank is worse than a missing question.
"""

from __future__ import annotations

import re
from pathlib import Path
from typing import Dict, List, Optional, Tuple

from .models import LETTERS, Option, Question

# A question starts at a line beginning with a number and a separator.
QUESTION_START = re.compile(r"^\s*(?:Q(?:uestion)?\.?\s*)?(\d{1,3})\s*[\.\)\:]\s+(?=\S)", re.IGNORECASE)

# An option on its own line: "A. text", "(b) text", "C) text".
OPTION_LINE = re.compile(r"^\s*[\(\[]?([A-Ja-j])[\)\].\:]\s+(?=\S)")

# Options printed inline inside one paragraph.
OPTION_INLINE = re.compile(r"(?:(?<=\s)|^)[\(\[]?([A-Ea-e])[\)\].]\s+")

# The answer line inside a block.
ANSWER_LINE = re.compile(
    r"^\s*(?:correct\s+)?ans(?:wer)?\s*(?:is)?\s*[\-\:\.–—]?\s*"
    r"[\(\[]?([A-Ja-j])[\)\]]?\s*$",
    re.IGNORECASE,
)

# A looser answer marker that may carry trailing words.
ANSWER_PREFIX = re.compile(
    r"^\s*(?:correct\s+)?ans(?:wer)?\s*[\-\:\.–—]\s*[\(\[]?([A-Ja-j])[\)\]]?\b",
    re.IGNORECASE,
)

EXPLANATION_PREFIX = re.compile(
    r"^\s*(?:explanation|solution|reason|why)\s*[\-\:\.–—]?\s*",
    re.IGNORECASE,
)

# The start of a trailing answer key.
KEY_HEADING = re.compile(r"^\s*(?:answers?|answer\s*key|solutions?)\s*[:\-]?\s*$", re.IGNORECASE)

# "1. B" / "1-B" / "1 B" pairs inside a key block.
KEY_PAIR = re.compile(r"(\d{1,3})\s*[\.\)\-:]?\s*([A-Ja-j])\b")

# Page furniture worth dropping.
NOISE_LINE = re.compile(
    r"^\s*(?:page\s*\d+|\d+\s*\|\s*page|www\.[^\s]+|https?://\S+|copyright.*|all rights reserved.*)\s*$",
    re.IGNORECASE,
)

YEAR_IN_NAME = re.compile(r"(19|20)\d{2}")
EXAM_IN_NAME = re.compile(r"\b(jamb|utme|waec|ssce|neco|nabteb|post[\s_\-]?utme|gce)\b", re.IGNORECASE)

EXAM_CANONICAL = {
    "jamb": "JAMB",
    "utme": "JAMB",
    "waec": "WAEC",
    "ssce": "WAEC",
    "gce": "WAEC",
    "neco": "NECO",
    "nabteb": "NABTEB",
    "post utme": "Post-UTME",
    "post-utme": "Post-UTME",
    "post_utme": "Post-UTME",
    "postutme": "Post-UTME",
}


def clean_text(raw: str) -> str:
    """Normalise line endings, drop page furniture, join broken words."""
    text = raw.replace("\r\n", "\n").replace("\r", "\n")
    text = text.replace(" ", " ")

    # A word split across a line break by hyphenation.
    text = re.sub(r"(\w)-\n(\w)", r"\1\2", text)

    lines = []

    for line in text.split("\n"):
        line = line.rstrip()

        if NOISE_LINE.match(line):
            continue

        lines.append(line)

    text = "\n".join(lines)

    # Never more than one blank line in a row.
    return re.sub(r"\n{3,}", "\n\n", text).strip()


def split_answer_key(text: str) -> Tuple[str, Dict[int, str]]:
    """Separate a trailing answer key from the body of the paper.

    Returns the paper without the key, plus a mapping of question number to
    answer letter. An empty mapping means no key was found, which is the
    normal case for papers that print the answer under each question.
    """
    lines = text.split("\n")
    start = None

    for index in range(len(lines) - 1, -1, -1):
        if KEY_HEADING.match(lines[index]):
            start = index
            break

    if start is None:
        return text, {}

    key_block = "\n".join(lines[start + 1 :])
    pairs = KEY_PAIR.findall(key_block)

    # A heading with only a handful of pairs under it is probably not a key.
    if len(pairs) < 3:
        return text, {}

    mapping: Dict[int, str] = {}

    for number, letter in pairs:
        mapping[int(number)] = letter.upper()

    return "\n".join(lines[:start]), mapping


def split_blocks(text: str) -> List[Tuple[int, str]]:
    """Split a paper into (question number, block text) pairs."""
    lines = text.split("\n")
    blocks: List[Tuple[int, List[str]]] = []
    current: Optional[Tuple[int, List[str]]] = None

    for line in lines:
        match = QUESTION_START.match(line)

        if match:
            number = int(match.group(1))

            # A new block only if the number advances; "2." inside a passage
            # that follows question 40 is not question 2.
            if current is not None and number <= current[0] and number != 1:
                current[1].append(line)
                continue

            if current is not None:
                blocks.append(current)

            remainder = line[match.end() :]
            current = (number, [remainder])
            continue

        if current is not None:
            current[1].append(line)

    if current is not None:
        blocks.append(current)

    return [(number, "\n".join(body).strip()) for number, body in blocks]


def parse_block(number: int, block: str) -> Tuple[str, List[Option], Optional[str], str]:
    """Pull a body, options, answer letter and explanation out of one block."""
    body_lines: List[str] = []
    options: List[Option] = []
    answer: Optional[str] = None
    explanation_lines: List[str] = []

    mode = "body"

    for line in block.split("\n"):
        stripped = line.strip()

        if not stripped:
            continue

        exact = ANSWER_LINE.match(stripped)

        if exact:
            answer = exact.group(1).upper()
            mode = "after"
            continue

        loose = ANSWER_PREFIX.match(stripped)

        if loose and answer is None:
            answer = loose.group(1).upper()
            rest = stripped[loose.end() :].strip(" .:-")

            if rest:
                explanation_lines.append(rest)

            mode = "after"
            continue

        if EXPLANATION_PREFIX.match(stripped):
            explanation_lines.append(EXPLANATION_PREFIX.sub("", stripped).strip())
            mode = "explanation"
            continue

        if mode == "explanation":
            explanation_lines.append(stripped)
            continue

        option_match = OPTION_LINE.match(stripped)

        if option_match:
            options.append(Option(text=stripped[option_match.end() :].strip()))
            mode = "options"
            continue

        if mode == "options" and options:
            # A wrapped option line continues the option above it.
            options[-1].text = f"{options[-1].text} {stripped}".strip()
            continue

        if mode == "body":
            body_lines.append(stripped)

    body = " ".join(body_lines).strip()

    # Options printed inline: pull them off the end of the body.
    if not options and body:
        body, options = _split_inline_options(body)

    return body, options, answer, " ".join(explanation_lines).strip()


def _split_inline_options(body: str) -> Tuple[str, List[Option]]:
    """Split "stem (a) one (b) two (c) three" into a stem and options."""
    matches = list(OPTION_INLINE.finditer(body))

    if len(matches) < 2:
        return body, []

    # The options must start at A and run in order, otherwise this is prose
    # that merely happens to contain "a)" somewhere.
    letters = [match.group(1).upper() for match in matches]
    expected = list(LETTERS[: len(letters)])

    if letters != expected:
        return body, []

    stem = body[: matches[0].start()].strip()
    options: List[Option] = []

    for index, match in enumerate(matches):
        end = matches[index + 1].start() if index + 1 < len(matches) else len(body)
        options.append(Option(text=body[match.end() : end].strip()))

    if not stem:
        return body, []

    return stem, options


def meta_from_name(path: Path) -> Dict[str, str]:
    """Guess subject, exam type and year from a file or folder name.

    "JAMB Mathematics 2019.pdf" and "waec/biology/2021.txt" both work. The
    guess is only ever a default: anything passed on the command line wins.
    """
    haystack = " ".join([path.stem] + [part for part in path.parts[:-1]])
    haystack = haystack.replace("_", " ").replace("-", " ")

    meta: Dict[str, str] = {}

    year = YEAR_IN_NAME.search(haystack)

    if year:
        meta["year"] = year.group(0)

    exam = EXAM_IN_NAME.search(haystack)

    if exam:
        token = exam.group(1).lower().replace(" ", "").replace("-", "").replace("_", "")
        meta["exam_type"] = EXAM_CANONICAL.get(token, EXAM_CANONICAL.get(exam.group(1).lower(), exam.group(1).upper()))

    subject = _subject_from(haystack)

    if subject:
        meta["subject"] = subject

    return meta


SUBJECT_WORDS = [
    ("further mathematics", "Further Mathematics"),
    ("further maths", "Further Mathematics"),
    ("mathematics", "Mathematics"),
    ("maths", "Mathematics"),
    ("english", "English Language"),
    ("literature", "Literature in English"),
    ("biology", "Biology"),
    ("chemistry", "Chemistry"),
    ("physics", "Physics"),
    ("economics", "Economics"),
    ("government", "Government"),
    ("geography", "Geography"),
    ("commerce", "Commerce"),
    ("accounting", "Accounting"),
    ("account", "Accounting"),
    ("agricultural science", "Agricultural Science"),
    ("agric", "Agricultural Science"),
    ("civic", "Civic Education"),
    ("crs", "Christian Religious Studies"),
    ("christian religious", "Christian Religious Studies"),
    ("irs", "Islamic Religious Studies"),
    ("islamic religious", "Islamic Religious Studies"),
    ("history", "History"),
    ("computer", "Computer Studies"),
    ("french", "French"),
]


def _subject_from(haystack: str) -> str:
    lowered = haystack.lower()

    for needle, label in SUBJECT_WORDS:
        if needle in lowered:
            return label

    return ""


def parse_text(
    text: str,
    source: str = "",
    defaults: Optional[Dict[str, str]] = None,
) -> Tuple[List[Question], List[str]]:
    """Parse a whole paper.

    Returns the questions it is confident about and a list of warnings for
    the blocks it could not read.
    """
    defaults = defaults or {}
    warnings: List[str] = []

    cleaned = clean_text(text)
    paper, answer_key = split_answer_key(cleaned)
    blocks = split_blocks(paper)

    if not blocks:
        warnings.append(f"{source}: no numbered questions found")
        return [], warnings

    questions: List[Question] = []

    for number, block in blocks:
        body, options, answer, explanation = parse_block(number, block)

        if not body:
            warnings.append(f"{source} Q{number}: no question text")
            continue

        if answer is None and number in answer_key:
            answer = answer_key[number]

        question = Question(
            body=body,
            options=options,
            correct=[answer] if answer else [],
            explanation=explanation,
            subject=defaults.get("subject", ""),
            exam_type=defaults.get("exam_type", ""),
            topic=defaults.get("topic", ""),
            year=defaults.get("year", ""),
            difficulty=defaults.get("difficulty", "medium"),
            source=source,
            number=number,
        )

        questions.append(question)

    return questions, warnings


def questions_from_rows(
    rows: List[dict],
    source: str = "",
    defaults: Optional[Dict[str, str]] = None,
) -> Tuple[List[Question], List[str]]:
    """Build questions from an already-structured CSV or JSON file."""
    defaults = defaults or {}
    warnings: List[str] = []
    questions: List[Question] = []

    for index, row in enumerate(rows, start=1):
        lowered = {str(key).strip().lower(): value for key, value in row.items()}

        body = str(lowered.get("question") or lowered.get("body") or "").strip()

        if not body:
            warnings.append(f"{source} row {index}: no question column")
            continue

        options: List[Option] = []
        raw_options = lowered.get("options")

        if isinstance(raw_options, list):
            for item in raw_options:
                if isinstance(item, dict):
                    options.append(Option(text=str(item.get("text", "")).strip()))
                else:
                    options.append(Option(text=str(item).strip()))
        else:
            for letter in LETTERS:
                value = lowered.get(f"option_{letter.lower()}")

                if value is None or not str(value).strip():
                    continue

                options.append(Option(text=str(value).strip()))

        raw_correct = lowered.get("correct") or lowered.get("answer") or ""

        if isinstance(raw_correct, list):
            correct = [str(item).strip().upper() for item in raw_correct if str(item).strip()]
        else:
            correct = [
                part.strip().upper()
                for part in re.split(r"[\s,;|/]+", str(raw_correct))
                if part.strip()
            ]

        questions.append(
            Question(
                body=body,
                options=options,
                correct=correct,
                explanation=str(lowered.get("explanation") or "").strip(),
                subject=str(lowered.get("subject") or defaults.get("subject", "")).strip(),
                exam_type=str(lowered.get("exam_type") or defaults.get("exam_type", "")).strip(),
                topic=str(lowered.get("topic") or defaults.get("topic", "")).strip(),
                year=str(lowered.get("year") or defaults.get("year", "")).strip(),
                difficulty=str(lowered.get("difficulty") or defaults.get("difficulty", "medium")).strip(),
                qtype=str(lowered.get("type") or "single").strip() or "single",
                source=source,
                number=index,
            )
        )

    return questions, warnings
