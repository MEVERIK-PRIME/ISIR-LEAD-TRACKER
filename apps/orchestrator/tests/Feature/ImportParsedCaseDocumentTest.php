<?php

namespace Tests\Feature;

use App\Models\CaseDocument;
use App\Models\Claim;
use App\Models\Creditor;
use App\Models\InsolvencyCase;
use App\Models\Lead;
use App\Models\LeadStatusHistory;
use App\Services\Isir\ImportParsedCaseDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportParsedCaseDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_a_parsed_document_into_case_claim_and_lead_tables(): void
    {
        Http::fake([
            'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/*' => Http::response([
                'ico' => '12345678',
            ], 200),
        ]);

        $result = app(ImportParsedCaseDocument::class)->import([
            'case_reference' => 'MSPH 99 INS 12345 / 2020',
            'isir_event_id' => '12345',
            'isir_document_id' => '31670169',
            'document_url' => 'https://isir.justice.cz/isir_public_ws/doc/Document?idDokument=31670169',
            'event_label' => 'Konečná zpráva insolvenčního správce',
            'section' => 'B',
            'debtor_name' => 'Novak Holding s.r.o.',
            'published_at' => '2026-07-15T09:12:00+00:00',
            'claims' => [
                [
                    'creditor_name' => 'Dodavatel One s.r.o.',
                    'creditor_ico' => '12345678',
                    'amount_czk' => 350000,
                    'secured' => false,
                    'claim_type' => 'principal',
                    'raw_excerpt' => 'nezajištěná pohledávka 350 000 Kč',
                ],
                [
                    'creditor_name' => 'Dodavatel One s.r.o.',
                    'creditor_ico' => '12345678',
                    'amount_czk' => 200000,
                    'secured' => false,
                    'claim_type' => 'costs',
                    'raw_excerpt' => 'náklady řízení 200 000 Kč',
                ],
                [
                    'creditor_name' => 'Velká banka a.s.',
                    'creditor_ico' => '87654321',
                    'amount_czk' => 350000,
                    'secured' => false,
                    'claim_type' => 'principal',
                    'nace_code' => '64190',
                    'raw_excerpt' => 'bankovní pohledávka 350 000 Kč',
                ],
            ],
        ]);

        $this->assertSame(3, $result['claim_count']);
        $this->assertSame(2, $result['lead_creates']);
        $this->assertSame(0, $result['lead_updates']);

        $this->assertDatabaseCount(InsolvencyCase::class, 1);
        $this->assertDatabaseCount(CaseDocument::class, 1);
        $this->assertDatabaseCount(Creditor::class, 2);
        $this->assertDatabaseCount(Claim::class, 3);
        $this->assertDatabaseCount(Lead::class, 2);
        $this->assertDatabaseCount(LeadStatusHistory::class, 2);

        $qualifiedLead = Lead::query()
            ->where('qualification_status', 'qualified')
            ->firstOrFail();

        $rejectedLead = Lead::query()
            ->where('qualification_status', 'rejected')
            ->firstOrFail();

        $this->assertSame('550000.00', $qualifiedLead->claim_amount_total_czk);
        $this->assertSame('0.00', $qualifiedLead->secured_claim_amount_czk);
        $this->assertSame('550000.00', $qualifiedLead->unsecured_claim_amount_czk);
        $this->assertNull($qualifiedLead->qualification_reason);
        $this->assertSame('350000.00', $rejectedLead->claim_amount_total_czk);
        $this->assertSame('creditor_name_blacklisted,excluded_nace', $rejectedLead->qualification_reason);
    }

    public function test_it_is_idempotent_for_the_same_parsed_document_payload(): void
    {
        Http::fake([
            'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/*' => Http::response([
                'ico' => '12345678',
            ], 200),
        ]);

        $payload = [
            'case_reference' => 'MSPH 99 INS 12345 / 2020',
            'isir_event_id' => '12345',
            'isir_document_id' => '31670169',
            'document_url' => 'https://isir.justice.cz/isir_public_ws/doc/Document?idDokument=31670169',
            'event_label' => 'Konečná zpráva insolvenčního správce',
            'section' => 'B',
            'claims' => [
                [
                    'creditor_name' => 'Dodavatel One s.r.o.',
                    'creditor_ico' => '12345678',
                    'amount_czk' => 350000,
                    'secured' => false,
                    'claim_type' => 'principal',
                    'raw_excerpt' => 'nezajištěná pohledávka 350 000 Kč',
                ],
            ],
        ];

        $service = app(ImportParsedCaseDocument::class);

        $service->import($payload);
        $result = $service->import($payload);

        $this->assertSame(1, $result['claim_count']);
        $this->assertSame(0, $result['lead_creates']);
        $this->assertSame(0, $result['lead_updates']);
        $this->assertDatabaseCount(CaseDocument::class, 1);
        $this->assertDatabaseCount(Creditor::class, 1);
        $this->assertDatabaseCount(Claim::class, 1);
        $this->assertDatabaseCount(Lead::class, 1);
        $this->assertDatabaseCount(LeadStatusHistory::class, 1);
    }

    public function test_it_enriches_missing_ico_via_hlidac_and_requalifies_with_ares_data(): void
    {
        config()->set('services.hlidac_statu.api_key', 'test-token');
        config()->set('services.hlidac_statu.base_url', 'https://www.hlidacstatu.cz/api/v1');

        Http::fake([
            'https://www.hlidacstatu.cz/api/v1/FindCompanyId*' => Http::response([
                'ICO' => '12345678',
                'Jmeno' => 'Dodavatel One s.r.o.',
                'DatovaSchranka' => ['abc1234'],
            ], 200),
            'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/12345678' => Http::response([
                'ico' => '12345678',
                'obchodniJmeno' => 'Dodavatel One s.r.o.',
                'pravniForma' => 121,
                'czNace' => [
                    ['kod' => '64190'],
                ],
            ], 200),
        ]);

        app(ImportParsedCaseDocument::class)->import([
            'case_reference' => 'MSPH 11 INS 99 / 2024',
            'isir_event_id' => '12346',
            'isir_document_id' => '31670170',
            'document_url' => 'https://isir.justice.cz/isir/common/stat.do?idDokument=31670170',
            'event_label' => 'Konečná zpráva insolvenčního správce',
            'section' => 'B',
            'claims' => [
                [
                    'creditor_name' => 'Dodavatel One s.r.o.',
                    'amount_czk' => 350000,
                    'secured' => false,
                    'claim_type' => 'principal',
                    'raw_excerpt' => 'nezajištěná pohledávka 350 000 Kč',
                ],
            ],
        ]);

        $creditor = Creditor::query()->firstOrFail();
        $lead = Lead::query()->firstOrFail();

        $this->assertSame('12345678', $creditor->ico);
        $this->assertSame('121', $creditor->legal_form_code);
        $this->assertSame('64190', $creditor->nace_code);
        $this->assertNotNull($creditor->ares_verified_at);
        $this->assertSame('rejected', $lead->qualification_status);
        $this->assertSame('excluded_nace', $lead->qualification_reason);
        $this->assertSame('12345678', data_get($creditor->metadata, 'enrichment.hlidac_statu.ico'));
    }
}
