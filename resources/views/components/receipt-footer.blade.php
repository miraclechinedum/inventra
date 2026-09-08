@php($footer = app(\App\Settings\BusinessSettings::class)->current()->receipt_footer)
@if($footer)
    <footer class="mt-8 border-t pt-4 text-sm text-slate-600 whitespace-pre-line">{{ $footer }}</footer>
@endif
