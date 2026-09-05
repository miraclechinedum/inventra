@php($footer = app(\App\Settings\BusinessSettings::class)->current()->receipt_footer)
@if($footer)
    <footer class="mt-8 border-t pt-4 text-sm text-slate-600" style="white-space: pre-line">{{ $footer }}</footer>
@endif
