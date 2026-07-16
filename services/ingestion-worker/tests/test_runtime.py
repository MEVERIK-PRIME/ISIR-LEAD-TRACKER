from __future__ import annotations

import json
from unittest import TestCase

import httpx

from isir_lead_tracker_worker.contracts import WorkerTaskEnvelope
from isir_lead_tracker_worker.document_contract import ParsedCaseDocument, ParsedClaim
from isir_lead_tracker_worker.isir_client import (
    IsirDocumentReference,
    IsirEventBatch,
    IsirSourceEvent,
)
from isir_lead_tracker_worker.orchestrator_client import OrchestratorImportClient
from isir_lead_tracker_worker.runtime import WorkerRuntime, event_matches_prefilter
from isir_lead_tracker_worker.settings import WorkerSettings


class FakeIsirClient:
    def __init__(self, batch: IsirEventBatch) -> None:
        self.batch = batch

    def fetch_event_batch(self, checkpoint: str, limit: int) -> IsirEventBatch:
        return self.batch


class RuntimeTest(TestCase):
    def setUp(self) -> None:
        self.settings = WorkerSettings(internal_api_token="secret-token")
        self.envelope = WorkerTaskEnvelope.model_validate(
            {
                "task_id": "task-1",
                "task_type": "isir.sync.events",
                "provider": "isir_public_ws",
                "stream": "events",
                "mode": "incremental",
                "checkpoint": "42",
                "limit": 10,
                "context": {
                    "source": {
                        "public_ws_url": "https://isir.justice.cz:8443/isir_public_ws/IsirWsPublicService",
                        "document_base_url": "https://isir.justice.cz/isir/common/stat.do",
                        "use_hlidac_statu": True,
                    },
                    "filters": {
                        "section": "B",
                        "proceeding": "konkurs",
                        "final_report_token": "konec",
                        "lead_min_claim_amount": 300000,
                        "lead_max_claim_amount": 600000,
                    },
                },
                "requested_at": "2026-07-16T00:00:00+00:00",
            }
        )

    def test_prefilter_matches_section_b_and_final_report(self) -> None:
        matching_event = IsirSourceEvent(
            event_id="123",
            label="Konečná zpráva insolvenčního správce",
            published_at="2026-07-16T00:00:00+00:00",
            section="B",
            status=None,
            case_reference="MSPH 99 INS 12345 / 2020",
            debtor_name="Novak Holding s.r.o.",
            document=None,
            raw_fields={},
        )
        non_matching_event = IsirSourceEvent(
            event_id="124",
            label="Usnesení",
            published_at="2026-07-16T00:00:00+00:00",
            section="A",
            status=None,
            case_reference="MSPH 99 INS 12345 / 2020",
            debtor_name="Novak Holding s.r.o.",
            document=None,
            raw_fields={},
        )

        self.assertTrue(event_matches_prefilter(matching_event, self.envelope, self.settings))
        self.assertFalse(event_matches_prefilter(non_matching_event, self.envelope, self.settings))

    def test_orchestrator_client_sends_internal_token_header(self) -> None:
        seen_headers: dict[str, str] = {}

        def handler(request: httpx.Request) -> httpx.Response:
            seen_headers["token"] = request.headers.get("X-Internal-Token", "")
            return httpx.Response(200, json={"data": {"document_id": 1}})

        client = OrchestratorImportClient(
            settings=self.settings,
            http_client=httpx.Client(transport=httpx.MockTransport(handler)),
        )

        response = client.import_parsed_document({"case_reference": "MSPH 99 INS 12345 / 2020"})

        self.assertEqual("secret-token", seen_headers["token"])
        self.assertEqual(1, response["data"]["document_id"])

    def test_runtime_filters_events_and_submits_only_importable_documents(self) -> None:
        calls: list[dict[str, object]] = []

        def handler(request: httpx.Request) -> httpx.Response:
            calls.append(json.loads(request.content.decode("utf-8")))
            return httpx.Response(200, json={"data": {"document_id": 11, "claim_count": 0}})

        batch = IsirEventBatch(
            requested_checkpoint="42",
            next_checkpoint="45",
            events=[
                IsirSourceEvent(
                    event_id="43",
                    label="Konečná zpráva insolvenčního správce",
                    published_at="2026-07-16T00:00:00+00:00",
                    section="B",
                    status=None,
                    case_reference="MSPH 99 INS 12345 / 2020",
                    debtor_name="Novak Holding s.r.o.",
                    document=IsirDocumentReference(
                        source_url="https://isir.justice.cz/isir_public_ws/doc/Document?idDokument=11",
                        normalized_url="https://isir.justice.cz/isir_public_ws/doc/Document?idDokument=11",
                        document_id="11",
                    ),
                    raw_fields={"oddil": "B"},
                ),
                IsirSourceEvent(
                    event_id="44",
                    label="Usnesení",
                    published_at="2026-07-16T00:00:00+00:00",
                    section="A",
                    status=None,
                    case_reference="MSPH 99 INS 12345 / 2020",
                    debtor_name="Novak Holding s.r.o.",
                    document=None,
                    raw_fields={"oddil": "A"},
                ),
            ],
        )

        runtime = WorkerRuntime(
            settings=self.settings,
            isir_client=FakeIsirClient(batch),
            orchestrator_client=OrchestratorImportClient(
                settings=self.settings,
                http_client=httpx.Client(transport=httpx.MockTransport(handler)),
            ),
            document_builder=lambda event, settings: ParsedCaseDocument(
                case_reference=event.case_reference or "MSPH 99 INS 12345 / 2020",
                isir_event_id=event.event_id,
                isir_document_id=event.document.document_id if event.document else event.event_id,
                document_url=event.document.normalized_url if event.document else "https://isir.justice.cz/example.txt",
                event_label=event.label,
                section=event.section,
                debtor_name=event.debtor_name,
                claims=[
                    ParsedClaim(
                        creditor_name="Dodavatel One s.r.o.",
                        amount_czk="350000",
                        secured=False,
                        claim_type="principal",
                    )
                ],
            ),
        )

        result = runtime.run_sync_task(self.envelope)

        self.assertEqual(2, result["fetched_events"])
        self.assertEqual(1, result["filtered_events"])
        self.assertEqual(1, result["submitted_documents"])
        self.assertEqual("MSPH 99 INS 12345 / 2020", calls[0]["case_reference"])
        self.assertEqual("11", calls[0]["isir_document_id"])
