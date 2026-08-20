<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'event', 'ip_address', 'user_agent', 'metadata'])]
class SecurityEvent extends Model
{
    use MassPrunable;

    public $timestamps = false;

    public function prunable(): Builder
    {
        return static::query()->where(
            'created_at',
            '<=',
            now()->subDays(config('auth_security.security_events.retention_days')),
        );
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
