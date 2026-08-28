<?php

namespace App\Http\Controllers;

use App\Actions\WhatsApp\ResolveUnknownWhatsAppDelivery;
use App\Actions\WhatsApp\SendWhatsAppReceipt;
use App\Contracts\WhatsAppClient;
use App\Models\Sale;
use App\Models\WhatsAppDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WhatsAppDeliveryController extends Controller
{
    public function send(Request $request, Sale $sale, SendWhatsAppReceipt $action): RedirectResponse
    {
        Gate::authorize('sendReceipt', [WhatsAppDelivery::class, $sale]);
        $requestId = $this->validatedSessionToken($request, 'whatsapp.send.'.$sale->id);
        $delivery = $action->execute($request->user(), $sale, $requestId);

        return redirect()->route('sales.show', $sale)->with(
            'status',
            $delivery->failure_code === 'outcome_unknown'
                ? 'The provider outcome could not be confirmed. Do not retry automatically.'
                : 'WhatsApp receipt request recorded.',
        );
    }

    public function resolveUnknown(
        Request $request,
        WhatsAppDelivery $delivery,
        ResolveUnknownWhatsAppDelivery $action,
    ): RedirectResponse {
        Gate::authorize('resolveUnknown', $delivery);
        $validated = $request->validate([
            'resolution_note' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $action->execute($request->user(), $delivery, trim($validated['resolution_note']));

        return redirect()->route('whatsapp.deliveries.show', $delivery)
            ->with('status', 'The ambiguous delivery attempt was marked unresolved.');
    }

    public function retry(Request $request, Sale $sale, WhatsAppDelivery $delivery, SendWhatsAppReceipt $action): RedirectResponse
    {
        Gate::authorize('retry', $delivery);

        if ($delivery->sale_id !== $sale->id) {
            abort(404);
        }

        $requestId = $this->validatedSessionToken($request, 'whatsapp.retry.'.$delivery->id);
        $action->execute($request->user(), $sale, $requestId, $delivery);

        return redirect()->route('whatsapp.deliveries.show', $delivery)->with('status', 'WhatsApp retry attempt recorded.');
    }

    public function index(Request $request, WhatsAppClient $client): View
    {
        Gate::authorize('viewAny', WhatsAppDelivery::class);

        return view('whatsapp.deliveries.index', [
            'deliveries' => WhatsAppDelivery::query()->with(['sale:id,sale_number', 'creator:id,name'])->latest()->paginate(20),
            'configured' => $client->isConfigured(),
        ]);
    }

    public function show(Request $request, WhatsAppDelivery $delivery): View
    {
        Gate::authorize('view', $delivery);
        $retryToken = (string) Str::uuid();
        $request->session()->put('whatsapp.retry.'.$delivery->id, $retryToken);

        return view('whatsapp.deliveries.show', [
            'delivery' => $delivery->load(['sale:id,sale_number', 'customer:id,customer_code,first_name,last_name', 'creator:id,name', 'resolver:id,name']),
            'retryToken' => $retryToken,
        ]);
    }

    private function validatedSessionToken(Request $request, string $sessionKey): string
    {
        $submitted = $request->input('request_token');
        $expected = $request->session()->get($sessionKey);

        if (! is_string($submitted) || ! is_string($expected) || ! Str::isUuid($submitted) || ! hash_equals($expected, $submitted)) {
            abort(422, 'This send confirmation has expired. Refresh the Sale page and try again.');
        }

        return $expected;
    }
}
