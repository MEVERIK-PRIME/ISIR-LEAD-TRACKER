<?php

namespace Tests\Feature;

use App\Models\CaseDocument;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportParsedCaseDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_the_internal_api_token(): void
    {
        config()->set('services.internal_ingest.token', 'secret-token');

        $this->postJson('/api/internal/isir/parsed-documents', [])
            ->assertUnauthorized();
    }

    public function test_it_imports_a_parsed_document_through_the_internal_api(): void
    {
        config()->set('services.internal_ingest.token', 'secret-token');
        Http::fake([
            'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/*' => Http::response([
                'ico' => '12345678',
            ], 200),
        ]);

        $this->withHeader('X-Internal-Token', 'secret-token')
            ->postJson('/api/internal/isir/parsed-documents', [
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
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.claim_count', 1)
            ->assertJsonPath('data.lead_creates', 1);

        $this->assertDatabaseCount(CaseDocument::class, 1);
        $this->assertDatabaseCount(Lead::class, 1);
    }
}
