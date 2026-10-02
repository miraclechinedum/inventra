<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * A server-issued Embedded Signup attempt. See the migration for the binding it carries.
 *
 * No global scope: expired attempts are pruned by a scheduled command with no Business, and the
 * one lookup — by state, inside CompleteMetaOnboarding — names the Business explicitly.
 */
class WhatsAppOnboardingAttempt extends Model
{
    use BelongsToBusiness, MassPrunable;

    /** Embedded Signup's exchangeable code lives 30 seconds; this covers the operator's time with Meta. */
    public const LIFETIME_MINUTES = 15;

    protected $table = 'whatsapp_onboarding_attempts';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    /** Unconsumed attempts that expired: they hold nothing but a hash and prove nothing. */
    public function prunable(): Builder
    {
        return self::query()->whereNull('consumed_at')->where('expires_at', '<', now()->subDay());
    }
}
