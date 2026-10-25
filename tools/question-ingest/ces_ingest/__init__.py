"""Classic EDU question ingest.

Turns past-question papers you hold the rights to into the CSV and JSON the
Classic EDU Suite CBT importer accepts, grouped by subject, deduplicated and
validated.

Stages: extract -> parse -> validate -> dedupe -> write.
"""

__all__ = ["dedupe", "extract", "models", "parse", "validate", "write"]

__version__ = "1.0.0"
