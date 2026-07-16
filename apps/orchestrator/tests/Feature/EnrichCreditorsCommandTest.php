<?php

namespace Tests\Feature;

use App\Models\CaseDocument;
use App\Models\Claim;
use App\Models\Creditor;
use App\Models\InsolvencyCase;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnrichCreditorsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requalifies_existing_leads_after_creditor_enrichment(): void
    {
        Http::fake([
            'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/12345678' => Http::response([
                'ico' => '12345678',
                'obchodniJmeno' => 'Dodavatel One s.r.o.',
                'pravniForma' => 121,
                'czNace' => [
                    ['kod' => '64190'],
                ],
            ], 200),
        ]);

        $case = InsolvencyCase::query()->create([
            'court_file_reference' => 'MSPH 11 INS 99 / 2024',
            'debtor_name' => 'Novak Holding s.r.o.',
            'source_section' => 'B',
            'last_isir_event_id' => '12346',
        ]);

        $document = CaseDocument::query()->create([
            'insolvency_case_id' => $case->id,
            'isir_event_id' => '12346',
            'isir_document_id' => '31670170',
            'section' => 'B',
            'event_label' => 'Konečná zpráva insolvenčního správce',
            'document_type' => 'final_report',
            'document_url' => 'https://isir.justice.cz/isir/common/stat.do?idDokument=31670170',
            'source_provider' => 'isir_public_ws',
            'download_status' => 'imported',
            'parse_status' => 'parsed',
        ]);

        $creditor = Creditor::query()->create([
            'normalized_name' => 'dodavatel one s r o',
            'display_name' => 'Dodavatel One s.r.o.',
            'ico' => '12345678',
            'person_type' => 'legal_entity',
        ]);

        Claim::query()->create([
            'insolvency_case_id' => $case->id,
            'case_document_id' => $document->id,
            'creditor_id' => $creditor->id,
            'claim_fingerprint' => 'claim-1',
            'claim_type' => 'principal',
            'amount_czk' => 350000,
            'currency' => 'CZK',
            'priority_label' => 'unsecured',
            'secured' => false,
            'qualification_snapshot' => ['qualified' => true, 'reasons' => []],
        ]);

        $lead = Lead::query()->create([
            'insolvency_case_id' => $case->id,
            'creditor_id' => $creditor->id,
            'lead_key' => hash('sha256', 'bootstrap'),
            'status' => 'new',
            'status_source' => 'system',
            'qualification_status' => 'qualified',
            'claim_amount_total_czk' => 350000,
            'secured_claim_amount_czk' => 0,
            'unsecured_claim_amount_czk' => 350000,
            'business_state_version' => 1,
            'metadata' => [],
        ]);

        $this->artisan('creditors:enrich', [
            '--limit' => 10,
        ])->assertSuccessful();

        $lead->refresh();
        $creditor->refresh();

        $this->assertSame('64190', $creditor->nace_code);
        $this->assertSame('rejected', $lead->qualification_status);
        $this->assertSame('excluded_nace', $lead->qualification_reason);
        $this->assertSame(2, $lead->business_state_version);
        $this->assertNotNull($lead->last_material_change_at);
    }
}
