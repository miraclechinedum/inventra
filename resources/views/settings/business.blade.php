<x-app-layout title="Business settings"><div><h2 class="text-2xl font-bold">Business settings</h2><p class="text-slate-500">Business identity used across Inventra receipts and documents.</p></div>

<x-validation-errors class="mt-6" />

<form method="POST" action="{{ route('settings.business.update') }}" class="mt-6 max-w-3xl space-y-6">
@csrf @method('PUT')

<div class="rounded-2xl border bg-white p-6"><h3 class="font-bold">Identity</h3>
<div class="mt-4 grid gap-4 md:grid-cols-2">
<label class="text-sm">Business name <span class="text-red-600">*</span><input class="mt-1 w-full rounded-xl border-slate-300" type="text" name="business_name" maxlength="150" required value="{{ old('business_name', $settings->business_name) }}"></label>
<label class="text-sm">Registered legal name<input class="mt-1 w-full rounded-xl border-slate-300" type="text" name="legal_name" maxlength="150" value="{{ old('legal_name', $settings->legal_name) }}"></label>
</div></div>

<div class="rounded-2xl border bg-white p-6"><h3 class="font-bold">Contact</h3>
<div class="mt-4 grid gap-4 md:grid-cols-2">
<label class="text-sm">Phone<input class="mt-1 w-full rounded-xl border-slate-300" type="text" name="business_phone" maxlength="20" value="{{ old('business_phone', $settings->business_phone) }}" placeholder="08030000000"></label>
<label class="text-sm">Email<input class="mt-1 w-full rounded-xl border-slate-300" type="text" name="business_email" maxlength="255" value="{{ old('business_email', $settings->business_email) }}"></label>
<label class="text-sm md:col-span-2">Address<input class="mt-1 w-full rounded-xl border-slate-300" type="text" name="business_address" maxlength="255" value="{{ old('business_address', $settings->business_address) }}"></label>
<label class="text-sm">City<input class="mt-1 w-full rounded-xl border-slate-300" type="text" name="city" maxlength="100" value="{{ old('city', $settings->city) }}"></label>
<label class="text-sm">State<input class="mt-1 w-full rounded-xl border-slate-300" type="text" name="state" maxlength="100" value="{{ old('state', $settings->state) }}"></label>
</div></div>

<div class="rounded-2xl border bg-white p-6"><h3 class="font-bold">Receipts</h3>
<label class="mt-4 block text-sm">Receipt footer<textarea class="mt-1 w-full rounded-xl border-slate-300" name="receipt_footer" rows="3" maxlength="500" placeholder="Thank you for your business.">{{ old('receipt_footer', $settings->receipt_footer) }}</textarea><small class="text-slate-500">Plain text, up to 500 characters. Shown at the bottom of printed receipts.</small></label>
</div>

<div class="rounded-2xl border bg-white p-6"><h3 class="font-bold">Fixed for this installation</h3>
<p class="mt-1 text-sm text-slate-500">These are set at deployment and are not editable here. Changing them would alter how existing records are interpreted.</p>
<dl class="mt-4 grid gap-3 md:grid-cols-2">
<div class="flex justify-between border-b py-2"><dt class="text-slate-500">Currency</dt><dd class="font-medium">{{ app(\App\Settings\BusinessSettings::class)->currencyCode() }} ({{ app(\App\Settings\BusinessSettings::class)->currencySymbol() }})</dd></div>
<div class="flex justify-between border-b py-2"><dt class="text-slate-500">Business timezone</dt><dd class="font-medium">{{ app(\App\Settings\BusinessSettings::class)->timezone() }}</dd></div>
</dl></div>

<button class="rounded-xl bg-slate-800 px-5 py-3 text-white">Save settings</button>
</form>
</x-app-layout>
