from __future__ import annotations

import re
import unicodedata
from dataclasses import dataclass
from decimal import Decimal

from .settings import WorkerSettings


def normalize_text(value: str) -> str:
    normalized = unicodedata.normalize("NFKD", value).encode("ascii", "ignore").decode("ascii")
    normalized = normalized.lower()
    normalized = re.sub(r"[^a-z0-9]+", " ", normalized)
    return normalized.strip()


@dataclass(slots=True)
class CreditorCandidate:
    name: str
    amount_czk: Decimal
    ico: str | None = None
    legal_form_code: str | None = None
    nace_code: str | None = None


@dataclass(slots=True)
class QualificationResult:
    qualified: bool
    reasons: list[str]


def amount_in_range(amount_czk: Decimal, settings: WorkerSettings) -> bool:
    return Decimal(settings.lead_min_claim_amount) <= amount_czk <= Decimal(settings.lead_max_claim_amount)


def name_matches_blacklist(name: str, settings: WorkerSettings) -> bool:
    normalized = normalize_text(name)
    return any(token in normalized for token in settings.creditor_name_blacklist)


def is_excluded_legal_form(code: str | None, settings: WorkerSettings) -> bool:
    if not code:
        return False

    return code in settings.excluded_legal_form_codes


def is_excluded_nace(code: str | None, settings: WorkerSettings) -> bool:
    if not code:
        return False

    return any(code.startswith(prefix) for prefix in settings.excluded_nace_codes)


def qualify_creditor(candidate: CreditorCandidate, settings: WorkerSettings) -> QualificationResult:
    reasons: list[str] = []

    if not amount_in_range(candidate.amount_czk, settings):
        reasons.append("amount_out_of_range")

    if name_matches_blacklist(candidate.name, settings):
        reasons.append("creditor_name_blacklisted")

    if candidate.ico:
        if is_excluded_legal_form(candidate.legal_form_code, settings):
            reasons.append("excluded_legal_form")

        if is_excluded_nace(candidate.nace_code, settings):
            reasons.append("excluded_nace")
    elif not settings.allow_natural_person_without_ico:
        reasons.append("missing_ico_not_allowed")

    return QualificationResult(
        qualified=not reasons,
        reasons=reasons,
    )
