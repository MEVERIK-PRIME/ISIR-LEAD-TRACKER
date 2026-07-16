from __future__ import annotations

import logging
from typing import Any, Callable

from .contracts import WorkerTaskEnvelope
from .document_contract import ParsedCaseDocument
from .document_pipeline import DocumentParsingPipeline
from .isir_client import IsirEventBatch, IsirSourceEvent, IsirPublicWsClient, event_matches_final_report
from .orchestrator_client import OrchestratorImportClient
from .settings import WorkerSettings

logger = logging.getLogger(__name__)


def event_matches_prefilter(event: IsirSourceEvent, envelope: WorkerTaskEnvelope, settings: WorkerSettings) -> bool:
    section = envelope.context.filters.section.strip().upper()
    if (event.section or "").strip().upper() != section:
        return False

    matches = event_matches_final_report(event.label, settings)
    if not matches:
        logger.debug("Section B skipped (no final_report_token match): %r", event.label)
    return matches


def build_parsed_document(event: IsirSourceEvent, settings: WorkerSettings) -> ParsedCaseDocument | None:
    return DocumentParsingPipeline(settings=settings).build_parsed_document(event)


class WorkerRuntime:
    def __init__(
        self,
        settings: WorkerSettings,
        isir_client: IsirPublicWsClient | None = None,
        orchestrator_client: OrchestratorImportClient | None = None,
        document_builder: Callable[[IsirSourceEvent, WorkerSettings], ParsedCaseDocument | None] = build_parsed_document,
    ) -> None:
        self.settings = settings
        self.isir_client = isir_client or IsirPublicWsClient(settings=settings)
        self.orchestrator_client = orchestrator_client or OrchestratorImportClient(settings=settings)
        self.document_builder = document_builder

    def run_sync_task(self, envelope: WorkerTaskEnvelope) -> dict[str, Any]:
        batch = self.isir_client.fetch_event_batch(checkpoint=envelope.checkpoint, limit=envelope.limit)
        return self._submit_batch(envelope, batch)

    def _submit_batch(self, envelope: WorkerTaskEnvelope, batch: IsirEventBatch) -> dict[str, Any]:
        filtered_events = [event for event in batch.events if event_matches_prefilter(event, envelope, self.settings)]
        submitted_documents = 0
        skipped_missing_document = 0
        import_results: list[dict[str, Any]] = []

        for event in filtered_events:
            parsed_document = self.document_builder(event, self.settings)
            if parsed_document is None:
                skipped_missing_document += 1
                continue

            import_results.append(
                self.orchestrator_client.import_parsed_document(
                    parsed_document.model_dump(mode="json"),
                ),
            )
            submitted_documents += 1

        return {
            "requested_checkpoint": envelope.checkpoint,
            "next_checkpoint": batch.next_checkpoint,
            "fetched_events": len(batch.events),
            "filtered_events": len(filtered_events),
            "submitted_documents": submitted_documents,
            "skipped_missing_document": skipped_missing_document,
            "imports": import_results,
        }
