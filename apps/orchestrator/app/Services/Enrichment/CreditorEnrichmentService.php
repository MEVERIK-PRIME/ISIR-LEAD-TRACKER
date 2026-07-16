<?php

namespace App\Services\Enrichment;

use App\Models\Creditor;
use App\Services\Isir\LeadQualificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CreditorEnrichmentService
{
    /**
     * @var array<string, array<string, mixed>|null>
     */
    private array $aresCache = [];

    /**
     * @var array<string, array<string, mixed>|null>
     */
    private array $hlidacCache = [];

    public function __construct(
        private readonly AresClient $aresClient,
        private readonly HlidacStatuCompanyLookupClient $hlidacClient,
        private readonly LeadQualificationService $qualification,
    ) {}

    /**
     * @param  array<string, mixed>  $claimPayload
     * @return array<string, mixed>
     */
    public function enrichClaimPayload(array $claimPayload): array
    {
        $normalizedName = $this->qualification->normalizeText((string) $claimPayload['creditor_name']);
        $ico = $this->qualification->normalizeIco($claimPayload['creditor_ico'] ?? null);

        $hlidac = null;
        if (! $ico && $this->hlidacClient->isEnabled()) {
            $hlidac = $this->hlidacCache[$normalizedName] ??= $this->hlidacClient->findCompanyByExactName((string) $claimPayload['creditor_name']);
            $ico = $this->qualification->normalizeIco($hlidac['ICO'] ?? null);
        }

        $ares = null;
        if ($ico) {
            $ares = $this->aresCache[$ico] ??= $this->aresClient->findSubjectByIco($ico);
        }

        $metadata = $claimPayload['metadata'] ?? [];
        $metadata['enrichment'] = array_filter([
            'hlidac_statu' => $hlidac ? [
                'lookup_name' => $claimPayload['creditor_name'],
                'matched_name' => $hlidac['Jmeno'] ?? null,
                'ico' => $ico,
                'datove_schranky' => $hlidac['DatovaSchranka'] ?? [],
                'verified_at' => CarbonImmutable::now()->toIso8601String(),
            ] : null,
            'ares' => $ares ? [
                'official_name' => $ares['obchodniJmeno'] ?? $ares['nazev'] ?? null,
                'legal_form_code' => $this->extractLegalFormCode($ares),
                'nace_code' => $this->extractNaceCode($ares),
                'verified_at' => CarbonImmutable::now()->toIso8601String(),
            ] : null,
        ]);

        return array_merge($claimPayload, [
            'creditor_ico' => $ico,
            'legal_form_code' => $this->extractLegalFormCode($ares) ?? ($claimPayload['legal_form_code'] ?? null),
            'nace_code' => $this->extractNaceCode($ares) ?? ($claimPayload['nace_code'] ?? null),
            'person_type' => $ico ? 'legal_entity' : ($claimPayload['person_type'] ?? 'natural_person'),
            'ares_payload' => $ares,
            'ares_verified_at' => $ares ? CarbonImmutable::now()->toIso8601String() : null,
            'metadata' => $metadata,
        ]);
    }

    public function enrichCreditor(Creditor $creditor): Creditor
    {
        $payload = $this->enrichClaimPayload([
            'creditor_name' => $creditor->display_name,
            'creditor_ico' => $creditor->ico,
            'legal_form_code' => $creditor->legal_form_code,
            'nace_code' => $creditor->nace_code,
            'person_type' => $creditor->person_type,
            'metadata' => $creditor->metadata ?? [],
            'amount_czk' => 1,
        ]);

        $creditor->fill([
            'ico' => $payload['creditor_ico'] ?? null,
            'person_type' => $payload['person_type'] ?? $creditor->person_type,
            'legal_form_code' => $payload['legal_form_code'] ?? null,
            'nace_code' => $payload['nace_code'] ?? null,
            'ares_verified_at' => $payload['ares_verified_at'] ?? null,
            'ares_payload' => $payload['ares_payload'] ?? null,
            'metadata' => $payload['metadata'] ?? [],
        ]);
        $creditor->save();

        return $creditor;
    }

    /**
     * @param  array<string, mixed>|null  $ares
     */
    private function extractLegalFormCode(?array $ares): ?string
    {
        if (! $ares) {
            return null;
        }

        $value = Arr::get($ares, 'pravniForma')
            ?? Arr::get($ares, 'pravniFormaKod')
            ?? Arr::get($ares, 'pravniForma.id');

        return $this->normalizeCode($value);
    }

    /**
     * @param  array<string, mixed>|null  $ares
     */
    private function extractNaceCode(?array $ares): ?string
    {
        if (! $ares) {
            return null;
        }

        $candidates = Collection::make([
            Arr::get($ares, 'czNace.0.kod'),
            Arr::get($ares, 'czNace.0'),
            Arr::get($ares, 'nace.0.kod'),
            Arr::get($ares, 'nace.0'),
            Arr::get($ares, 'primarniCinnost.kod'),
            Arr::get($ares, 'hlavniCinnost.kod'),
        ])->filter();

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeCode($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeCode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? Str::of($string)->replace('.', '')->toString() : null;
    }
}
