"""Turn a source file into plain text.

PDF and DOCX support depend on third-party packages. They are imported lazily
and the error, when one is missing, says exactly what to install rather than
dying with an ImportError in the middle of a run.
"""

from __future__ import annotations

import csv
import io
import json
from pathlib import Path
from typing import List

TEXT_SUFFIXES = {".txt", ".md", ".text"}
PDF_SUFFIXES = {".pdf"}
DOCX_SUFFIXES = {".docx"}
TABLE_SUFFIXES = {".csv", ".tsv"}
JSON_SUFFIXES = {".json"}

SUPPORTED = TEXT_SUFFIXES | PDF_SUFFIXES | DOCX_SUFFIXES | TABLE_SUFFIXES | JSON_SUFFIXES


class ExtractError(RuntimeError):
    """Raised when a file cannot be read at all."""


def is_supported(path: Path) -> bool:
    return path.suffix.lower() in SUPPORTED


def read_text(path: Path) -> str:
    """Plain text for any supported document type."""
    suffix = path.suffix.lower()

    if suffix in TEXT_SUFFIXES:
        return _read_plain(path)

    if suffix in PDF_SUFFIXES:
        return _read_pdf(path)

    if suffix in DOCX_SUFFIXES:
        return _read_docx(path)

    raise ExtractError(f"{path.name}: no text extractor for {suffix} files")


def _read_plain(path: Path) -> str:
    for encoding in ("utf-8-sig", "utf-8", "cp1252", "latin-1"):
        try:
            return path.read_text(encoding=encoding)
        except UnicodeDecodeError:
            continue

    raise ExtractError(f"{path.name}: could not be decoded as text")


def _read_pdf(path: Path) -> str:
    """Extract text from a PDF, preferring pdfplumber's layout handling."""
    try:
        import pdfplumber  # type: ignore
    except ImportError:
        pdfplumber = None

    if pdfplumber is not None:
        pages: List[str] = []

        with pdfplumber.open(str(path)) as pdf:
            for page in pdf.pages:
                pages.append(page.extract_text() or "")

        text = "\n".join(pages)

        if text.strip():
            return text

    try:
        from pypdf import PdfReader  # type: ignore
    except ImportError as exc:  # pragma: no cover - environment dependent
        raise ExtractError(
            f"{path.name}: reading PDFs needs pdfplumber or pypdf. "
            "Run: pip install -r requirements.txt"
        ) from exc

    reader = PdfReader(str(path))
    text = "\n".join((page.extract_text() or "") for page in reader.pages)

    if not text.strip():
        raise ExtractError(
            f"{path.name}: no text layer found. This is a scanned PDF — "
            "run OCR over it first (e.g. ocrmypdf) and try again."
        )

    return text


def _read_docx(path: Path) -> str:
    try:
        import docx  # type: ignore
    except ImportError as exc:  # pragma: no cover - environment dependent
        raise ExtractError(
            f"{path.name}: reading .docx needs python-docx. "
            "Run: pip install -r requirements.txt"
        ) from exc

    document = docx.Document(str(path))
    lines = [paragraph.text for paragraph in document.paragraphs]

    # Questions are often laid out in tables; read those too, cell by cell.
    for table in document.tables:
        for row in table.rows:
            for cell in row.cells:
                if cell.text.strip():
                    lines.append(cell.text)

    return "\n".join(lines)


def read_rows(path: Path) -> List[dict]:
    """Rows from a CSV, TSV or JSON file that is already structured."""
    suffix = path.suffix.lower()

    if suffix in JSON_SUFFIXES:
        data = json.loads(_read_plain(path))

        if isinstance(data, dict):
            for key in ("questions", "items", "data", "rows"):
                if isinstance(data.get(key), list):
                    return [row for row in data[key] if isinstance(row, dict)]

            return []

        if isinstance(data, list):
            return [row for row in data if isinstance(row, dict)]

        return []

    delimiter = "\t" if suffix == ".tsv" else ","
    text = _read_plain(path)

    reader = csv.DictReader(io.StringIO(text), delimiter=delimiter)

    return [
        {(key or "").strip().lower(): (value or "") for key, value in row.items()}
        for row in reader
    ]
