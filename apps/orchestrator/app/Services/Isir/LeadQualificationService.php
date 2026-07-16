<?php

namespace App\Services\Isir;

use App\Models\Claim;
use App\Models\Creditor;
use App\Models\Lead;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LeadQualificationService
{
    public function qualifyClaim(array $claimPayload): array
    {
        $reasons = [];
        $amount = (float) $claimPayload['amount_czk'];

        $minimum = (float) config('isir.filter.lead_min_claim_amount', 300000);
        $maximum = (float) config('isir.filter.lead_max_claim_amount', 600000);
        if ($amount < $minimum || $amount > $maximum) {
            $reasons[] = 'amount_out_of_range';
        }

        $normalizedName = $this->normalizeText($claimPayload['creditor_name']);
        foreach (config('isir.qualification.creditor_name_blacklist', []) as $token) {
            if (str_contains($normalizedName, $this->normalizeText($token))) {
                $reasons[] = 'creditor_name_blacklisted';
                break;
            }
        }

        $ico = $this->normalizeIco($claimPayload['creditor_ico'] ?? null);
        if ($ico) {
            if (in_array((string) ($claimPayload['legal_form_code'] ?? ''), config('isir.qualification.excluded_legal_form_codes', []), true)) {
                $reasons[] = 'excluded_legal_form';
            }

            $nace = (string) ($claimPayload['nace_code'] ?? '');
            foreach (config('isir.qualification.excluded_nace_codes', []) as $prefix) {
                if ($prefix !== '' && str_starts_with($nace, (string) $prefix)) {
                    $reasons[] = 'excluded_nace';
                    break;
                }
            }
        } elseif (! config('isir.qualification.allow_natural_person_without_ico', true)) {
            $reasons[] = 'missing_ico_not_allowed';
        }

        return [
            'qualified' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    public function buildLeadKey(string $caseReference, string $creditorName): string
    {
        return hash('sha256', mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $caseReference) ?? $caseReference)).'|'.$this->normalizeText($creditorName));
    }

    /**
     * @param  Collection<int, Claim>  $claims
     * @return array<string, mixed>
     */
    public function summarizeLead(string $caseReference, Creditor $creditor, Collection $claims): array
    {
        $qualified = false;
        $reasons = [];
        $total = 0.0;
        $secured = 0.0;
        $unsecured = 0.0;
        $primaryClaimType = $claims->first()?->claim_type ?? 'other';

        foreach ($claims as $claim) {
            $claimPayload = [
                'creditor_name' => $creditor->display_name,
                'creditor_ico' => $creditor->ico,
                'amount_czk' => (float) $claim->amount_czk,
                'secured' => (bool) $claim->secured,
                'claim_type' => $claim->claim_type,
                'legal_form_code' => $creditor->legal_form_code,
                'nace_code' => $creditor->nace_code,
            ];

            $qualification = $this->qualifyClaim($claimPayload);
            $amount = (float) $claim->amount_czk;

            $qualified = $qualified || $qualification['qualified'];
            $reasons = array_values(array_unique(array_merge($reasons, $qualification['reasons'])));
            $total += $amount;

            if ($claim->secured) {
                $secured += $amount;
            } else {
                $unsecured += $amount;
            }
        }

        return [
            'lead_key' => $this->buildLeadKey($caseReference, $creditor->display_name),
            'qualified' => $qualified,
            'reasons' => $reasons,
            'claim_amount_total_czk' => $total,
            'secured_claim_amount_czk' => $secured,
            'unsecured_claim_amount_czk' => $unsecured,
            'primary_claim_type' => $primaryClaimType,
        ];
    }

    public function hasMaterialChange(Lead $lead, array $previousState): bool
    {
        return $previousState['qualification_status'] !== $lead->qualification_status
            || $previousState['qualification_reason'] !== $lead->qualification_reason
            || $previousState['claim_amount_total_czk'] !== (string) $lead->claim_amount_total_czk
            || $previousState['secured_claim_amount_czk'] !== (string) $lead->secured_claim_amount_czk
            || $previousState['unsecured_claim_amount_czk'] !== (string) $lead->unsecured_claim_amount_czk;
    }

    public function normalizeIco(?string $ico): ?string
    {
        if ($ico === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $ico);

        return $digits !== '' ? $digits : null;
    }

    public function normalizeText(string $value): string
    {
        $ascii = Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish();

        return (string) $ascii;
    }
}
