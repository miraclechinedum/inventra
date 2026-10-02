<span class="err-icon is-danger" aria-hidden="true">
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><path d="m13 6-2 3h3l-2 3M6 7h.01M6 17h.01"/></svg>
</span>
<h2 class="err-title">Something went wrong</h2>
<p class="err-note">An unexpected error occurred on our end.<br>Please try again.</p>
<div class="err-actions">
    @auth<a href="{{ route('dashboard') }}" class="wa-button">Back to Dashboard</a>@endauth
    <a href="{{ url()->current() }}" class="inventra-primary-action">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 12a8.5 8.5 0 1 1-2.9-6.4"/><path d="M20.5 4v5H15"/></svg>
        Reload
    </a>
</div>
