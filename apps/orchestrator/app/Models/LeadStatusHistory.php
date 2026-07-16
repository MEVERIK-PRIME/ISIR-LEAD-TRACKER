<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class LeadStatusHistory extends Model
{
    protected $fillable = [
        'lead_id',
        'previous_status',
        'new_status',
        'source',
        'reason',
        'payload',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'changed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
