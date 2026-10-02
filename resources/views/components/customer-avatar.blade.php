{{--
    A customer's photograph, or their initials when there is none.

    One component so the list, the profile and the forms cannot disagree about how a customer looks.
    The photograph streams from the authorized route — never a public path — and the initials
    fallback is what keeps a list aligned whether or not anything has been uploaded.
--}}
@props(['customer', 'size' => 'md'])

@if($customer->photo_path)
    <img src="{{ route('customers.photo', $customer) }}"
         alt="{{ $customer->full_name }}"
         loading="lazy"
         {{ $attributes->class(['cust-avatar', 'is-'.$size]) }}>
@else
    <span aria-hidden="true" {{ $attributes->class(['cust-avatar', 'is-initials', 'is-'.$size]) }}>
        {{ \App\Support\Initials::from($customer->full_name) }}
    </span>
@endif
