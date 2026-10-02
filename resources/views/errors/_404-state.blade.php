<p class="err-code" aria-hidden="true">404</p>
<h2 class="err-title">Page not found</h2>
<p class="err-note">The page you're looking for doesn't exist or has moved.</p>
{{-- Signed-in staff go to the dashboard; a visitor is offered the way in, since the dashboard
     would only bounce them to the login screen. --}}
@auth
    <a href="{{ route('dashboard') }}" class="inventra-primary-action err-action"><span aria-hidden="true">←</span> Back to Dashboard</a>
@else
    <a href="{{ route('login') }}" class="inventra-primary-action err-action"><span aria-hidden="true">←</span> Back to sign in</a>
@endauth
