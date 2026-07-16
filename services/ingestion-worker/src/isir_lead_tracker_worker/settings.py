from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from urllib.parse import urlparse, urlunparse

from pydantic import computed_field
from pydantic_settings import BaseSettings, SettingsConfigDict

SERVICE_ROOT = Path(__file__).resolve().parents[2]


class WorkerSettings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=SERVICE_ROOT / ".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    app_env: str = "local"
    database_url: str = ""
    redis_url: str = ""

    isir_public_ws_url: str = "https://isir.justice.cz:8443/isir_public_ws/IsirWsPublicService"
    isir_public_ws_fallback_urls: str = (
        "https://isir.justice.cz:8443/isir_public_ws/IsirWsPublicService,"
        "https://isir.justice.cz/isir_public_ws/IsirWsPublicService"
    )
    isir_document_base_url: str = "https://isir.justice.cz/isir/common/stat.do"
    isir_final_report_token: str = "konec,zaverecna"
    isir_sync_provider: str = "isir_public_ws"
    isir_sync_stream: str = "events"
    isir_sync_batch_size: int = 250
    isir_soap_namespace: str = "http://isirpublicws.cca.cz/types/"
    isir_event_by_id_request_element: str = "getIsirWsPublicIdDataRequest"
    isir_latest_id_request_element: str = "getIsirWsPublicPosledniIdDataRequest"
    isir_checkpoint_field: str = "idPodnetu"
    isir_timeout_seconds: int = 90
    isir_request_delay_seconds: float = 0.5
    orchestrator_import_url: str = "http://localhost/api/internal/isir/parsed-documents"
    internal_api_token: str | None = None
    lead_min_claim_amount: int = 300_000
    lead_max_claim_amount: int = 600_000
    worker_task_queue: str = "isir:tasks"
    creditor_name_blacklist: list[str] = [
        "banka",
        "bank",
        "pojistovna",
        "insurance",
        "ministerstvo",
        "urad",
        "úřad",
        "financni urad",
        "finanční úřad",
        "celni urad",
        "celní úřad",
        "sprava socialniho zabezpeceni",
        "správa sociálního zabezpečení",
        "zdravotni pojistovna",
        "zdravotní pojišťovna",
        "statni fond",
        "státní fond",
        "obec",
        "mesto",
        "město",
        "kraj",
        "ceska republika",
        "česká republika",
    ]
    excluded_legal_form_codes: list[str] = ["325", "331", "801", "804"]
    excluded_nace_codes: list[str] = ["64190", "64920", "651"]
    allow_natural_person_without_ico: bool = True

    google_creds_json: str | None = None
    google_project_id: str | None = None
    google_client_email: str | None = None
    google_private_key: str | None = None
    google_sheets_spreadsheet_id: str = ""
    google_sheets_worksheet_name: str = "Dashboard / Leady"

    ares_base_url: str = "https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty"
    hlidac_statu_api_key: str | None = None

    gemini_api_key: str | None = None
    groq_api_key: str | None = None
    llm_primary_provider: str = "gemini"
    llm_fallback_provider: str = "groq"
    enable_hlidac_statu: bool = True
    enable_gemini: bool = True
    enable_groq_fallback: bool = True

    @computed_field
    @property
    def orchestrator_checkpoint_url(self) -> str:
        """Derive checkpoint-advancement URL from the import URL base.

        e.g. http://host/api/internal/isir/parsed-documents
          →  http://host/api/internal/advance-checkpoint
        """
        p = urlparse(self.orchestrator_import_url)
        parts = p.path.rstrip("/").rsplit("/", 2)
        base_path = "/".join(parts[:-2]) if len(parts) >= 3 else ""
        return urlunparse(p._replace(path=f"{base_path}/advance-checkpoint"))

    @computed_field
    @property
    def google_credentials_mode(self) -> str:
        if self.google_creds_json:
            return "json"

        if self.google_project_id and self.google_client_email and self.google_private_key:
            return "split"

        return "missing"

    @computed_field
    @property
    def isir_public_ws_candidate_urls(self) -> list[str]:
        configured_fallbacks = [
            candidate.strip()
            for candidate in self.isir_public_ws_fallback_urls.split(",")
            if candidate.strip()
        ]
        defaults = [
            "https://isir.justice.cz:8443/isir_public_ws/IsirWsPublicService",
            "https://isir.justice.cz/isir_public_ws/IsirWsPublicService",
        ]
        ordered = [self.isir_public_ws_url, *configured_fallbacks, *defaults]
        unique_urls: list[str] = []
        for candidate in ordered:
            if candidate not in unique_urls:
                unique_urls.append(candidate)

        return unique_urls


@lru_cache(maxsize=1)
def load_settings() -> WorkerSettings:
    return WorkerSettings()
