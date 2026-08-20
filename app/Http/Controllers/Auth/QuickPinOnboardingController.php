<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SetQuickPin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\QuickPinRequest;
use App\Services\SecurityEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QuickPinOnboardingController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        if ($request->user()->quick_pin_setup_completed) {
            return redirect()->route('dashboard');
        }

        return view('auth.onboarding-pin');
    }

    public function store(QuickPinRequest $request, SetQuickPin $setQuickPin): RedirectResponse
    {
        $setQuickPin->handle($request->user(), $request->string('pin')->toString());

        return redirect()->route('dashboard');
    }

    public function skip(Request $request, SecurityEventRecorder $events): RedirectResponse
    {
        $user = $request->user();
        $user->quick_pin_setup_completed = true;
        $user->save();

        $events->record('pin_skipped', $user);

        return redirect()->route('dashboard');
    }
}
