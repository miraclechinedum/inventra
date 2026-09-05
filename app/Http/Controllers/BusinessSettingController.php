<?php

namespace App\Http\Controllers;

use App\Actions\Settings\UpdateBusinessSettings;
use App\Http\Requests\Settings\UpdateBusinessSettingsRequest;
use App\Models\BusinessSetting;
use App\Settings\BusinessSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BusinessSettingController extends Controller
{
    public function edit(BusinessSettings $settings): View
    {
        Gate::authorize('view', BusinessSetting::class);

        return view('settings.business', ['settings' => $settings->current()]);
    }

    public function update(UpdateBusinessSettingsRequest $request, UpdateBusinessSettings $action): RedirectResponse
    {
        // The action drops the memoised read itself, after its transaction commits.
        $changed = $action->execute($request->user(), $request->safe()->only(BusinessSetting::EDITABLE));

        return redirect()->route('settings.business.edit')->with('status', $changed === []
            ? 'No changes were made to the business settings.'
            : 'Business settings updated.');
    }
}
