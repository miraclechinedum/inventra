@csrf
@if (($method ?? null) === 'PUT') @method('PUT') @endif
<div class="grid gap-5 sm:grid-cols-2">
    <x-form-field label="Full name" name="name" :value="old('name', $staffMember->name ?? '')" required autofocus />
    <x-form-field label="Email address" name="email" type="email" :value="old('email', $staffMember->email ?? '')" required />
    <x-form-field label="Nigerian phone" name="phone" :value="old('phone', $staffMember->phone ?? '')" placeholder="0801 234 5678" />
    @if (! isset($staffMember))
        <div>
            <label for="role" class="block text-sm font-semibold text-slate-800">Role</label>
            <select id="role" name="role" required class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 shadow-sm outline-none focus:border-[#0b56c9] focus:ring-4 focus:ring-blue-100">
                <option value="manager" @selected(old('role') === 'manager')>Manager</option>
                <option value="sales_rep" @selected(old('role') === 'sales_rep')>Sales Representative</option>
            </select>
            @error('role') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
    @endif
</div>
<div class="mt-7 flex items-center gap-3">
    <button class="rounded-xl bg-[#0b56c9] px-5 py-3 font-semibold text-white hover:bg-[#0848aa]">{{ $submitLabel }}</button>
    <a href="{{ isset($staffMember) ? route('staff.show', $staffMember) : route('staff.index') }}" class="rounded-xl px-5 py-3 font-semibold text-slate-600 hover:bg-slate-100">Cancel</a>
</div>
