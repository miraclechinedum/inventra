<?php

namespace App\Http\Controllers;

use App\Actions\WhatsAppAutomation\CompleteMetaOnboarding;
use App\Actions\WhatsAppAutomation\DisconnectWhatsApp;
use App\Actions\WhatsAppAutomation\RetryWhatsAppMessage;
use App\Actions\WhatsAppAutomation\SendWhatsAppTestMessage;
use App\Actions\WhatsAppAutomation\StartWhatsAppOnboarding;
use App\Actions\WhatsAppAutomation\SyncWhatsAppTemplates;
use App\Actions\WhatsAppAutomation\UpdateWhatsAppAutomation;
use App\Actions\WhatsAppAutomation\WhatsAppAutomationEligibility;
use App\Contracts\WhatsAppConnectionProvider;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Settings\BusinessSettings;
use App\Subscriptions\Entitlement;
use App\Subscriptions\Entitlements;
use App\Support\WhatsApp\GraphVersion;
use App\Support\WhatsApp\OnboardingFailure;
use App\Support\WhatsApp\WhatsAppTemplate;
use App\Tenancy\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The WhatsApp Automation module.
 *
 * Every action authorizes through WhatsAppAutomationPolicy before doing anything. The policy is the
 * gate; the hidden sidebar link is not. A Manager or Sales Rep reaching any of these URLs directly
 * receives 403.
 */
class WhatsAppAutomationController extends Controller
{
    public function index(Request $request, BusinessSettings $business): View
    {
        Gate::authorize('viewAny', WhatsAppAutomation::class);

        $connection = WhatsAppConnection::forCurrentBusiness();
        $automations = WhatsAppAutomation::query()->with('recipients')->get()
            ->keyBy('key')
            ->sortBy(fn (WhatsAppAutomation $a): int => array_search($a->key, [
                WhatsAppAutomation::WELCOME,
                WhatsAppAutomation::POST_PURCHASE,
                WhatsAppAutomation::PICKUP_REMINDER,
                WhatsAppAutomation::LOW_STOCK,
            ], true));

        return view('whatsapp.automation.index', [
            'connection' => $connection,
            'connected' => $connection->isConnected(),
            'automations' => $automations,
            'messages' => $this->messages($request),
            'filters' => [
                'type' => is_string($request->query('type')) ? $request->query('type') : '',
                'status' => is_string($request->query('status')) ? $request->query('status') : '',
            ],
            'recipientOptions' => WhatsAppAutomationEligibility::eligibleRecipients(),
            'sampleValues' => WhatsAppTemplate::sampleValues($business->current()->business_name),
        ]);
    }

    /** The message log, filtered server-side and paginated. */
    private function messages(Request $request)
    {
        $type = $request->query('type');
        $status = $request->query('status');

        return WhatsAppMessage::query()
            ->when(is_string($type) && $type !== '', fn ($q) => $q->where('type', $type))
            ->when(is_string($status) && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();
    }

    /** What Meta's SDK needs in the browser, and nothing else: never the app secret or verify token. */
    public function connectionConfig(Request $request, WhatsAppConnectionProvider $provider): JsonResponse
    {
        Gate::authorize('connect', WhatsAppAutomation::class);

        return response()->json($this->sdkConfig($provider));
    }

    /**
     * Opens an Embedded Signup attempt: issues the server-side state the completion must present.
     * Requested before Meta's window opens, because Meta's code is valid for only 30 seconds.
     */
    public function startConnection(Request $request, WhatsAppConnectionProvider $provider, StartWhatsAppOnboarding $action, Entitlements $entitlements, CurrentBusiness $tenancy): JsonResponse
    {
        Gate::authorize('connect', WhatsAppAutomation::class);

        if (! $provider->isConfigured()) {
            throw ValidationException::withMessages(['connection' => OnboardingFailure::NotConfigured->message()]);
        }

        if (! $entitlements->allows($tenancy->forActor($request->user()), Entitlement::WhatsAppAutomation)) {
            throw ValidationException::withMessages(['connection' => OnboardingFailure::NotEntitled->message()]);
        }

        return response()->json($this->sdkConfig($provider) + [
            'state' => $action->execute($request->user(), $request->session()->getId()),
        ]);
    }

    public function completeConnection(Request $request, CompleteMetaOnboarding $action): JsonResponse
    {
        Gate::authorize('connect', WhatsAppAutomation::class);

        // The Business is never among these: it is the Administrator's own, from their account.
        $validated = $request->validate([
            'state' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'max:2048'],
            'waba_id' => ['required', 'string', 'regex:/^\d{1,64}$/'],
            'phone_number_id' => ['required', 'string', 'regex:/^\d{1,64}$/'],
            'pin' => ['required', 'string', 'regex:/^\d{6}$/'],
            'business_id' => ['prohibited'],
        ], [
            'pin.regex' => 'Enter the number\'s six-digit two-step verification PIN.',
            'pin.required' => 'Enter the number\'s six-digit two-step verification PIN.',
        ]);

        $connection = $action->execute(
            $request->user(),
            $validated['state'],
            $request->session()->getId(),
            $validated['code'],
            $validated['waba_id'],
            $validated['phone_number_id'],
            $validated['pin'],
        );

        // Identity only. The token is never serialised — the model hides it — and never returned.
        return response()->json([
            'status' => $connection->status,
            'phone' => $connection->displayNumber(),
            'verified_name' => $connection->verified_name,
        ]);
    }

    public function disconnect(Request $request, DisconnectWhatsApp $action): RedirectResponse
    {
        Gate::authorize('connect', WhatsAppAutomation::class);

        return back()->with('status', $action->execute($request->user())
            ? 'WhatsApp was disconnected. Messages that had not been sent were cancelled.'
            : 'WhatsApp was not connected.');
    }

    public function syncTemplates(Request $request, SyncWhatsAppTemplates $action, CurrentBusiness $tenancy): RedirectResponse
    {
        Gate::authorize('configure', WhatsAppAutomation::class);

        $changed = $action->execute($tenancy->forActor($request->user()), $request->user());

        return back()->with('status', $changed === null
            ? 'Message templates could not be read from Meta right now.'
            : 'Message templates were refreshed from Meta.');
    }

    /** @return array<string, mixed> */
    private function sdkConfig(WhatsAppConnectionProvider $provider): array
    {
        $configured = $provider->isConfigured();

        return [
            'configured' => $configured,
            'app_id' => (string) config('whatsapp.app_id'),
            'config_id' => (string) config('whatsapp.config_id'),
            'graph_version' => $configured ? GraphVersion::configured() : '',
        ];
    }

    public function toggle(Request $request, WhatsAppAutomation $automation, UpdateWhatsAppAutomation $action): JsonResponse
    {
        Gate::authorize('configure', $automation);

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $updated = $action->setEnabled($request->user(), $automation, (bool) $validated['enabled']);

        return response()->json(['enabled' => $updated->enabled]);
    }

    public function update(Request $request, WhatsAppAutomation $automation, UpdateWhatsAppAutomation $action): JsonResponse
    {
        Gate::authorize('configure', $automation);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:'.WhatsAppTemplate::MAX_LENGTH],
            'enabled' => ['nullable', 'boolean'],
            'delay_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
        ]);

        // `recipients` is deliberately no longer accepted. The low-stock destination is the
        // business's Manager alert number, so a posted recipient list — from a stale cached page or
        // a hand-written request — must not be able to re-establish a second source of truth by
        // writing the pivot. The pivot itself is left intact; nothing here reads or clears it.
        $updated = $action->save(
            actor: $request->user(),
            automation: $automation,
            body: $validated['body'],
            enabled: array_key_exists('enabled', $validated) ? (bool) $validated['enabled'] : null,
            delayHours: $validated['delay_hours'] ?? null,
            recipientIds: null,
        );

        return response()->json([
            'enabled' => $updated->enabled,
            'body' => $updated->body,
            'delay_hours' => $updated->delay_hours,
            'recipients' => $updated->recipients()->pluck('users.id'),
        ]);
    }

    public function sendTest(Request $request, WhatsAppAutomation $automation, SendWhatsAppTestMessage $action): JsonResponse
    {
        Gate::authorize('sendTest', $automation);

        $validated = $request->validate(['body' => ['nullable', 'string', 'max:'.WhatsAppTemplate::MAX_LENGTH]]);
        $message = $action->execute($request->user(), $automation, $validated['body'] ?? null);

        // Truthful either way: the provider's actual answer, never an assumed success.
        return response()->json([
            'status' => $message->status,
            'ok' => $message->status !== WhatsAppMessage::STATUS_FAILED,
            'message' => $message->status === WhatsAppMessage::STATUS_FAILED
                ? 'The test message could not be sent.'
                : 'Test message sent to your number.',
        ], $message->status === WhatsAppMessage::STATUS_FAILED ? 422 : 200);
    }

    public function retry(Request $request, WhatsAppMessage $message, RetryWhatsAppMessage $action): RedirectResponse
    {
        Gate::authorize('retry', [WhatsAppAutomation::class, $message]);

        $retry = $action->execute($request->user(), $message);

        return back()->with('status', $retry->status === WhatsAppMessage::STATUS_FAILED
            ? 'The retry was refused by WhatsApp.'
            : 'The message was sent again.');
    }
}
