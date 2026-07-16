<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class InsolvencyCase extends Model
{
    protected $fillable = [
        'court_file_reference',
        'debtor_name',
        'debtor_ico',
        'proceeding_status',
        'source_section',
        'last_isir_event_id',
        'last_event_at',
        'last_document_published_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_event_at' => 'datetime',
            'last_document_published_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function caseDocuments(): HasMany
    {
        return $this->hasMany(CaseDocument::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
