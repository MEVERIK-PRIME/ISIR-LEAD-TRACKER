from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field


class IsirSyncSourceContext(BaseModel):
    model_config = ConfigDict(extra="ignore")

    public_ws_url: str
    document_base_url: str
    use_hlidac_statu: bool = True


class IsirSyncFilterContext(BaseModel):
    model_config = ConfigDict(extra="ignore")

    section: str
    proceeding: str
    final_report_token: str
    lead_min_claim_amount: int
    lead_max_claim_amount: int


class IsirSyncTaskContext(BaseModel):
    model_config = ConfigDict(extra="ignore")

    source: IsirSyncSourceContext
    filters: IsirSyncFilterContext


class WorkerTaskEnvelope(BaseModel):
    model_config = ConfigDict(extra="ignore")

    task_id: str
    task_type: Literal["isir.sync.events"]
    provider: str
    stream: str
    mode: Literal["incremental", "backfill"]
    checkpoint: str
    limit: int = Field(gt=0)
    context: IsirSyncTaskContext
    requested_at: str

    def summary(self) -> dict[str, Any]:
        return {
            "task_id": self.task_id,
            "task_type": self.task_type,
            "provider": self.provider,
            "stream": self.stream,
            "mode": self.mode,
            "checkpoint": self.checkpoint,
            "limit": self.limit,
            "section": self.context.filters.section,
            "lead_amount_range": [
                self.context.filters.lead_min_claim_amount,
                self.context.filters.lead_max_claim_amount,
            ],
        }
