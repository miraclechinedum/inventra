<x-app-layout title="Add category">
    <form method="POST" action="{{ route('inventory.categories.store') }}" class="ui-form-sheet">
        @include('inventory.categories._form', ['heading' => 'Add category', 'submitLabel' => 'Save category'])
    </form>
</x-app-layout>
