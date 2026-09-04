<div class="grid gap-5 sm:grid-cols-2">
    @if(!isset($customer) || ($canUpdateIdentity ?? true))
        <x-form-field label="First name" name="first_name" :value="\App\Support\OldInput::scalar('first_name', $customer->first_name ?? '')" required />
        <x-form-field label="Last name" name="last_name" :value="\App\Support\OldInput::scalar('last_name', $customer->last_name ?? '')" />
        <x-form-field label="Phone" name="phone" :value="\App\Support\OldInput::scalar('phone', $customer->phone ?? '')" inputmode="tel" required />
    @endif
    <x-form-field label="Email" name="email" type="email" :value="\App\Support\OldInput::scalar('email', $customer->email ?? '')" />
    <x-form-field label="City" name="city" :value="\App\Support\OldInput::scalar('city', $customer->city ?? '')" />
    @if($canEditInternalDetails ?? true)
        <x-form-field label="Address" name="address" :value="\App\Support\OldInput::scalar('address', $customer->address ?? '')" />
        <div class="sm:col-span-2">
        <label for="notes" class="text-sm font-semibold">Notes</label>
        <textarea id="notes" name="notes" rows="5" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3">{{ \App\Support\OldInput::scalar('notes', $customer->notes ?? '') }}</textarea>
        @error('notes')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
    @endif
    @if(!isset($customer))<label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 sm:col-span-2"><input type="hidden" name="whatsapp_opt_in" value="0"><input type="checkbox" name="whatsapp_opt_in" value="1" @checked(\App\Support\OldInput::scalar('whatsapp_opt_in', false)) class="mt-1"><span><span class="block font-semibold">Customer explicitly consents to WhatsApp</span><span class="text-sm text-slate-500">Leave unchecked unless consent has been affirmatively provided.</span></span></label>@endif
</div>
<div class="mt-7 flex gap-3">
    <button class="rounded-xl bg-[#0b56c9] px-5 py-3 font-semibold text-white">{{ $submitLabel }}</button>
    <a href="{{ route('customers.index') }}" class="rounded-xl px-5 py-3 font-semibold text-slate-600">Cancel</a>
</div>
