{{-- TR1 (specs/core/18): the bot check on a public form, when bot protection is on. Expects $bot from BotProtection::widget(). --}}
@if (! empty($bot))
    <div class="{{ $bot['class'] }}" data-sitekey="{{ $bot['site_key'] }}" data-theme="auto" style="margin:12px 0"></div>
    <script src="{{ $bot['script'] }}" async defer></script>
@endif
