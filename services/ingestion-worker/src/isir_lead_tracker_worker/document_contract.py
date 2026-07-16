from __future__ import annotations

from datetime import datetime
from decimal import Decimal
import hashlib
import re
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, computed_field, field_validator, model_validator

from .qualification import CreditorCandidate, normalize_text
from .settings import WorkerSettings


def normalize_case_reference(value: str) -> str:
    collapsed = re.sub(r"\s+", " ", value).strip()
    return collapsed.upper()


def build_lead_key(case_reference: str, creditor_name: str) -> str:
    normalized_case_reference = normalize_case_reference(case_reference)
    normalized_creditor_name = normalize_text(creditor_name)
    key_material = f"{normalized_case_reference}|{normalized_creditor_name}".encode("utf-8")
    return hashlib.sha256(key_material).hexdigest()


class ParsedClaim(BaseModel):
    model_config = ConfigDict(extra="ignore", str_strip_whitespace=True)

    creditor_name: str = Field(min_length=1)
    amount_czk: Decimal = Field(gt=0)
    currency: str = "CZK"
    secured: bool = False
    claim_type: str = "other"
    priority_label: str | None = None
    creditor_ico: str | None = None
    legal_form_code: str | None = None
    nace_code: str | None = None
    source_reference: str | None = None
    raw_excerpt: str | None = None
    metadata: dict[str, Any] = Field(default_factory=dict)

    @field_validator("creditor_ico")
    @classmethod
    def normalize_creditor_ico(cls, value: str | None) -> str | None:
        if value is None:
            return None

        digits_only = "".join(character for character in value if character.isdigit())
        return digits_only or None

    @field_validator("currency")
    @classmethod
    def normalize_currency(cls, value: str) -> str:
        return value.upper()

    @model_validator(mode="after")
    def apply_default_priority_label(self) -> "ParsedClaim":
        if self.priority_label:
            return self

        self.priority_label = "secured" if self.secured else "unsecured"
        return self

    def build_lead_key(self, case_reference: str) -> str:
        return build_lead_key(case_reference=case_reference, creditor_name=self.creditor_name)

    def build_claim_fingerprint(self, case_reference: str, document_id: str | None) -> str:
        key_material = "|".join(
            [
                self.build_lead_key(case_reference),
                document_id or "no-document-id",
                self.claim_type,
                f"{self.amount_czk:.2f}",
                self.currency,
                "secured" if self.secured else "unsecured",
                normalize_text(self.raw_excerpt or self.source_reference or self.creditor_name),
            ]
        )
        return hashlib.sha256(key_material.encode("utf-8")).hexdigest()

    def to_qualification_candidate(self) -> CreditorCandidate:
        return CreditorCandidate(
            name=self.creditor_name,
            amount_czk=self.amount_czk,
            ico=self.creditor_ico,
            legal_form_code=self.legal_form_code,
            nace_code=self.nace_code,
        )


class ParsedCaseDocument(BaseModel):
    model_config = ConfigDict(extra="ignore", str_strip_whitespace=True)

    case_reference: str = Field(min_length=1)
    isir_event_id: str = Field(min_length=1)
    document_url: str = Field(min_length=1)
    event_label: str = Field(min_length=1)
    isir_document_id: str | None = None
    section: str | None = None
    document_type: str | None = None
    source_provider: str = "isir_public_ws"
    extraction_method: Literal["text", "ocr", "llm", "hybrid"] = "hybrid"
    parser_version: str = "v1"
    debtor_name: str | None = None
    published_at: datetime | None = None
    parsed_at: datetime | None = None
    claims: list[ParsedClaim] = Field(default_factory=list)
    payload: dict[str, Any] = Field(default_factory=dict)

    @computed_field
    @property
    def claim_count(self) -> int:
        return len(self.claims)

    @computed_field
    @property
    def total_claim_amount_czk(self) -> Decimal:
        return sum((claim.amount_czk for claim in self.claims), Decimal("0"))

    @computed_field
    @property
    def secured_claim_amount_czk(self) -> Decimal:
        return sum((claim.amount_czk for claim in self.claims if claim.secured), Decimal("0"))

    @computed_field
    @property
    def unsecured_claim_amount_czk(self) -> Decimal:
        return sum((claim.amount_czk for claim in self.claims if not claim.secured), Decimal("0"))

    def summary(self) -> dict[str, Any]:
        return {
            "case_reference": normalize_case_reference(self.case_reference),
            "isir_event_id": self.isir_event_id,
            "isir_document_id": self.isir_document_id,
            "claim_count": self.claim_count,
            "total_claim_amount_czk": f"{self.total_claim_amount_czk:.2f}",
            "secured_claim_amount_czk": f"{self.secured_claim_amount_czk:.2f}",
            "unsecured_claim_amount_czk": f"{self.unsecured_claim_amount_czk:.2f}",
            "secured_claims": sum(1 for claim in self.claims if claim.secured),
            "unsecured_claims": sum(1 for claim in self.claims if not claim.secured),
        }

    def lead_keys(self) -> list[str]:
        return [claim.build_lead_key(self.case_reference) for claim in self.claims]

    def qualification_snapshots(self, settings: WorkerSettings) -> list[dict[str, Any]]:
        from .qualification import qualify_creditor

        snapshots: list[dict[str, Any]] = []
        for claim in self.claims:
            result = qualify_creditor(claim.to_qualification_candidate(), settings)
            snapshots.append(
                {
                    "creditor_name": claim.creditor_name,
                    "lead_key": claim.build_lead_key(self.case_reference),
                    "claim_fingerprint": claim.build_claim_fingerprint(self.case_reference, self.isir_document_id),
                    "qualified": result.qualified,
                    "reasons": result.reasons,
                    "secured": claim.secured,
                    "amount_czk": f"{claim.amount_czk:.2f}",
                }
            )

        return snapshots
