<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Creditor extends Model
{
    protected $fillable = [
        'normalized_name',
        'display_name',
        'ico',
        'person_type',
        'legal_form_code',
        'nace_code',
        'ares_verified_at',
        'ares_payload',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'ares_verified_at' => 'datetime',
            'ares_payload' => 'array',
            'metadata' => 'array',
        ];
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
