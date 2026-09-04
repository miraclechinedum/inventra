@csrf
@if(isset($supplier)) @method('PUT') @endif

@if($errors->any())
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">
        Please correct the highlighted Supplier details.
    </div>
@endif

<div class="grid gap-5 md:grid-cols-2">
    @foreach(['name' => 'Supplier name', 'contact_person' => 'Contact person', 'phone' => 'Phone', 'email' => 'Email', 'city' => 'City', 'address' => 'Address'] as $field => $label)
        <label class="grid gap-2 text-sm font-semibold">
            {{ $label }}
            <input class="rounded-xl border-slate-300" name="{{ $field }}" value="{{ \App\Support\OldInput::scalar($field, $supplier->$field ?? '') }}" @required($field === 'name')>
            @error($field)<span class="font-normal text-red-600">{{ $message }}</span>@enderror
        </label>
    @endforeach
    <label class="grid gap-2 text-sm font-semibold md:col-span-2">
        Notes
        <textarea class="rounded-xl border-slate-300" name="notes">{{ \App\Support\OldInput::scalar('notes', $supplier->notes ?? '') }}</textarea>
        @error('notes')<span class="font-normal text-red-600">{{ $message }}</span>@enderror
    </label>
    @foreach(['supplier_code', 'created_by', 'updated_by', 'is_active'] as $field)
        @error($field)<p class="text-sm text-red-600 md:col-span-2">{{ $message }}</p>@enderror
    @endforeach
</div>
<div class="mt-6 flex gap-3"><button class="inventra-primary-action">Save Supplier</button><a class="px-4 py-3" href="{{ route('suppliers.index') }}">Cancel</a></div>
