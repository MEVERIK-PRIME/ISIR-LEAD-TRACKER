from __future__ import annotations

from decimal import Decimal
from unittest import TestCase

from isir_lead_tracker_worker.document_pipeline import (
    CASE_REFERENCE_PATTERN,
    DEBTOR_PATTERN,
    DocumentDownloadClient,
    DocumentParsingPipeline,
    DownloadedDocument,
    extract_case_reference,
    extract_debtor_name,
    extract_text_from_document,
    parse_claims_from_text,
)
from isir_lead_tracker_worker.isir_client import IsirDocumentReference, IsirSourceEvent
from isir_lead_tracker_worker.settings import WorkerSettings

SAMPLE_FINAL_REPORT_TEXT = """
Spisová značka: MSPH 99 INS 12345 / 2020
Dlužník: Novak Holding s.r.o.

Dodavatel One s.r.o. - nezajištěná pohledávka ve výši 350 000 Kč
Dodavatel Two s.r.o. - zajištěná pohledávka ve výši 450 000,00 Kč
Dodavatel Three s.r.o. - náklady řízení 125 000 Kč
"""


class FakeDownloadClient(DocumentDownloadClient):
    def __init__(self, settings: WorkerSettings, document: DownloadedDocument) -> None:
        super().__init__(settings=settings)
        self.document = document

    def download(self, url: str) -> DownloadedDocument:
        return self.document


class DocumentPipelineTest(TestCase):
    def setUp(self) -> None:
        self.settings = WorkerSettings()

    def test_regexes_detect_case_reference_and_debtor(self) -> None:
        self.assertIsNotNone(CASE_REFERENCE_PATTERN.search(SAMPLE_FINAL_REPORT_TEXT))
        self.assertIsNotNone(DEBTOR_PATTERN.search(SAMPLE_FINAL_REPORT_TEXT))
        self.assertEqual("MSPH 99 INS 12345 / 2020", extract_case_reference(SAMPLE_FINAL_REPORT_TEXT))
        self.assertEqual("Novak Holding s.r.o.", extract_debtor_name(SAMPLE_FINAL_REPORT_TEXT))

    def test_text_document_extraction_returns_plain_text(self) -> None:
        document = DownloadedDocument(
            url="https://isir.justice.cz/example.txt",
            content_type="text/plain; charset=utf-8",
            body=SAMPLE_FINAL_REPORT_TEXT.encode("utf-8"),
        )

        extracted = extract_text_from_document(document)

        self.assertIn("Dodavatel One s.r.o.", extracted)

    def test_claim_parser_extracts_secured_and_unsecured_claims(self) -> None:
        claims = parse_claims_from_text(SAMPLE_FINAL_REPORT_TEXT)

        self.assertEqual(3, len(claims))
        self.assertEqual("Dodavatel One s.r.o.", claims[0].creditor_name)
        self.assertFalse(claims[0].secured)
        self.assertEqual(Decimal("350000"), claims[0].amount_czk)
        self.assertTrue(claims[1].secured)
        self.assertEqual("principal", claims[1].claim_type)
        self.assertEqual("costs", claims[2].claim_type)

    def test_pipeline_builds_parsed_document_with_real_claims(self) -> None:
        document = DownloadedDocument(
            url="https://isir.justice.cz/example.txt",
            content_type="text/plain; charset=utf-8",
            body=SAMPLE_FINAL_REPORT_TEXT.encode("utf-8"),
        )

        pipeline = DocumentParsingPipeline(
            settings=self.settings,
            download_client=FakeDownloadClient(settings=self.settings, document=document),
        )

        parsed_document = pipeline.build_parsed_document(
            IsirSourceEvent(
                event_id="12345",
                label="Konečná zpráva insolvenčního správce",
                published_at="2026-07-16T00:00:00+00:00",
                section="B",
                status=None,
                case_reference="MSPH 99 INS 12345 / 2020",
                debtor_name=None,
                document=IsirDocumentReference(
                    source_url="https://isir.justice.cz/example.txt",
                    normalized_url="https://isir.justice.cz/example.txt",
                    document_id="31670169",
                ),
                raw_fields={"oddil": "B"},
            )
        )

        self.assertIsNotNone(parsed_document)
        self.assertEqual(3, parsed_document.claim_count)
        self.assertEqual("Novak Holding s.r.o.", parsed_document.debtor_name)
        self.assertEqual("text", parsed_document.extraction_method)
        self.assertEqual("heuristic-v1", parsed_document.parser_version)
