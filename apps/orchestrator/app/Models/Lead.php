<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $fillable = [
        'insolvency_case_id',
        'creditor_id',
        'lead_key',
        'sheet_row_id',
        'status',
        'status_source',
        'qualification_status',
        'qualification_reason',
        'claim_amount_total_czk',
        'secured_claim_amount_czk',
        'unsecured_claim_amount_czk',
        'primary_claim_type',
        'last_qualified_at',
        'last_synced_to_sheet_at',
        'last_sheet_import_at',
        'last_material_change_at',
        'business_state_version',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'claim_amount_total_czk' => 'decimal:2',
            'secured_claim_amount_czk' => 'decimal:2',
            'unsecured_claim_amount_czk' => 'decimal:2',
            'last_qualified_at' => 'datetime',
            'last_synced_to_sheet_at' => 'datetime',
            'last_sheet_import_at' => 'datetime',
            'last_material_change_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function insolvencyCase(): BelongsTo
    {
        return $this->belongsTo(InsolvencyCase::class);
    }

    public function creditor(): BelongsTo
    {
        return $this->belongsTo(Creditor::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(LeadStatusHistory::class);
    }
}
