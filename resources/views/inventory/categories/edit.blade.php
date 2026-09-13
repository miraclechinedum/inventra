<x-app-layout :title="'Edit '.$category->name">
    <form method="POST" action="{{ route('inventory.categories.update', $category) }}" class="ui-form-sheet">
        @include('inventory.categories._form', ['heading' => 'Edit category', 'submitLabel' => 'Save changes', 'method' => 'PUT'])
    </form>
</x-app-layout>
