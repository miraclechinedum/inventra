<?php

namespace App\Actions\WhatsAppAutomation;

use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Services\AuditLogger;
use App\Support\WhatsApp\WhatsAppTemplate;
use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of automation configuration. Toggling and template editing both land here, so the
 * allowlist can never be enforced on one path and skipped on the other.
 *
 * Every meaningful change is audited — a message going out automatically to customers is worth
 * being able to explain later.
 */
class UpdateWhatsAppAutomation
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrentBusiness $tenancy,
    ) {}

    /** The operator's own Business's automation, locked. Another Business's is simply not found. */
    private function lockOwn(User $actor, WhatsAppAutomation $automation): WhatsAppAutomation
    {
        return WhatsAppAutomation::query()
            ->where('business_id', $this->tenancy->forActor($actor)->getKey())
            ->lockForUpdate()
            ->findOrFail($automation->id);
    }

    /** The switch, on its own. */
    public function setEnabled(User $actor, WhatsAppAutomation $automation, bool $enabled): WhatsAppAutomation
    {
        return DB::transaction(function () use ($actor, $automation, $enabled): WhatsAppAutomation {
            $locked = $this->lockOwn($actor, $automation);

            if ($locked->enabled === $enabled) {
                return $locked;
            }

            $was = $locked->enabled;
            $locked->enabled = $enabled;
            $locked->updated_by = $actor->id;
            $locked->save();

            $this->audit->record(
                $enabled ? 'whatsapp_automation_enabled' : 'whatsapp_automation_disabled',
                $locked,
                $actor,
                oldValues: ['enabled' => $was],
                newValues: ['enabled' => $enabled],
            );

            return $locked;
        });
    }

    /**
     * The editor's Save. Validates the body against this automation's allowlist before it can be
     * persisted, so an unknown token is refused at the point of entry rather than discovered at
     * send time when a customer is waiting.
     *
     * @param  list<int>|null  $recipientIds  low-stock recipients; ignored for other automations
     */
    public function save(
        User $actor,
        WhatsAppAutomation $automation,
        string $body,
        ?bool $enabled = null,
        ?int $delayHours = null,
        ?array $recipientIds = null,
    ): WhatsAppAutomation {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'The message cannot be empty.']);
        }

        if (mb_strlen($body) > WhatsAppTemplate::MAX_LENGTH) {
            throw ValidationException::withMessages([
                'body' => 'The message cannot exceed '.WhatsAppTemplate::MAX_LENGTH.' characters.',
            ]);
        }

        // The allowlist gate. Server-side, so a crafted request cannot introduce a token the editor
        // never offered.
        $unknown = WhatsAppTemplate::unknownTokensFor($automation->key, $body);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'body' => 'This message uses details that are not available here: '.implode(', ', $unknown).'.',
            ]);
        }

        return DB::transaction(function () use ($actor, $automation, $body, $enabled, $delayHours, $recipientIds): WhatsAppAutomation {
            $locked = $this->lockOwn($actor, $automation);
            $old = ['body' => $locked->body, 'enabled' => $locked->enabled, 'delay_hours' => $locked->delay_hours];

            $locked->body = $body;

            if ($enabled !== null) {
                $locked->enabled = $enabled;
            }

            // Only the pickup reminder carries a delay; the column stays NULL elsewhere.
            if ($locked->key === WhatsAppAutomation::PICKUP_REMINDER && $delayHours !== null) {
                $locked->delay_hours = max(0, min(720, $delayHours));
            }

            $locked->updated_by = $actor->id;
            $locked->save();

            if ($locked->key === WhatsAppAutomation::LOW_STOCK && $recipientIds !== null) {
                // Only genuinely eligible staff may be stored, whatever the request asked for.
                $eligible = WhatsAppAutomationEligibility::eligibleRecipients()->pluck('id')->all();
                $locked->syncRecipients(array_values(array_intersect($recipientIds, $eligible)));
            }

            $this->audit->record('whatsapp_automation_updated', $locked, $actor, oldValues: $old, newValues: [
                'body' => $locked->body,
                'enabled' => $locked->enabled,
                'delay_hours' => $locked->delay_hours,
            ]);

            return $locked->refresh();
        });
    }
}
