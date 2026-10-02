{{-- Deliberately says nothing about what failed. Laravel has already logged the exception with its full trace; an exception message can carry SQL, file paths, customer data or a credential. --}}
@include('errors._state', ['title' => 'Something went wrong', 'state' => 'errors._500-state'])
