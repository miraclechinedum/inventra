<x-app-layout title="My profile">
    <x-validation-errors />
    <h1 class="text-3xl font-bold">My profile</h1>
    <p class="mt-2 text-slate-600">Your photo appears beside your name in Inventra. Only you and an administrator can see it.</p>
    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
            <h2 class="text-lg font-bold">Account</h2>
            <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                <div><dt class="text-xs uppercase text-slate-500">Name</dt><dd class="mt-1 font-semibold">{{ $user->name }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">Role</dt><dd class="mt-1 font-semibold">{{ $user->role->label() }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">Email</dt><dd class="mt-1">{{ $user->email }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">Phone</dt><dd class="mt-1">{{ $user->phone ?: '—' }}</dd></div>
            </dl>
            <p class="mt-6 border-t pt-5 text-sm text-slate-600">Your name, role and contact details are managed by an administrator. Ask them to change any of these.</p>
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-bold">Profile photo</h2>
            <div class="mt-4 flex items-center gap-4">
                <x-entity-image :url="$user->photo_path ? route('users.photo', $user) : null" :label="$user->name" size="h-20 w-20" rounded="rounded-full" />
                <p class="text-sm text-slate-600">JPG, PNG or WebP · up to 2 MB · maximum 4000&times;4000.</p>
            </div>
            <form method="POST" action="{{ route('profile.photo.store') }}" enctype="multipart/form-data" class="mt-5 space-y-3">
                @csrf
                <input type="file" aria-label="Profile photo" name="photo" accept="image/jpeg,image/png,image/webp" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('photo')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <button class="w-full rounded-lg bg-[#0b56c9] px-4 py-2.5 font-semibold text-white">{{ $user->photo_path ? 'Replace photo' : 'Upload photo' }}</button>
            </form>
            @if($user->photo_path)
                <div class="mt-4"><x-confirm-action :action="route('profile.photo.destroy')" label="Remove photo" message="Remove your profile photo? Your initials will be shown instead." destructive method="DELETE" /></div>
            @endif
        </section>
    </div>
</x-app-layout>
