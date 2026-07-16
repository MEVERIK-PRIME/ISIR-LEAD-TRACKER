from __future__ import annotations

from decimal import Decimal
from unittest import TestCase

from isir_lead_tracker_worker.document_contract import (
    ParsedCaseDocument,
    ParsedClaim,
    build_lead_key,
)
from isir_lead_tracker_worker.settings import WorkerSettings


class DocumentContractTest(TestCase):
    def setUp(self) -> None:
        self.settings = WorkerSettings()

    def test_parsed_claim_normalizes_ico_and_default_priority(self) -> None:
        claim = ParsedClaim(
            creditor_name="ACME s.r.o.",
            creditor_ico=" 123 45 678 ",
            amount_czk=Decimal("350000"),
            secured=False,
        )

        self.assertEqual("12345678", claim.creditor_ico)
        self.assertEqual("unsecured", claim.priority_label)
        self.assertEqual("CZK", claim.currency)

    def test_lead_key_is_stable_for_diacritics_and_spacing(self) -> None:
        first = build_lead_key("MSPH 99 INS 12345 / 2020", "Česká spořitelna, a.s.")
        second = build_lead_key(" MSPH 99   INS 12345 / 2020 ", "Ceska sporitelna a.s.")

        self.assertEqual(first, second)

    def test_parsed_document_computes_totals_and_fingerprints(self) -> None:
        parsed_document = ParsedCaseDocument(
            case_reference="MSPH 99 INS 12345 / 2020",
            isir_event_id="12345",
            isir_document_id="31670169",
            document_url="https://isir.justice.cz/isir_public_ws/doc/Document?idDokument=31670169",
            event_label="Konečná zpráva insolvenčního správce",
            claims=[
                ParsedClaim(
                    creditor_name="Dodavatel One s.r.o.",
                    amount_czk=Decimal("350000"),
                    secured=False,
                    claim_type="principal",
                    raw_excerpt="nezajištěná pohledávka 350 000 Kč",
                ),
                ParsedClaim(
                    creditor_name="Dodavatel Two s.r.o.",
                    amount_czk=Decimal("450000"),
                    secured=True,
                    claim_type="principal",
                    raw_excerpt="zajištěná pohledávka 450 000 Kč",
                ),
            ],
        )

        self.assertEqual(2, parsed_document.claim_count)
        self.assertEqual(Decimal("800000"), parsed_document.total_claim_amount_czk)
        self.assertEqual(Decimal("450000"), parsed_document.secured_claim_amount_czk)
        self.assertEqual(Decimal("350000"), parsed_document.unsecured_claim_amount_czk)
        self.assertEqual(2, len(parsed_document.lead_keys()))
        self.assertTrue(
            parsed_document.claims[0].build_claim_fingerprint(
                parsed_document.case_reference,
                parsed_document.isir_document_id,
            ),
        )

    def test_qualification_snapshots_follow_runtime_filter_rules(self) -> None:
        parsed_document = ParsedCaseDocument(
            case_reference="MSPH 99 INS 12345 / 2020",
            isir_event_id="12345",
            document_url="https://isir.justice.cz/isir_public_ws/doc/Document?idDokument=31670169",
            event_label="Konečná zpráva insolvenčního správce",
            claims=[
                ParsedClaim(
                    creditor_name="Dodavatel One s.r.o.",
                    amount_czk=Decimal("350000"),
                    secured=False,
                    claim_type="principal",
                ),
                ParsedClaim(
                    creditor_name="Velká banka a.s.",
                    amount_czk=Decimal("350000"),
                    secured=False,
                    claim_type="principal",
                    nace_code="64190",
                ),
            ],
        )

        snapshots = parsed_document.qualification_snapshots(self.settings)

        self.assertTrue(snapshots[0]["qualified"])
        self.assertFalse(snapshots[1]["qualified"])
        self.assertIn("creditor_name_blacklisted", snapshots[1]["reasons"])
