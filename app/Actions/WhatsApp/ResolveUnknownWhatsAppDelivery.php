<?php

namespace App\Actions\WhatsApp;

use App\Enums\UserRole;
use App\Enums\WhatsAppDeliveryStatus;
use App\Models\User;
use App\Models\WhatsAppDelivery;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveUnknownWhatsAppDelivery
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, WhatsAppDelivery $delivery, string $note): WhatsAppDelivery
    {
        if ($actor->role !== UserRole::Admin) {
            throw new AuthorizationException('Only an Administrator may resolve an ambiguous WhatsApp delivery.');
        }

        $note = trim($note);

        if (mb_strlen($note) < 10 || mb_strlen($note) > 500) {
            throw ValidationException::withMessages([
                'resolution_note' => 'The resolution note must be between 10 and 500 characters.',
            ]);
        }

        return DB::transaction(function () use ($actor, $delivery, $note): WhatsAppDelivery {
            $locked = WhatsAppDelivery::query()->lockForUpdate()->findOrFail($delivery->id);

            if ($locked->status !== WhatsAppDeliveryStatus::Pending
                || $locked->failure_code !== 'outcome_unknown'
                || $locked->provider_message_id !== null) {
                throw ValidationException::withMessages([
                    'delivery' => 'Only an ambiguous delivery without a provider message ID can be marked unresolved.',
                ]);
            }

            DB::table('whatsapp_deliveries')->where('id', $locked->id)->update([
                'status' => WhatsAppDeliveryStatus::Unresolved->value,
                'resolved_at' => now(),
                'resolved_by' => $actor->id,
                'resolution_note' => $note,
                'updated_at' => now(),
            ]);

            $resolved = $locked->fresh();
            $this->audit->record('whatsapp_delivery_marked_unresolved', $resolved, $actor, metadata: [
                'delivery_id' => $resolved->id,
                'sale_id' => $resolved->sale_id,
                'attempt' => $resolved->attempt,
            ]);

            return $resolved;
        });
    }
}
