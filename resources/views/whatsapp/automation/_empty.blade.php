{{-- The disconnected empty state, shown in all three cards exactly as the Figma does.

     Informational only. The connection action lives once in the page header rather than being
     repeated in every card: three identical CTAs on one screen read as three different actions. --}}
<div class="wa-empty">
    <span class="wa-empty-icon" aria-hidden="true">@include('whatsapp.automation._muted-icon')</span>
    <p class="wa-empty-title">WhatsApp isn't connected yet</p>
    <p class="wa-empty-note">Connect Business WhatsApp to continue automation</p>
</div>
