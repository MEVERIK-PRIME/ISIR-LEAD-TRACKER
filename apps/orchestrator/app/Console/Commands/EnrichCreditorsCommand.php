<?php

namespace App\Console\Commands;

use App\Models\Claim;
use App\Models\Creditor;
use App\Models\Lead;
use App\Services\Enrichment\CreditorEnrichmentService;
use App\Services\Isir\LeadQualificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class EnrichCreditorsCommand extends Command
{
    protected $signature = 'creditors:enrich {--limit=100 : Maximum creditors to process} {--force : Re-enrich creditors even when ARES data already exists}';

    protected $description = 'Enrich imported creditors through Hlídac státu and ARES, then re-qualify dependent leads.';

    public function handle(CreditorEnrichmentService $enrichment, LeadQualificationService $qualification): int
    {
        $query = Creditor::query()->orderBy('id');

        if (! $this->option('force')) {
            $query->where(function ($builder) {
                $builder->whereNull('ares_verified_at')
                    ->orWhereNull('legal_form_code')
                    ->orWhereNull('nace_code');
            });
        }

        $creditors = $query->limit((int) $this->option('limit'))->get();

        $processed = 0;
        $leadUpdates = 0;

        foreach ($creditors as $creditor) {
            $creditor = $enrichment->enrichCreditor($creditor);
            $processed++;

            $leads = Lead::query()
                ->where('creditor_id', $creditor->id)
                ->with('insolvencyCase')
                ->get();

            foreach ($leads as $lead) {
                $claims = Claim::query()
                    ->where('creditor_id', $creditor->id)
                    ->where('insolvency_case_id', $lead->insolvency_case_id)
                    ->get();

                if ($claims->isEmpty()) {
                    continue;
                }

                $aggregate = $qualification->summarizeLead($lead->insolvencyCase->court_file_reference, $creditor, $claims);
                $previousState = [
                    'qualification_status' => $lead->qualification_status,
                    'qualification_reason' => $lead->qualification_reason,
                    'claim_amount_total_czk' => (string) $lead->claim_amount_total_czk,
                    'secured_claim_amount_czk' => (string) $lead->secured_claim_amount_czk,
                    'unsecured_claim_amount_czk' => (string) $lead->unsecured_claim_amount_czk,
                ];

                $lead->fill([
                    'lead_key' => $aggregate['lead_key'],
                    'qualification_status' => $aggregate['qualified'] ? 'qualified' : 'rejected',
                    'qualification_reason' => $aggregate['qualified'] ? null : implode(',', $aggregate['reasons']),
                    'claim_amount_total_czk' => $aggregate['claim_amount_total_czk'],
                    'secured_claim_amount_czk' => $aggregate['secured_claim_amount_czk'],
                    'unsecured_claim_amount_czk' => $aggregate['unsecured_claim_amount_czk'],
                    'primary_claim_type' => $aggregate['primary_claim_type'],
                    'last_qualified_at' => CarbonImmutable::now(),
                    'metadata' => array_merge($lead->metadata ?? [], [
                        'qualification_reasons' => $aggregate['reasons'],
                        'creditor_enrichment' => $creditor->metadata['enrichment'] ?? [],
                    ]),
                ]);

                $materialChange = $qualification->hasMaterialChange($lead, $previousState);
                if ($materialChange) {
                    $lead->business_state_version = $lead->business_state_version + 1;
                    $lead->last_material_change_at = CarbonImmutable::now();
                    $leadUpdates++;
                }

                $lead->save();
            }
        }

        $this->info("Processed {$processed} creditors and updated {$leadUpdates} leads.");

        return self::SUCCESS;
    }
}
