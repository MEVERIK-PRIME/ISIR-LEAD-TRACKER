from __future__ import annotations

from dataclasses import dataclass
from urllib.parse import parse_qs, urljoin, urlsplit, urlunsplit
import xml.etree.ElementTree as ET

import httpx

from .qualification import normalize_text
from .settings import WorkerSettings

SOAP_ENV_NAMESPACE = "http://schemas.xmlsoap.org/soap/envelope/"
STATUS_OK = "OK"


@dataclass(slots=True)
class IsirDocumentReference:
    source_url: str
    normalized_url: str
    document_id: str | None


@dataclass(slots=True)
class IsirSourceEvent:
    event_id: str
    label: str
    published_at: str | None
    section: str | None
    status: str | None
    case_reference: str | None
    debtor_name: str | None
    document: IsirDocumentReference | None
    raw_fields: dict[str, str]


@dataclass(slots=True)
class IsirEventBatch:
    requested_checkpoint: str
    next_checkpoint: str
    events: list[IsirSourceEvent]


@dataclass(slots=True)
class IsirResponseStatus:
    state: str
    error_code: str | None
    error_description: str | None


class IsirPublicWsError(RuntimeError):
    pass


def local_name(tag: str) -> str:
    if "}" in tag:
        return tag.rsplit("}", 1)[1]

    return tag


def find_elements(root: ET.Element, name: str) -> list[ET.Element]:
    return [node for node in root.iter() if local_name(node.tag) == name]


def first_text(element: ET.Element, aliases: set[str]) -> str | None:
    for node in element.iter():
        if local_name(node.tag) not in aliases:
            continue

        text = (node.text or "").strip()
        if text:
            return text

    return None


def first_text_by_priority(element: ET.Element, aliases: list[str]) -> str | None:
    for alias in aliases:
        text = first_text(element, {alias})
        if text is not None:
            return text

    return None


def collect_text_fields(element: ET.Element) -> dict[str, str]:
    fields: dict[str, str] = {}

    for node in element.iter():
        text = (node.text or "").strip()
        if not text:
            continue

        fields.setdefault(local_name(node.tag), text)

    return fields


def normalize_document_url(url: str, settings: WorkerSettings) -> str:
    parsed = urlsplit(url)

    if not parsed.scheme and not parsed.netloc:
        return urljoin(settings.isir_document_base_url, url)

    netloc = parsed.netloc or urlsplit(settings.isir_document_base_url).netloc or parsed.hostname or ""
    path = parsed.path

    if path.startswith("/isir_public_ws/doc/Document") and not parsed.query:
        query = parsed.fragment
    else:
        query = parsed.query

    return urlunsplit(("https", netloc, path, query, ""))


def extract_document_reference(element: ET.Element, settings: WorkerSettings) -> IsirDocumentReference | None:
    document_url = first_text(element, {"dokumentUrl", "dokument", "document", "url"})
    if document_url is None:
        return None

    normalized_url = normalize_document_url(document_url, settings)
    document_id = parse_qs(urlsplit(normalized_url).query).get("idDokument", [None])[0]

    return IsirDocumentReference(
        source_url=document_url,
        normalized_url=normalized_url,
        document_id=document_id,
    )


def pick_next_checkpoint(events: list[IsirSourceEvent], fallback: str) -> str:
    numeric_ids = [int(event.event_id) for event in events if event.event_id.isdigit()]
    if numeric_ids:
        return str(max(numeric_ids))

    return fallback


def event_matches_final_report(label: str, settings: WorkerSettings) -> bool:
    normalized_label = normalize_text(label)
    normalized_token = normalize_text(settings.isir_final_report_token)
    return normalized_token in normalized_label


def parse_status(root: ET.Element) -> IsirResponseStatus:
    status_element = next(iter(find_elements(root, "status")), None)
    if status_element is None:
        return IsirResponseStatus(state=STATUS_OK, error_code=None, error_description=None)

    return IsirResponseStatus(
        state=first_text(status_element, {"stav"}) or STATUS_OK,
        error_code=first_text(status_element, {"kodChyby"}),
        error_description=first_text(status_element, {"popisChyby"}),
    )


def ensure_success_status(root: ET.Element) -> None:
    status = parse_status(root)
    if status.state == STATUS_OK:
        return

    error_message = status.error_description or "Unknown ISIR WS error."
    error_code = status.error_code or "UNKNOWN"
    raise IsirPublicWsError(f"ISIR WS returned {status.state} ({error_code}): {error_message}")


def parse_event_node(element: ET.Element, settings: WorkerSettings) -> IsirSourceEvent:
    fields = collect_text_fields(element)
    label = first_text_by_priority(
        element,
        ["popisUdalosti", "typUdalosti", "typ", "text", "nazev", "popis"],
    ) or "unknown"
    event_id = first_text(element, {"id", "idPodnetu", "idPodani", "eventId"}) or "unknown"

    return IsirSourceEvent(
        event_id=event_id,
        label=label,
        published_at=first_text(element, {"datumZverejneniUdalosti", "datum", "publishdate", "zverejneno"}),
        section=first_text(element, {"oddil", "section"}),
        status=first_text(element, {"stav", "status"}),
        case_reference=first_text(element, {"spisovaZnacka", "spisZnacka", "caseReference"}),
        debtor_name=first_text(element, {"nazev", "dluznikNazev", "debtorName"}),
        document=extract_document_reference(element, settings),
        raw_fields=fields,
    )


def parse_event_response(xml_text: str, requested_checkpoint: str, settings: WorkerSettings) -> IsirEventBatch:
    root = ET.fromstring(xml_text)
    ensure_success_status(root)

    events = [parse_event_node(node, settings) for node in find_elements(root, "data")]

    return IsirEventBatch(
        requested_checkpoint=requested_checkpoint,
        next_checkpoint=pick_next_checkpoint(events, requested_checkpoint),
        events=events,
    )


def parse_latest_id_response(xml_text: str) -> int | None:
    root = ET.fromstring(xml_text)
    ensure_success_status(root)

    latest_ids = []
    for node in find_elements(root, "cisloPosledniId"):
        text = (node.text or "").strip()
        if text.isdigit():
            latest_ids.append(int(text))

    if not latest_ids:
        return None

    return max(latest_ids)


class IsirPublicWsClient:
    def __init__(
        self,
        settings: WorkerSettings,
        http_client: httpx.Client | None = None,
    ) -> None:
        self.settings = settings
        self.http_client = http_client or httpx.Client(timeout=settings.isir_timeout_seconds)
        self._resolved_public_ws_url: str | None = None

    def _candidate_ws_urls(self) -> list[str]:
        configured = self.settings.isir_public_ws_candidate_urls
        if not self._resolved_public_ws_url:
            return configured

        if self._resolved_public_ws_url in configured:
            return [self._resolved_public_ws_url, *[url for url in configured if url != self._resolved_public_ws_url]]

        return [self._resolved_public_ws_url, *configured]

    def _post_soap_envelope(self, envelope: str) -> httpx.Response:
        failures: list[str] = []
        for endpoint in self._candidate_ws_urls():
            try:
                response = self.http_client.post(
                    endpoint,
                    content=envelope.encode("utf-8"),
                    headers={
                        "Content-Type": "text/xml; charset=utf-8",
                        "SOAPAction": '""',
                    },
                )
                response.raise_for_status()
                self._resolved_public_ws_url = endpoint
                return response
            except (httpx.TimeoutException, httpx.TransportError, httpx.HTTPStatusError) as exc:
                failures.append(f"{endpoint}: {exc}")

        raise IsirPublicWsError(
            "Unable to reach ISIR_PUBLIC_WS through configured endpoints. "
            f"Failures: {' | '.join(failures)}",
        )

    def build_latest_id_envelope(self) -> str:
        return f"""<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="{SOAP_ENV_NAMESPACE}" xmlns:isir="{self.settings.isir_soap_namespace}">
  <soapenv:Header/>
  <soapenv:Body>
    <isir:{self.settings.isir_latest_id_request_element}/>
  </soapenv:Body>
</soapenv:Envelope>"""

    def build_event_by_id_envelope(self, podnet_id: int) -> str:
        return f"""<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="{SOAP_ENV_NAMESPACE}" xmlns:isir="{self.settings.isir_soap_namespace}">
  <soapenv:Header/>
  <soapenv:Body>
    <isir:{self.settings.isir_event_by_id_request_element}>
      <{self.settings.isir_checkpoint_field}>{podnet_id}</{self.settings.isir_checkpoint_field}>
    </isir:{self.settings.isir_event_by_id_request_element}>
  </soapenv:Body>
</soapenv:Envelope>"""

    def fetch_latest_event_id(self) -> int | None:
        response = self._post_soap_envelope(self.build_latest_id_envelope())
        return parse_latest_id_response(response.text)

    def fetch_event_by_id(self, podnet_id: int) -> IsirSourceEvent | None:
        response = self._post_soap_envelope(self.build_event_by_id_envelope(podnet_id))
        batch = parse_event_response(response.text, requested_checkpoint=str(podnet_id), settings=self.settings)
        if not batch.events:
            return None

        return batch.events[0]

    def fetch_event_batch(self, checkpoint: str, limit: int) -> IsirEventBatch:
        if limit <= 0:
            return IsirEventBatch(
                requested_checkpoint=checkpoint,
                next_checkpoint=checkpoint,
                events=[],
            )

        if not checkpoint.isdigit():
            raise ValueError("Checkpoint must be numeric because ISIR_PUBLIC_WS synchronizes by idPodnetu.")

        latest_id = self.fetch_latest_event_id()
        if latest_id is None:
            return IsirEventBatch(
                requested_checkpoint=checkpoint,
                next_checkpoint=checkpoint,
                events=[],
            )

        start_id = int(checkpoint) + 1
        end_id = min(latest_id, int(checkpoint) + limit)

        if start_id > end_id:
            return IsirEventBatch(
                requested_checkpoint=checkpoint,
                next_checkpoint=checkpoint,
                events=[],
            )

        events: list[IsirSourceEvent] = []
        for podnet_id in range(start_id, end_id + 1):
            event = self.fetch_event_by_id(podnet_id)
            if event is not None:
                events.append(event)

        numeric_event_ids = [int(event.event_id) for event in events if event.event_id.isdigit()]

        return IsirEventBatch(
            requested_checkpoint=checkpoint,
            next_checkpoint=str(end_id if not numeric_event_ids else max(numeric_event_ids)),
            events=events,
        )
