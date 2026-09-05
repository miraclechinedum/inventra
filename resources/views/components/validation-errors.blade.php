@props(['title' => 'We could not save this. Please review the following:'])

@if ($errors->any())
    <div role="alert" {{ $attributes->class('rounded-xl bg-red-50 p-4 text-red-700') }}>
        <p class="font-semibold">{{ $title }}</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
