<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\ScopedToCurrentBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One WhatsApp message Inventra sent or tried to send, whatever caused it.
 *
 * Status vocabulary is deliberately narrow and provider-truthful:
 *
 *   queued    — persisted, not yet handed to the provider.
 *   sent      — the provider accepted it. This is as far as an API response can establish.
 *   delivered — a webhook said it reached the handset.
 *   read      — a webhook said it was read.
 *   failed    — the provider refused it, or a webhook reported failure.
 *
 * Nothing here may set `delivered` or `read` from an HTTP 200: acceptance is not delivery, and
 * claiming otherwise would be a lie told to an operator about a customer's message.
 */
class WhatsAppMessage extends Model
{
    use ScopedToCurrentBusiness;

    protected $table = 'whatsapp_messages';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id', 'business_id'];

    /**
     * A message is stamped with its Business by whoever creates it, and must agree with everything
     * it names. Composite keys already hold the automation, connection, customer and staff
     * recipient to that Business; the polymorphic subject and the retried original cannot be keys,
     * so they are checked here.
     */
    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            if ($message->business_id === null) {
                throw new LogicException('A WhatsApp message must be stamped with its business.');
            }

            foreach ($message->namedOwners() as $owner) {
                if ($owner !== null && (int) $owner !== (int) $message->business_id) {
                    throw new LogicException('A WhatsApp message must belong to the same business as everything it names.');
                }
            }
        });
    }

    /** @return list<int|string|null> the Business of every record this message references */
    private function namedOwners(): array
    {
        $owners = [];

        foreach ([
            'whatsapp_automation_id' => 'whatsapp_automations', 'whatsapp_connection_id' => 'whatsapp_connection',
            'customer_id' => 'customers', 'user_id' => 'users', 'retry_of_id' => 'whatsapp_messages',
        ] as $column => $table) {
            if ($this->{$column} !== null) {
                $owners[] = DB::table($table)->where('id', $this->{$column})->value('business_id');
            }
        }

        $subject = $this->subject_type !== null ? Relation::getMorphedModel($this->subject_type) ?? $this->subject_type : null;

        if (is_string($subject) && is_subclass_of($subject, Model::class) && in_array(BelongsToBusiness::class, class_uses_recursive($subject), true)) {
            $owners[] = DB::table((new $subject)->getTable())->where('id', $this->subject_id)->value('business_id');
        }

        return $owners;
    }

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'dispatch_claimed_at' => 'datetime',
            'send_after' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempt' => 'integer',
            'template_values' => 'array',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAutomation::class, 'whatsapp_automation_id');
    }

    /** The connection this went out under — the sender identity, not the recipient. */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    /** Only a definitively failed message may be retried, and only the newest attempt of it. */
    public function isRetryable(): bool
    {
        return $this->status === self::STATUS_FAILED
            && ! self::query()->where('retry_of_id', $this->id)->exists();
    }

    /** How the log's TYPE column reads. */
    public function typeLabel(): string
    {
        return match ($this->type) {
            WhatsAppAutomation::WELCOME => 'Welcome',
            WhatsAppAutomation::POST_PURCHASE => 'Post-purchase',
            WhatsAppAutomation::PICKUP_REMINDER => 'Pickup reminder',
            WhatsAppAutomation::LOW_STOCK => 'Low-stock',
            'test' => 'Test',
            default => ucfirst(str_replace('_', ' ', $this->type)),
        };
    }

    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }

    /** How the log's BY column reads: what caused this message, not who typed it. */
    public function originLabel(): string
    {
        return match ($this->origin) {
            'automatic' => 'Auto',
            'manual' => 'Manual',
            'test' => 'Test',
            default => ucfirst($this->origin),
        };
    }
}
