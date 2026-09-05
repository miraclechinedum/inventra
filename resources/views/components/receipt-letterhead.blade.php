@php($business = app(\App\Settings\BusinessSettings::class)->current())
<header class="mb-6 border-b pb-4">
    <h1 class="text-2xl font-bold">{{ $business->business_name }}</h1>
    @if($business->legal_name)<p class="text-sm text-slate-500">{{ $business->legal_name }}</p>@endif
    @php($lines = array_filter([$business->business_address, implode(', ', array_filter([$business->city, $business->state]))]))
    @if($lines)<p class="mt-1 text-sm text-slate-600">{{ implode(' · ', $lines) }}</p>@endif
    @php($contact = array_filter([$business->business_phone, $business->business_email]))
    @if($contact)<p class="text-sm text-slate-600">{{ implode(' · ', $contact) }}</p>@endif
    @isset($document)<p class="mt-2 font-semibold">{{ $document }}</p>@endisset
</header>
