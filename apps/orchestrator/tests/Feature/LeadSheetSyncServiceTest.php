<?php

namespace Tests\Feature;

use App\Models\Creditor;
use App\Models\InsolvencyCase;
use App\Models\Lead;
use App\Models\LeadStatusHistory;
use App\Services\Google\GoogleSheetsClient;
use App\Services\Isir\LeadSheetSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadSheetSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_preserves_operator_columns_and_updates_sheet_row_ids(): void
    {
        $lead = $this->createLead();

        $client = new class ([
            [
                'lead_key',
                'sheet_status',
                'sheet_note',
                'sheet_owner',
                'court_file_reference',
                'debtor_name',
                'creditor_name',
                'creditor_ico',
                'qualification_status',
                'qualification_reason',
                'claim_amount_total_czk',
                'secured_claim_amount_czk',
                'unsecured_claim_amount_czk',
                'primary_claim_type',
                'last_qualified_at',
                'last_material_change_at',
                'business_state_version',
            ],
            [
                $lead->lead_key,
                'contacted',
                'Follow-up next week',
                'alice',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
            ],
        ]) extends GoogleSheetsClient {
            public array $writtenRows = [];

            public bool $cleared = false;

            public function __construct(private array $rows)
            {
            }

            public function fetchRows(string $columnsRange): array
            {
                return $this->rows;
            }

            public function clearRows(string $columnsRange): void
            {
                $this->cleared = true;
            }

            public function writeRows(string $startCellRange, array $rows): void
            {
                $this->writtenRows = $rows;
            }
        };

        $result = (new LeadSheetSyncService($client))->pushLeads();

        $lead->refresh();

        $this->assertTrue($client->cleared);
        $this->assertSame(1, $result['rows_written']);
        $this->assertSame('2', $lead->sheet_row_id);
        $this->assertSame('contacted', $client->writtenRows[1][1]);
        $this->assertSame('Follow-up next week', $client->writtenRows[1][2]);
        $this->assertSame('alice', $client->writtenRows[1][3]);
    }

    public function test_pull_updates_status_from_sheet_and_creates_history(): void
    {
        $lead = $this->createLead();

        $client = new class ([
            [
                'lead_key',
                'sheet_status',
                'sheet_note',
                'sheet_owner',
                'court_file_reference',
                'debtor_name',
                'creditor_name',
                'creditor_ico',
                'qualification_status',
                'qualification_reason',
                'claim_amount_total_czk',
                'secured_claim_amount_czk',
                'unsecured_claim_amount_czk',
                'primary_claim_type',
                'last_qualified_at',
                'last_material_change_at',
                'business_state_version',
            ],
            [
                $lead->lead_key,
                'won',
                'Closed after call',
                'bob',
                'MSPH 99 INS 12345 / 2020',
                'Novak Holding s.r.o.',
                'Dodavatel One s.r.o.',
                '12345678',
                'qualified',
                '',
                '350000.00',
                '0.00',
                '350000.00',
                'principal',
                '',
                '',
                '1',
            ],
        ]) extends GoogleSheetsClient {
            public function __construct(private array $rows)
            {
            }

            public function fetchRows(string $columnsRange): array
            {
                return $this->rows;
            }

            public function clearRows(string $columnsRange): void
            {
            }

            public function writeRows(string $startCellRange, array $rows): void
            {
            }
        };

        $result = (new LeadSheetSyncService($client))->pullStatuses();

        $lead->refresh();

        $this->assertSame(1, $result['leads_updated']);
        $this->assertSame('won', $lead->status);
        $this->assertSame('sheet', $lead->status_source);
        $this->assertSame('Closed after call', $lead->metadata['sheet_note']);
        $this->assertSame('bob', $lead->metadata['sheet_owner']);
        $this->assertDatabaseCount(LeadStatusHistory::class, 1);
    }

    private function createLead(): Lead
    {
        $case = InsolvencyCase::query()->create([
            'court_file_reference' => 'MSPH 99 INS 12345 / 2020',
            'debtor_name' => 'Novak Holding s.r.o.',
            'source_section' => 'B',
        ]);

        $creditor = Creditor::query()->create([
            'normalized_name' => 'dodavatel one s r o',
            'display_name' => 'Dodavatel One s.r.o.',
            'ico' => '12345678',
            'person_type' => 'legal_entity',
        ]);

        return Lead::query()->create([
            'insolvency_case_id' => $case->id,
            'creditor_id' => $creditor->id,
            'lead_key' => hash('sha256', 'test-key'),
            'status' => 'new',
            'status_source' => 'system',
            'qualification_status' => 'qualified',
            'claim_amount_total_czk' => 350000,
            'secured_claim_amount_czk' => 0,
            'unsecured_claim_amount_czk' => 350000,
            'primary_claim_type' => 'principal',
            'business_state_version' => 1,
            'metadata' => [],
        ]);
    }
}
