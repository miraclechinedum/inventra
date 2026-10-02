<?php

namespace App\Actions\WhatsAppAutomation;

use App\Contracts\WhatsAppConnectionProvider;
use App\Models\Business;
use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Services\AuditLogger;
use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;

/**
 * Reads a Business's own message templates from Meta and records, for each of its automations,
 * the status Meta reports — read-only against Meta, and never an approval Meta did not give.
 *
 * The mapping is the automation's `template_name` and `template_language`. An automation with no
 * mapping adopts the conventional template `inventra_{key}` only when that template exists on the
 * Business's own WABA in exactly one language. A mapped template Meta no longer lists loses its
 * status, which is what stops it sending. Creating, editing or submitting templates is not done
 * here, and neither is mapping template variables beyond the automation's allowlist order.
 */
class SyncWhatsAppTemplates
{
    public function __construct(
        private readonly WhatsAppConnectionProvider $provider,
        private readonly CurrentBusiness $tenancy,
        private readonly AuditLogger $audit,
    ) {}

    /** @return int|null automations whose template state changed, or null when Meta could not be read */
    public function execute(Business $business, ?User $actor = null): ?int
    {
        return $this->tenancy->run($business, function () use ($business, $actor): ?int {
            $connection = WhatsAppConnection::forBusiness($business);

            if (! $connection->isConnected()) {
                return null;
            }

            $templates = $this->provider->messageTemplates($connection);

            if ($templates === null) {
                return null;
            }

            $byName = collect($templates)->groupBy('name');

            return DB::transaction(function () use ($byName, $actor): int {
                $changed = 0;

                foreach (WhatsAppAutomation::query()->lockForUpdate()->get() as $automation) {
                    [$name, $language] = $this->mapping($automation, $byName);
                    $match = $name === null ? null : $byName->get($name, collect())->firstWhere('language', $language);
                    $status = $match === null ? null : strtoupper($match['status']);

                    $before = [$automation->template_name, $automation->template_language, $automation->template_status];
                    $automation->forceFill([
                        'template_name' => $name,
                        'template_language' => $language,
                        'template_status' => $status,
                        'template_synced_at' => now(),
                    ])->save();

                    if ($before !== [$name, $language, $status]) {
                        $changed++;
                        $this->audit->record('whatsapp_template_synced', $automation, $actor, newValues: ['status' => $status]);
                    }
                }

                return $changed;
            });
        });
    }

    /** @return array{0: string|null, 1: string} */
    private function mapping(WhatsAppAutomation $automation, $byName): array
    {
        $language = is_string($automation->template_language) && $automation->template_language !== '' ? $automation->template_language : 'en';

        if (is_string($automation->template_name) && trim($automation->template_name) !== '') {
            return [trim($automation->template_name), $language];
        }

        $conventional = $byName->get('inventra_'.$automation->key);

        return $conventional !== null && $conventional->count() === 1
            ? ['inventra_'.$automation->key, $conventional->first()['language']]
            : [null, $language];
    }
}
