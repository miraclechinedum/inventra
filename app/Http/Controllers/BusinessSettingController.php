<?php

namespace App\Http\Controllers;

use App\Actions\Settings\SetBusinessLogo;
use App\Actions\Settings\UpdateBusinessSettings;
use App\Http\Requests\Settings\BusinessLogoRequest;
use App\Http\Requests\Settings\UpdateBusinessSettingsRequest;
use App\Models\BusinessSetting;
use App\Models\WhatsAppConnection;
use App\Settings\BusinessSettings;
use App\Support\ImageStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BusinessSettingController extends Controller
{
    public function edit(BusinessSettings $settings): View
    {
        Gate::authorize('view', $settings->current());

        $connection = WhatsAppConnection::forCurrentBusiness();

        // No recipient count any more: the low-stock destination is the business's own
        // `manager_alert_number`, which the form already renders from the settings record.
        return view('settings.business', [
            'settings' => $settings->current(),
            'connection' => $connection,
            'connected' => $connection->isConnected(),
        ]);
    }

    public function update(UpdateBusinessSettingsRequest $request, UpdateBusinessSettings $action): RedirectResponse
    {
        // The action drops the memoised read itself, after its transaction commits.
        $changed = $action->execute($request->user(), $request->safe()->only(BusinessSetting::EDITABLE));

        return redirect()->route('settings.business.edit')->with('status', $changed === []
            ? 'No changes were made to the business settings.'
            : 'Business settings updated.');
    }

    public function storeLogo(BusinessLogoRequest $request, SetBusinessLogo $action): RedirectResponse
    {
        $action->store($request->user(), $request->file('logo'));

        return back()->with('status', 'Business logo saved.');
    }

    public function destroyLogo(Request $request, SetBusinessLogo $action, BusinessSettings $settings): RedirectResponse
    {
        Gate::authorize('update', $settings->current());
        $action->remove($request->user());

        return back()->with('status', 'Business logo removed.');
    }

    /**
     * Streams the logo from the private disk. The file is not web-reachable, so no storage:link or
     * signed URL is needed — and any signed-in staff member may see their own business's mark.
     */
    public function logo(BusinessSettings $settings, ImageStore $images): BinaryFileResponse
    {
        $path = $settings->current()->logo_path;
        abort_unless($images->exists($path), 404);

        return response()->file($images->absolutePath($path), ['Cache-Control' => 'private, max-age=300']);
    }
}
