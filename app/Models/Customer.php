<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCurrentBusiness;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['first_name', 'last_name', 'phone', 'whatsapp_phone', 'email', 'address', 'city', 'notes', 'tag'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, ScopedToCurrentBusiness;

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Every WhatsApp message the application has attempted to this customer.
     *
     * Real delivery records, written by the WhatsApp pipeline — the profile's communication history
     * reads these and nothing else. There is no separate message log to invent entries from.
     */
    public function whatsappDeliveries(): HasMany
    {
        return $this->hasMany(WhatsAppDelivery::class);
    }

    /** WhatsApp automation messages sent to this customer. */
    public function whatsappMessages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.($this->last_name ?? '')));
    }

    /**
     * The number WhatsApp actually reaches this customer on.
     *
     * One definition, used by every delivery path, so a message can never be addressed one way in
     * eligibility and another way when it is sent.
     *
     *     effective WhatsApp number = whatsapp_phone ?? phone
     *
     * `whatsapp_phone` is null for almost every customer, and that is meaningful rather than
     * missing: it says WhatsApp reaches them on their ordinary number. A value is stored only when
     * the number genuinely differs, so the same number is never held twice in two columns that
     * could later disagree.
     *
     * This answers *where* a message would go. It says nothing about whether one may be sent —
     * that is `whatsapp_opt_in`, and WhatsAppReceiptEligibility still decides it.
     */
    public function effectiveWhatsAppPhone(): ?string
    {
        $number = $this->whatsapp_phone ?? $this->phone;

        return $number === null || trim($number) === '' ? null : $number;
    }

    /**
     * Whether WhatsApp reaches this customer on their ordinary phone rather than a separate number.
     *
     * The Customer screens phrase this as "WhatsApp same as phone number"; it is the absence of an
     * alternate destination, not a stored flag.
     */
    public function whatsAppUsesPhone(): bool
    {
        return $this->whatsapp_phone === null;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'whatsapp_opt_in' => 'boolean',
            'whatsapp_opt_in_at' => 'datetime',
            'whatsapp_opt_out_at' => 'datetime',
        ];
    }
}
