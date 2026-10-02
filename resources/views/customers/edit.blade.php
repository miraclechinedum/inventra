{{--
    Edit a customer. Same partial as create; the mode is decided by `$customer` being present.
--}}
<x-app-layout title="Edit customer">
    <div class="cust cust-form-page">
        <div class="cust-form-head">
            {{-- The customer, then where you are within them: the name links back to the profile
                 the close button also returns to, and the current page is the emphasised crumb. --}}
            <p class="cust-crumbs">
                <a href="{{ route('customers.show', $customer) }}">{{ $customer->full_name }}</a>
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                <b>Edit customer</b>
            </p>
            <a href="{{ route('customers.show', $customer) }}" class="cust-close" aria-label="Close" data-tooltip="Close">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </a>
        </div>

        {{-- Two forms, one visual column. Consent posts to its own audited endpoint and so cannot
             be nested inside the profile form; `.cust-form-page` orders them so it still reads
             where the design puts it — after the fields, above the actions. --}}
        <div class="cust-edit-stack">
            @include('customers._form')
            @include('customers._consent')
        </div>
    </div>
</x-app-layout>
