<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Claim extends Model
{
    protected $fillable = [
        'insolvency_case_id',
        'case_document_id',
        'creditor_id',
        'claim_fingerprint',
        'source_reference',
        'claim_type',
        'amount_czk',
        'currency',
        'priority_label',
        'secured',
        'raw_excerpt',
        'extracted_at',
        'qualification_snapshot',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_czk' => 'decimal:2',
            'secured' => 'boolean',
            'extracted_at' => 'datetime',
            'qualification_snapshot' => 'array',
            'metadata' => 'array',
        ];
    }

    public function insolvencyCase(): BelongsTo
    {
        return $this->belongsTo(InsolvencyCase::class);
    }

    public function caseDocument(): BelongsTo
    {
        return $this->belongsTo(CaseDocument::class);
    }

    public function creditor(): BelongsTo
    {
        return $this->belongsTo(Creditor::class);
    }
}
