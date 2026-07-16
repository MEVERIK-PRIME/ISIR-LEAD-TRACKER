from __future__ import annotations

from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
from html import unescape
from io import BytesIO
import re

import httpx
from pypdf import PdfReader

from .document_contract import ParsedCaseDocument, ParsedClaim
from .isir_client import IsirSourceEvent
from .qualification import normalize_text
from .settings import WorkerSettings

AMOUNT_PATTERN = re.compile(r"(?P<amount>(?:\d{1,3}(?:[ .]\d{3})+|\d+)(?:,\d{2})?)\s*K[ČC]", re.IGNORECASE)
DEBTOR_PATTERN = re.compile(r"dlu[zž]n[ií]k\s*[:\-]\s*(?P<name>.+)", re.IGNORECASE)
CASE_REFERENCE_PATTERN = re.compile(r"spisov[aá]\s+zna[cč]ka\s*[:\-]\s*(?P<reference>.+)", re.IGNORECASE)
TAG_PATTERN = re.compile(r"<[^>]+>")
WHITESPACE_PATTERN = re.compile(r"\s+")


@dataclass(slots=True)
class DownloadedDocument:
    url: str
    content_type: str
    body: bytes
    charset: str = "utf-8"


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

        claims.append(
            ParsedClaim(
                creditor_name=creditor_name,
                amount_czk=amount,
                secured=detect_secured(line),
                claim_type=detect_claim_type(line),
                raw_excerpt=line,
            )
        )

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
    def __init__(
        self,
        settings: WorkerSettings,
        http_client: httpx.Client | None = None,
    ) -> None:
        self.settings = settings
        self.http_client = http_client or httpx.Client(timeout=settings.isir_timeout_seconds)

    def download(self, url: str) -> DownloadedDocument:
        response = self.http_client.get(
            url,
            headers={
                "Accept": "application/pdf,text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.8",
                "User-Agent": "ISIR-LEAD-TRACKER/1.0",
            },
        )
        response.raise_for_status()

        content_type = response.headers.get("Content-Type", "application/octet-stream")

        return DownloadedDocument(
            url=str(response.url),
            content_type=content_type,
            body=response.content,
            charset=parse_charset(content_type),
        )


class DocumentParsingPipeline:
    def __init__(
        self,
        settings: WorkerSettings,
        download_client: DocumentDownloadClient | None = None,
    ) -> None:
        self.settings = settings
        self.download_client = download_client or DocumentDownloadClient(settings=settings)

    def build_parsed_document(self, event: IsirSourceEvent) -> ParsedCaseDocument | None:
        if event.document is None:
            return None

        downloaded_document = self.download_client.download(event.document.normalized_url)
        extracted_text = extract_text_from_document(downloaded_document)
        claims = parse_claims_from_text(extracted_text)

        return ParsedCaseDocument(
            case_reference=extract_case_reference(extracted_text) or event.case_reference or f"ISIR-EVENT-{event.event_id}",
            isir_event_id=event.event_id,
            isir_document_id=event.document.document_id or event.event_id,
            document_url=downloaded_document.url,
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
                "downloaded_content_type": downloaded_document.content_type,
                "extracted_text_length": len(extracted_text),
                "extracted_text_preview": extracted_text[:1000],
            },
        )
