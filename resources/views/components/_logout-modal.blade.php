{{--
    Logout confirmation.

    Signing out is one click from every screen, so it is easy to hit by accident — the modal is the
    pause. It is opened by an `open-logout` event, so the sidebar icon and the profile page's Log
    out action share one dialog rather than duplicating it.

    The confirm is a real POST with CSRF through the existing `logout` route. There is deliberately
    no GET logout: a GET could be triggered by a prefetch, an image tag or a link in an email.
--}}
<div class="lo-modal" x-data="logoutConfirm" x-cloak x-show="open"
     x-on:open-logout.window="show" x-on:keydown.escape.window="close"
     x-on:keydown.tab="trapFocus" role="dialog" aria-modal="true" aria-labelledby="logout-title">
    <div class="lo-backdrop" x-on:click="close" aria-hidden="true"></div>

    <div class="lo-panel" x-ref="panel" tabindex="-1">
        <span class="lo-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.9"
                 stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></svg>
        </span>

        <h2 id="logout-title">Log out of Inventra?</h2>
        <p>You'll need to sign in again to access your business.</p>

        <div class="lo-actions">
            <button type="button" class="wa-button" x-on:click="close" x-ref="cancel">Cancel</button>
            <form method="POST" action="{{ route('logout') }}" x-on:submit="submit">
                @csrf
                <button type="submit" class="lo-confirm" x-bind:disabled="submitting">Log out</button>
            </form>
        </div>
    </div>
</div>
