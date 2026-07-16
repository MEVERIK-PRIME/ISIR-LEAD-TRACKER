from __future__ import annotations

import argparse
import logging
from pathlib import Path

from .contracts import WorkerTaskEnvelope
from .document_contract import ParsedCaseDocument
from .queue_worker import RedisQueueWorker
from .runtime import WorkerRuntime
from .settings import WorkerSettings, load_settings


def configure_logging() -> None:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Run the ISIR lead tracker worker.")
    parser.add_argument(
        "--print-settings",
        action="store_true",
        help="Print the resolved runtime settings and exit.",
    )
    parser.add_argument(
        "--payload-file",
        type=Path,
        help="Validate and print a worker task payload from a JSON file.",
    )
    parser.add_argument(
        "--parsed-document-file",
        type=Path,
        help="Validate and print a parsed case document JSON file.",
    )
    parser.add_argument(
        "--run-payload-file",
        type=Path,
        help="Execute a worker sync task payload from a JSON file.",
    )
    parser.add_argument(
        "--consume-queue",
        action="store_true",
        help="Continuously consume sync tasks from Redis queue.",
    )
    return parser


def settings_summary(settings: WorkerSettings) -> dict[str, object]:
    return {
        "app_env": settings.app_env,
        "database_url_configured": bool(settings.database_url),
        "redis_url_configured": bool(settings.redis_url),
        "google_credentials_mode": settings.google_credentials_mode,
        "llm_chain": [settings.llm_primary_provider, settings.llm_fallback_provider],
        "hlidac_statu_enabled": settings.enable_hlidac_statu,
        "lead_amount_range": [settings.lead_min_claim_amount, settings.lead_max_claim_amount],
        "worker_task_queue": settings.worker_task_queue,
    }


def read_json_text(path: Path) -> str:
    return path.read_text(encoding="utf-8").lstrip("\ufeff")


def main() -> None:
    configure_logging()
    args = build_parser().parse_args()
    settings = load_settings()

    if args.payload_file:
        payload = WorkerTaskEnvelope.model_validate_json(read_json_text(args.payload_file))
        logging.getLogger(__name__).info("Validated worker task payload: %s", payload.summary())
        return

    if args.parsed_document_file:
        parsed_document = ParsedCaseDocument.model_validate_json(
            read_json_text(args.parsed_document_file),
        )
        logging.getLogger(__name__).info("Validated parsed case document: %s", parsed_document.summary())
        return

    if args.run_payload_file:
        payload = WorkerTaskEnvelope.model_validate_json(read_json_text(args.run_payload_file))
        result = WorkerRuntime(settings=settings).run_sync_task(payload)
        logging.getLogger(__name__).info("Executed worker sync task: %s", result)
        return

    if args.consume_queue:
        RedisQueueWorker(settings=settings).consume_forever()
        return

    if args.print_settings:
        logging.getLogger(__name__).info("Worker settings: %s", settings_summary(settings))
        return

    logging.getLogger(__name__).info(
        "Worker scaffold ready. ISIR source=%s, final report token=%s",
        settings.isir_public_ws_url,
        settings.isir_final_report_token,
    )


if __name__ == "__main__":
    main()
