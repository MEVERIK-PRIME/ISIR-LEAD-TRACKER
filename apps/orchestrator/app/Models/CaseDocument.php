<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class CaseDocument extends Model
{
    protected $fillable = [
        'insolvency_case_id',
        'isir_document_id',
        'isir_event_id',
        'section',
        'event_label',
        'document_type',
        'document_url',
        'source_provider',
        'download_status',
        'parse_status',
        'checksum',
        'content_hash',
        'published_at',
        'fetched_at',
        'parsed_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'fetched_at' => 'datetime',
            'parsed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function insolvencyCase(): BelongsTo
    {
        return $this->belongsTo(InsolvencyCase::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }
}
