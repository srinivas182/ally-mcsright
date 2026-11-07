<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Temporarily unavailable') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; background: #f8fafc; color: #0f172a; }
        main { max-width: 32rem; padding: 2rem; text-align: center; }
        h1 { font-size: 1.5rem; margin-bottom: .5rem; }
        p { color: #475569; line-height: 1.6; }
    </style>
</head>
<body>
<main>
    @if ($state === 'provisioning')
        <h1>{{ __('This site is being set up') }}</h1>
        <p>{{ __('Please check back in a few minutes.') }}</p>
    @elseif ($state === 'suspended')
        <h1>{{ __('This account is temporarily unavailable') }}</h1>
        <p>{{ __('If you manage this account, please contact RightAlly support.') }}</p>
    @else
        <h1>{{ __('This site is no longer available') }}</h1>
        <p>{{ __('The account for this address has been closed.') }}</p>
    @endif
</main>
</body>
</html>
