<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SyncCheckpoint extends Model
{
    protected $fillable = [
        'provider',
        'stream',
        'checkpoint_value',
        'last_seen_reference',
        'status',
        'meta',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function scopeForStream(Builder $query, string $provider, string $stream): Builder
    {
        return $query->where('provider', $provider)->where('stream', $stream);
    }
}
