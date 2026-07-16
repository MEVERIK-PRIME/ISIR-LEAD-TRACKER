from __future__ import annotations

from typing import Any

import httpx

from .settings import WorkerSettings


class OrchestratorImportClient:
    def __init__(
        self,
        settings: WorkerSettings,
        http_client: httpx.Client | None = None,
    ) -> None:
        self.settings = settings
        self.http_client = http_client or httpx.Client(timeout=settings.isir_timeout_seconds)

    def import_parsed_document(self, payload: dict[str, Any]) -> dict[str, Any]:
        headers = {
            "Accept": "application/json",
            "Content-Type": "application/json",
        }

        if self.settings.internal_api_token:
            headers["X-Internal-Token"] = self.settings.internal_api_token

        response = self.http_client.post(
            self.settings.orchestrator_import_url,
            json=payload,
            headers=headers,
        )
        response.raise_for_status()
        return response.json()
