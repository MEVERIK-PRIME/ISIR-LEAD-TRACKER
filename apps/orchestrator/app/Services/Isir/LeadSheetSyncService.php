<?php

namespace App\Services\Isir;

use App\Models\Lead;
use App\Models\LeadStatusHistory;
use App\Services\Google\GoogleSheetsClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class LeadSheetSyncService
{
    private const HEADER = [
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
    ];

    public function __construct(
        private readonly GoogleSheetsClient $sheetsClient,
    ) {
    }

    public function sync(string $direction = 'both'): array
    {
        return match ($direction) {
            'push' => ['push' => $this->pushLeads()],
            'pull' => ['pull' => $this->pullStatuses()],
            default => [
                'push' => $this->pushLeads(),
                'pull' => $this->pullStatuses(),
            ],
        };
    }

    public function pushLeads(): array
    {
        $existingRows = $this->rowsByLeadKey($this->sheetsClient->fetchRows('A:Q'));
        $leads = Lead::query()
            ->with(['creditor', 'insolvencyCase'])
            ->orderByDesc('last_material_change_at')
            ->orderByDesc('id')
            ->get();

        $rows = [self::HEADER];
        $rowNumber = 2;
        $rowAssignments = [];

        foreach ($leads as $lead) {
            $existing = $existingRows[$lead->lead_key] ?? [];

            $rows[] = [
                $lead->lead_key,
                $existing['sheet_status'] ?? $lead->status,
                $existing['sheet_note'] ?? '',
                $existing['sheet_owner'] ?? '',
                $lead->insolvencyCase?->court_file_reference ?? '',
                $lead->insolvencyCase?->debtor_name ?? '',
                $lead->creditor?->display_name ?? '',
                $lead->creditor?->ico ?? '',
                $lead->qualification_status,
                $lead->qualification_reason ?? '',
                number_format((float) $lead->claim_amount_total_czk, 2, '.', ''),
                number_format((float) $lead->secured_claim_amount_czk, 2, '.', ''),
                number_format((float) $lead->unsecured_claim_amount_czk, 2, '.', ''),
                $lead->primary_claim_type ?? '',
                optional($lead->last_qualified_at)?->toIso8601String() ?? '',
                optional($lead->last_material_change_at)?->toIso8601String() ?? '',
                (string) $lead->business_state_version,
            ];
            $rowAssignments[$lead->id] = (string) $rowNumber;
            $rowNumber++;
        }

        $this->sheetsClient->clearRows('A:Q');
        $this->sheetsClient->writeRows('A1:Q'.count($rows), $rows);

        $syncedAt = CarbonImmutable::now();
        Lead::query()->whereIn('id', array_keys($rowAssignments))->get()->each(function (Lead $lead) use ($rowAssignments, $syncedAt): void {
            $lead->forceFill([
                'sheet_row_id' => $rowAssignments[$lead->id],
                'last_synced_to_sheet_at' => $syncedAt,
            ])->save();
        });

        return [
            'rows_written' => count($rows) - 1,
            'lead_ids' => array_keys($rowAssignments),
        ];
    }

    public function pullStatuses(): array
    {
        $rows = $this->sheetsClient->fetchRows('A:Q');
        if ($rows === [] || count($rows) === 1) {
            return [
                'leads_updated' => 0,
            ];
        }

        $header = array_map('strval', $rows[0]);
        $dataRows = array_slice($rows, 1);
        $updated = 0;

        DB::transaction(function () use ($header, $dataRows, &$updated): void {
            foreach ($dataRows as $index => $row) {
                $record = $this->mapRow($header, $row);
                $leadKey = $record['lead_key'] ?? '';

                if ($leadKey === '') {
                    continue;
                }

                /** @var Lead|null $lead */
                $lead = Lead::query()->where('lead_key', $leadKey)->first();
                if (! $lead) {
                    continue;
                }

                $sheetStatus = trim((string) ($record['sheet_status'] ?? ''));
                $sheetNote = trim((string) ($record['sheet_note'] ?? ''));
                $sheetOwner = trim((string) ($record['sheet_owner'] ?? ''));
                $rowNumber = (string) ($index + 2);

                $metadata = $lead->metadata ?? [];
                $metadata['sheet_note'] = $sheetNote;
                $metadata['sheet_owner'] = $sheetOwner;

                $statusChanged = $sheetStatus !== '' && $sheetStatus !== $lead->status;
                $previousStatus = $lead->status;

                $lead->forceFill([
                    'sheet_row_id' => $rowNumber,
                    'status' => $sheetStatus !== '' ? $sheetStatus : $lead->status,
                    'status_source' => $sheetStatus !== '' ? 'sheet' : $lead->status_source,
                    'metadata' => $metadata,
                    'last_sheet_import_at' => CarbonImmutable::now(),
                ])->save();

                if ($statusChanged) {
                    LeadStatusHistory::query()->create([
                        'lead_id' => $lead->id,
                        'previous_status' => $previousStatus,
                        'new_status' => $sheetStatus,
                        'source' => 'sheet',
                        'reason' => 'sheet_status_import',
                        'payload' => [
                            'sheet_note' => $sheetNote,
                            'sheet_owner' => $sheetOwner,
                            'sheet_row_id' => $rowNumber,
                        ],
                        'changed_at' => CarbonImmutable::now(),
                    ]);

                    $updated++;
                }
            }
        });

        return [
            'leads_updated' => $updated,
        ];
    }

    private function rowsByLeadKey(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $header = array_map('strval', $rows[0]);
        $records = [];

        foreach (array_slice($rows, 1) as $row) {
            $record = $this->mapRow($header, $row);
            $leadKey = $record['lead_key'] ?? '';

            if ($leadKey === '') {
                continue;
            }

            $records[$leadKey] = $record;
        }

        return $records;
    }

    private function mapRow(array $header, array $row): array
    {
        $record = [];

        foreach ($header as $index => $column) {
            $record[$column] = $row[$index] ?? '';
        }

        return $record;
    }
}
