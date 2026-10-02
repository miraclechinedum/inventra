<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCurrentBusiness;
use App\Support\WhatsApp\TemplateBinding;
use App\Support\WhatsApp\WhatsAppTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Business's configuration of one automation. Every Business has its own row per key —
 * UNIQUE(business_id, key) — so switching, editing or mapping a template in one Business never
 * touches another's.
 */
class WhatsAppAutomation extends Model
{
    use ScopedToCurrentBusiness;

    protected $table = 'whatsapp_automations';

    public const WELCOME = 'welcome';

    public const POST_PURCHASE = 'post_purchase';

    public const PICKUP_REMINDER = 'pickup_reminder';

    public const LOW_STOCK = 'low_stock';

    protected $guarded = ['id', 'business_id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'delay_hours' => 'integer',
            'template_variables' => 'array',
            'template_synced_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'whatsapp_automation_id');
    }

    /**
     * Low-stock recipients. Stored as user ids, never as names or bare phone strings. Each pivot row
     * carries the automation's Business, and composite keys hold both the automation and the user
     * to it, so no automation can name another Business's staff.
     */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'whatsapp_automation_recipients', 'whatsapp_automation_id', 'user_id')
            ->withPivot('business_id')
            ->withTimestamps();
    }

    /** @param  list<int>  $userIds */
    public function syncRecipients(array $userIds): void
    {
        $this->recipients()->syncWithPivotValues($userIds, ['business_id' => $this->business_id]);
    }

    /** Whether Meta has approved the mapped template, so this may actually send. */
    public function templateIsApproved(): bool
    {
        return $this->template_status === TemplateBinding::APPROVED;
    }

    /** What the editor shows beside the template name. Never asserts an approval Meta didn't give. */
    public function templateStatusLabel(): string
    {
        return match ($this->template_status) {
            TemplateBinding::APPROVED => 'Approved',
            'PENDING' => 'Pending Meta review',
            'REJECTED' => 'Rejected by Meta',
            'PAUSED' => 'Paused by Meta',
            default => 'Not submitted',
        };
    }

    /** The current Business's automation for this key. Fails closed when no Business is in context. */
    public static function forKey(string $key): ?self
    {
        return self::query()->where('key', $key)->first();
    }

    /** The name shown in the Automations list and the editor header. */
    public function title(): string
    {
        return match ($this->key) {
            self::WELCOME => 'Welcome message',
            self::POST_PURCHASE => 'Post-purchase message',
            self::PICKUP_REMINDER => 'Pickup reminder',
            self::LOW_STOCK => 'Low-stock alert to manager',
            default => $this->key,
        };
    }

    /** The short label the Message templates card uses. */
    public function templateLabel(): string
    {
        return match ($this->key) {
            self::WELCOME => 'Welcome',
            self::POST_PURCHASE => 'Post-purchase',
            self::PICKUP_REMINDER => 'Pickup reminder',
            self::LOW_STOCK => 'Low-stock alert',
            default => $this->key,
        };
    }

    public function description(): string
    {
        return match ($this->key) {
            self::WELCOME => "Greet new customers the first time they're added.",
            self::POST_PURCHASE => 'Thank customers automatically after every paid sale.',
            self::PICKUP_REMINDER => 'Remind customers when their order is ready for pickup.',
            self::LOW_STOCK => 'Message the manager when any product hits its reorder level.',
            default => '',
        };
    }

    /** Trigger and audience, as the editor header states them. Wording matches the Figma. */
    public function triggerDescription(): string
    {
        return match ($this->key) {
            self::WELCOME => 'Trigger: customer added · Sends to: the customer',
            self::POST_PURCHASE => 'Trigger: sale marked paid · Sends to: the customer',
            self::PICKUP_REMINDER => 'Trigger: order ready · Sends to: the customer',
            self::LOW_STOCK => 'Trigger: stock ≤ reorder · Sends to: alert recipients',
            default => '',
        };
    }

    public function sendsToStaff(): bool
    {
        return $this->key === self::LOW_STOCK;
    }

    /** @return array<string, string> */
    public function chips(): array
    {
        return WhatsAppTemplate::chipsFor($this->key);
    }
}
