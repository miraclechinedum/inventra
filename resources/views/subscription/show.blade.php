@php($tz = config('business.timezone'))
<x-app-layout title="Subscription">
    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <h1 class="text-3xl font-bold">Subscription</h1>
            <p class="mt-2 text-slate-600">Your plan and what it includes.</p>
        </div>

        @if (session('status'))
            <p class="rounded-xl bg-amber-50 p-4 text-sm text-amber-800" role="status">{{ session('status') }}</p>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="text-sm text-slate-500">Plan</dt><dd class="mt-1 text-lg font-semibold">{{ $plan->name }}</dd></div>
                <div><dt class="text-sm text-slate-500">Status</dt><dd class="mt-1 text-lg font-semibold">{{ $status->label() }}</dd></div>
                @if ($status === \App\Enums\SubscriptionStatus::Trialing)
                    <div><dt class="text-sm text-slate-500">Trial ends</dt><dd class="mt-1">{{ $subscription->trial_ends_at->timezone($tz)->format('j M Y, g:i a') }}</dd></div>
                @endif
                @if ($status === \App\Enums\SubscriptionStatus::Grace && $subscription->grace_ends_at)
                    <div><dt class="text-sm text-slate-500">Full access until</dt><dd class="mt-1">{{ $subscription->grace_ends_at->timezone($tz)->format('j M Y, g:i a') }}</dd></div>
                @endif
                @if ($status === \App\Enums\SubscriptionStatus::Active && $subscription->current_period_ends_at)
                    <div><dt class="text-sm text-slate-500">Current period ends</dt><dd class="mt-1">{{ $subscription->current_period_ends_at->timezone($tz)->format('j M Y, g:i a') }}</dd></div>
                @endif
            </dl>

            @if ($access === \App\Subscriptions\Access::Restricted)
                <p class="mt-5 rounded-xl bg-red-50 p-4 text-sm text-red-700">{{ \App\Http\Middleware\EnsureSubscriptionPermitsWrites::MESSAGE }}</p>
            @elseif ($access === \App\Subscriptions\Access::Grace)
                <p class="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">Your trial or billing period has ended. Everything keeps working until the date above, after which Inventra becomes read-only.</p>
            @endif
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-bold">Included in your plan</h2>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach ($allowances as $label => $allowance)
                    <div><dt class="text-sm text-slate-500">{{ $label }}</dt><dd class="mt-1 font-semibold">{{ $allowance['used'] }} / {{ $allowance['limit'] ?? 'Unlimited' }}</dd></div>
                @endforeach
                <div><dt class="text-sm text-slate-500">WhatsApp automation</dt><dd class="mt-1">{{ $whatsapp ? 'Included' : 'Not included' }}</dd></div>
            </dl>
        </section>

        {{-- No payment provider exists yet, so there is deliberately no way to pay or change plan. --}}
        <section class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6">
            <h2 class="font-bold">Billing</h2>
            <p class="mt-2 text-sm text-slate-600">Billing setup is coming soon. You won't be charged, and your records stay safe in the meantime.</p>
        </section>
    </div>
</x-app-layout>
