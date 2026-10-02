<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\BusinessSetting;
use App\Models\User;

/**
 * Business-wide configuration changes every receipt the business issues, so it stays
 * Administrator-only. Managers and Sales Representatives are denied both reading and updating.
 *
 * When a settings record is supplied it must also be the Administrator's own Business's: being an
 * Administrator of one business confers nothing over another's.
 */
class BusinessSettingPolicy
{
    public function view(User $user, ?BusinessSetting $settings = null): bool
    {
        return $user->role === UserRole::Admin && $this->owns($user, $settings);
    }

    public function update(User $user, ?BusinessSetting $settings = null): bool
    {
        return $user->role === UserRole::Admin && $this->owns($user, $settings);
    }

    private function owns(User $user, ?BusinessSetting $settings): bool
    {
        return $settings === null || ($user->business_id !== null && $settings->belongsToBusiness($user->business_id));
    }
}
