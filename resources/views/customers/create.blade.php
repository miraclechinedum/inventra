{{--
    Add a customer. The form itself is the shared partial, so create and edit cannot drift apart;
    this only supplies the chrome the design puts around it.
--}}
<x-app-layout title="Add customer">
    <div class="cust cust-form-page">
        <div class="cust-form-head">
            <p class="cust-crumbs">
                <a href="{{ route('customers.index') }}">Customers</a>
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                <b>Add customer</b>
            </p>
            <a href="{{ route('customers.index') }}" class="cust-close" aria-label="Close" data-tooltip="Close">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </a>
        </div>

        @include('customers._form', ['customer' => null, 'canUpdateIdentity' => true])
    </div>
</x-app-layout>
