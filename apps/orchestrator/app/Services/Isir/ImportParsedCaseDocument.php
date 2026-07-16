<?php

namespace App\Services\Isir;

use App\Models\CaseDocument;
use App\Models\Claim;
use App\Models\Creditor;
use App\Models\InsolvencyCase;
use App\Models\Lead;
use App\Models\LeadStatusHistory;
use App\Services\Enrichment\CreditorEnrichmentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ImportParsedCaseDocument
{
    public function __construct(
        private readonly CreditorEnrichmentService $creditorEnrichment,
        private readonly LeadQualificationService $qualification,
    ) {}

    public function import(array $payload): array
    {
        $validated = Validator::make($payload, [
            'case_reference' => ['required', 'string'],
            'isir_event_id' => ['required', 'string'],
            'isir_document_id' => ['required', 'string'],
            'document_url' => ['required', 'string'],
            'event_label' => ['required', 'string'],
            'section' => ['nullable', 'string'],
            'document_type' => ['nullable', 'string'],
            'source_provider' => ['nullable', 'string'],
            'debtor_name' => ['nullable', 'string'],
            'published_at' => ['nullable', 'date'],
            'parsed_at' => ['nullable', 'date'],
            'payload' => ['nullable', 'array'],
            'claims' => ['array'],
            'claims.*.creditor_name' => ['required', 'string'],
            'claims.*.creditor_ico' => ['nullable', 'string'],
            'claims.*.amount_czk' => ['required', 'numeric', 'gt:0'],
            'claims.*.currency' => ['nullable', 'string', 'size:3'],
            'claims.*.secured' => ['nullable', 'boolean'],
            'claims.*.claim_type' => ['nullable', 'string'],
            'claims.*.priority_label' => ['nullable', 'string'],
            'claims.*.legal_form_code' => ['nullable', 'string'],
            'claims.*.nace_code' => ['nullable', 'string'],
            'claims.*.source_reference' => ['nullable', 'string'],
            'claims.*.raw_excerpt' => ['nullable', 'string'],
            'claims.*.metadata' => ['nullable', 'array'],
        ])->validate();

        return DB::transaction(function () use ($validated) {
            $publishedAt = $this->parseDate($validated['published_at'] ?? null);
            $parsedAt = $this->parseDate($validated['parsed_at'] ?? null);

            $case = InsolvencyCase::query()->firstOrNew([
                'court_file_reference' => $validated['case_reference'],
            ]);

            $case->fill([
                'debtor_name' => $validated['debtor_name'] ?? $validated['case_reference'],
                'source_section' => $validated['section'] ?? 'B',
                'last_isir_event_id' => $validated['isir_event_id'],
                'last_event_at' => $publishedAt,
                'last_document_published_at' => $publishedAt,
                'metadata' => [
                    'source_provider' => $validated['source_provider'] ?? 'isir_public_ws',
                ],
            ]);
            $case->save();

            $document = CaseDocument::query()->firstOrNew([
                'isir_document_id' => $validated['isir_document_id'],
            ]);

            $document->fill([
                'insolvency_case_id' => $case->id,
                'isir_event_id' => $validated['isir_event_id'],
                'section' => $validated['section'] ?? 'B',
                'event_label' => $validated['event_label'],
                'document_type' => $validated['document_type'] ?? 'final_report',
                'document_url' => $validated['document_url'],
                'source_provider' => $validated['source_provider'] ?? 'isir_public_ws',
                'download_status' => 'imported',
                'parse_status' => 'parsed',
                'published_at' => $publishedAt,
                'parsed_at' => $parsedAt ?? CarbonImmutable::now(),
                'payload' => $validated['payload'] ?? $validated,
            ]);
            $document->save();

            $claimCount = 0;
            $leadCreates = 0;
            $leadUpdates = 0;
            $leadAggregates = [];

            foreach ($validated['claims'] ?? [] as $claimPayload) {
                $claimPayload = $this->creditorEnrichment->enrichClaimPayload($claimPayload);
                $creditor = $this->upsertCreditor($claimPayload);
                $qualification = $this->qualification->qualifyClaim($claimPayload);
                $claimFingerprint = $this->buildClaimFingerprint(
                    caseReference: $validated['case_reference'],
                    documentId: $validated['isir_document_id'],
                    claimPayload: $claimPayload,
                );

                $claim = Claim::query()->firstOrNew([
                    'claim_fingerprint' => $claimFingerprint,
                ]);

                $claim->fill([
                    'insolvency_case_id' => $case->id,
                    'case_document_id' => $document->id,
                    'creditor_id' => $creditor->id,
                    'source_reference' => $claimPayload['source_reference'] ?? null,
                    'claim_type' => $claimPayload['claim_type'] ?? 'other',
                    'amount_czk' => $claimPayload['amount_czk'],
                    'currency' => strtoupper($claimPayload['currency'] ?? 'CZK'),
                    'priority_label' => $claimPayload['priority_label'] ?? ((bool) ($claimPayload['secured'] ?? false) ? 'secured' : 'unsecured'),
                    'secured' => (bool) ($claimPayload['secured'] ?? false),
                    'raw_excerpt' => $claimPayload['raw_excerpt'] ?? null,
                    'extracted_at' => $parsedAt ?? CarbonImmutable::now(),
                    'qualification_snapshot' => $qualification,
                    'metadata' => $claimPayload['metadata'] ?? [],
                ]);
                $claim->save();
                $claimCount++;

                $leadKey = $this->qualification->buildLeadKey($validated['case_reference'], $claimPayload['creditor_name']);
                $aggregate = $leadAggregates[$leadKey] ?? [
                    'creditor' => $creditor,
                    'lead_key' => $leadKey,
                    'qualified' => false,
                    'reasons' => [],
                    'claim_amount_total_czk' => 0.0,
                    'secured_claim_amount_czk' => 0.0,
                    'unsecured_claim_amount_czk' => 0.0,
                    'primary_claim_type' => $claimPayload['claim_type'] ?? 'other',
                ];

                $amount = (float) $claimPayload['amount_czk'];
                $aggregate['qualified'] = $aggregate['qualified'] || $qualification['qualified'];
                $aggregate['reasons'] = array_values(array_unique(array_merge($aggregate['reasons'], $qualification['reasons'])));
                $aggregate['claim_amount_total_czk'] += $amount;

                if ((bool) ($claimPayload['secured'] ?? false)) {
                    $aggregate['secured_claim_amount_czk'] += $amount;
                } else {
                    $aggregate['unsecured_claim_amount_czk'] += $amount;
                }

                $leadAggregates[$leadKey] = $aggregate;
            }

            foreach ($leadAggregates as $aggregate) {
                $lead = Lead::query()->firstOrNew([
                    'insolvency_case_id' => $case->id,
                    'creditor_id' => $aggregate['creditor']->id,
                ]);

                $wasRecentlyCreated = ! $lead->exists;
                $previousState = [
                    'qualification_status' => $lead->qualification_status,
                    'qualification_reason' => $lead->qualification_reason,
                    'claim_amount_total_czk' => (string) $lead->claim_amount_total_czk,
                    'secured_claim_amount_czk' => (string) $lead->secured_claim_amount_czk,
                    'unsecured_claim_amount_czk' => (string) $lead->unsecured_claim_amount_czk,
                ];

                $lead->fill([
                    'lead_key' => $aggregate['lead_key'],
                    'status' => $lead->status ?: 'new',
                    'status_source' => $lead->status_source ?: 'system',
                    'qualification_status' => $aggregate['qualified'] ? 'qualified' : 'rejected',
                    'qualification_reason' => $aggregate['qualified'] ? null : implode(',', $aggregate['reasons']),
                    'claim_amount_total_czk' => $aggregate['claim_amount_total_czk'],
                    'secured_claim_amount_czk' => $aggregate['secured_claim_amount_czk'],
                    'unsecured_claim_amount_czk' => $aggregate['unsecured_claim_amount_czk'],
                    'primary_claim_type' => $aggregate['primary_claim_type'],
                    'last_qualified_at' => CarbonImmutable::now(),
                    'metadata' => [
                        'qualification_reasons' => $aggregate['reasons'],
                        'source_document_id' => $validated['isir_document_id'],
                        'creditor_enrichment' => $aggregate['creditor']->metadata['enrichment'] ?? [],
                    ],
                ]);

                $materialChange = ! $wasRecentlyCreated && $this->qualification->hasMaterialChange($lead, $previousState);
                if ($wasRecentlyCreated || $materialChange) {
                    $lead->last_material_change_at = CarbonImmutable::now();
                }

                if ($materialChange) {
                    $lead->business_state_version = $lead->business_state_version + 1;
                }

                $lead->save();

                if ($wasRecentlyCreated) {
                    $leadCreates++;

                    LeadStatusHistory::query()->create([
                        'lead_id' => $lead->id,
                        'previous_status' => null,
                        'new_status' => $lead->status,
                        'source' => 'system',
                        'reason' => 'initial_import',
                        'payload' => [
                            'qualification_status' => $lead->qualification_status,
                            'source_document_id' => $validated['isir_document_id'],
                        ],
                        'changed_at' => CarbonImmutable::now(),
                    ]);
                } elseif ($materialChange) {
                    $leadUpdates++;
                }
            }

            return [
                'case_id' => $case->id,
                'document_id' => $document->id,
                'claim_count' => $claimCount,
                'lead_creates' => $leadCreates,
                'lead_updates' => $leadUpdates,
            ];
        });
    }

    private function upsertCreditor(array $claimPayload): Creditor
    {
        $normalizedName = $this->qualification->normalizeText($claimPayload['creditor_name']);
        $ico = $this->normalizeIco($claimPayload['creditor_ico'] ?? null);

        $query = Creditor::query();
        if ($ico) {
            $query->where('ico', $ico);
        } else {
            $query->where('normalized_name', $normalizedName);
        }

        $creditor = $query->first() ?? new Creditor();
        $creditor->fill([
            'normalized_name' => $normalizedName,
            'display_name' => $claimPayload['creditor_name'],
            'ico' => $ico,
            'person_type' => $claimPayload['person_type'] ?? ($ico ? 'legal_entity' : 'natural_person'),
            'legal_form_code' => $claimPayload['legal_form_code'] ?? null,
            'nace_code' => $claimPayload['nace_code'] ?? null,
            'ares_verified_at' => $this->parseDate($claimPayload['ares_verified_at'] ?? null),
            'ares_payload' => $claimPayload['ares_payload'] ?? $creditor->ares_payload,
            'metadata' => $claimPayload['metadata'] ?? [],
        ]);
        $creditor->save();

        return $creditor;
    }

    private function buildClaimFingerprint(string $caseReference, string $documentId, array $claimPayload): string
    {
        $parts = [
            $this->qualification->buildLeadKey($caseReference, $claimPayload['creditor_name']),
            $documentId,
            (string) ($claimPayload['claim_type'] ?? 'other'),
            number_format((float) $claimPayload['amount_czk'], 2, '.', ''),
            strtoupper($claimPayload['currency'] ?? 'CZK'),
            (bool) ($claimPayload['secured'] ?? false) ? 'secured' : 'unsecured',
            $this->qualification->normalizeText((string) ($claimPayload['raw_excerpt'] ?? $claimPayload['source_reference'] ?? $claimPayload['creditor_name'])),
        ];

        return hash('sha256', implode('|', $parts));
    }

    private function normalizeIco(?string $ico): ?string
    {
        return $this->qualification->normalizeIco($ico);
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

}
