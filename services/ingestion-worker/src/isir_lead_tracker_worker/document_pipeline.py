from __future__ import annotations

from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
from html import unescape
from io import BytesIO
import logging
import random
import re
import time
from urllib.parse import urlsplit

import httpx
from pydantic import ValidationError
from pypdf import PdfReader

from .document_contract import ParsedCaseDocument, ParsedClaim
from .isir_client import IsirSourceEvent
from .qualification import normalize_text
from .settings import WorkerSettings

logger = logging.getLogger(__name__)

AMOUNT_PATTERN = re.compile(r"(?P<amount>(?:\d{1,3}(?:[ .]\d{3})+|\d+)(?:,\d{2})?)\s*K[ČC]", re.IGNORECASE)
DEBTOR_PATTERN = re.compile(r"dlu[zž]n[ií]k\s*[:\-]\s*(?P<name>.+)", re.IGNORECASE)
CASE_REFERENCE_PATTERN = re.compile(r"spisov[aá]\s+zna[cč]ka\s*[:\-]\s*(?P<reference>.+)", re.IGNORECASE)
TAG_PATTERN = re.compile(r"<[^>]+>")
WHITESPACE_PATTERN = re.compile(r"\s+")

# 503 / rate-limit status codes that warrant retry
_RETRY_STATUS_CODES: frozenset[int] = frozenset({429, 500, 502, 503, 504})
_DOWNLOAD_MAX_RETRIES: int = 5
_DOWNLOAD_RETRY_BASE: float = 3.0
_DOWNLOAD_RETRY_JITTER: float = 2.0

# Realistic browser User-Agents to avoid bot detection on justice.cz / eISIR
_BROWSER_USER_AGENTS: list[str] = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36",
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:127.0) Gecko/20100101 Firefox/127.0",
]


@dataclass(slots=True)
class DownloadedDocument:
    url: str
    content_type: str
    body: bytes
    charset: str = "utf-8"
    source: str = "direct"  # "direct" | "hlidac_statu"


class DocumentDownload503Error(RuntimeError):
    """Raised when direct document download consistently returns 503 / 502 from eISIR."""

    def __init__(self, url: str, attempts: int) -> None:
        super().__init__(f"Document download blocked after {attempts} attempts: {url}")
        self.url = url
        self.attempts = attempts


def parse_charset(content_type: str) -> str:
    for part in content_type.split(";"):
        part = part.strip()
        if part.lower().startswith("charset="):
            return part.split("=", 1)[1].strip()

    return "utf-8"


def normalize_line(value: str) -> str:
    return WHITESPACE_PATTERN.sub(" ", value).strip()


def strip_html(value: str) -> str:
    return normalize_line(unescape(TAG_PATTERN.sub(" ", value)))


def decode_text(data: bytes, charset: str) -> str:
    try:
        return data.decode(charset)
    except UnicodeDecodeError:
        return data.decode("utf-8", errors="ignore")


def extract_text_from_document(document: DownloadedDocument) -> str:
    content_type = document.content_type.lower()

    if "pdf" in content_type or document.body.startswith(b"%PDF"):
        reader = PdfReader(BytesIO(document.body))
        return "\n".join((page.extract_text() or "") for page in reader.pages).strip()

    decoded = decode_text(document.body, document.charset)

    if "html" in content_type or "<html" in decoded.lower():
        return strip_html(decoded)

    return decoded.strip()


def parse_amount(value: str) -> Decimal | None:
    normalized = value.replace(" ", "").replace(".", "").replace(",", ".")

    try:
        return Decimal(normalized)
    except InvalidOperation:
        return None


def detect_secured(line: str) -> bool:
    normalized = normalize_text(line)

    if "nezajisten" in normalized:
        return False

    return "zajisten" in normalized


def detect_claim_type(line: str) -> str:
    normalized = normalize_text(line)

    if "naklad" in normalized:
        return "costs"

    if "urok" in normalized:
        return "interest"

    return "principal"


def extract_creditor_name(line: str, amount_match: re.Match[str]) -> str | None:
    left_side = line[:amount_match.start()].strip(" -–:;\t")
    left_side = re.split(r"\s+(?:nezaji[sš]t[eě]n[aá]?|zaji[sš]t[eě]n[aá]?|pohled[aá]vka|ve\s+v[yý][sš]i)\b", left_side, maxsplit=1, flags=re.IGNORECASE)[0]
    left_side = normalize_line(left_side.strip(" -–:;\t"))

    if len(left_side) < 3:
        return None

    if left_side.lower().startswith(("celkem", "součet", "soucet", "spisová značka", "spisova znacka")):
        return None

    return left_side


def parse_claims_from_text(text: str) -> list[ParsedClaim]:
    claims: list[ParsedClaim] = []

    for raw_line in text.splitlines():
        line = normalize_line(raw_line)
        if line == "":
            continue

        amount_match = AMOUNT_PATTERN.search(line)
        if amount_match is None:
            continue

        creditor_name = extract_creditor_name(line, amount_match)
        if creditor_name is None:
            continue

        amount = parse_amount(amount_match.group("amount"))
        if amount is None:
            continue

        # Zero-value rows are common in summary/administrative lines and should
        # not fail the entire event processing.
        if amount <= 0:
            continue

        try:
            claims.append(
                ParsedClaim(
                    creditor_name=creditor_name,
                    amount_czk=amount,
                    secured=detect_secured(line),
                    claim_type=detect_claim_type(line),
                    raw_excerpt=line,
                )
            )
        except ValidationError as exc:
            logger.warning("Skipping invalid parsed claim line: %s (%s)", line[:200], exc.errors())
            continue

    return claims


def extract_debtor_name(text: str) -> str | None:
    for line in text.splitlines():
        match = DEBTOR_PATTERN.search(line)
        if match:
            return normalize_line(match.group("name"))

    return None


def extract_case_reference(text: str) -> str | None:
    for line in text.splitlines():
        match = CASE_REFERENCE_PATTERN.search(line)
        if match:
            return normalize_line(match.group("reference"))

    return None


class DocumentDownloadClient:
    """Browser-emulating HTTP client for downloading ISIR documents.

    eISIR (justice.cz) returns 503 for plain machine-like requests, especially
    from cloud IPs.  This client mitigates that with:
    - Realistic browser User-Agent and Accept headers
    - Session warming (visit portal homepage to obtain JSESSIONID cookie)
    - Exponential backoff with jitter on 5xx / 429 responses
    """

    def __init__(
        self,
        settings: WorkerSettings,
        http_client: httpx.Client | None = None,
    ) -> None:
        self.settings = settings
        self.http_client = http_client or httpx.Client(
            timeout=settings.isir_timeout_seconds,
            follow_redirects=True,
            headers={
                "User-Agent": random.choice(_BROWSER_USER_AGENTS),
                "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,application/pdf,*/*;q=0.8",
                "Accept-Language": "cs-CZ,cs;q=0.9,sk;q=0.8,en;q=0.7",
                "Accept-Encoding": "gzip, deflate, br",
                "DNT": "1",
                "Connection": "keep-alive",
                "Upgrade-Insecure-Requests": "1",
            },
        )
        self._warmed_origins: set[str] = set()

    def _warm_session(self, origin: str) -> None:
        """Visit portal homepage once per origin to acquire session cookie."""
        if origin in self._warmed_origins:
            return
        self._warmed_origins.add(origin)
        warm_url = origin + "/isir/urstav/hledani.do"
        try:
            self.http_client.get(warm_url, timeout=20)
            logger.debug("Session warmed for origin %s", origin)
        except Exception as exc:  # noqa: BLE001
            logger.debug("Session warm-up failed for %s (non-fatal): %s", origin, exc)

    def download(self, url: str) -> DownloadedDocument:
        parsed = urlsplit(url)
        origin = f"{parsed.scheme}://{parsed.netloc}"
        self._warm_session(origin)

        last_exc: Exception | None = None
        blocked_attempts = 0

        for attempt in range(_DOWNLOAD_MAX_RETRIES):
            try:
                response = self.http_client.get(
                    url,
                    headers={
                        "Referer": origin + "/isir/common/index.do",
                        "Cache-Control": "no-cache",
                        "Pragma": "no-cache",
                    },
                )

                if response.status_code in _RETRY_STATUS_CODES:
                    blocked_attempts += 1
                    logger.warning(
                        "Document download got HTTP %s (attempt %d/%d): %s",
                        response.status_code,
                        attempt + 1,
                        _DOWNLOAD_MAX_RETRIES,
                        url,
                    )
                    if attempt < _DOWNLOAD_MAX_RETRIES - 1:
                        delay = _DOWNLOAD_RETRY_BASE * (2 ** attempt) + random.uniform(0, _DOWNLOAD_RETRY_JITTER)
                        time.sleep(delay)
                        continue
                    raise DocumentDownload503Error(url, attempts=blocked_attempts)

                response.raise_for_status()
                content_type = response.headers.get("Content-Type", "application/octet-stream")
                if attempt > 0:
                    logger.info("Document download succeeded after %d attempts: %s", attempt + 1, url)
                return DownloadedDocument(
                    url=str(response.url),
                    content_type=content_type,
                    body=response.content,
                    charset=parse_charset(content_type),
                    source="direct",
                )

            except DocumentDownload503Error:
                raise
            except httpx.HTTPStatusError as exc:
                last_exc = exc
                if exc.response.status_code in _RETRY_STATUS_CODES:
                    blocked_attempts += 1
                    if attempt < _DOWNLOAD_MAX_RETRIES - 1:
                        delay = _DOWNLOAD_RETRY_BASE * (2 ** attempt) + random.uniform(0, _DOWNLOAD_RETRY_JITTER)
                        time.sleep(delay)
                        continue
                    raise DocumentDownload503Error(url, attempts=blocked_attempts) from exc
                raise
            except (httpx.TransportError, httpx.TimeoutException) as exc:
                last_exc = exc
                logger.warning("Document download transport error (attempt %d/%d): %s", attempt + 1, _DOWNLOAD_MAX_RETRIES, exc)
                if attempt < _DOWNLOAD_MAX_RETRIES - 1:
                    delay = _DOWNLOAD_RETRY_BASE * (2 ** attempt) + random.uniform(0, _DOWNLOAD_RETRY_JITTER)
                    time.sleep(delay)
                else:
                    raise

        if last_exc:
            raise last_exc
        raise RuntimeError(f"Document download failed after {_DOWNLOAD_MAX_RETRIES} attempts: {url}")


class HlidacStatuDocumentFallback:
    """Retrieve ISIR document text via Hlídač státu API when direct eISIR download is blocked.

    Hlídač státu aggregates ISIR data and serves it through their own API, which
    does not suffer from the same anti-bot restrictions as the justice.cz portal.

    API docs: https://api.hlidacstatu.cz/swagger
    Auth header: Authorization: Token <api_key>
    """

    _HLIDAC_INSOLVENCE_SEARCH = "https://api.hlidacstatu.cz/api/v2/insolvence/hledat"
    _HLIDAC_INSOLVENCE_DETAIL = "https://api.hlidacstatu.cz/api/v2/insolvence/{id}"

    def __init__(self, settings: WorkerSettings, http_client: httpx.Client | None = None) -> None:
        self.settings = settings
        self._http_client = http_client or httpx.Client(
            timeout=30,
            follow_redirects=True,
            headers={"User-Agent": _BROWSER_USER_AGENTS[0]},
        )

    def _auth_headers(self) -> dict[str, str]:
        return {"Authorization": f"Token {self.settings.hlidac_statu_api_key}"}

    def _is_available(self) -> bool:
        return bool(self.settings.hlidac_statu_api_key and self.settings.enable_hlidac_statu)

    def fetch_document_text(
        self,
        case_reference: str | None,
        document_id: str | None,
        event_label: str,
    ) -> str | None:
        """Return plain text of an ISIR document from Hlídač státu, or None if unavailable."""
        if not self._is_available():
            return None
        if not case_reference:
            return None

        try:
            # Step 1: find the case in Hlídač státu by case reference
            search_resp = self._http_client.get(
                self._HLIDAC_INSOLVENCE_SEARCH,
                params={"q": case_reference, "strana": 1, "pocet": 1},
                headers=self._auth_headers(),
                timeout=20,
            )
            search_resp.raise_for_status()
            results = search_resp.json()

            items = results if isinstance(results, list) else results.get("results") or results.get("data") or []
            if not items:
                logger.debug("Hlídač státu: no results for case_reference=%s", case_reference)
                return None

            case_id = items[0].get("id") or items[0].get("spz") or items[0].get("vec")
            if not case_id:
                return None

            # Step 2: fetch case detail to get document list
            detail_resp = self._http_client.get(
                self._HLIDAC_INSOLVENCE_DETAIL.format(id=case_id),
                headers=self._auth_headers(),
                timeout=20,
            )
            detail_resp.raise_for_status()
            case_data = detail_resp.json()

            documents = case_data.get("dokumenty") or case_data.get("documents") or []
            if not documents:
                logger.debug("Hlídač státu: no documents for case %s", case_id)
                return None

            # Step 3: pick the best matching document
            best_doc = _pick_best_document(documents, document_id=document_id, event_label=event_label)
            if best_doc is None:
                return None

            # Step 4: return plain text if Hlídač státu indexed it
            text = (
                best_doc.get("plainText")
                or best_doc.get("plain_text")
                or best_doc.get("content")
                or best_doc.get("text")
            )
            if text:
                logger.info(
                    "Hlídač státu fallback succeeded for case %s doc %s (len=%d)",
                    case_reference,
                    document_id,
                    len(text),
                )
                return str(text)

            return None

        except Exception as exc:  # noqa: BLE001
            logger.warning("Hlídač státu document fallback failed for %s: %s", case_reference, exc)
            return None


def _pick_best_document(
    documents: list[dict],
    document_id: str | None,
    event_label: str,
) -> dict | None:
    """Pick the most relevant document from a Hlídač státu case document list."""
    # Priority 1: match by ISIR document ID
    if document_id:
        for doc in documents:
            doc_url = doc.get("url") or ""
            if document_id in doc_url:
                return doc

    # Priority 2: match by label (e.g. "konečná zpráva")
    normalized_label = normalize_text(event_label)
    for doc in documents:
        doc_name = normalize_text(doc.get("nazev") or doc.get("name") or "")
        if "konec" in doc_name or "zprava" in doc_name or "zpráva" in doc_name:
            return doc

    # Priority 3: return any document that looks like a final report by label token match
    for doc in documents:
        doc_name = normalize_text(doc.get("nazev") or doc.get("name") or "")
        if any(token in doc_name for token in normalize_text(event_label).split()):
            return doc

    return None


class DocumentParsingPipeline:
    def __init__(
        self,
        settings: WorkerSettings,
        download_client: DocumentDownloadClient | None = None,
        hlidac_fallback: HlidacStatuDocumentFallback | None = None,
    ) -> None:
        self.settings = settings
        self.download_client = download_client or DocumentDownloadClient(settings=settings)
        self.hlidac_fallback = hlidac_fallback or HlidacStatuDocumentFallback(settings=settings)

    def build_parsed_document(self, event: IsirSourceEvent) -> ParsedCaseDocument | None:
        if event.document is None:
            return None

        extracted_text: str
        download_source: str
        downloaded_url: str
        downloaded_content_type: str

        try:
            downloaded_document = self.download_client.download(event.document.normalized_url)
            extracted_text = extract_text_from_document(downloaded_document)
            download_source = downloaded_document.source
            downloaded_url = downloaded_document.url
            downloaded_content_type = downloaded_document.content_type

        except DocumentDownload503Error as exc:
            # Direct download was blocked by eISIR — attempt Hlídač státu text fallback
            logger.warning(
                "Direct download blocked for event_id=%s (%s) — trying Hlídač státu fallback",
                event.event_id,
                exc,
            )
            text_from_fallback = self.hlidac_fallback.fetch_document_text(
                case_reference=event.case_reference,
                document_id=event.document.document_id,
                event_label=event.label,
            )
            if text_from_fallback is None:
                logger.error(
                    "Both direct download and Hlídač státu fallback failed for event_id=%s — skipping document",
                    event.event_id,
                )
                return None
            extracted_text = text_from_fallback
            download_source = "hlidac_statu"
            downloaded_url = event.document.normalized_url
            downloaded_content_type = "text/plain"

        claims = parse_claims_from_text(extracted_text)

        return ParsedCaseDocument(
            case_reference=extract_case_reference(extracted_text) or event.case_reference or f"ISIR-EVENT-{event.event_id}",
            isir_event_id=event.event_id,
            isir_document_id=event.document.document_id or event.event_id,
            document_url=downloaded_url,
            event_label=event.label,
            section=event.section,
            document_type="final_report",
            source_provider=self.settings.isir_sync_provider,
            extraction_method="text",
            parser_version="heuristic-v1",
            debtor_name=extract_debtor_name(extracted_text) or event.debtor_name,
            published_at=event.published_at,
            claims=claims,
            payload={
                "raw_event_fields": event.raw_fields,
                "source_document_url": event.document.source_url,
                "download_source": download_source,
                "downloaded_content_type": downloaded_content_type,
                "extracted_text_length": len(extracted_text),
                "extracted_text_preview": extracted_text[:1000],
            },
        )
