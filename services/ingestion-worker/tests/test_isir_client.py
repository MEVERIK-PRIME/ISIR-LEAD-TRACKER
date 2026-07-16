from __future__ import annotations

from unittest import TestCase

import httpx

from isir_lead_tracker_worker.isir_client import (
    IsirPublicWsClient,
    event_matches_final_report,
    normalize_document_url,
    parse_event_response,
    parse_latest_id_response,
)
from isir_lead_tracker_worker.settings import WorkerSettings

SAMPLE_EVENT_RESPONSE = """<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns="http://isirpublicws.cca.cz/types/">
  <soapenv:Body>
    <ns:getIsirWsPublicDataResponse>
      <ns:data>
        <ns:id>12345</ns:id>
        <ns:datumZalozeniUdalosti>2026-07-15T09:10:00</ns:datumZalozeniUdalosti>
        <ns:datumZverejneniUdalosti>2026-07-15T09:12:00</ns:datumZverejneniUdalosti>
        <ns:dokumentUrl>https://isir.justice.cz:8443/isir_public_ws/doc/Document?idDokument=31670169</ns:dokumentUrl>
        <ns:spisovaZnacka>MSPH 99 INS 12345 / 2020</ns:spisovaZnacka>
        <ns:typUdalosti>KON</ns:typUdalosti>
        <ns:popisUdalosti>Konečná zpráva insolvenčního správce</ns:popisUdalosti>
        <ns:oddil>B</ns:oddil>
        <ns:cisloVOddilu>42</ns:cisloVOddilu>
      </ns:data>
      <ns:status>
        <ns:stav>OK</ns:stav>
      </ns:status>
    </ns:getIsirWsPublicDataResponse>
  </soapenv:Body>
</soapenv:Envelope>
"""

SAMPLE_LATEST_ID_RESPONSE = """<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns="http://isirpublicws.cca.cz/types/">
  <soapenv:Body>
    <ns:getIsirWsPublicPosledniDataResponse>
      <ns:cisloPosledniId>12346</ns:cisloPosledniId>
      <ns:status>
        <ns:stav>OK</ns:stav>
      </ns:status>
    </ns:getIsirWsPublicPosledniDataResponse>
  </soapenv:Body>
</soapenv:Envelope>
"""


class IsirClientTest(TestCase):
    def setUp(self) -> None:
        self.settings = WorkerSettings()

    def test_document_url_normalization_removes_8443_port(self) -> None:
        normalized = normalize_document_url(
            "https://isir.justice.cz:8443/isir_public_ws/doc/Document?idDokument=31670169",
            self.settings,
        )

        self.assertEqual(
            "https://isir.justice.cz/isir_public_ws/doc/Document?idDokument=31670169",
            normalized,
        )

    def test_event_response_parsing_extracts_official_xsd_fields(self) -> None:
        batch = parse_event_response(SAMPLE_EVENT_RESPONSE, requested_checkpoint="12000", settings=self.settings)

        self.assertEqual("12345", batch.next_checkpoint)
        self.assertEqual(1, len(batch.events))
        self.assertEqual("MSPH 99 INS 12345 / 2020", batch.events[0].case_reference)
        self.assertEqual("B", batch.events[0].section)
        self.assertEqual("Konečná zpráva insolvenčního správce", batch.events[0].label)
        self.assertEqual("31670169", batch.events[0].document.document_id)
        self.assertEqual(
            "https://isir.justice.cz/isir_public_ws/doc/Document?idDokument=31670169",
            batch.events[0].document.normalized_url,
        )

    def test_latest_id_response_extracts_checkpoint(self) -> None:
        self.assertEqual(12346, parse_latest_id_response(SAMPLE_LATEST_ID_RESPONSE))

    def test_final_report_matching_uses_configured_token(self) -> None:
        self.assertTrue(event_matches_final_report("Konečná zpráva insolvenčního správce", self.settings))
        self.assertFalse(event_matches_final_report("Usnesení o schválení oddlužení", self.settings))

    def test_envelope_builder_uses_official_request_elements(self) -> None:
        client = IsirPublicWsClient(settings=self.settings)

        event_envelope = client.build_event_by_id_envelope(podnet_id=56789)
        latest_envelope = client.build_latest_id_envelope()

        self.assertIn("<isir:getIsirWsPublicIdDataRequest>", event_envelope)
        self.assertIn("<isir:idPodnetu>56789</isir:idPodnetu>", event_envelope)
        self.assertIn("<isir:getIsirWsPublicPosledniIdDataRequest/>", latest_envelope)

    def test_incremental_batch_fetches_latest_id_then_missing_events(self) -> None:
        calls: list[str] = []

        def handler(request: httpx.Request) -> httpx.Response:
            body = request.content.decode("utf-8")
            calls.append(body)

            if "getIsirWsPublicPosledniIdDataRequest" in body:
                return httpx.Response(200, text=SAMPLE_LATEST_ID_RESPONSE)

            event_id = body.split("<isir:idPodnetu>", 1)[1].split("</isir:idPodnetu>", 1)[0]
            event_response = SAMPLE_EVENT_RESPONSE.replace("<ns:id>12345</ns:id>", f"<ns:id>{event_id}</ns:id>", 1)
            return httpx.Response(200, text=event_response)

        client = IsirPublicWsClient(
            settings=self.settings,
            http_client=httpx.Client(transport=httpx.MockTransport(handler)),
        )

        batch = client.fetch_event_batch(checkpoint="12344", limit=2)

        self.assertEqual(3, len(calls))
        self.assertEqual("12346", batch.next_checkpoint)
        self.assertEqual(["12345", "12346"], [event.event_id for event in batch.events])

    def test_client_falls_back_to_next_endpoint_after_primary_failure(self) -> None:
        requested_urls: list[str] = []
        primary = "https://isir.justice.cz/isir_public_ws/webservice"
        fallback = "https://isir.justice.cz:8443/isir_public_ws/IsirWsPublicService"

        settings = WorkerSettings(
            isir_public_ws_url=primary,
            isir_public_ws_fallback_urls=fallback,
        )

        def handler(request: httpx.Request) -> httpx.Response:
            requested_urls.append(str(request.url))
            body = request.content.decode("utf-8")

            if str(request.url) == primary:
                return httpx.Response(404, text="Not Found")

            if "getIsirWsPublicPosledniIdDataRequest" in body:
                return httpx.Response(200, text=SAMPLE_LATEST_ID_RESPONSE)

            event_id = body.split("<isir:idPodnetu>", 1)[1].split("</isir:idPodnetu>", 1)[0]
            event_response = SAMPLE_EVENT_RESPONSE.replace("<ns:id>12345</ns:id>", f"<ns:id>{event_id}</ns:id>", 1)
            return httpx.Response(200, text=event_response)

        client = IsirPublicWsClient(
            settings=settings,
            http_client=httpx.Client(transport=httpx.MockTransport(handler)),
        )

        batch = client.fetch_event_batch(checkpoint="12344", limit=1)

        self.assertEqual("12345", batch.next_checkpoint)
        self.assertEqual(
            [
                primary,
                fallback,
                fallback,
            ],
            requested_urls,
        )
