@if($errors->any())<div class="rounded-xl bg-red-50 p-4 text-red-700"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<label class="grid gap-2 font-semibold">Name<input aria-label="Name" class="rounded-xl border-slate-300" name="name" value="{{ \App\Support\OldInput::scalar('name', $category->name ?? '') }}" required>@error('name')<small class="text-red-600">{{ $message }}</small>@enderror</label>
<label class="grid gap-2 font-semibold">Description<textarea aria-label="Description" class="rounded-xl border-slate-300" name="description">{{ \App\Support\OldInput::scalar('description', $category->description ?? '') }}</textarea>@error('description')<small class="text-red-600">{{ $message }}</small>@enderror</label>
@foreach(['category_code','is_active','created_by','updated_by'] as $field)@error($field)<small class="text-red-600">{{ $message }}</small>@enderror @endforeach
<button class="inventra-primary-action w-fit">Save Expense Category</button>
