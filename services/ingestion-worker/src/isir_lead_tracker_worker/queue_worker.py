from __future__ import annotations

import logging

import httpx
import redis
from pydantic import ValidationError

from .contracts import WorkerTaskEnvelope
from .runtime import WorkerRuntime
from .settings import WorkerSettings


class RedisQueueWorker:
    def __init__(
        self,
        settings: WorkerSettings,
        runtime: WorkerRuntime | None = None,
        redis_client: redis.Redis | None = None,
    ) -> None:
        self.settings = settings
        self.runtime = runtime or WorkerRuntime(settings=settings)
        self.logger = logging.getLogger(__name__)
        self.redis_client = redis_client or self._build_redis_client()

    def consume_forever(self, timeout_seconds: int = 5) -> None:
        queue_name = self.settings.worker_task_queue
        self.logger.info("Starting Redis queue consumer for queue=%s", queue_name)

        while True:
            item = self.redis_client.brpop(queue_name, timeout=timeout_seconds)
            if item is None:
                continue

            _, raw_payload = item
            payload_text = self._payload_text(raw_payload)
            self._process_payload(payload_text)

    def _process_payload(self, payload_text: str) -> None:
        try:
            envelope = WorkerTaskEnvelope.model_validate_json(payload_text.lstrip("\ufeff"))
        except ValidationError as exc:
            self.logger.error("Discarding invalid worker payload: %s", exc)
            return

        try:
            result = self.runtime.run_sync_task(envelope)
        except httpx.HTTPError as exc:
            self.logger.error("Orchestrator request failed for task_id=%s: %s", envelope.task_id, exc)
            return

        self.logger.info(
            "Processed task_id=%s fetched=%s submitted=%s next_checkpoint=%s",
            envelope.task_id,
            result.get("fetched_events"),
            result.get("submitted_documents"),
            result.get("next_checkpoint"),
        )

    def _build_redis_client(self) -> redis.Redis:
        if not self.settings.redis_url:
            raise ValueError("REDIS_URL must be configured for worker queue consumption.")

        return redis.Redis.from_url(self.settings.redis_url, decode_responses=False)

    @staticmethod
    def _payload_text(raw_payload: bytes | str) -> str:
        if isinstance(raw_payload, bytes):
            return raw_payload.decode("utf-8")

        return raw_payload
