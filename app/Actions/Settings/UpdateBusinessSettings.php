<?php

namespace App\Actions\Settings;

use App\Models\BusinessSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Settings\BusinessSettings;
use Illuminate\Support\Facades\DB;

class UpdateBusinessSettings
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessSettings $settings,
    ) {}

    /**
     * Applies only the fields that actually differ, under a row lock so two Administrators saving
     * at once cannot read a stale before-image. Last writer wins on any field they both changed;
     * the audit row records the values that were genuinely applied.
     *
     * @param  array<string, string|null>  $data  validated, already-normalised input
     * @return array<string, string|null> the fields that changed, empty when the submission was a no-op
     */
    public function execute(User $actor, array $data): array
    {
        $changed = DB::transaction(function () use ($actor, $data): array {
            $locked = BusinessSetting::query()
                ->where('singleton_key', BusinessSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            $old = [];
            $new = [];

            foreach (BusinessSetting::EDITABLE as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                if ($locked->{$field} !== $data[$field]) {
                    $old[$field] = $locked->{$field};
                    $new[$field] = $data[$field];
                    $locked->{$field} = $data[$field];
                }
            }

            // An unchanged submission writes nothing and records nothing: an audit row with an
            // empty before and after is not evidence of anything.
            if ($new === []) {
                return [];
            }

            $locked->updated_by = $actor->getKey();
            $locked->save();

            // $old and $new hold exactly the fields that changed, so a null here means the
            // Administrator deliberately cleared that field and must be recorded as such.
            $this->audit->record('business_settings_updated', $locked, $actor, $old, $new, explicitDiff: true);

            return $new;
        });

        // Dropped only once the transaction has committed. Invalidating inside it would leave the
        // reader empty-handed on a rollback, and a no-op changed nothing worth re-reading.
        if ($changed !== []) {
            $this->settings->forget();
        }

        return $changed;
    }
}
